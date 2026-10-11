<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules;

use Pterodactyl\Extensions\Fields;
use Pterodactyl\Models\Egg;

/**
 * Admin egg form fields for the file rules. Both are newline separated textareas on the form and are
 * saved through EggRules, so the hidden list lives in ramjet_egg_rules and the protected list in
 * core eggs.file_denylist.
 */
final class EggRulesFields extends Fields
{
    public function __construct(private readonly EggRules $rules) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file_hidden' => ['nullable', 'string', 'max:65535', new FilePatterns()],
            'file_denylist' => ['nullable', 'string', 'max:65535', new FilePatterns()],
        ];
    }

    /** @return array<string, string> */
    public function values(Egg $egg): array
    {
        $rules = $this->rules->get($egg);

        return [
            'file_hidden' => implode("\n", $rules->hidden),
            'file_denylist' => implode("\n", $rules->protected),
        ];
    }

    /**
     * A key that is absent keeps the stored list, so a request carrying only one of the two fields
     * changes only that one.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Egg $egg, array $values): void
    {
        $current = $this->rules->get($egg);

        $this->rules->set($egg, new RuleSet(
            array_key_exists('file_hidden', $values)
                ? FilePatterns::fromText($this->string($values['file_hidden']))
                : $current->hidden,
            array_key_exists('file_denylist', $values)
                ? FilePatterns::fromText($this->string($values['file_denylist']))
                : $current->protected,
        ));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'file_hidden' => 'hidden files',
            'file_denylist' => 'protected files',
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
