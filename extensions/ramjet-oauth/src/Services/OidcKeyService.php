<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Provides the RSA key material used to sign ID tokens and publish the JWKS.
 *
 * The keys are the ones Passport uses for access tokens: PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY from
 * the environment, or the files written by `php artisan ramjet-oauth:keys` (storage/oauth-*.key).
 */
class OidcKeyService
{
    private ?string $privateKey = null;

    private ?string $publicKey = null;

    private ?string $kid = null;

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * PEM encoded private key used for signing.
     *
     * @throws RuntimeException
     */
    public function privateKey(): string
    {
        if ($this->privateKey === null) {
            $this->privateKey = $this->loadKey(config('passport.private_key'), 'oauth-private.key');
        }

        return $this->privateKey;
    }

    /**
     * PEM encoded public key. Derived from the private key when no public key is configured.
     *
     * @throws RuntimeException
     */
    public function publicKey(): string
    {
        if ($this->publicKey === null) {
            $configured = config('passport.public_key');

            if (! empty($configured) || file_exists(Passport::keyPath('oauth-public.key'))) {
                $this->publicKey = $this->loadKey($configured, 'oauth-public.key');
            } else {
                $private = openssl_pkey_get_private($this->privateKey());
                $details = $private ? openssl_pkey_get_details($private) : false;
                if (! is_array($details) || ! isset($details['key'])) {
                    throw new RuntimeException('Unable to derive the OAuth public key from the private key.');
                }

                $this->publicKey = $details['key'];
            }
        }

        return $this->publicKey;
    }

    /**
     * Key ID for the JWT "kid" header and the JWKS entry: the RFC 7638 JWK thumbprint (SHA-256 over the
     * required members e, kty and n) of the public key, base64url encoded.
     *
     * @throws RuntimeException
     */
    public function kid(): string
    {
        if ($this->kid === null) {
            $parameters = $this->rsaParameters();

            $thumbprint = json_encode([
                'e' => self::base64UrlEncode($parameters['e']),
                'kty' => 'RSA',
                'n' => self::base64UrlEncode($parameters['n']),
            ], JSON_UNESCAPED_SLASHES);

            $this->kid = self::base64UrlEncode(hash('sha256', (string) $thumbprint, true));
        }

        return $this->kid;
    }

    /**
     * The JSON Web Key Set published at /oauth/jwks.
     *
     * @return array{keys: array<int, array<string, string>>}
     *
     * @throws RuntimeException
     */
    public function jwks(): array
    {
        $parameters = $this->rsaParameters();

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $this->kid(),
                    'n' => self::base64UrlEncode($parameters['n']),
                    'e' => self::base64UrlEncode($parameters['e']),
                ],
            ],
        ];
    }

    /**
     * The raw modulus (n) and exponent (e) of the public key.
     *
     * @return array{n: string, e: string}
     *
     * @throws RuntimeException
     */
    private function rsaParameters(): array
    {
        $key = openssl_pkey_get_public($this->publicKey());
        $details = $key ? openssl_pkey_get_details($key) : false;

        if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The configured OAuth public key is not a valid RSA key.');
        }

        return ['n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    /**
     * Loads a PEM key from an inline value (environment) or from the Passport key directory.
     *
     * @throws RuntimeException
     */
    private function loadKey(mixed $inline, string $file): string
    {
        if (is_string($inline) && $inline !== '') {
            // Environment files often store newlines as a literal "\n" sequence.
            return str_replace('\n', "\n", trim($inline));
        }

        $path = Passport::keyPath($file);
        if (! file_exists($path)) {
            throw new RuntimeException('OAuth signing keys are missing. Run "php artisan ramjet-oauth:keys" or set PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY.');
        }

        return (string) file_get_contents($path);
    }
}
