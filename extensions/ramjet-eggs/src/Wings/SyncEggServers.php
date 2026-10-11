<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Wings;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pterodactyl\Models\Server;

/** Re-syncs many servers after an egg change, off the request that caused it. */
final class SyncEggServers implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @param  list<int>  $serverIds */
    public function __construct(public readonly array $serverIds) {}

    public function handle(EggServerSync $sync): void
    {
        $sync->resync(Server::query()->with('node')->whereIn('id', $this->serverIds)->orderBy('id')->get());
    }
}
