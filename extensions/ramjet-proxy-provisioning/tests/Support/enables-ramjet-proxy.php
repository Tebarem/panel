<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;

/**
 * Makes the ramjet-proxy-provisioning package behave as an installed, enabled extension for the current test:
 * discovered from the panel's extensions directory, its migration applied, and its provider booted.
 * Rows are rolled back with the test's transaction; the schema, which DDL leaves in place, is not.
 */
function ramjetProxyEnable(): void
{
    config([
        'extensions.enabled' => true,
        'extensions.directory' => base_path('extensions'),
    ]);

    // Normally applied by tests/bootstrap.php; this only runs the migration when the table is missing.
    if (! Schema::hasTable('ramjet_user_proxies')) {
        Artisan::call('migrate', [
            '--path' => base_path('extensions/ramjet-proxy-provisioning/database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    Extension::query()->updateOrCreate(
        ['identifier' => 'ramjet-proxy-provisioning'],
        ['version' => '1.0.0', 'enabled' => true, 'error' => null],
    );

    app()->forgetInstance(ExtensionRepository::class);
    app()->forgetInstance(ExtensionProviderLoader::class);

    $repository = app()->make(ExtensionRepository::class);
    app()->make(ExtensionProviderLoader::class)->registerProviders($repository->enabled());
}
