<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Wings\EggConfigCache;
use Ramjet\Eggs\Wings\EggServerSync;

/**
 * Which Docker registry an egg's images are pulled with. The credentials are read from here when
 * the server configuration is built for Wings (see the server configuration contributor).
 */
final class EggRegistries
{
    public function __construct(
        private readonly EggServerSync $serverSync,
        private readonly EggConfigCache $cache,
    ) {}

    /**
     * The registry whose credentials Wings should use for this egg, or null when it pulls anonymously.
     */
    public function registryFor(Egg $egg): ?DockerRegistry
    {
        return EggRegistryAssignment::query()->with('registry')->find($egg->id)?->registry;
    }

    /**
     * Assigns a registry to an egg (null for none). Servers using the egg are re-synced with Wings
     * once the surrounding transaction has committed, and only when the assignment changes.
     */
    public function assign(Egg $egg, ?int $registryId): void
    {
        $previous = EggRegistryAssignment::query()->find($egg->id)?->registry_id;
        if ($previous === $registryId) {
            return;
        }

        EggRegistryAssignment::query()->updateOrCreate(
            ['egg_id' => $egg->id],
            ['registry_id' => $registryId],
        );

        $this->cache->flush();

        DB::afterCommit(fn () => $this->serverSync->eggsChanged([$egg->id]));
    }
}
