<?php

namespace Pterodactyl\Services\Eggs;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Tells Wings to re-sync the servers that use an egg whenever settings Wings reads from the
 * egg (file visibility rules and Docker registry credentials) have changed. Wings otherwise
 * only picks up these values the next time it boots or syncs a server.
 */
class EggServerSyncService
{
    public function __construct(private DaemonServerRepository $daemonServerRepository)
    {
    }

    /**
     * Returns a fingerprint of the egg settings that are pushed to Wings. Compare the fingerprint
     * before and after an update to find out whether a sync is required.
     */
    public static function fingerprint(Egg $egg): string
    {
        return json_encode([
            'config_from' => $egg->config_from,
            'file_denylist' => $egg->file_denylist,
            'file_hidden' => $egg->file_hidden,
            'inherit_file_denylist' => $egg->inherit_file_denylist,
            'inherit_file_hidden' => $egg->inherit_file_hidden,
            'docker_registry_id' => $egg->docker_registry_id,
        ]);
    }

    /**
     * Triggers a sync for every server using one of the given eggs, as well as every server using
     * an egg that copies its configuration from one of them. Connection failures are logged for
     * each server and never thrown, so they will not fail the request that caused the change.
     *
     * @param int[] $eggIds
     */
    public function handle(array $eggIds): void
    {
        $eggIds = array_values(array_unique(array_map('intval', $eggIds)));
        if (empty($eggIds)) {
            return;
        }

        $affectedEggIds = Egg::query()
            ->whereIn('id', $eggIds)
            ->orWhereIn('config_from', $eggIds)
            ->pluck('id')
            ->all();

        if (empty($affectedEggIds)) {
            return;
        }

        $servers = Server::query()->with('node')->whereIn('egg_id', $affectedEggIds)->get();

        foreach ($servers as $server) {
            try {
                $this->daemonServerRepository->setServer($server)->sync();
            } catch (DaemonConnectionException $exception) {
                Log::warning($exception, ['server_id' => $server->id, 'egg_id' => $server->egg_id]);
            }
        }
    }
}
