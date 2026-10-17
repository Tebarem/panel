<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning;

use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/**
 * The admin settings of the extension, rendered on the extension's settings page. Every value a customer's proxy
 * receives comes from here; the customer's own request supplies none of them.
 */
final class ProxySettings
{
    public const string EXTENSION_ID = 'ramjet-proxy-provisioning';

    public const string EGG_NAME = 'Ramjet Proxy';

    /** Off until an admin has picked the node, the port range and the egg. */
    public const bool DEFAULT_ENABLED = false;

    public const int DEFAULT_MEMORY = 128;

    public const int DEFAULT_CPU = 50;

    public const int DEFAULT_DISK = 512;

    public const int DEFAULT_SWAP = 0;

    public const int DEFAULT_IO = 500;

    public const int DEFAULT_BACKUP_LIMIT = 1;

    public const int DEFAULT_DATABASE_LIMIT = 0;

    public const int DEFAULT_ALLOCATION_LIMIT = 0;

    public const int DEFAULT_MAX_PROXIES = 0;

    public const string DEFAULT_NAME_PATTERN = '{username}-proxy';

    public static function definition(ExtensionSettings $settings): ExtensionSettingsDefinition
    {
        return new ExtensionSettingsDefinition($settings, [
            ExtensionSettingDefinition::make('enabled', 'enabled', self::DEFAULT_ENABLED)
                ->field('toggle')
                ->label('Enabled')
                ->help('Off by default. When off, the dashboard card is hidden and customers cannot create a proxy. Existing proxies keep running. Turn it on only after the node, the port range and the egg are set.'),

            ExtensionSettingDefinition::make('egg', 'egg', null, ['nullable', 'integer', 'min:1'])
                ->field('number')
                ->label('Egg ID')
                ->help('The egg new proxies are created from. Leave empty to use the egg named "Ramjet Proxy".'),

            ExtensionSettingDefinition::make('node', 'node', null, ['nullable', 'integer', 'min:1'])
                ->field('number')
                ->label('Node ID')
                ->help('The node proxies are placed on. Required while the feature is enabled; there is no default node. A proxy takes the lowest free allocation on this node inside the port range below.'),

            ExtensionSettingDefinition::make('port_min', 'port_min', null, ['nullable', 'integer', 'between:1024,65535'])
                ->field('number')
                ->label('Port range: first port')
                ->help('Lowest allocation port proxies may use on the node. Required while the feature is enabled, from 1024 to 65535.'),

            ExtensionSettingDefinition::make('port_max', 'port_max', null, ['nullable', 'integer', 'between:1024,65535', 'gte:port_min'])
                ->field('number')
                ->label('Port range: last port')
                ->help('Highest allocation port proxies may use on the node. Must be at least the first port. Required while the feature is enabled.'),

            ExtensionSettingDefinition::make('max_proxies', 'max_proxies', self::DEFAULT_MAX_PROXIES, ['required', 'integer', 'between:0,10000'])
                ->field('number')
                ->label('Maximum proxies')
                ->help('Most Ramjet Proxies that may exist at once across all customers. 0 means no limit.'),

            ExtensionSettingDefinition::make('memory', 'memory', self::DEFAULT_MEMORY, ['required', 'integer', 'between:16,65536'])
                ->field('number')
                ->label('Memory (MB)')
                ->help('Memory limit of each proxy server, from 16 to 65536 MB. A proxy is refused while the node has no room for it.'),

            ExtensionSettingDefinition::make('cpu', 'cpu', self::DEFAULT_CPU, ['required', 'integer', 'between:0,1000'])
                ->field('number')
                ->label('CPU (%)')
                ->help('CPU limit as a percentage of one core, from 0 to 1000. 0 means unlimited.'),

            ExtensionSettingDefinition::make('disk', 'disk', self::DEFAULT_DISK, ['required', 'integer', 'between:64,102400'])
                ->field('number')
                ->label('Disk (MB)')
                ->help('Disk limit of each proxy server, from 64 to 102400 MB. A proxy is refused while the node has no room for it.'),

            ExtensionSettingDefinition::make('swap', 'swap', self::DEFAULT_SWAP, ['required', 'integer', 'between:-1,65536'])
                ->field('number')
                ->label('Swap (MB)')
                ->help('Swap limit in MB. 0 disables swap, -1 means unlimited.'),

            ExtensionSettingDefinition::make('io', 'io', self::DEFAULT_IO, ['required', 'integer', 'between:10,1000'])
                ->field('number')
                ->label('Block IO weight')
                ->help('Block IO weight, from 10 to 1000. The default of 500 is the Wings default weight.'),

            ExtensionSettingDefinition::make('backup_limit', 'backup_limit', self::DEFAULT_BACKUP_LIMIT, ['required', 'integer', 'between:0,100'])
                ->field('number')
                ->label('Backup limit')
                ->help('How many backups a proxy may keep, from 0 to 100.'),

            ExtensionSettingDefinition::make('database_limit', 'database_limit', self::DEFAULT_DATABASE_LIMIT, ['required', 'integer', 'between:0,100'])
                ->field('number')
                ->label('Database limit')
                ->help('How many databases a proxy may create, from 0 to 100. 0 means none.'),

            ExtensionSettingDefinition::make('allocation_limit', 'allocation_limit', self::DEFAULT_ALLOCATION_LIMIT, ['required', 'integer', 'between:0,100'])
                ->field('number')
                ->label('Allocation limit')
                ->help('How many extra ports a proxy may add, from 0 to 100. 0 means none.'),

            ExtensionSettingDefinition::make('name_pattern', 'name_pattern', self::DEFAULT_NAME_PATTERN, ['required', 'string', 'max:120'])
                ->field('text')
                ->label('Server name pattern')
                ->help('Name of each proxy server. {username} is replaced with the customer\'s username and {user_id} with their user id.'),
        ]);
    }
}
