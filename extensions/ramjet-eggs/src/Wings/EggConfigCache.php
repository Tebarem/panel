<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Wings;

use Closure;

/**
 * Request-scoped memo of what the server configuration needs from each egg: its effective file rules
 * and its registry credentials. Wings boots by requesting pages of servers, and many share an egg, so
 * without this each server would repeat the same queries.
 *
 * Bound as scoped (one instance per request or job). Every write to rules or registries flushes it, so a
 * configuration built after a change in the same request sees the change.
 */
final class EggConfigCache
{
    /** @var array<int, ResolvedEggState> */
    private array $entries = [];

    /**
     * @param  Closure(): ResolvedEggState  $resolve  called on a miss; an exception is not cached
     */
    public function remember(int $eggId, Closure $resolve): ResolvedEggState
    {
        return $this->entries[$eggId] ??= $resolve();
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
