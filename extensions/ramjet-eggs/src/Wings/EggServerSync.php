<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Wings;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\DaemonManager;
use Ramjet\Eggs\Rules\EggRules;
use Throwable;

/**
 * Asks Wings to re-sync the servers whose egg's sent configuration changed (file rules and registry
 * credentials). Wings reads those values only when it syncs, boots or installs a server.
 *
 * Changes are coalesced per database transaction. Before a change, willChange() records the sent state
 * of the affected eggs and of the eggs copying from them (the first time they are touched). eggsChanged()
 * schedules one flush after the outermost commit; the flush compares every recorded egg with its current
 * state and syncs only the servers of eggs that actually changed. Changing a rule and changing it back in
 * one transaction therefore syncs nothing. Outside a transaction, eggsChanged() syncs immediately.
 *
 * Registered as a singleton: the pending state lives on this instance.
 */
final class EggServerSync
{
    /** Up to this many affected servers are synced inline; more are queued. */
    public const INLINE_LIMIT = 10;

    /** @var array<int, string|null> sent state of each egg before its first change in the pending transaction */
    private array $baselines = [];

    /** @var array<int, true> eggs whose change was not announced with willChange(): synced unconditionally */
    private array $unannounced = [];

    private bool $flushScheduled = false;

    public function __construct(private readonly DaemonManager $daemon) {}

    /**
     * Records the sent state of the eggs, and of the eggs copying from them, before they change.
     * Only the first call per egg in a transaction counts. Call before writing.
     *
     * @param  list<int>  $eggIds
     */
    public function willChange(array $eggIds): void
    {
        if (DB::transactionLevel() === 0) {
            return;
        }

        foreach ($this->withChildren($this->normalize($eggIds)) as $eggId) {
            if (! array_key_exists($eggId, $this->baselines)) {
                $this->baselines[$eggId] = $this->snapshot($eggId);
            }
        }
    }

    /**
     * Announces that the eggs changed. Call after writing.
     *
     * @param  list<int>  $eggIds
     */
    public function eggsChanged(array $eggIds): void
    {
        $eggIds = $this->normalize($eggIds);
        if ($eggIds === []) {
            return;
        }

        if (DB::transactionLevel() === 0) {
            $this->sync($this->withChildren($eggIds));

            return;
        }

        foreach ($eggIds as $eggId) {
            if (! array_key_exists($eggId, $this->baselines)) {
                $this->unannounced[$eggId] = true;
            }
        }

        if (! $this->flushScheduled) {
            $this->flushScheduled = true;
            DB::afterCommit(fn () => $this->flush());
        }
    }

    /**
     * Runs the pending flush now. Production runs it when the outermost transaction commits; a test
     * transaction never commits, so tests call this to see the syncs a commit would send.
     */
    public function settle(): void
    {
        $this->flush();
    }

    /**
     * A rollback can discard the scheduled flush (it was registered inside the rolled-back level), so the
     * next announcement schedules it again. Recorded baselines stay: they describe committed state.
     */
    public function transactionRolledBack(): void
    {
        $this->flushScheduled = false;
    }

    /**
     * Re-syncs the given servers. A connection failure to a node skips that node's remaining servers;
     * every other failure is logged and never thrown.
     *
     * @param  iterable<Server>  $servers
     */
    public function resync(iterable $servers): void
    {
        $unreachableNodes = [];

        foreach ($servers as $server) {
            if (isset($unreachableNodes[$server->node_id])) {
                continue;
            }

            try {
                $this->daemon->server($server)->sync();
            } catch (DaemonConnectionException $exception) {
                $unreachableNodes[$server->node_id] = true;
                $this->logFailure($server, $exception);
            } catch (Throwable $exception) {
                $this->logFailure($server, $exception);
            }
        }
    }

    private function flush(): void
    {
        $baselines = $this->baselines;
        $changed = array_keys($this->unannounced);

        $this->baselines = [];
        $this->unannounced = [];
        $this->flushScheduled = false;

        foreach ($baselines as $eggId => $before) {
            if ($this->snapshot($eggId) !== $before) {
                $changed[] = $eggId;
            }
        }

        $this->sync($this->withChildren($changed));
    }

    /** @param  list<int>  $eggIds */
    private function sync(array $eggIds): void
    {
        if ($eggIds === []) {
            return;
        }

        $serverIds = $this->intList(Server::query()->whereIn('egg_id', $eggIds)->pluck('id'));
        if ($serverIds === []) {
            return;
        }

        if (count($serverIds) > self::INLINE_LIMIT) {
            dispatch(new SyncEggServers($serverIds));

            return;
        }

        $this->resync(Server::query()->with('node')->whereIn('id', $serverIds)->orderBy('id')->get());
    }

    /**
     * The sent state of an egg: its effective rules and its registry. Null when the egg does not exist.
     * EggRules depends on this class, so it is resolved here rather than injected.
     */
    private function snapshot(int $eggId): ?string
    {
        $egg = Egg::query()->with('configFrom')->find($eggId);
        if (! $egg instanceof Egg) {
            return null;
        }

        $rules = app(EggRules::class)->effective($egg);

        return json_encode([
            'hidden' => $rules->hidden,
            'protected' => $rules->protected,
            'registry' => $this->registryId($eggId),
        ], JSON_THROW_ON_ERROR);
    }

    /** Registry assignment of the egg itself; the table is owned by the registries feature. */
    private function registryId(int $eggId): ?int
    {
        try {
            $value = DB::table('ramjet_egg_registries')->where('egg_id', $eggId)->value('registry_id');
        } catch (QueryException) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  list<int>  $eggIds
     * @return list<int>
     */
    private function withChildren(array $eggIds): array
    {
        if ($eggIds === []) {
            return [];
        }

        $children = $this->intList(Egg::query()->whereIn('config_from', $eggIds)->pluck('id'));

        return $this->normalize([...$eggIds, ...$children]);
    }

    /**
     * @param  array<mixed>  $eggIds
     * @return list<int>
     */
    private function normalize(array $eggIds): array
    {
        return array_values(array_unique(array_map($this->toInt(...), $eggIds)));
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return list<int>
     */
    private function intList(Collection $values): array
    {
        return $this->normalize($values->all());
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function logFailure(Server $server, Throwable $exception): void
    {
        Log::warning('Unable to ask Wings to re-sync a server after an egg change.', [
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
