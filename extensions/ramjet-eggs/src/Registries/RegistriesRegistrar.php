<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Illuminate\Database\Eloquent\Relations\Relation;
use Ramjet\Eggs\RamjetEggsProvider;

/**
 * Feature 4: Docker registries.
 *
 * The admin API routes are mounted by the provider from routes/admin/registries.php. The egg form
 * field is EggRegistryFields, composed into the extension's single Egg Fields class (EggFields),
 * because ExtensionProvider::registerFields() accepts one Fields class per model and extension.
 */
final class RegistriesRegistrar
{
    public static function register(RamjetEggsProvider $provider): void {}

    public static function boot(RamjetEggsProvider $provider): void
    {
        // The panel enforces its morph map, so activity log subjects need an alias of this extension's own.
        Relation::morphMap(['docker_registry' => DockerRegistry::class]);
    }
}
