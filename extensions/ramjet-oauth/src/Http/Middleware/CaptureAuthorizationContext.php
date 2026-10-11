<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Ramjet\OAuth\Listeners\RecordAuthenticationTime;
use Ramjet\OAuth\Services\OidcAuthorizationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on GET /oauth/authorize.
 *
 * - Rejects PKCE requests that do not use S256 (League accepts "plain" by default, which is not allowed).
 * - Guests, and users who must sign in again (prompt=login), are sent to the panel login. The login page returns
 *   them to this request through its redirect parameter. prompt=none is left to Passport, which answers with
 *   login_required on the redirect URI.
 * - For an OpenID Connect request (scope includes "openid") records the nonce and the time of login. The record
 *   stays in the request context for skip-consent clients, and in the session (keyed by the consent token) when
 *   the user has to approve the request first.
 *
 * The login checks run before Passport's controller. Passport signals a sign-in with an exception, and the panel's
 * exception handling renders that exception before this middleware could catch it.
 */
class CaptureAuthorizationContext
{
    public const SESSION_KEY = 'ramjet_oauth.context';

    /** Session flag Passport uses to tell that the sign-in prompt was already shown for this request. */
    private const PROMPTED_FOR_LOGIN = 'promptedForLogin';

    private const MAX_NONCE_LENGTH = 255;

    public function handle(Request $request, Closure $next): Response
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

        // A request Passport would reject goes to Passport untouched, which renders the error. Nothing below may run
        // for it: a sign-out (prompt=login) on an invalid request would let a third party log the user out.
        try {
            app(AuthorizationServer::class)->validateAuthorizationRequest(app(ServerRequestInterface::class));
        } catch (LeagueOAuthServerException) {
            return $next($request);
        }

        $prompt = $this->prompt($request);
        $user = $request->user();

        if ($user === null && ! in_array('none', $prompt, true)) {
            $request->session()->put(self::PROMPTED_FOR_LOGIN, true);

            return redirect($this->loginUrl($request));
        }

        if ($user !== null && in_array('login', $prompt, true) && ! in_array('none', $prompt, true) && ! $request->session()->get(self::PROMPTED_FOR_LOGIN, false)) {
            // Same steps as Passport: sign out, start a fresh session, then ask for the password again.
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put(self::PROMPTED_FOR_LOGIN, true);

            return redirect($this->loginUrl($request));
        }

        $clientId = $request->query('client_id');
        $scope = $request->query('scope');
        $nonce = $request->query('nonce');

        if (
            $user
            && is_string($clientId) && $clientId !== ''
            && is_string($scope) && in_array('openid', explode(' ', $scope), true)
        ) {
            $context->pending = [
                'nonce' => is_string($nonce) && $nonce !== '' && mb_strlen($nonce) <= self::MAX_NONCE_LENGTH ? $nonce : null,
                'auth_time' => $request->session()->get(RecordAuthenticationTime::SESSION_KEY),
            ];
        }

        $previousToken = $request->session()->get('authToken');

        $response = $next($request);

        // Passport generates a new consent token when it shows the consent screen. Remember the context against
        // that token; the approve/deny POST resumes it.
        $newToken = $request->session()->get('authToken');
        if (is_array($context->pending) && is_string($newToken) && $newToken !== $previousToken) {
            $request->session()->put(self::SESSION_KEY, ['auth_token' => $newToken, 'context' => $context->pending]);
        }

        return $response;
    }

    /**
     * @return string[] the space separated prompt values, as Passport reads them
     */
    private function prompt(Request $request): array
    {
        return array_values(array_filter(array_map(
            trim(...),
            explode(' ', (string) $request->query('prompt')),
        ), fn (string $value): bool => $value !== ''));
    }

    /**
     * The SPA login page honours a same-origin path in its "redirect" parameter.
     */
    private function loginUrl(Request $request): string
    {
        return '/auth/login?redirect='.rawurlencode($request->getRequestUri());
    }
}
