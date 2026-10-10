<?php

namespace Pterodactyl\Services\Eggs\Sources;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Models\EggSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Pterodactyl\Services\Eggs\EggServerSyncService;
use Pterodactyl\Services\Eggs\Sharing\EggImporterService;
use Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService;

/**
 * Synchronizes eggs from a remote egg source (a GitHub or GitLab repository directory, or a single
 * URL) into the panel.
 *
 * - Every *.json file below the configured path that is an egg export (has "meta.version") is imported.
 * - Eggs previously imported from the same source file are updated in place; all others are created.
 * - The file rules of an updated egg are the UNION of the rules already on the egg and the rules in
 *   the repository. A sync can add patterns but never removes one.
 * - Redirects are never followed, and every host is checked before a request is made.
 *
 * This runs inline (in the request for "Sync now", and in the hourly scheduled command).
 */
class EggSourceSyncService
{
    /**
     * Timeout, in seconds, applied to every request made to a remote provider.
     */
    public const TIMEOUT_SECONDS = 15;

    /**
     * Timeout, in seconds, for establishing a connection to a remote provider.
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Maximum number of JSON files a single source may contain.
     */
    public const MAX_FILES = 250;

    /**
     * Maximum size, in bytes, of a single downloaded egg file.
     */
    public const MAX_FILE_BYTES = 1048576;

    /**
     * Upper bound on the number of GitLab tree pages requested during one sync.
     */
    private const MAX_GITLAB_PAGES = 500;

    /**
     * Addresses that are never allowed, regardless of the private host setting.
     */
    private const ALWAYS_BLOCKED_ADDRESSES = ['100.100.100.200', 'fd00:ec2::254'];

    public function __construct(
        private EggImporterService $importerService,
        private EggUpdateImporterService $updateImporterService,
        private EggServerSyncService $serverSyncService,
    ) {
    }

