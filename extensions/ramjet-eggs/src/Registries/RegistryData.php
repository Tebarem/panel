<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

/**
 * The validated attributes of a Docker registry as an admin submitted them. A null password means
 * "keep the stored one" (only possible when updating).
 */
final readonly class RegistryData
{
    public function __construct(
        public string $name,
        public string $registry,
        public string $username,
        public ?string $password,
    ) {}
}
