<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Wings\EggConfigCache;
use Ramjet\Eggs\Wings\EggServerSync;

/**
 * Reads and writes an egg's file rules.
 *
 * - protected patterns live in core `eggs.file_denylist`;
 * - hidden patterns live in the extension table `ramjet_egg_rules`.
 *
 * Every change that alters what Wings receives asks Wings to re-sync the affected servers
 * once the surrounding transaction has committed.
 */
final readonly class EggRules
{
    public function __construct(
        private EggServerSync $sync,
        private EggConfigCache $cache,
    ) {}

    /**
     * Trims, drops empty entries and removes duplicates while keeping the first occurrence.
     *
     * @param  iterable<mixed>  $patterns
     * @return list<string>
     */
    public static function normalize(iterable $patterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            $trimmed = trim($pattern);
            if ($trimmed !== '' && ! in_array($trimmed, $result, true)) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /** Own (non-inherited) rules of the egg. */
    public function get(Egg $egg): RuleSet
    {
        return new RuleSet($this->hiddenOf($egg), $this->protectedOf($egg));
    }

    /**
     * Rules Wings enforces for the egg. Follows `config_from` exactly like core's
     * `inherit_file_denylist`: an egg that copies its configuration uses its parent's rules.
     */
    public function effective(Egg $egg): RuleSet
    {
        if ($egg->config_from === null) {
            return $this->get($egg);
        }

        $parent = $egg->configFrom;

        return new RuleSet(
            $parent === null ? [] : $this->hiddenOf($parent),
            $egg->inherit_file_denylist ?? [],
        );
    }

    /**
     * Replace the egg's own rules. The change is announced to the sync service inside one transaction, so
     * Wings is asked to re-sync only when the sent rules actually differ once that transaction commits.
     */
    public function set(Egg $egg, RuleSet $rules): void
    {
        $after = new RuleSet(self::normalize($rules->hidden), self::normalize($rules->protected));

        DB::transaction(function () use ($egg, $after): void {
            $this->sync->willChange([$egg->id]);

            $egg->file_denylist = $after->protected;
            $egg->save();

            DB::table('ramjet_egg_rules')->updateOrInsert(
                ['egg_id' => $egg->id],
                ['hidden' => json_encode($after->hidden, JSON_THROW_ON_ERROR)],
            );

            $this->cache->flush();
            $this->sync->eggsChanged([$egg->id]);
        });
    }

    /** Union the given rules into the egg's own rules, keeping order and removing duplicates. */
    public function merge(Egg $egg, RuleSet $rules): void
    {
        $current = $this->get($egg);

        $this->set($egg, new RuleSet(
            [...$current->hidden, ...$rules->hidden],
            [...$current->protected, ...$rules->protected],
        ));
    }

    /** @return list<string> */
    private function hiddenOf(Egg $egg): array
    {
        $value = DB::table('ramjet_egg_rules')->where('egg_id', $egg->id)->value('hidden');
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /** @return list<string> */
    private function protectedOf(Egg $egg): array
    {
        return self::normalize($egg->file_denylist ?? []);
    }
}
