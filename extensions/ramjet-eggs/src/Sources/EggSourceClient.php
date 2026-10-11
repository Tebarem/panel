<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Pterodactyl\Support\JsonValueGuard;
use RuntimeException;
use UnexpectedValueException;

/**
 * Lists and downloads the egg files of a source.
 *
 * - Redirects are never followed, so an access token cannot be forwarded to another host. Tokens are only sent
 *   over https.
 * - Every request is checked by EggSourceAddressPolicy, and the connection is pinned to the validated address, so
 *   the name cannot be resolved again to a different address (DNS rebinding).
 * - Listings are capped at LISTING_MAX_BYTES and files at MAX_FILE_BYTES, both read as streams and cut off as soon
 *   as they pass the limit. Error responses are not read.
 * - Paths with an empty, "." or ".." segment are never requested.
 */
final readonly class EggSourceClient
{
    /** Timeout, in seconds, applied to every request made to a remote provider. */
    public const TIMEOUT_SECONDS = 15;

    /** Timeout, in seconds, for establishing a connection to a remote provider. */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Maximum number of JSON files a single source may contain. */
    public const MAX_FILES = 250;

    /** Maximum size, in bytes, of a single downloaded egg file. */
    public const MAX_FILE_BYTES = 1048576;

    /** Maximum size, in bytes, of a single listing response. */
    public const LISTING_MAX_BYTES = 10485760;

    /** Upper bound on the number of GitLab tree pages requested during one sync. */
    private const MAX_GITLAB_PAGES = 500;

    public function __construct(private EggSourceAddressPolicy $policy) {}

    /**
     * True when no segment of the path is empty, "." or "..".
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns every JSON file that should be considered for the source. Paging stops once the list is over MAX_FILES,
     * which the caller reports as a limit error.
     *
     * @return list<SourceFile>
     *
     * @throws RuntimeException
     */
    public function listFiles(EggSource $source): array
    {
        return match ($source->type) {
            EggSource::TYPE_GITHUB => $this->listGitHubFiles($source),
            EggSource::TYPE_GITLAB => $this->listGitLabFiles($source),
            EggSource::TYPE_URL => [new SourceFile($this->urlAddress($source), null)],
            default => throw new RuntimeException("Unsupported egg source type [{$source->type}]."),
        };
    }

    /**
     * Downloads the contents of one file returned by listFiles().
     *
     * @throws RuntimeException
     */
    public function download(EggSource $source, string $path): string
    {
        return match ($source->type) {
            EggSource::TYPE_GITHUB => $this->downloadGitHubFile($source, $path),
            EggSource::TYPE_GITLAB => $this->downloadGitLabFile($source, $path),
            EggSource::TYPE_URL => $this->fetchFile($this->http($source, ['Accept' => 'application/json']), $this->urlAddress($source)),
            default => throw new RuntimeException("Unsupported egg source type [{$source->type}]."),
        };
    }

    /**
     * @return list<SourceFile>
     *
     * @throws RuntimeException
     */
    private function listGitHubFiles(EggSource $source): array
    {
        $api = $this->apiBase($source, 'https://api.github.com');
        $repository = $this->requireValue($source->repository, 'repository');
        $branch = $this->requireValue($source->branch, 'branch');
        $directory = $this->directory($source);

        [$tree] = $this->getJson(
            $this->http($source, ['Accept' => 'application/vnd.github+json']),
            sprintf('%s/repos/%s/git/trees/%s', $api, $repository, rawurlencode($branch)),
            ['recursive' => 1],
        );

        if (($tree['truncated'] ?? null) === true) {
            throw new RuntimeException('GitHub truncated the repository tree because it is too large. Set the path to a smaller directory so fewer files are listed.');
        }

        $files = [];
        foreach (JsonValueGuard::jsonArray($tree['tree'] ?? []) as $entry) {
            $item = JsonValueGuard::jsonArray($entry);
            $path = JsonValueGuard::string($item['path'] ?? null);

            if (JsonValueGuard::nullableString($item['type'] ?? null) === 'blob' && $this->isEggFile($path, $directory)) {
                $files[] = new SourceFile($path, JsonValueGuard::nullableString($item['sha'] ?? null));
            }
        }

        return $files;
    }

    /**
     * Lists every JSON blob below the configured directory, following GitLab's pagination.
     *
     * @return list<SourceFile>
     *
     * @throws RuntimeException
     */
    private function listGitLabFiles(EggSource $source): array
    {
        $api = $this->apiBase($source, 'https://gitlab.com');
        $project = rawurlencode($this->requireValue($source->repository, 'repository'));
        $branch = $this->requireValue($source->branch, 'branch');
        $directory = $this->directory($source);

        $query = ['recursive' => 'true', 'ref' => $branch, 'per_page' => 100];
        if ($directory !== '') {
            $query['path'] = $directory;
        }

        $files = [];
        for ($page = 1; $page <= self::MAX_GITLAB_PAGES; $page++) {
            $query['page'] = $page;

            [$items, $response] = $this->getJson(
                $this->http($source),
                sprintf('%s/api/v4/projects/%s/repository/tree', $api, $project),
                $query,
            );

            foreach ($items as $entry) {
                $item = JsonValueGuard::jsonArray($entry);
                $path = JsonValueGuard::string($item['path'] ?? null);

                if (JsonValueGuard::nullableString($item['type'] ?? null) === 'blob' && $this->isEggFile($path, $directory)) {
                    $files[] = new SourceFile($path, JsonValueGuard::nullableString($item['id'] ?? null));
                }
            }

            // Stop as soon as the source is over the limit: the caller reports it, and no further pages are needed.
            if (count($files) > self::MAX_FILES) {
                break;
            }

            // GitLab reports the next page in a header and leaves it empty on the last page.
            if (trim((string) $response->header('X-Next-Page')) === '') {
                break;
            }
        }

        return $files;
    }

    /**
     * @throws RuntimeException
     */
    private function downloadGitHubFile(EggSource $source, string $path): string
    {
        $api = $this->apiBase($source, 'https://api.github.com');
        $repository = $this->requireValue($source->repository, 'repository');
        $branch = $this->requireValue($source->branch, 'branch');

        return $this->fetchFile(
            $this->http($source, ['Accept' => 'application/vnd.github.raw+json']),
            sprintf('%s/repos/%s/contents/%s', $api, $repository, $this->encodePath($path)),
            ['ref' => $branch],
        );
    }

    /**
     * @throws RuntimeException
     */
    private function downloadGitLabFile(EggSource $source, string $path): string
    {
        $api = $this->apiBase($source, 'https://gitlab.com');
        $project = rawurlencode($this->requireValue($source->repository, 'repository'));
        $branch = $this->requireValue($source->branch, 'branch');

        return $this->fetchFile(
            $this->http($source),
            sprintf('%s/api/v4/projects/%s/repository/files/%s/raw', $api, $project, rawurlencode($path)),
            ['ref' => $branch],
        );
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array{0: array<array-key, ApiValue7>, 1: Response}
     *
     * @throws RuntimeException
     */
    private function getJson(PendingRequest $request, string $url, array $query = []): array
    {
        [$body, $response] = $this->readCapped(
            $request,
            $url,
            $query,
            self::LISTING_MAX_BYTES,
            'The listing of the egg source is larger than 10 MiB. Set the path to a smaller directory.',
        );

        try {
            $decoded = JsonValueGuard::decodeArray8($body);
        } catch (JsonException|UnexpectedValueException) {
            throw new RuntimeException('The egg source returned a listing that is not valid JSON.');
        }

        return [$decoded, $response];
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws RuntimeException
     */
    private function fetchFile(PendingRequest $request, string $url, array $query = []): string
    {
        [$body] = $this->readCapped($request, $url, $query, self::MAX_FILE_BYTES, 'The file is larger than the 1 MiB limit for egg files.');

        return $body;
    }

    /**
     * Sends a GET request, checks the address and reads the body up to the limit. Redirects and error statuses are
     * refused without reading the body.
     *
     * @param  array<string, string|int>  $query
     * @return array{0: string, 1: Response}
     *
     * @throws RuntimeException
     */
    private function readCapped(PendingRequest $request, string $url, array $query, int $limit, string $tooLarge): array
    {
        $addresses = $this->policy->assertAllowed($url);

        $response = $this->pin($request, $url, $addresses)
            ->withOptions(['stream' => true])
            ->get($url, $query);

        $this->assertNoRedirect($response);
        if ($response->failed()) {
            throw new RuntimeException(sprintf('The egg source responded with HTTP %d.', $response->status()));
        }

        $length = $response->header('Content-Length');
        // SAFETY: a missing or non-numeric length casts to 0, which is within the limit.
        if ((int) $length > $limit) {
            throw new RuntimeException($tooLarge);
        }

        $stream = $response->toPsrResponse()->getBody();
        // Rewind seekable bodies so a body that was already read is read from the start. Live network streams are
        // not seekable.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $content = '';
        while (! $stream->eof()) {
            $content .= $stream->read(min(8192, $limit + 1 - mb_strlen($content, '8bit')));

            // Bytes, not characters: the limit is a size in bytes.
            if (mb_strlen($content, '8bit') > $limit) {
                throw new RuntimeException($tooLarge);
            }
        }

        return [$content, $response];
    }

    /**
     * Pins the connection to the first validated address of the host, so the name is not resolved again. Literal IP
     * addresses need no pinning.
     *
     * @param  list<string>  $addresses
     */
    private function pin(PendingRequest $request, string $url, array $addresses): PendingRequest
    {
        $host = mb_strtolower(trim(SourceUrl::part($url, PHP_URL_HOST), '[]'));
        if ($addresses === [] || filter_var($host, FILTER_VALIDATE_IP)) {
            return $request;
        }

        $scheme = mb_strtolower(SourceUrl::part($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return $request->withOptions([
            'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $addresses[0])]],
        ]);
    }

    /**
     * Redirects are never followed for egg sources, so a provider cannot send the access token to another host.
     *
     * @throws RuntimeException
     */
    private function assertNoRedirect(Response $response): void
    {
        if ($response->status() >= 300 && $response->status() < 400) {
            throw new RuntimeException('The source responded with a redirect, which is not followed for egg sources.');
        }
    }

    /**
     * Returns the URL of a single-file source after checking that it is allowed.
     *
     * @throws RuntimeException
     */
    private function urlAddress(EggSource $source): string
    {
        $url = $this->requireValue($source->path, 'URL');
        $this->policy->assertAllowed($url);

        return $url;
    }

    /**
     * Returns an HTTP client with the timeouts, redirects disabled and the authentication headers applied.
     *
     * @param  array<string, string>  $headers
     *
     * @throws RuntimeException
     */
    private function http(EggSource $source, array $headers = []): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withHeaders([...$this->authHeaders($source), ...$headers]);
    }

    /**
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function authHeaders(EggSource $source): array
    {
        $token = $source->token;
        if (blank($token)) {
            return [];
        }

        $this->assertTokenMayBeSent($source);

        return $source->type === EggSource::TYPE_GITLAB
            ? ['PRIVATE-TOKEN' => $token]
            : ['Authorization' => 'Bearer '.$token];
    }

    /**
     * A token is only ever sent over https.
     *
     * @throws RuntimeException
     */
    private function assertTokenMayBeSent(EggSource $source): void
    {
        $url = ($source->type === EggSource::TYPE_URL ? $source->path : $source->base_url) ?? '';
        if ($url !== '' && mb_strtolower(SourceUrl::part($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Access tokens can only be sent to https:// addresses.');
        }
    }

    /**
     * Returns the configured API base URL (or the public default) after checking that it is allowed.
     *
     * @throws RuntimeException
     */
    private function apiBase(EggSource $source, string $default): string
    {
        $base = rtrim(filled($source->base_url) ? (string) $source->base_url : $default, '/');
        $this->policy->assertAllowed($base);

        return $base;
    }

    /**
     * The configured directory without leading or trailing slashes ("" for the repository root).
     *
     * @throws RuntimeException when the directory contains an empty, "." or ".." segment
     */
    private function directory(EggSource $source): string
    {
        $directory = trim($source->path ?? '', '/');
        if (! self::isSafePath($directory)) {
            throw new RuntimeException('The directory of the egg source must not contain "." or ".." segments.');
        }

        return $directory;
    }

    private function isEggFile(string $path, string $directory): bool
    {
        if (! self::isSafePath($path)) {
            return false;
        }

        if ($directory !== '' && ! str_starts_with($path, $directory.'/')) {
            return false;
        }

        return str_ends_with(mb_strtolower($path), '.json');
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @throws RuntimeException
     */
    private function requireValue(?string $value, string $field): string
    {
        if (blank($value)) {
            throw new RuntimeException("The egg source is missing its {$field}.");
        }

        return $value;
    }
}
