<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Pterodactyl\Contracts\Eggs\ImportsEggs;
use Pterodactyl\Contracts\Eggs\UpdatesEggsFromImports;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Eggs\EggImportData;
use Pterodactyl\Support\JsonValueGuard;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\FilePatterns;
use Ramjet\Eggs\Rules\RuleSet;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Synchronizes eggs from a remote egg source (a GitHub or GitLab repository directory, or a single URL) into the panel.
 *
 * - Every *.json file below the configured path that is an egg export (has "meta.version") is imported. Other JSON
 *   files are reported as skipped.
 * - An egg previously imported from the same source file is updated in place; all others are created.
 * - A file whose provider revision (blob id) has not changed since its last import is not downloaded again.
 * - File rules of an egg are the UNION of the rules already on it and the rules in the repository, in that order.
 *   A sync can add patterns but never removes one. The union must stay within the file pattern limits, otherwise the
 *   file fails and nothing of it is kept.
 * - Every imported egg gets the source's tag, and the source's registry when one is set.
 * - Each file is imported in its own database transaction. Only one sync of a source runs at a time.
 *
 * Wings is re-synced for eggs whose rules or registry change, by EggRules and EggRegistries after commit.
 *
 * @phpstan-type FileResult array{path: string, status: string, egg: string|null, message: string|null}
 * @phpstan-type SyncResult array{error: string|null, files: list<FileResult>}
 */
