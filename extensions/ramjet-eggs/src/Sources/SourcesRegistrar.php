<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Ramjet\Eggs\RamjetEggsProvider;
use Ramjet\Eggs\Sources\Console\SyncEggSourcesCommand;

/**
 * Feature 5: egg sources. Wired from RamjetEggsProvider. Routes live in routes/admin/sources.php and are
 * loaded by the provider; the client screen is registered by src/client/sources.
 */
final class SourcesRegistrar
{
    public static function register(RamjetEggsProvider $provider): void
    {
        app()->singleton(HostResolver::class, SystemHostResolver::class);

        $provider->registerSettings(new ExtensionSettingsDefinition($provider->settings(), [
            ExtensionSettingDefinition::make('allow_private_hosts', 'allow_private_hosts', false)
                ->field('toggle')
                ->label('Allow private hosts')
                ->help('Let egg sources reach private, loopback and LAN addresses (for example a self-hosted GitLab). Link-local and cloud metadata addresses are always refused.'),
        ]));
    }

    public static function boot(RamjetEggsProvider $provider): void
    {
        // Activity entries name the source by this alias, as the panel's morph map requires.
        Relation::morphMap(['ramjet-egg-source' => EggSource::class]);

        $provider->registerCommands([SyncEggSourcesCommand::class]);

        $provider->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('ramjet-eggs:sync')->hourly()->withoutOverlapping();
        });
    }
}
