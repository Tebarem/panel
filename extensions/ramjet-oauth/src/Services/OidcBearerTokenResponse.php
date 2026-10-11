<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Illuminate\Contracts\Container\Container;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Pterodactyl\Models\User;

/**
 * Token response used by the authorization server. Adds an OpenID Connect "id_token" to the token response
 * of an authorization code exchange when the "openid" scope was granted.
 *
 * Registered with Passport::useAuthorizationServerResponseType() in RamjetOAuthProvider.
 */
class OidcBearerTokenResponse extends BearerTokenResponse
{
    public function __construct(
        private readonly IdTokenIssuer $issuer,
        private readonly Container $container,
    ) {}

    /**
     * @return array<string, string>
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(
            fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        if (! in_array('openid', $scopes, true)) {
            return [];
        }

        // The context is only present for an authorization code exchange (not for refresh tokens), and is
        // consumed here so it cannot be reused by a later request.
        $context = $this->container->make(OidcAuthorizationContext::class);
        $exchanged = $context->exchanged;
        $context->exchanged = null;

        if (! is_array($exchanged)) {
            return [];
        }

        // Only user-bound tokens can have an ID token (no client credentials).
        $userId = $accessToken->getUserIdentifier();
        $user = $userId === null ? null : User::query()->find((int) $userId);
        if (! $user instanceof User) {
            return [];
        }

        $nonce = $exchanged['nonce'] ?? null;
        $authTime = $exchanged['auth_time'] ?? null;

        return [
            'id_token' => $this->issuer->issue(
                $user,
                (string) $accessToken->getClient()->getIdentifier(),
                $scopes,
                is_string($nonce) ? $nonce : null,
                is_numeric($authTime) ? (int) $authTime : null,
            ),
        ];
    }
}
