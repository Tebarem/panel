<?php

namespace Pterodactyl\Http\Controllers\OAuth;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\OAuth\OidcKeyService;
use Pterodactyl\Services\OAuth\OpenIdConnectClaims;

class OpenIdController extends Controller
{
    public function __construct(
        private OidcKeyService $keys,
        private OpenIdConnectClaims $claims,
    ) {
    }

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
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/oauth/jwks',
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
     * JSON Web Key Set containing the public key used to verify ID tokens.
     */
    public function jwks(): JsonResponse
    {
        return new JsonResponse($this->keys->jwks(), 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Returns the claims for the user the access token was issued to. Requires the "openid" scope;
     * "profile" and "email" add their claims when granted.
     */
    public function userInfo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('oauth');
        $token = $user->currentAccessToken();

        if (!$token || !$token->can('openid')) {
            return new JsonResponse(
                ['error' => 'insufficient_scope', 'error_description' => 'The access token must include the openid scope.'],
                403,
                ['WWW-Authenticate' => 'Bearer error="insufficient_scope"', 'Cache-Control' => 'no-store'],
            );
        }

        $scopes = array_values(array_filter(
            OpenIdConnectClaims::SCOPES,
            fn (string $scope) => $token->can($scope),
        ));

        return new JsonResponse(
            $this->claims->forUser($user, $scopes),
            200,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }
}
