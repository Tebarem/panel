<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Pterodactyl\Http\Controllers\Controller;
use Ramjet\OAuth\Models\OAuthUser;
use Ramjet\OAuth\Services\OidcKeyService;
use Ramjet\OAuth\Services\OpenIdConnectClaims;

class OpenIdController extends Controller
{
    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly OpenIdConnectClaims $claims,
    ) {}

    /**
     * OpenID Connect discovery document.
     *
     * @see https://openid.net/specs/openid-connect-discovery-1_0.html
     */
    public function configuration(): JsonResponse
    {
        $issuer = OpenIdConnectClaims::issuer();

        return new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'scopes_supported' => OpenIdConnectClaims::SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => OpenIdConnectClaims::CLAIMS,
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'require_request_uri_registration' => false,
        ], 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * JSON Web Key Set containing the public key used to verify ID tokens and access tokens.
     */
    public function jwks(): JsonResponse
    {
        return new JsonResponse($this->keys->jwks(), 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Claims for the user the access token was issued to. Requires the "openid" scope; "profile" and "email"
     * add their claims when granted.
     */
    public function userInfo(Request $request): JsonResponse
    {
        // Read before the guard runs: Passport's token guard clears the Authorization header of a token it rejects.
        $tokenPresented = $request->bearerToken() !== null;

        $user = Auth::guard('oauth')->user();

        if (! $user instanceof OAuthUser) {
            // RFC 6750: a presented token that does not verify is an invalid_token; no token at all is a plain challenge.
            $challenge = $tokenPresented ? 'Bearer error="invalid_token"' : 'Bearer';

            return new JsonResponse(
                ['error' => 'invalid_token', 'error_description' => 'The access token is missing, invalid or expired.'],
                401,
                ['WWW-Authenticate' => $challenge, 'Cache-Control' => 'no-store'],
            );
        }

        $token = $user->oauthAccessToken();
        if ($token === null || ! $token->can('openid')) {
            return new JsonResponse(
                ['error' => 'insufficient_scope', 'error_description' => 'The access token must include the openid scope.'],
                403,
                ['WWW-Authenticate' => 'Bearer error="insufficient_scope"', 'Cache-Control' => 'no-store'],
            );
        }

        $scopes = array_values(array_filter(
            OpenIdConnectClaims::SCOPES,
            fn (string $scope): bool => $token->can($scope),
        ));

        return new JsonResponse(
            $this->claims->forUser($user, $scopes),
            200,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }
}
