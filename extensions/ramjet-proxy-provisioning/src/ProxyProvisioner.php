<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Ramjet\ProxyProvisioning\Models\UserProxy;
use Throwable;

/**
 * Creates the one Ramjet Proxy a customer may own. Everything the server receives (egg, node, resources, port) is
 * decided here from the admin settings; the caller only names the user.
 *
 * Concurrency: two named MySQL locks, one per customer and one per node, serialise creation. They are deliberately
 * not a database transaction. CreatesServers::create() commits the server row and then calls Wings, which calls back
 * into the panel to read the row; an open transaction would hide the row from that callback and the create would fail.
 *
 * Named locks belong to one database server. Production must run a single MySQL/MariaDB primary: the locks do not
 * hold across a Galera cluster or a read/write split.
 */
final class ProxyProvisioner
{
    public const string RATE_LIMITER = 'ramjet-proxy-create';

    public const string ACTIVITY_EVENT = 'server:proxy.self-provision';

    public const string DISABLED = 'The Ramjet Proxy is currently disabled.';

    public const string ALREADY_HAS_PROXY = 'You already have a Ramjet Proxy.';

    public const string IN_PROGRESS = 'Your Ramjet Proxy is already being created.';

    public const string NODE_BUSY = 'Another proxy is being created right now. Try again in a moment.';

    public const string EGG_MISSING = 'The Ramjet Proxy is not available yet: its egg is not configured.';

    public const string NODE_MISSING = 'The Ramjet Proxy is not available yet: no node is configured.';

    public const string NODE_MAINTENANCE = 'The proxy node is in maintenance right now. Try again later.';

    public const string PORTS_MISSING = 'The Ramjet Proxy is not available yet: its port range is not configured.';

    public const string LIMIT_REACHED = 'The Ramjet Proxy limit has been reached.';

    public const string NODE_FULL = 'The proxy node is full right now. Try again later.';

    public const string NO_ALLOCATION = 'No free port is available on the node right now. Try again later.';

    public const string NO_RESERVE = 'Could not reserve a port, try again.';

    public const string NODE_UNREACHABLE = 'Could not reach the node, try again.';

    public const string VARIABLE_INVALID = 'The Ramjet Proxy egg has a variable that cannot be set automatically.';

    private const int USER_LOCK_WAIT = 0;

    private const int NODE_LOCK_WAIT = 5;

    public function __construct(
        private readonly CreatesServers $creation,
        private readonly ExtensionRepository $extensions,
    ) {}

    /** @return array{identifier: string, name: string, uuidShort: string} */
    public static function present(Server $server): array
    {
        return [
            'identifier' => $server->uuidShort,
            'name' => $server->name,
            'uuidShort' => $server->uuidShort,
        ];
    }

    /**
     * The name of one of this panel's proxy locks. Scoped to the database, so two panels sharing a database server do
     * not contend for each other's locks. $scope is 'u' (customer) or 'n' (node).
     */
    public static function lockName(string $scope, int $id): string
    {
        return 'rjproxy:'.mb_substr(sha1((string) DB::connection()->getDatabaseName()), 0, 12).':'.$scope.':'.$id;
    }

    /**
     * @return array{enabled: bool, has_proxy: bool, server: array{identifier: string, name: string, uuidShort: string}|null, can_create: bool, reason?: string}
     */
    public function status(User $user): array
    {
        $config = $this->config();
        $server = $this->ownedServer($user) ?? $this->adoptableServer($user, $config);
        $reason = $this->blockedReason($config, $server !== null);

        $status = [
            'enabled' => $config->enabled,
            'has_proxy' => $server !== null,
            'server' => $server === null ? null : self::present($server),
            'can_create' => $reason === null,
        ];
        if ($reason !== null) {
            $status['reason'] = $reason;
        }

        return $status;
    }

