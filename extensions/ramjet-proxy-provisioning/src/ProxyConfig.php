<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning;

use Pterodactyl\Services\Extensions\ExtensionSettings;

/**
 * The admin settings as the provisioner reads them, with the defaults of ProxySettings for anything not stored.
 */
final readonly class ProxyConfig
{
    public function __construct(
        public bool $enabled,
        public ?int $egg,
        public ?int $node,
        public ?int $portMin,
        public ?int $portMax,
        public int $maxProxies,
        public int $memory,
        public int $cpu,
        public int $disk,
        public int $swap,
        public int $io,
        public int $backupLimit,
        public int $databaseLimit,
        public int $allocationLimit,
        public string $namePattern,
    ) {}

    public static function load(ExtensionSettings $settings): self
    {
        return new self(
            enabled: (bool) $settings->get('enabled', ProxySettings::DEFAULT_ENABLED),
            egg: self::nullableInt($settings->get('egg')),
            node: self::nullableInt($settings->get('node')),
            portMin: self::nullableInt($settings->get('port_min')),
            portMax: self::nullableInt($settings->get('port_max')),
            maxProxies: (int) $settings->get('max_proxies', ProxySettings::DEFAULT_MAX_PROXIES),
            memory: (int) $settings->get('memory', ProxySettings::DEFAULT_MEMORY),
            cpu: (int) $settings->get('cpu', ProxySettings::DEFAULT_CPU),
            disk: (int) $settings->get('disk', ProxySettings::DEFAULT_DISK),
            swap: (int) $settings->get('swap', ProxySettings::DEFAULT_SWAP),
            io: (int) $settings->get('io', ProxySettings::DEFAULT_IO),
            backupLimit: (int) $settings->get('backup_limit', ProxySettings::DEFAULT_BACKUP_LIMIT),
            databaseLimit: (int) $settings->get('database_limit', ProxySettings::DEFAULT_DATABASE_LIMIT),
            allocationLimit: (int) $settings->get('allocation_limit', ProxySettings::DEFAULT_ALLOCATION_LIMIT),
            namePattern: (string) $settings->get('name_pattern', ProxySettings::DEFAULT_NAME_PATTERN),
        );
    }

    /**
     * The inclusive port range proxies may take, or null when the range is missing or inverted. While the feature is
     * enabled that is a configuration error, and creation is refused until an admin fixes it.
     *
     * @return array{0: int, 1: int}|null
     */
    public function portRange(): ?array
    {
        if ($this->portMin === null || $this->portMax === null || $this->portMax < $this->portMin) {
            return null;
        }

        return [$this->portMin, $this->portMax];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
