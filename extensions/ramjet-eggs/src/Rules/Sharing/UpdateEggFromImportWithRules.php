<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules\Sharing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Eggs\UpdatesEggsFromImports;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;
use Ramjet\Eggs\Wings\EggServerSync;
use Throwable;

/**
 * Applies the `file_hidden` list of an uploaded egg file when an existing egg is updated from it.
 * Replaces the hidden rules when the file has the key, and leaves them alone when it does not.
 *
 * The update can also change the protected list and `config_from`, which change the rules Wings
 * receives. The egg is therefore announced to the sync service around the whole update, and the sync
 * service compares its sent state before and after.
 */
final readonly class UpdateEggFromImportWithRules implements UpdatesEggsFromImports
{
    public function __construct(
        private UpdatesEggsFromImports $inner,
        private EggRules $rules,
        private EggServerSync $sync,
    ) {}

    /** @throws Throwable */
    public function update(Egg $egg, UploadedFile $file): Egg
    {
        $hidden = HiddenFileRulesFromUpload::read($file);

        return DB::transaction(function () use ($egg, $file, $hidden): Egg {
            $this->sync->willChange([$egg->id]);

            $updated = $this->inner->update($egg, $file);

            if ($hidden !== null) {
                $this->rules->set($updated, new RuleSet($hidden, $updated->file_denylist ?? []));
            }

            $this->sync->eggsChanged([$updated->id]);

            return $updated;
        });
    }
}
