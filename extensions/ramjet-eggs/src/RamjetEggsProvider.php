<?php

declare(strict_types=1);

namespace Ramjet\Eggs;

use Closure;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Pterodactyl\Services\Servers\ServerConfigurationStructureService;
use Ramjet\Eggs\Registries\RegistriesRegistrar;
use Ramjet\Eggs\Rules\RulesRegistrar;
use Ramjet\Eggs\Sources\SourcesRegistrar;
use Ramjet\Eggs\Wings\EggConfigCache;
use Ramjet\Eggs\Wings\EggServerSync;
use Ramjet\Eggs\Wings\ServerConfigContributor;

/**
 * Entry point of the ramjet-eggs extension. Each feature owns a registrar; the provider wires the
 * shared pieces. The helpers the registrars call are protected on ExtensionProvider, so the ones in use
 * are re-exposed publicly here (widening visibility in a subclass is allowed).
 */
final class RamjetEggsProvider extends ExtensionProvider
{
    public function register(): void
    {
        // The sync service keeps pending state for the transaction in progress, so it is shared.
        $this->app->singleton(EggServerSync::class);

        // One memo of egg rules and registries per request, shared by every server configuration built in it.
        $this->app->scoped(EggConfigCache::class);

        // Feeds the file rules and registry credentials to Wings with every server configuration.
        $this->app->tag([ServerConfigContributor::class], ServerConfigurationStructureService::CONTRIBUTORS_TAG);

        RegistriesRegistrar::register($this);
        SourcesRegistrar::register($this);
    }

    public function boot(): void
    {
        // routes/admin.php -> /api/admin/extensions/ramjet-eggs
        $this->registerAdminApiRoutes($this->extensionPath('routes', 'admin.php'));

        // One Fields class per model: the file rules and the registry field share the egg form.
        $this->registerFields(Egg::class, EggFields::class);

        RulesRegistrar::boot($this);
        RegistriesRegistrar::boot($this);
        SourcesRegistrar::boot($this);
    }

    public function registerFields(string $model, string $fields): void
    {
        parent::registerFields($model, $fields);
    }

    public function wrapAction(string $contract, Closure $decorator): void
    {
        parent::wrapAction($contract, $decorator);
    }

    public function registerSchedule(Closure $callback): void
    {
        parent::registerSchedule($callback);
    }

    /** @param  list<class-string>  $classes */
    public function registerCommands(array $classes): void
    {
        parent::registerCommands($classes);
    }

    public function registerSettings(ExtensionSettingsDefinition $definition): void
    {
        parent::registerSettings($definition);
    }

    public function settings(): ExtensionSettings
    {
        return parent::settings();
    }
}
