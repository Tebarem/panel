<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Illuminate\Validation\Rule;
use Pterodactyl\Extensions\Fields;
use Pterodactyl\Models\Egg;

/**
 * The "Docker registry" field of the admin egg form. Fields of one extension and model are
 * registered as a single Fields class (ExtensionProvider::registerFields() allows one per model), so
 * this class is composed with the other egg fields of the extension rather than registered alone:
 * its rules(), values() and save() are merged into that class.
 */
final class EggRegistryFields extends Fields
{
    public const string FIELD = 'registry_id';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            self::FIELD => ['nullable', 'integer', Rule::exists(DockerRegistry::class, 'id')],
        ];
    }

    /** @return array<string, int|null> */
    public function values(Egg $egg, EggRegistries $registries): array
    {
        return [self::FIELD => $registries->registryFor($egg)?->id];
    }

    /**
     * A payload without the field leaves the assignment alone; only an explicit value (null for none)
     * changes it.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Egg $egg, array $values, EggRegistries $registries): void
    {
        if (! array_key_exists(self::FIELD, $values)) {
            return;
        }

        /**
         * SAFETY: rules() admits only an integer or null here, and the form request validated it.
         *
         * @var int|string|null $registryId
         */
        $registryId = $values[self::FIELD];

        $registries->assign($egg, $registryId === null ? null : (int) $registryId);
    }
}