    /**
     * Run a full sync for the given egg source and persist the outcome on the source.
     *
     * Per-file failures are collected and do not stop the sync. A failure to list the files at all
     * is returned in the "error" key instead.
     *
     * @return array{error: string|null, files: array<int, array{path: string, status: string, egg: string|null, message: string|null}>}
     */
    public function handle(EggSource $source): array
    {
        $files = [];
        $error = null;
        $changedEggIds = [];

        try {
            $paths = $this->listFiles($source);
            if (empty($paths)) {
                throw new \RuntimeException('No JSON egg files were found at the configured location.');
            }

            if (count($paths) > self::MAX_FILES) {
                throw new \RuntimeException(sprintf('The location contains %d JSON files, which is more than the limit of %d. Narrow the path.', count($paths), self::MAX_FILES));
            }
        } catch (\Throwable $exception) {
            $error = $this->sanitize($exception->getMessage());
            $paths = [];
        }

        foreach ($paths as $path) {
            try {
                $content = $this->download($source, $path);
                [$result, $changedEggId] = $this->importFile($source, $path, $content);

                if (!is_null($changedEggId)) {
                    $changedEggIds[] = $changedEggId;
                }

                $files[] = $result;
            } catch (\Throwable $exception) {
                $files[] = [
                    'path' => $path,
                    'status' => 'failed',
                    'egg' => null,
                    'message' => $this->sanitize($exception->getMessage()),
                ];
            }
        }

        // Only servers of eggs whose file rules or registry changed need to be re-synced by Wings.
        $this->serverSyncService->handle($changedEggIds);

        $failures = array_values(array_filter($files, fn ($file) => $file['status'] === 'failed'));
        if (is_null($error) && !empty($failures)) {
            $error = sprintf(
                '%d of %d file(s) failed to sync: %s',
                count($failures),
                count($files),
                implode('; ', array_map(fn ($file) => $file['path'] . ' (' . $file['message'] . ')', array_slice($failures, 0, 5))),
            );
        }

        $source->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $error,
        ])->save();

        return ['error' => $error, 'files' => array_values($files)];
    }

    /**
     * Returns the repository-relative paths (or the URL for the "url" type) of every JSON file
     * that should be considered for this source.
     *
     * @return string[]
     *
     * @throws \RuntimeException|RequestException
     */
    protected function listFiles(EggSource $source): array
    {
        return match ($source->type) {
            EggSource::TYPE_GITHUB => $this->listGitHubFiles($source),
            EggSource::TYPE_GITLAB => $this->listGitLabFiles($source),
            EggSource::TYPE_URL => [$this->urlSourceAddress($source)],
            default => throw new \RuntimeException("Unsupported egg source type [{$source->type}]."),
        };
    }

    /**
     * Downloads the contents of a single file returned by listFiles().
     *
     * @throws \RuntimeException|RequestException
     */
    protected function download(EggSource $source, string $path): string
    {
        return match ($source->type) {
            EggSource::TYPE_GITHUB => $this->downloadGitHubFile($source, $path),
            EggSource::TYPE_GITLAB => $this->downloadGitLabFile($source, $path),
            EggSource::TYPE_URL => $this->fetchLimited($this->http($source, ['Accept' => 'application/json']), $this->urlSourceAddress($source)),
            default => throw new \RuntimeException("Unsupported egg source type [{$source->type}]."),
        };
    }

    /**
     * Lists every JSON blob below the configured directory using the recursive git tree API.
     *
     * @return string[]
     *
     * @throws \RuntimeException|RequestException
     */
    protected function listGitHubFiles(EggSource $source): array
    {
        $api = $this->apiBase($source, 'https://api.github.com');
        $repository = $this->requireValue($source->repository, 'repository');
        $branch = $this->requireValue($source->branch, 'branch');

        $response = $this->getChecked(
            $this->http($source, ['Accept' => 'application/vnd.github+json']),
            sprintf('%s/repos/%s/git/trees/%s', $api, $repository, rawurlencode($branch)),
            ['recursive' => 1],
        );

        if ($response->json('truncated') === true) {
            throw new \RuntimeException('GitHub truncated the repository tree because it is too large. Set the path to a smaller directory so fewer files are listed.');
        }

        $directory = $this->directory($source);
        $paths = [];
        foreach ((array) $response->json('tree', []) as $item) {
            $path = $item['path'] ?? '';
            if (($item['type'] ?? null) === 'blob' && $this->isEggFile($path, $directory)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Lists every JSON blob below the configured directory, following GitLab's pagination.
     *
     * @return string[]
     *
     * @throws \RuntimeException|RequestException
     */
    protected function listGitLabFiles(EggSource $source): array
    {
        $api = $this->apiBase($source, 'https://gitlab.com');
        $project = rawurlencode($this->requireValue($source->repository, 'repository'));
        $branch = $this->requireValue($source->branch, 'branch');
        $directory = $this->directory($source);

        $query = ['recursive' => 'true', 'ref' => $branch, 'per_page' => 100];
        if ($directory !== '') {
            $query['path'] = $directory;
        }

        $paths = [];
        for ($page = 1; $page <= self::MAX_GITLAB_PAGES; ++$page) {
            $query['page'] = $page;

            $response = $this->getChecked(
                $this->http($source),
                sprintf('%s/api/v4/projects/%s/repository/tree', $api, $project),
                $query,
            );

            foreach ((array) $response->json() as $item) {
                $path = $item['path'] ?? '';
                if (($item['type'] ?? null) === 'blob' && $this->isEggFile($path, $directory)) {
                    $paths[] = $path;
                }
            }

            // GitLab reports the next page in a header and leaves it empty on the last page.
            if (trim((string) $response->header('X-Next-Page')) === '') {
                break;
            }
        }

        return $paths;
    }

    /**
     * @throws \RuntimeException|RequestException
     */
    protected function downloadGitHubFile(EggSource $source, string $path): string
    {
        $api = $this->apiBase($source, 'https://api.github.com');
        $repository = $this->requireValue($source->repository, 'repository');
        $branch = $this->requireValue($source->branch, 'branch');

        return $this->fetchLimited(
            $this->http($source, ['Accept' => 'application/vnd.github.raw+json']),
            sprintf('%s/repos/%s/contents/%s', $api, $repository, $this->encodePath($path)),
            ['ref' => $branch],
        );
    }

    /**
     * @throws \RuntimeException|RequestException
     */
    protected function downloadGitLabFile(EggSource $source, string $path): string
    {
        $api = $this->apiBase($source, 'https://gitlab.com');
        $project = rawurlencode($this->requireValue($source->repository, 'repository'));
        $branch = $this->requireValue($source->branch, 'branch');

        return $this->fetchLimited(
            $this->http($source),
            sprintf('%s/api/v4/projects/%s/repository/files/%s/raw', $api, $project, rawurlencode($path)),
            ['ref' => $branch],
        );
    }

    /**
     * Imports a single downloaded file inside a transaction, so an egg is never left behind without
     * its source tags. Returns the per-file result along with the ID of the egg that changed in a way
     * Wings needs to know about (or null if nothing relevant changed).
     *
     * @return array{0: array{path: string, status: string, egg: string|null, message: string|null}, 1: int|null}
     *
     * @throws \Throwable
     */
    protected function importFile(EggSource $source, string $path, string $content): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The file is not valid JSON.');
        }

        // Other JSON files in the repository (package manifests, data files, etc.) are not eggs.
        if (is_null(Arr::get($decoded, 'meta.version'))) {
            return [
                ['path' => $path, 'status' => 'skipped', 'egg' => null, 'message' => 'Not an egg export (no meta.version).'],
                null,
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'egg-source-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a temporary file for the egg import.');
        }

        try {
            file_put_contents($tmp, $content);
            $file = new UploadedFile($tmp, basename($path), 'application/json', null, true);

            return DB::transaction(function () use ($source, $path, $file) {
                /** @var Egg|null $existing */
                $existing = Egg::query()
                    ->where('egg_source_id', $source->id)
                    ->where('egg_source_path', $path)
                    ->lockForUpdate()
                    ->first();

                $before = null;
                if (is_null($existing)) {
                    $egg = $this->importerService->handle($file, $source->nest_id);
                    $status = 'created';
                } else {
                    $before = EggServerSyncService::fingerprint($existing);
                    $previousDenylist = $existing->file_denylist ?? [];
                    $previousHidden = $existing->file_hidden ?? [];

                    $egg = $this->updateImporterService->handle($existing, $file);
                    $status = 'updated';

                    // Union: patterns already on the egg are kept, and the repository can only add to them.
                    $egg->forceFill([
                        'file_denylist' => $this->mergePatterns($previousDenylist, $egg->file_denylist ?? []),
                        'file_hidden' => $this->mergePatterns($previousHidden, $egg->file_hidden ?? []),
                    ]);
                }

                $attributes = ['egg_source_id' => $source->id, 'egg_source_path' => $path];
                if (!is_null($source->docker_registry_id)) {
                    $attributes['docker_registry_id'] = $source->docker_registry_id;
                }

                $egg->forceFill($attributes)->save();

                // Compare against the fingerprint from before the change, once the final values are stored.
                $changed = !is_null($before) && $before !== EggServerSyncService::fingerprint($egg->refresh());

                return [
                    ['path' => $path, 'status' => $status, 'egg' => $egg->name, 'message' => null],
                    $changed ? $egg->id : null,
                ];
            });
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Merges two lists of file patterns, keeping the order of the first list followed by any new entries.
     *
     * @param string[] $existing
     * @param string[] $incoming
     *
     * @return string[]
     */
    protected function mergePatterns(array $existing, array $incoming): array
    {
        return array_values(array_unique(array_merge($existing, $incoming)));
    }

    /**
     * Sends a GET request (after checking the host) and returns the response, refusing redirects.
     *
     * @throws \RuntimeException|RequestException
     */
    protected function getChecked(PendingRequest $request, string $url, array $query = []): Response
    {
        $this->assertAllowedHost($url);

        return $this->assertNoRedirect($request->get($url, $query)->throw());
    }

    /**
     * Downloads a body with a hard size limit. The body is read as a stream so an oversized response is
     * never buffered in full.
     *
     * @throws \RuntimeException|RequestException
     */
    protected function fetchLimited(PendingRequest $request, string $url, array $query = []): string
    {
        $this->assertAllowedHost($url);

        $response = $this->assertNoRedirect(
            $request->withOptions(['stream' => true])->get($url, $query)->throw()
        );

        $length = $response->header('Content-Length');
        if (is_numeric($length) && (int) $length > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('The file is larger than the 1 MiB limit for egg files.');
        }

        $stream = $response->toPsrResponse()->getBody();
        // Rewind seekable bodies so a body that was already read (for example a response object that is
        // shared between requests) is read from the start. Live network streams are not seekable.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $content = '';
        while (!$stream->eof()) {
            $remaining = self::MAX_FILE_BYTES + 1 - strlen($content);
            $content .= $stream->read(min(8192, $remaining));

            if (strlen($content) > self::MAX_FILE_BYTES) {
                throw new \RuntimeException('The file is larger than the 1 MiB limit for egg files.');
            }
        }

        return $content;
    }

    /**
     * Redirects are never followed for egg sources, so a provider cannot send the access token to
     * another host.
     *
     * @throws \RuntimeException
     */
    protected function assertNoRedirect(Response $response): Response
    {
        if ($response->status() >= 300 && $response->status() < 400) {
            throw new \RuntimeException('The source responded with a redirect, which is not followed for egg sources.');
        }

        return $response;
    }

    /**
     * Validates the URL of a single-file source before it is requested.
     *
     * @throws \RuntimeException
     */
    protected function urlSourceAddress(EggSource $source): string
    {
        $url = $this->requireValue($source->path, 'URL');
        $this->assertAllowedHost($url);

        return $url;
    }

    /**
     * Returns an HTTP client with the provider timeouts, redirects disabled and the authentication
     * headers applied.
     */
    protected function http(EggSource $source, array $headers = []): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withHeaders(array_merge($this->authHeaders($source), $headers));
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(EggSource $source): array
    {
        $token = $source->token;
        if (blank($token)) {
            return [];
        }

        return $source->type === EggSource::TYPE_GITLAB
            ? ['PRIVATE-TOKEN' => $token]
            : ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Returns the configured API base URL (or the public default) after checking that it is allowed.
     *
     * @throws \RuntimeException
     */
    protected function apiBase(EggSource $source, string $default): string
    {
        $base = rtrim(filled($source->base_url) ? $source->base_url : $default, '/');
        $this->assertAllowedHost($base);

        return $base;
    }

    /**
     * Rejects addresses that egg sources must never reach. Link-local and cloud metadata addresses are
     * always rejected. Private, loopback and other reserved ranges are rejected only when the
     * "pterodactyl.egg_sources.allow_private_hosts" setting is disabled.
     *
     * Hosts that do not resolve are allowed through, because the request itself will then fail.
     *
     * @throws \RuntimeException
     */
    protected function assertAllowedHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('The egg source address does not contain a valid host.');
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHost($host);

        $allowPrivate = (bool) config('pterodactyl.egg_sources.allow_private_hosts', true);
        foreach ($addresses as $address) {
            if ($this->isAlwaysBlocked($address)) {
                throw new \RuntimeException('The egg source address resolves to a link-local or metadata address, which is not allowed.');
            }

            if (!$allowPrivate && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Private and reserved addresses are not allowed for egg sources.');
            }
        }
    }

    /**
     * @return string[]
     */
    protected function resolveHost(string $host): array
    {
        $addresses = [];

        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        return array_values(array_unique($addresses));
    }

    protected function isAlwaysBlocked(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return true;
        }

        // IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are checked as their IPv4 address.
        if (strlen($binary) === 16 && substr($binary, 0, 10) === str_repeat("\0", 10) && substr($binary, 10, 2) === "\xff\xff") {
            $binary = substr($binary, 12);
        }

        foreach (self::ALWAYS_BLOCKED_ADDRESSES as $blocked) {
            if ($binary === inet_pton($blocked)) {
                return true;
            }
        }

        // IPv4 link-local 169.254.0.0/16 (includes the cloud metadata address).
        if (strlen($binary) === 4 && $binary[0] === "\xa9" && $binary[1] === "\xfe") {
            return true;
        }

        // IPv6 link-local fe80::/10.
        if (strlen($binary) === 16 && ord($binary[0]) === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
            return true;
        }

        return false;
    }

    /**
     * Removes query strings from any URLs in an error message, so tokens passed as query parameters
     * are never stored or displayed.
     */
    protected function sanitize(string $message): string
    {
        return preg_replace('/(https?:\/\/[^\s"\'<>?]+)\?[^\s"\'<>]*/i', '$1', $message) ?? $message;
    }

    /**
     * The configured directory without leading or trailing slashes ("" for the repository root).
     */
    protected function directory(EggSource $source): string
    {
        return trim((string) $source->path, '/');
    }

    protected function isEggFile(string $path, string $directory): bool
    {
        if ($directory !== '' && !str_starts_with($path, $directory . '/')) {
            return false;
        }

        return str_ends_with(strtolower($path), '.json');
    }

    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @throws \RuntimeException
     */
    protected function requireValue(?string $value, string $field): string
    {
        if (blank($value)) {
            throw new \RuntimeException("The egg source is missing its {$field}.");
        }

        return $value;
    }
}
