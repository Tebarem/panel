<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

/**
 * Resolves a host name to the addresses it currently points at. Replaced in tests so they do not depend on DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> every address the name resolves to; empty when it does not resolve
     */
    public function resolve(string $host): array;
}
