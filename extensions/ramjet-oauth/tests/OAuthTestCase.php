<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Models\OAuthUser;
use RuntimeException;

/**
 * Enables the extension the way the panel does at runtime (its manifest, autoloader, migrations and provider), with
 * a database transaction around each test.
 */
abstract class OAuthTestCase extends IntegrationTestCase
{
    use DatabaseTransactions;

    public const EXTENSION_ID = 'ramjet-oauth';

    /** Directory with the key files written by useSigningKeys(), removed in tearDown(). */
    private ?string $keyDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableExtension();
    }

    protected function tearDown(): void
    {
        if ($this->keyDirectory !== null) {
            Passport::loadKeysFrom(storage_path());
            Passport::$validateKeyPermissions = true;

            @unlink($this->keyDirectory.DIRECTORY_SEPARATOR.'oauth-private.key');
            @unlink($this->keyDirectory.DIRECTORY_SEPARATOR.'oauth-public.key');
            @rmdir($this->keyDirectory);
        }

        parent::tearDown();
    }

    /**
     * Writes a fresh RSA key pair for the test and points Passport and the ID token signer at it.
     *
     * @return array{private: string, public: string}
     */
    protected function useSigningKeys(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate a test key pair.');
        }

        $private = '';
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        // Passport checks file permissions by default, which does not hold for files created by the test process.
        $this->keyDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ramjet-oauth-test-'.Str::random(12);
        mkdir($this->keyDirectory, 0o700, true);
        file_put_contents($this->keyDirectory.DIRECTORY_SEPARATOR.'oauth-private.key', $private);
        file_put_contents($this->keyDirectory.DIRECTORY_SEPARATOR.'oauth-public.key', $public);

        Passport::loadKeysFrom($this->keyDirectory);
        Passport::$validateKeyPermissions = false;

        config([
            'passport.private_key' => $private,
            'passport.public_key' => $public,
            'app.url' => 'http://localhost',
        ]);

        return ['private' => $private, 'public' => $public];
    }

    /**
     * Runs a real authorization code exchange for the user through the endpoints, with a skip-consent application,
     * and returns the access token. Needs signing keys (useSigningKeys() or the keys command).
     *
     * @param  string[]  $scopes
     */
    protected function issueAccessToken(User $user, array $scopes): string
    {
        $redirectUri = 'https://rp.example.com/callback';
        $secret = Str::random(40);

        $client = new OAuthClient();
        $client->forceFill([
            'name' => 'Token helper',
            'secret' => $secret,
            'provider' => null,
            'redirect_uris' => [$redirectUri],
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => true,
        ])->save();

        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $authorize = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'helper',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertRedirect();

        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, 'The authorization request did not return a code.');

        return (string) $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $redirectUri,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }

    /**
     * Acts as the given user for the oauth guard, with the given access token scopes.
     *
     * @param  string[]  $scopes
     */
    protected function actingAsOAuthUser(User $user, array $scopes): void
    {
        $oauthUser = OAuthUser::query()->findOrFail($user->id);

        Passport::actingAs($oauthUser, $scopes, 'oauth');
    }

    private function enableExtension(): void
    {
        config([
            'extensions.enabled' => true,
            'extensions.directory' => base_path('extensions'),
        ]);

        $this->app->forgetInstance(ExtensionRepository::class);
        $this->app->forgetInstance(ExtensionProviderLoader::class);

        Extension::query()->updateOrCreate(
            ['identifier' => self::EXTENSION_ID],
            ['version' => '1.0.0', 'enabled' => true],
        );

        $repository = $this->app->make(ExtensionRepository::class);
        $repository->flushDiscovery();

        $this->app->make(ExtensionProviderLoader::class)->registerProviders($repository->enabled());
    }
}
