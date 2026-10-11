<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Parsing and limits for file pattern lists. The admin form sends one pattern per line; imported
 * files carry a JSON list. Both end up as a normalized list of patterns.
 */
final class FilePatterns implements ValidationRule
{
    public const MAX_PATTERNS = 500;

    public const MAX_LENGTH = 255;

    /**
     * Parses textarea input: one pattern per line.
     *
     * @return list<string>
     */
    public static function fromText(?string $input): array
    {
        return EggRules::normalize(preg_split('/\R/', (string) $input) ?: []);
    }

    /**
     * Parses a JSON list from an import file.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the value is not a list of strings or exceeds the limits
     */
    public static function fromList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw new InvalidArgumentException(sprintf('"%s" must be a list of file patterns.', $field));
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(sprintf('"%s" must only contain strings.', $field));
            }
        }

        $patterns = EggRules::normalize($value);
        self::assertWithinLimits($patterns, $field);

        return $patterns;
    }

    /** @param  list<string>  $patterns */
    public static function assertWithinLimits(array $patterns, string $field): void
    {
        if (count($patterns) > self::MAX_PATTERNS) {
            throw new InvalidArgumentException(sprintf('"%s" may contain at most %d patterns.', $field, self::MAX_PATTERNS));
        }

        foreach ($patterns as $pattern) {
            if (mb_strlen($pattern) > self::MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('Each pattern in "%s" may be at most %d characters long.', $field, self::MAX_LENGTH));
            }
        }
    }

    /** Validation rule for the textarea fields of the admin form. */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $patterns = self::fromText(is_string($value) ? $value : null);

        if (count($patterns) > self::MAX_PATTERNS) {
            $fail(sprintf('The :attribute may contain at most %d patterns.', self::MAX_PATTERNS));

            return;
        }

        foreach ($patterns as $pattern) {
            if (mb_strlen($pattern) > self::MAX_LENGTH) {
                $fail(sprintf('Each pattern in the :attribute may be at most %d characters long.', self::MAX_LENGTH));

                return;
            }
        }
    }
}
