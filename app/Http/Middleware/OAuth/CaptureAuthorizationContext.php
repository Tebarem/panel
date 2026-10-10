<?php

namespace Pterodactyl\Http\Middleware\OAuth;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\OAuth\OidcAuthorizationContext;

/**
 * Runs on GET /oauth/authorize. For an OpenID Connect request (scope includes "openid") it records the
 * nonce and the time of login. The record is kept in the request context for skip-consent clients, and
 * in the session (keyed by the consent token) when the user has to approve the request first.
 *
 * Also rejects PKCE requests that do not use S256. League accepts "plain" by default, which is not allowed.
 */
class CaptureAuthorizationContext
{
    private const MAX_NONCE_LENGTH = 255;

    public function handle(Request $request, \Closure $next): mixed
    {
        $context = app(OidcAuthorizationContext::class);
        $context->pending = null;

        if ($request->query('code_challenge') !== null && $request->query('code_challenge_method') !== 'S256') {
            // Not redirected: the redirect URI has not been validated at this point.
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'The code_challenge_method parameter must be S256.',
            ], 400, ['Cache-Control' => 'no-store']);
        }

        $user = $request->user();
        $clientId = $request->query('client_id');
        $scope = $request->query('scope');
        $nonce = $request->query('nonce');

        if (
            $user
            && is_string($clientId) && $clientId !== ''
            && is_string($scope) && in_array('openid', explode(' ', $scope), true)
        ) {
            $context->pending = [
                'nonce' => is_string($nonce) && $nonce !== '' && strlen($nonce) <= self::MAX_NONCE_LENGTH ? $nonce : null,
                'auth_time' => $request->session()->get('auth.authenticated_at'),
            ];
        }

        $previousToken = $request->session()->get('authToken');

        $response = $next($request);

        // Passport generates a new consent token when it shows the consent screen. Remember the context
        // against that token; the approve/deny POST resumes it.
        $newToken = $request->session()->get('authToken');
        if (is_array($context->pending) && is_string($newToken) && $newToken !== $previousToken) {
            $request->session()->put('oidc_context', ['auth_token' => $newToken, 'context' => $context->pending]);
        }

        return $response;
    }
}
