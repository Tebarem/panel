<?php

namespace Pterodactyl\Http\Middleware\OAuth;

use Illuminate\Http\Request;
use Pterodactyl\Services\OAuth\OidcAuthorizationContext;

/**
 * Runs on POST/DELETE /oauth/authorize (the approve and deny buttons). Restores the OpenID Connect
 * context saved when the consent screen was shown, but only when the consent token matches.
 */
class ResumeAuthorizationContext
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $context = app(OidcAuthorizationContext::class);
        $context->pending = null;

        $saved = $request->session()->pull('oidc_context');
        $submitted = $request->input('auth_token');

        if (
            is_array($saved)
            && is_string($saved['auth_token'] ?? null)
            && is_string($submitted)
            && hash_equals($saved['auth_token'], $submitted)
        ) {
            $context->pending = is_array($saved['context'] ?? null) ? $saved['context'] : null;
        }

        return $next($request);
    }
}
