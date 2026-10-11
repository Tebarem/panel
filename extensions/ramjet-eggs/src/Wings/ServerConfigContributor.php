<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Wings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Servers\ContributesToServerConfiguration;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Rules\EggRules;

/**
 * Adds the file rules and registry credentials Wings reads from the server configuration:
 * `egg.file_denylist` (protected), `egg.file_hidden` (hidden) and `container.registry_auth`.
 * The password is only ever placed in this payload, which goes to Wings.
 *
 * The rules and credentials of an egg are resolved once per request (see EggConfigCache), so building
 * the configuration for many servers that share an egg costs the same queries as for one.
 *
 * Fails open: if the extension's tables cannot be read, the configuration is sent unchanged and the
 * error is logged. The rules are an additional layer, and a missing table must not stop servers from
 * starting.
 */
final readonly class ServerConfigContributor implements ContributesToServerConfiguration
{
    public function __construct(
        private EggRules $rules,
        private EggRegistries $registries,
        private EggConfigCache $cache,
    ) {}

    /**
     * @param  array<string, JsonValue>  $configuration
     * @return array<string, JsonValue>
     */
    public function contribute(Server $server, array $configuration): array
    {
        $server->loadMissing('egg');
        $egg = $server->egg;
        if (! $egg instanceof Egg) {
            return $configuration;
        }

        try {
            $state = $this->cache->remember($egg->id, fn (): ResolvedEggState => new ResolvedEggState(
                $this->rules->effective($egg),
                $this->registryAuth($egg),
            ));
        } catch (QueryException $exception) {
            Log::error('Unable to read the file rules or registry of an egg; the server configuration is sent without them.', [
                'server_id' => $server->id,
                'egg_id' => $egg->id,
                'exception' => $exception->getMessage(),
            ]);

            return $configuration;
        }

        /** @var JsonValue $egg */
        $egg = [
            ...$this->objectOf($configuration['egg'] ?? null),
            'file_denylist' => $state->rules->protected,
            'file_hidden' => $state->rules->hidden,
        ];

        /** @var JsonValue $container */
        $container = [
            ...$this->objectOf($configuration['container'] ?? null),
            'registry_auth' => $state->registryAuth,
        ];

        /** @var array<string, JsonValue> $result */
        $result = [...$configuration, 'egg' => $egg, 'container' => $container];

        return $result;
    }

    /**
     * The existing object at a configuration key, or an empty one. Values come from the core payload,
     * which only ever holds JSON values.
     *
     * @return array<string, JsonValue>
     */
    private function objectOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, JsonValue> $object */
        $object = $value;

        return $object;
    }

    /** @return list<array{registry: string, username: string, password: string}> */
    private function registryAuth(Egg $egg): array
    {
        $registry = $this->registries->registryFor($egg);
        if (! $registry instanceof DockerRegistry) {
            return [];
        }

        try {
            // Reading the attribute decrypts it; this fails when APP_KEY changed or the value is corrupt.
            $password = $registry->password;
            // The encrypted cast throws on read, which phpstan cannot see.
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown
            Log::error('Unable to decrypt the password of a Docker registry; its credentials will not be sent to Wings.', [
                'registry_id' => $registry->id,
            ]);

            return [];
        }

        return [
            [
                'registry' => $registry->registry,
                'username' => $registry->username,
                'password' => (string) $password,
            ],
        ];
    }
}
