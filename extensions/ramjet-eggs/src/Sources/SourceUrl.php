<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

/**
 * Reads the components of a source address.
 */
final class SourceUrl
{
    /**
     * One component of the URL, as written; '' when the URL does not have it or does not parse.
     *
     * @param  int  $component  one of the PHP_URL_* constants
     */
    public static function part(string $url, int $component): string
    {
        $parts = parse_url($url);
        $key = match ($component) {
            PHP_URL_SCHEME => 'scheme',
            PHP_URL_HOST => 'host',
            PHP_URL_PORT => 'port',
            PHP_URL_USER => 'user',
            PHP_URL_PASS => 'pass',
            PHP_URL_PATH => 'path',
            PHP_URL_QUERY => 'query',
            PHP_URL_FRAGMENT => 'fragment',
            default => '',
        };

        if ($parts === false || $key === '' || ! isset($parts[$key])) {
            return '';
        }

        // SAFETY: parse_url gives each component as a string, and the port as an integer; both are read as text here.
        return (string) $parts[$key];
    }
}
