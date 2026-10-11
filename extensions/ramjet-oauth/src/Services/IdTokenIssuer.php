<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Carbon\CarbonImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Pterodactyl\Models\User;
use RuntimeException;
use Throwable;

/**
 * Creates signed OpenID Connect ID tokens (RS256).
 */
class IdTokenIssuer
{
    /** Lifetime of an ID token, in seconds. */
    public const TTL_SECONDS = 3600;

    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly OpenIdConnectClaims $claims,
    ) {}

    /**
     * @param  string[]  $scopes  granted scopes, must include "openid"
     *
     * @throws RuntimeException if the signing keys cannot be loaded. Fails closed: no ID token is issued without a key.
     */
    public function issue(User $user, string $clientId, array $scopes, ?string $nonce, ?int $authTime): string
    {
        try {
            $privateKey = $this->keys->privateKey();
            $publicKey = $this->keys->publicKey();
            $kid = $this->keys->kid();
        } catch (Throwable $exception) {
            // The key loader's messages describe the problem without including any key material.
            throw new RuntimeException('The OpenID Connect ID token could not be signed: '.$exception->getMessage(), 0, $exception);
        }

        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addSeconds(self::TTL_SECONDS);

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

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        if ($nonce !== null) {
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
