<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Contracts\Eggs\ExportsEggs;
use Pterodactyl\Contracts\Eggs\ImportsEggs;
use Pterodactyl\Contracts\Eggs\UpdatesEggsFromImports;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\RamjetEggsProvider;
use Ramjet\Eggs\Rules\Sharing\ExportEggWithRules;
use Ramjet\Eggs\Rules\Sharing\ImportEggWithRules;
use Ramjet\Eggs\Rules\Sharing\UpdateEggFromImportWithRules;
use Ramjet\Eggs\Wings\EggConfigCache;
use Ramjet\Eggs\Wings\EggServerSync;

/**
 * Feature 2: hidden and protected file rules. The admin form fields are composed into EggFields by
 * the provider, which registers that class for eggs. This registrar wires the import and export
 * contracts so the hidden list travels with egg files, and feeds the sync service the events it
 * needs to coalesce Wings re-syncs.
 */
final class RulesRegistrar
{
    public static function boot(RamjetEggsProvider $provider): void
    {
        $provider->wrapAction(
            ExportsEggs::class,
            static fn (ExportsEggs $inner): ExportsEggs => new ExportEggWithRules($inner, app(EggRules::class)),
        );

        $provider->wrapAction(
            ImportsEggs::class,
            static fn (ImportsEggs $inner): ImportsEggs => new ImportEggWithRules($inner, app(EggRules::class)),
        );

        $provider->wrapAction(
            UpdatesEggsFromImports::class,
            static fn (UpdatesEggsFromImports $inner): UpdatesEggsFromImports => new UpdateEggFromImportWithRules(
                $inner,
                app(EggRules::class),
                app(EggServerSync::class),
            ),
        );

        // Any update of an egg, from any path, records its sent state before the write, so a change to
        // config_from or to the protected list is compared with the state before it.
        Egg::updating(static function (Egg $egg): void {
            app(EggServerSync::class)->willChange([$egg->id]);
        });

        Event::listen(TransactionRolledBack::class, static function (): void {
            app(EggServerSync::class)->transactionRolledBack();
            app(EggConfigCache::class)->flush();
        });
    }
}
