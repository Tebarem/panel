<?php

namespace Pterodactyl\Services\OAuth;

use Pterodactyl\Models\User;
use Illuminate\Contracts\Container\Container;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

/**
 * Token response used by the authorization server. Adds an OpenID Connect "id_token" to the
 * token response for an authorization code exchange when the "openid" scope was granted.
 *
 * Registered with Passport::useAuthorizationServerResponseType() in OAuthServiceProvider.
 */
class OidcBearerTokenResponse extends BearerTokenResponse
{
    public function __construct(
        private IdTokenIssuer $issuer,
        private Container $container,
    ) {
    }

    /**
     * @return array<string, string>
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(
            fn (ScopeEntityInterface $scope) => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        if (!in_array('openid', $scopes, true)) {
            return [];
        }

        // The context is only present for an authorization code exchange (not for refresh tokens), and
        // is consumed here so it cannot be reused by a later request.
        $context = $this->container->make(OidcAuthorizationContext::class);
        $exchanged = $context->exchanged;
        $context->exchanged = null;

        if (!is_array($exchanged)) {
            return [];
        }

        // Only user-bound tokens can have an ID token (no client credentials).
        $userId = $accessToken->getUserIdentifier();
        if (is_null($userId) || !($user = User::query()->find((int) $userId))) {
            return [];
        }

        return [
            'id_token' => $this->issuer->issue(
                $user,
                (string) $accessToken->getClient()->getIdentifier(),
                $scopes,
                $exchanged['nonce'] ?? null,
                isset($exchanged['auth_time']) ? (int) $exchanged['auth_time'] : null,
            ),
        ];
    }
}
