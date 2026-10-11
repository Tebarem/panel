<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ramjet\OAuth\Services\OidcAuthorizationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on POST and DELETE /oauth/authorize (the approve and deny buttons). Restores the OpenID Connect context
 * saved when the consent screen was shown, but only when the consent token matches.
 */
class ResumeAuthorizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(OidcAuthorizationContext::class);
        $context->pending = null;

        $saved = $request->session()->pull(CaptureAuthorizationContext::SESSION_KEY);
        $submitted = $request->input('auth_token');

        if (
            is_array($saved)
            && is_string($saved['auth_token'] ?? null)
            && is_string($submitted)
            && hash_equals($saved['auth_token'], $submitted)
        ) {
            $pending = $saved['context'] ?? null;
            $context->pending = is_array($pending) ? $pending : null;
        }

        return $next($request);
    }
}
