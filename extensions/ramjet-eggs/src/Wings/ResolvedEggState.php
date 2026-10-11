<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Wings;

use Ramjet\Eggs\Rules\RuleSet;

/** What the server configuration takes from an egg: its effective rules and the registry credentials. */
final readonly class ResolvedEggState
{
    /** @param  list<array{registry: string, username: string, password: string}>  $registryAuth */
    public function __construct(
        public RuleSet $rules,
        public array $registryAuth,
    ) {}
}
