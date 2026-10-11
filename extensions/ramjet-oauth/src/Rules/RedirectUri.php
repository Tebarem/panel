<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An OAuth redirect URI as registered for a client.
 *
 * - https:// URLs with a host.
 * - http:// only for loopback hosts (localhost, 127.0.0.1, [::1]).
 * - Custom schemes (com.example.app:/callback) for native applications.
 * - No fragments, whitespace, control characters or backslashes. javascript:, data:, file: and vbscript: are refused.
 */
class RedirectUri implements ValidationRule
{
    /** Schemes that are never acceptable as a redirect target. */
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript'];

    /** Hosts that may use plain HTTP. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function isAllowed(string $uri): bool
    {
        // No whitespace, control characters or backslashes.
        if (preg_match('/[^\x21-\x7E]/', $uri) === 1 || str_contains($uri, '\\')) {
            return false;
        }

        // OAuth redirect URIs must not contain a fragment.
        if (str_contains($uri, '#')) {
            return false;
        }

        // Must be an absolute URI with a scheme.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $uri, $matches) !== 1) {
            return false;
        }

        $scheme = mb_strtolower($matches[1]);
        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return false;
        }

        if ($scheme === 'https') {
            return ! empty(parse_url($uri, PHP_URL_HOST));
        }

        if ($scheme === 'http') {
            $host = mb_strtolower((string) parse_url($uri, PHP_URL_HOST));

            return in_array($host, self::LOOPBACK_HOSTS, true);
        }

        // Custom schemes are used by native applications.
        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed($value)) {
            $fail('The redirect URI must use https:// (http:// is only allowed for localhost), or a custom scheme for native applications. Fragments are not allowed.');
        }
    }
}