    /**
     * @throws ProxyProvisioningException when the proxy cannot be created; nothing is persisted then.
     */
    public function create(User $user): Server
    {
        $config = $this->config();
        throw_unless($config->enabled, ProxyProvisioningException::conflict(self::DISABLED));

        $userLock = self::lockName('u', $user->id);
        if (! $this->acquire($userLock, self::USER_LOCK_WAIT)) {
            throw ProxyProvisioningException::conflict(self::IN_PROGRESS);
        }

        $nodeLock = null;
        try {
            $this->assertHasNoProxy($user, $config);

            $egg = $this->resolveEgg($config);
            $image = $egg === null ? null : $this->image($egg);
            if ($egg === null || $image === null) {
                throw ProxyProvisioningException::conflict(self::EGG_MISSING);
            }

            $node = $this->resolveNode($config);
            if ($node === null) {
                throw ProxyProvisioningException::conflict(self::NODE_MISSING);
            }

            // Every create on this node waits here, so the capacity and port checks below cannot race another create.
            $nodeLock = self::lockName('n', $node->id);
            if (! $this->acquire($nodeLock, self::NODE_LOCK_WAIT)) {
                throw $this->refused(ProxyProvisioningException::unavailable(self::NODE_BUSY), 'node_busy', $user, $node);
            }

            // Re-read under the lock: maintenance and capacity may have changed while this request waited.
            $node->refresh();
            if ($node->maintenance_mode) {
                throw $this->refused(ProxyProvisioningException::unavailable(self::NODE_MAINTENANCE), 'node_maintenance', $user, $node);
            }

            $range = $config->portRange();
            if ($range === null) {
                throw ProxyProvisioningException::conflict(self::PORTS_MISSING);
            }

            if ($this->limitReached($config, $egg)) {
                Log::info('Ramjet proxy create refused: cap reached', ['reason' => 'cap_reached', 'user_id' => $user->id, 'node_id' => $node->id]);

                throw ProxyProvisioningException::unprocessable(self::LIMIT_REACHED);
            }

            if (! $node->isViable($config->memory, $config->disk)) {
                throw $this->refused(ProxyProvisioningException::unprocessable(self::NODE_FULL), 'node_full', $user, $node);
            }

            $server = $this->placeServer($config, $user, $egg, $node, $range, $image);

            $this->recordOwnership($user, $server);

            return $server;
        } finally {
            if ($nodeLock !== null) {
                $this->release($nodeLock);
            }

            $this->release($userLock);
        }
    }

    /**
     * Creates the server on a free allocation in the port range. An allocation taken concurrently (CreateServer no
     * longer assigns it) is retried once with the next free one; a second loss is a 503. Nothing is persisted on a loss,
     * because CreateServer rolls its own insert back.
     *
     * @param  array{0: int, 1: int}  $range
     *
     * @throws ProxyProvisioningException
     */
    private function placeServer(ProxyConfig $config, User $user, Egg $egg, Node $node, array $range, string $image): Server
    {
        $tried = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $allocation = $this->freeAllocations($node, $range)->whereNotIn('id', $tried)->first();
            if ($allocation === null) {
                if ($tried === []) {
                    Log::info('Ramjet proxy create refused: port range exhausted', ['reason' => 'ports_exhausted', 'user_id' => $user->id, 'node_id' => $node->id]);

                    throw ProxyProvisioningException::unprocessable(self::NO_ALLOCATION);
                }

                throw $this->refused(ProxyProvisioningException::unavailable(self::NO_RESERVE), 'port_reserve_failed', $user, $node);
            }

            $tried[] = $allocation->id;

            // CreatesServers commits the server row before it calls Wings, so Wings can read it back.
            try {
                return $this->creation->create($this->serverData($config, $user, $egg, $node, $allocation, $image));
            } catch (NoViableAllocationException|UniqueConstraintViolationException) {
                // The allocation was taken between the query and the assignment. Try the next free one, once.
                continue;
            } catch (ValidationException $exception) {
                throw $this->refused(ProxyProvisioningException::conflict(self::VARIABLE_INVALID, $exception), 'egg_variables', $user, $node);
            } catch (DaemonConnectionException $exception) {
                throw $this->refused(ProxyProvisioningException::unavailable(self::NODE_UNREACHABLE, $exception), 'node_unreachable', $user, $node);
            }
        }

