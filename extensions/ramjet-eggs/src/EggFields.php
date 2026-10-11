<?php

declare(strict_types=1);

namespace Ramjet\Eggs;

use Pterodactyl\Extensions\Fields;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Registries\EggRegistryFields;
use Ramjet\Eggs\Rules\EggRulesFields;

/**
 * The one Fields class registered for eggs. ExtensionProvider::registerFields() takes one class per
 * model, so the file rules and the registry field are composed here and each keeps its own logic.
 */
final class EggFields extends Fields
{
    public function __construct(
        private readonly EggRulesFields $rules,
        private readonly EggRegistryFields $registry,
    ) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [...$this->rules->rules(), ...$this->registry->rules()];
    }

    /** @return array<string, mixed> */
    public function values(Egg $egg, EggRegistries $registries): array
    {
        return [...$this->rules->values($egg), ...$this->registry->values($egg, $registries)];
    }

    /** @param  array<string, mixed>  $values */
    public function save(Egg $egg, array $values, EggRegistries $registries): void
    {
        $this->rules->save($egg, $values);
        $this->registry->save($egg, $values, $registries);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...$this->rules->attributes(), EggRegistryFields::FIELD => 'Docker registry'];
    }
}
