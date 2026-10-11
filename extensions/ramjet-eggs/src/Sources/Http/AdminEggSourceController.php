<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Facades\Activity;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceData;
use Ramjet\Eggs\Sources\EggSourceEgg;
use Ramjet\Eggs\Sources\EggSourceSyncService;
use Ramjet\Eggs\Sources\Jobs\SyncEggSourceJob;
use Ramjet\Eggs\Sources\SourceUrl;

/**
 * Admin API for egg sources. Base: /api/admin/extensions/ramjet-eggs/sources.
 */
final class AdminEggSourceController
{
    /** The settings whose change means every file must be read again on the next sync. */
    private const CONFIGURATION_FIELDS = ['type', 'base_url', 'repository', 'branch', 'path', 'tag', 'docker_registry_id'];

    public function index(): JsonResponse
    {
        $sources = EggSource::query()->withCount('eggs')->orderBy('name')->get();

        return new JsonResponse(['data' => $sources->map(fn (EggSource $source): array => $this->present($source))->values()->all()]);
    }

    public function store(EggSourceRequest $request): JsonResponse
    {
        $data = EggSourceData::fromRequest($request);
        $this->assertTokenSafety($data, $data->token !== null);

        $source = new EggSource();
        $source->forceFill($data->attributes());
        if ($data->token !== null) {
            $source->token = $data->token;
        }

        $source->save();

        $this->logActivity('egg-source:create', $source);

        return new JsonResponse(['data' => $this->present($source->refresh()->loadCount('eggs'))], 201);
    }

    /**
     * A blank token keeps the stored token unless "clear_token" is set.
     *
     * @throws ValidationException
     */
    public function update(EggSourceRequest $request, int $id): JsonResponse
    {
        $source = EggSource::query()->findOrFail($id);
        $data = EggSourceData::fromRequest($request);

        // A stored token is only ever sent to the destination it was entered for. If that changes, the token must be
        // entered again (or removed) rather than being carried over.
        $destinationChanged = EggSourceData::destinationOf($data->type, $data->baseUrl, $data->path)
            !== EggSourceData::destinationOf($source->type, $source->base_url, $source->path);

        if ($destinationChanged && ! $data->clearToken && $data->token === null && $source->hasToken()) {
            throw ValidationException::withMessages(['token' => 'Re-enter the access token (or tick remove) when changing the source location.']);
        }

        $this->assertTokenSafety($data, $data->token !== null || ($source->hasToken() && ! $data->clearToken));

        $configurationChanged = $this->configurationChanged($source, $data);

        $attributes = $data->attributes();
        if ($data->clearToken) {
            $attributes['token'] = null;
        } elseif ($data->token !== null) {
            $attributes['token'] = $data->token;
        }

        $source->forceFill($attributes)->save();

        // Changed settings (tag, registry, location) must reach eggs whose files have not changed, so every file is
        // read again on the next sync.
        if ($configurationChanged) {
            EggSourceEgg::query()->where('source_id', $source->id)->update(['revision' => null]);
        }

        $this->logActivity('egg-source:update', $source);

        return new JsonResponse(['data' => $this->present($source->refresh()->loadCount('eggs'))]);
    }

    /**
     * Queues a sync of one source and returns at once. The admin screen polls the source until it has finished.
     */
    public function sync(int $id): JsonResponse
    {
        $source = EggSource::query()->findOrFail($id);

        Cache::put(SyncEggSourceJob::busyKey($source->id), true, EggSourceSyncService::LOCK_SECONDS);
        SyncEggSourceJob::dispatch($source->id);

        $this->logActivity('egg-source:sync', $source);

        return new JsonResponse(['data' => ['queued' => true, 'source' => $this->present($source->refresh()->loadCount('eggs'))]], 202);
    }

    /**
     * Deletes a source. Eggs imported from it are kept, but are no longer synchronized.
     */
    public function destroy(int $id): Response
    {
        $source = EggSource::query()->findOrFail($id);
        $this->logActivity('egg-source:delete', $source);

        $source->delete();

        return new Response('', 204);
    }

    /**
     * A token is only sent over https. A plain http address is refused when a token will be stored for it.
     *
     * @throws ValidationException
     */
    private function assertTokenSafety(EggSourceData $data, bool $willHaveToken): void
    {
        if (! $willHaveToken || ! EggSourceData::isPlainHttp($data->address())) {
            return;
        }

        throw ValidationException::withMessages([
            $data->type === EggSource::TYPE_URL ? 'path' : 'base_url' => 'Access tokens can only be used with https:// addresses.',
        ]);
    }

    private function configurationChanged(EggSource $source, EggSourceData $data): bool
    {
        $current = [
            'type' => $source->type,
            'base_url' => $source->base_url,
            'repository' => $source->repository,
            'branch' => $source->branch,
            'path' => $source->path,
            'tag' => $source->tag,
            'docker_registry_id' => $source->docker_registry_id,
        ];

        foreach ($data->attributes() as $field => $value) {
            if (in_array($field, self::CONFIGURATION_FIELDS, true) && $value !== $current[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes an activity entry with the non-secret properties of the source. The token is never recorded.
     */
    private function logActivity(string $event, EggSource $source): void
    {
        $url = ($source->type === EggSource::TYPE_URL ? $source->path : $source->base_url) ?? '';
        $host = SourceUrl::part($url, PHP_URL_HOST);

        Activity::event($event)
            ->withRequestMetadata()
            ->subject($source)
            ->property('id', $source->id)
            ->property('name', $source->name)
            ->property('type', $source->type)
            ->property('host', $host === '' ? null : $host)
            ->property('has_token', $source->hasToken())
            ->log();
    }

    /**
     * The token itself is never presented; only whether one is stored.
     *
     * @return array<string, string|int|bool|list<array{path: string, status: string, egg: string|null, message: string|null}>|null>
     */
    private function present(EggSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'type' => $source->type,
            'base_url' => $source->base_url,
            'repository' => $source->repository,
            'branch' => $source->branch,
            'path' => $source->path,
            'tag' => $source->tag,
            'docker_registry_id' => $source->docker_registry_id,
            'has_token' => $source->hasToken(),
            'eggs_count' => (int) ($source->eggs_count ?? 0),
            'syncing' => Cache::has(SyncEggSourceJob::busyKey($source->id)),
            'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            'last_sync_error' => $source->last_sync_error,
            'last_sync_results' => $source->last_sync_results ?? [],
            'created_at' => $source->created_at->toIso8601String(),
            'updated_at' => $source->updated_at->toIso8601String(),
        ];
    }
}
