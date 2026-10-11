<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Ramjet\Eggs\Sources\HostResolver;

/**
 * Resolves names from a fixed map, so the tests never depend on DNS. Unknown names resolve to one public address.
 */
final class StubHostResolver implements HostResolver
{
    public const PUBLIC_ADDRESS = '93.184.216.34';

    /**
     * @param  array<string, list<string>>  $addresses  the addresses of each host name
     */
    public function __construct(private readonly array $addresses = []) {}

    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [self::PUBLIC_ADDRESS];
    }
}
