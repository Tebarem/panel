<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning\Tests\Support;

use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;

/**
 * Stands in for CreatesServers: the first $failures calls fail as if the chosen allocation had been taken concurrently,
 * before anything is stored; later calls delegate to the real action.
 */
final class FlakyCreatesServers implements CreatesServers
{
    public int $calls = 0;

    public function __construct(
        private readonly CreatesServers $inner,
        private readonly int $failures,
    ) {}

    public function create(array $data, ?DeploymentObject $deployment = null): Server
    {
        $this->calls++;

        if ($this->calls <= $this->failures) {
            throw new NoViableAllocationException('The allocation was taken concurrently.');
        }

        return $this->inner->create($data, $deployment);
    }
}
