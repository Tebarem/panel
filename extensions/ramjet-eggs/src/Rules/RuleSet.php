<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules;

/**
 * File patterns for one egg. `hidden` files are listed but hidden from the file manager;
 * `protected` files are listed but cannot be modified, moved, deleted or downloaded.
 * Both are glob-like patterns evaluated by Wings.
 */
final readonly class RuleSet
{
    /**
     * @param  list<string>  $hidden
     * @param  list<string>  $protected
     */
    public function __construct(public array $hidden, public array $protected) {}

    public static function none(): self
    {
        return new self([], []);
    }
}
