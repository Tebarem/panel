<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules\Sharing;

use JsonException;
use Pterodactyl\Contracts\Eggs\ExportsEggs;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Rules\EggRules;

/**
 * Adds `file_hidden` to an egg export, right after `file_denylist`. Like the protected list, the
 * value is the one Wings enforces for the egg, following `config_from`.
 */
final readonly class ExportEggWithRules implements ExportsEggs
{
    public function __construct(private ExportsEggs $inner, private EggRules $rules) {}

    /** @throws JsonException */
    public function export(Egg $egg): string
    {
        $json = $this->inner->export($egg);

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            return $json;
        }

        $hidden = $this->rules->effective($egg)->hidden;

        $ordered = [];
        foreach ($data as $key => $value) {
            if ($key === 'file_hidden') {
                continue;
            }

            $ordered[$key] = $value;
            if ($key === 'file_denylist') {
                $ordered['file_hidden'] = $hidden;
            }
        }

        $ordered['file_hidden'] ??= $hidden;

        return json_encode($ordered, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