final readonly class EggSourceSyncService
{
    /** Seconds a sync may hold the lock of its source. */
    public const LOCK_SECONDS = 900;

    public function __construct(
        private EggSourceClient $client,
        private ImportsEggs $importer,
        private UpdatesEggsFromImports $updater,
        private EggRules $rules,
        private EggRegistries $registries,
    ) {}

    /** The cache key of the lock that serializes the syncs of one source. */
    public static function lockKey(EggSource $source): string
    {
        return "ramjet-eggs:sync:{$source->id}";
    }

    /**
     * Runs a full sync for the source and persists the outcome on it. Per-file failures are collected and do not
     * stop the sync. A failure to list the files is returned in "error".
     *
     * @return SyncResult
     */
    public function handle(EggSource $source): array
    {
        $lock = Cache::lock(self::lockKey($source), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['error' => 'A sync of this egg source is already running.', 'files' => []];
        }

        try {
            return $this->run($source);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return SyncResult
     */
    private function run(EggSource $source): array
    {
        $files = [];
        $error = null;

        try {
            $listed = $this->client->listFiles($source);
            if (empty($listed)) {
                throw new RuntimeException('No JSON egg files were found at the configured location.');
            }

            if (count($listed) > EggSourceClient::MAX_FILES) {
                throw new RuntimeException(sprintf('The location contains more than %d JSON files, which is the limit. Narrow the path.', EggSourceClient::MAX_FILES));
            }
        } catch (Throwable $exception) {
            $error = $this->sanitize($exception->getMessage());
            $listed = [];
        }

        foreach ($listed as $file) {
            try {
                $files[] = $this->syncFile($source, $file);
            } catch (Throwable $exception) {
                $files[] = [
                    'path' => $file->path,
                    'status' => 'failed',
                    'egg' => null,
                    'message' => $this->sanitize($exception->getMessage()),
                ];
            }
        }

        $failures = array_values(array_filter($files, fn (array $file): bool => $file['status'] === 'failed'));
        if ($error === null && ! empty($failures)) {
            $error = sprintf(
                '%d of %d file(s) failed to sync: %s',
                count($failures),
                count($files),
                implode('; ', array_map(fn (array $file): string => $file['path'].' ('.$file['message'].')', array_slice($failures, 0, 5))),
            );
        }

        $source->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $error,
            'last_sync_results' => $files,
        ])->save();

        return ['error' => $error, 'files' => $files];
    }

    /**
     * @return FileResult
     *
     * @throws Throwable
     */
    private function syncFile(EggSource $source, SourceFile $file): array
    {
        $link = EggSourceEgg::query()->where('source_id', $source->id)->where('path', $file->path)->first();

        // The provider reports the blob id: an unchanged file is not downloaded again.
        if ($link !== null && $file->revision !== null && $link->revision === $file->revision) {
            return ['path' => $file->path, 'status' => 'unchanged', 'egg' => null, 'message' => null];
        }

        return $this->importFile($source, $file, $this->client->download($source, $file->path));
    }

    /**
     * Imports one downloaded file inside a transaction and returns its per-file result.
     *
     * @return FileResult
     *
     * @throws Throwable
     */
    private function importFile(EggSource $source, SourceFile $file, string $content): array
    {
        $decoded = $this->decode($content);

        // Other JSON files in the repository (package manifests, data files, etc.) are not eggs.
        if (Arr::get($decoded, 'meta.version') === null) {
            return ['path' => $file->path, 'status' => 'skipped', 'egg' => null, 'message' => 'Not an egg export (no meta.version).'];
        }

        $hidden = $this->hiddenPatterns($decoded);
        $parsed = EggImportData::fromJson($content);
        $this->assertWithinLimits($parsed->fileDenylist, 'file_denylist');

        return DB::transaction(function () use ($source, $file, $content, $parsed, $hidden): array {
            // SAFETY: the lookup is keyed by the unique (source, path) pair, so it yields at most one link.
            /** @var EggSourceEgg|null $link */
            $link = EggSourceEgg::query()
                ->where('source_id', $source->id)
                ->where('path', $file->path)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                $egg = $this->importer->importParsed($parsed);
                $status = 'created';

                EggSourceEgg::query()->create([
                    'egg_id' => $egg->id,
                    'source_id' => $source->id,
                    'path' => $file->path,
                    'revision' => $file->revision,
                ]);
            } else {
                $egg = Egg::query()->findOrFail($link->egg_id);
                $status = 'updated';

                $previous = $this->rules->get($egg);

                // The union is checked before anything is written: an over-limit union fails the file.
                $this->assertWithinLimits(EggRules::normalize([...$previous->hidden, ...$hidden]), 'file_hidden');
                $this->assertWithinLimits(EggRules::normalize([...$previous->protected, ...$parsed->fileDenylist]), 'file_denylist');

                $egg = $this->update($egg, $content);

                // The update replaces the rules with the repository's. Put back the ones already on the egg, so the
                // merge below keeps them in their original order and adds the repository's. Skipped when unchanged.
                if ($this->rules->get($egg) !== $previous) {
                    $this->rules->set($egg, $previous);
                }

                $link->forceFill(['revision' => $file->revision])->save();
            }

            $this->rules->merge($egg, new RuleSet(hidden: $hidden, protected: $parsed->fileDenylist));

            if ($source->docker_registry_id !== null) {
                $this->registries->assign($egg, $source->docker_registry_id);
            }

            $tag = $this->tagFor($source);
            if ($tag !== null) {
                $egg->tags()->syncWithoutDetaching([$tag->id]);
            }

            return ['path' => $file->path, 'status' => $status, 'egg' => $egg->name, 'message' => null];
        });
    }

    /**
     * Applies the repository's version of an existing egg through the regular update-from-import path. The contract
     * takes an uploaded file, so the content is written to a temporary file for the call.
     *
     * @throws Throwable
     */
    private function update(Egg $egg, string $content): Egg
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ramjet-egg-source-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create a temporary file for the egg import.');
        }

        try {
            file_put_contents($tmp, $content);
            $file = new UploadedFile($tmp, 'egg.json', 'application/json', null, true);

            return $this->updater->update($egg, $file);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The tag attached to the eggs of a source, created on first use. Null when the source has no tag.
     */
    private function tagFor(EggSource $source): ?Tag
    {
        if (blank($source->tag)) {
            return null;
        }

        return Tag::query()->firstOrCreate(['slug' => $source->tag], ['name' => $source->tag]);
    }

    /**
     * @return array<array-key, ApiValue7>
     *
     * @throws RuntimeException
     */
    private function decode(string $content): array
    {
        try {
            return JsonValueGuard::decodeArray8($content);
        } catch (JsonException|UnexpectedValueException) {
            throw new RuntimeException('The file is not valid JSON.');
        }
    }

    /**
     * The hidden file rules of the file. The import data does not carry them, so they are read here.
     *
     * @param  array<array-key, ApiValue7>  $decoded
     * @return list<string>
     *
     * @throws RuntimeException
     */
    private function hiddenPatterns(array $decoded): array
    {
        try {
            return FilePatterns::fromList($decoded['file_hidden'] ?? null, 'file_hidden');
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Refuses a file whose patterns, or whose union with the rules of its egg, is over the limits.
     *
     * @param  list<string>  $patterns
     *
     * @throws RuntimeException
     */
    private function assertWithinLimits(array $patterns, string $field): void
    {
        try {
            FilePatterns::assertWithinLimits($patterns, $field);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Removes query strings from any URL in an error message, so a token passed as a query parameter is never stored
     * or displayed.
     */
    private function sanitize(string $message): string
    {
        return preg_replace('/(https?:\/\/[^\s"\'<>?]+)\?[^\s"\'<>]*/i', '$1', $message) ?? $message;
    }
}
