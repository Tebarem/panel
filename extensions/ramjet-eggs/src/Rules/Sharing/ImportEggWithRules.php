<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules\Sharing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Eggs\ImportsEggs;
use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Eggs\EggImportData;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;
use Throwable;

/**
 * Applies the `file_hidden` list of an uploaded egg file to the egg the core import creates. Manual
 * imports replace the hidden rules. Catalog imports go through importParsed() and are left alone:
 * the sources feature decides their rules itself.
 */
final readonly class ImportEggWithRules implements ImportsEggs
{
    public function __construct(private ImportsEggs $inner, private EggRules $rules) {}

    /** @throws Throwable */
    public function import(UploadedFile $file): Egg
    {
        $hidden = HiddenFileRulesFromUpload::read($file);

        return DB::transaction(function () use ($file, $hidden): Egg {
            $egg = $this->inner->import($file);

            if ($hidden !== null) {
                $this->rules->set($egg, new RuleSet($hidden, $egg->file_denylist ?? []));
            }

            return $egg;
        });
    }

    public function importParsed(EggImportData $parsed): Egg
    {
        return $this->inner->importParsed($parsed);
    }
}
