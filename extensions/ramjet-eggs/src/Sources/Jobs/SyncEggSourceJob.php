<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceSyncService;

/**
 * Syncs one egg source off the request. Only one job per source is queued at a time. The "busy" marker lets the admin
 * screen poll until the sync has finished.
 */
final class SyncEggSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = EggSourceSyncService::LOCK_SECONDS;

    public int $uniqueFor = EggSourceSyncService::LOCK_SECONDS;

    public function __construct(public readonly int $sourceId) {}

    /** The cache key that is set while a sync of the source is queued or running. */
    public static function busyKey(int $sourceId): string
    {
        return "ramjet-eggs:sync-busy:{$sourceId}";
    }

    public function uniqueId(): string
    {
        return 'ramjet-eggs:sync-job:'.$this->sourceId;
    }

    public function handle(EggSourceSyncService $sync): void
    {
        try {
            $source = EggSource::query()->find($this->sourceId);
            if ($source !== null) {
                $sync->handle($source);
            }
        } finally {
            Cache::forget(self::busyKey($this->sourceId));
        }
    }
}
