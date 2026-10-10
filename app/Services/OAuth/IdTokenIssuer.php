<?php

namespace Pterodactyl\Services\OAuth;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Encoding\ChainedFormatter;

/**
 * Creates signed OpenID Connect ID tokens (RS256).
 */
class IdTokenIssuer
{
    public function __construct(
        private OidcKeyService $keys,
        private OpenIdConnectClaims $claims,
    ) {
    }

    /**
     * @param string[] $scopes granted scopes, must include "openid"
     *
     * @throws \RuntimeException if the signing keys cannot be loaded. Fails closed: no ID token is issued without a key.
     */
    public function issue(User $user, string $clientId, array $scopes, ?string $nonce, ?int $authTime): string
    {
        try {
            $privateKey = $this->keys->privateKey();
            $publicKey = $this->keys->publicKey();
            $kid = $this->keys->kid();
        } catch (\Throwable $exception) {
            // The key loader's messages describe the problem without including any key material.
            throw new \RuntimeException('The OpenID Connect ID token could not be signed: ' . $exception->getMessage(), 0, $exception);
        }

        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addSeconds((int) config('pterodactyl.oauth.id_token_ttl', 3600));

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($privateKey),
            InMemory::plainText($publicKey),
        );

        $builder = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $kid)
            ->issuedBy(OpenIdConnectClaims::issuer())
            ->permittedFor($clientId)
            ->relatedTo((string) $user->uuid)
            ->issuedAt($issuedAt)
            ->expiresAt($expiresAt)
            ->withClaim('azp', $clientId);

        if (!is_null($authTime)) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        if (!is_null($nonce)) {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        foreach ($this->claims->forUser($user, $scopes) as $name => $value) {
            if ($name === 'sub') {
                continue;
            }

            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