        throw $this->refused(ProxyProvisioningException::unavailable(self::NO_RESERVE), 'port_reserve_failed', $user, $node);
    }

    /**
     * Records the server as the customer's proxy. The server already exists at this point, so a failure here must not
     * turn into a 500 for the customer: the row is re-created by adoption on their next request, and the failure is
     * logged for an operator.
     */
    private function recordOwnership(User $user, Server $server): void
    {
        try {
            $inserted = UserProxy::query()->insertOrIgnore([[
                'user_id' => $user->id,
                'server_id' => $server->id,
                'created_at' => now(),
            ]]);
        } catch (Throwable $exception) {
            Log::critical('Ramjet proxy created but its ownership row could not be stored', [
                'user_id' => $user->id,
                'server_id' => $server->id,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if ($inserted === 0) {
            Log::critical('Ramjet proxy created but its ownership row was not inserted', [
                'user_id' => $user->id,
                'server_id' => $server->id,
            ]);
        }
    }

    /**
     * Refuses a create when the customer already has a proxy, and adopts an existing proxy-egg server they own (one an
     * admin made, or one left behind by a create that stopped after the server was stored). The adopted server is
     * recorded, so the customer keeps exactly one.
     *
     * @throws ProxyProvisioningException
     */
    private function assertHasNoProxy(User $user, ProxyConfig $config): void
    {
        if (UserProxy::query()->whereKey($user->id)->exists()) {
            throw_if($this->ownedServer($user) !== null, ProxyProvisioningException::conflict(self::ALREADY_HAS_PROXY));
        }

        $server = $this->adoptableServer($user, $config);
        if ($server !== null) {
            UserProxy::query()->insertOrIgnore([[
                'user_id' => $user->id,
                'server_id' => $server->id,
                'created_at' => now(),
            ]]);

            throw ProxyProvisioningException::conflict(self::ALREADY_HAS_PROXY);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serverData(ProxyConfig $config, User $user, Egg $egg, Node $node, Allocation $allocation, string $image): array
    {
        return [
            'external_id' => null,
            'name' => $this->serverName($config, $user),
            'description' => '',
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'egg_id' => $egg->id,
            'image' => $image,
            'startup' => $egg->startup ?? '',
            'environment' => $this->environment($egg),
            'memory' => $config->memory,
            'swap' => $config->swap,
            'disk' => $config->disk,
            'io' => $config->io,
            'cpu' => $config->cpu,
            'threads' => null,
            'skip_scripts' => false,
            'oom_disabled' => false,
            'allocation_id' => $allocation->id,
            'allocation_additional' => null,
            'start_on_completion' => true,
            'database_limit' => $config->databaseLimit,
            'allocation_limit' => $config->allocationLimit,
            'backup_limit' => $config->backupLimit,
        ];
    }

    private function serverName(ProxyConfig $config, User $user): string
    {
        $name = mb_trim(str_replace(
            ['{username}', '{user_id}'],
            [$user->username, (string) $user->id],
            $config->namePattern,
        ));

        return mb_substr($name !== '' ? $name : $user->username.'-proxy', 0, 191);
    }

    /** @return array<string, string> */
    private function environment(Egg $egg): array
    {
        $environment = [];
        foreach ($egg->variables()->get() as $variable) {
            $environment[$variable->env_variable] = (string) $variable->default_value;
        }

        return $environment;
    }

    /** The egg's single Docker image, or null when the egg has none. */
    private function image(Egg $egg): ?string
    {
        $image = array_values($egg->docker_images ?? [])[0] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    private function resolveEgg(ProxyConfig $config): ?Egg
    {
        if ($config->egg !== null) {
            return Egg::query()->whereKey($config->egg)->first();
        }

        return Egg::query()->where('name', ProxySettings::EGG_NAME)->orderBy('id')->first();
    }

    private function resolveNode(ProxyConfig $config): ?Node
    {
        if ($config->node === null) {
            return null;
        }

        return Node::query()->whereKey($config->node)->first();
    }

    /**
     * @param  array{0: int, 1: int}  $range
     * @return Builder<Allocation>
     */
    private function freeAllocations(Node $node, array $range): Builder
    {
        return Allocation::query()
            ->where('node_id', $node->id)
            ->whereNull('server_id')
            ->whereBetween('port', $range)
            ->orderBy('port')
            ->orderBy('id');
    }

    /**
     * The cap counts every proxy-egg server, whoever owns it and however it was made, and every recorded proxy. Either
     * count alone can miss servers, so the larger of the two is used.
     */
    private function limitReached(ProxyConfig $config, Egg $egg): bool
    {
        if ($config->maxProxies <= 0) {
            return false;
        }

        $count = max(UserProxy::query()->count(), Server::query()->where('egg_id', $egg->id)->count());

        return $count >= $config->maxProxies;
    }

    /** The first reason the customer cannot create a proxy right now, or null when they can. */
    private function blockedReason(ProxyConfig $config, bool $hasProxy): ?string
    {
        if (! $config->enabled) {
            return self::DISABLED;
        }

        if ($hasProxy) {
            return self::ALREADY_HAS_PROXY;
        }

        $egg = $this->resolveEgg($config);
        if ($egg === null || $this->image($egg) === null) {
            return self::EGG_MISSING;
        }

        $node = $this->resolveNode($config);
        if ($node === null) {
            return self::NODE_MISSING;
        }

        if ($node->maintenance_mode) {
            return self::NODE_MAINTENANCE;
        }

        $range = $config->portRange();
        if ($range === null) {
            return self::PORTS_MISSING;
        }

        if ($this->limitReached($config, $egg)) {
            return self::LIMIT_REACHED;
        }

        if (! $node->isViable($config->memory, $config->disk)) {
            return self::NODE_FULL;
        }

        return $this->freeAllocations($node, $range)->exists() ? null : self::NO_ALLOCATION;
    }

    /**
     * The proxy this customer owns, or null. A row whose server no longer belongs to them (the owner was changed) is
     * stale: it is removed, which frees the customer's slot.
     */
    private function ownedServer(User $user): ?Server
    {
        $row = UserProxy::query()->whereKey($user->id)->first();
        if ($row === null) {
            return null;
        }

        $server = $row->server;
        if ($server === null || (int) $server->owner_id !== $user->id) {
            $row->delete();

            return null;
        }

        return $server;
    }

    /** A proxy-egg server the customer owns that has no UserProxy row yet, or null. */
    private function adoptableServer(User $user, ProxyConfig $config): ?Server
    {
        $egg = $this->resolveEgg($config);
        if ($egg === null) {
            return null;
        }

        return Server::query()
            ->where('owner_id', $user->id)
            ->where('egg_id', $egg->id)
            ->orderBy('id')
            ->first();
    }

    /** Logs a refusal the operator may need to see, then returns it for throwing. */
    private function refused(ProxyProvisioningException $exception, string $reason, User $user, Node $node): ProxyProvisioningException
    {
        Log::warning('Ramjet proxy create refused', [
            'reason' => $reason,
            'user_id' => $user->id,
            'node_id' => $node->id,
            'message' => $exception->getMessage(),
            'cause' => $exception->getPrevious()?->getMessage(),
        ]);

        return $exception;
    }

    /** MySQL named lock, waiting at most $seconds. Named locks are per connection and outlive transactions. */
    private function acquire(string $name, int $seconds): bool
    {
        $row = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $seconds]);

        return (int) ($row->acquired ?? 0) === 1;
    }

    /**
     * Never throws: a failed release must not mask the error that is already in flight, nor skip the other release.
     * An unreleased lock is freed when the connection closes.
     */
    private function release(string $name): void
    {
        try {
            DB::select('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (Throwable $exception) {
            Log::warning('Ramjet proxy lock could not be released', ['lock' => $name, 'message' => $exception->getMessage()]);
        }
    }

    private function config(): ProxyConfig
    {
        return ProxyConfig::load($this->extensions->settings(ProxySettings::EXTENSION_ID));
    }
}
