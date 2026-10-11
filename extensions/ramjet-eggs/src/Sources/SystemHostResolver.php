<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Pterodactyl\Support\JsonValueGuard;

final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $addresses[] = JsonValueGuard::string($record['ip'] ?? $record['ipv6'] ?? null);
        }

        $ipv4 = @gethostbynamel($host);
        if ($ipv4 !== false) {
            $addresses = [...$addresses, ...$ipv4];
        }

        return array_values(array_unique($addresses));
    }
}
