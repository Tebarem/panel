<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;

/**
 * Makes the ramjet-eggs package behave as an installed, enabled extension for the current test:
 * discovered from the panel's extensions directory, its migrations applied, and its provider booted.
 * Everything is rolled back with the test's transaction except the schema, which DDL leaves in place.
 */
function ramjetEggsEnable(): void
{
    config([
        'extensions.enabled' => true,
        'extensions.directory' => base_path('extensions'),
    ]);

    // Rules (1xxxxx), registries (2xxxxx) and egg sources (3xxxxx) migrations. Normally already applied by
    // tests/bootstrap.php; this only runs them when the schema is missing.
    if (! Schema::hasTable('ramjet_egg_rules') || ! Schema::hasTable('ramjet_egg_sources')) {
        foreach (glob(base_path('extensions/ramjet-eggs/database/migrations/2026_10_11_[123]*.php')) ?: [] as $migration) {
            Artisan::call('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true]);
        }
    }

    Extension::query()->updateOrCreate(
        ['identifier' => 'ramjet-eggs'],
        ['version' => '1.0.0', 'enabled' => true, 'error' => null],
    );

    app()->forgetInstance(ExtensionRepository::class);
    app()->forgetInstance(ExtensionProviderLoader::class);

    $repository = app()->make(ExtensionRepository::class);
    app()->make(ExtensionProviderLoader::class)->registerProviders($repository->enabled());
}

/** Runs the pending Wings sync flush, as the outermost commit would. */
function ramjetEggsSettle(): void
{
    app(Ramjet\Eggs\Wings\EggServerSync::class)->settle();
}
