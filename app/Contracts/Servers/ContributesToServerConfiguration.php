<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Servers;

use Pterodactyl\Models\Server;

/**
 * Lets a service adjust the configuration payload sent to Wings for a server.
 *
 * Register implementations in the container with the tag
 * `pterodactyl.server-configuration.contributors`. Contributors run in
 * registration order; each receives the output of the previous one.
 */
interface ContributesToServerConfiguration
{
    /**
     * @param  array<string, JsonValue>  $configuration
     * @return array<string, JsonValue>
     */
    public function contribute(Server $server, array $configuration): array;
}
