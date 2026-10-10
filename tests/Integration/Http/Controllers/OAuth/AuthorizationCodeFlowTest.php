<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\OAuth;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Pterodactyl\Models\OAuthClient;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Pterodactyl\Http\Middleware\VerifyCsrfToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

/**
 * End-to-end OpenID Connect authorization code flow through the real Passport endpoints.
 */
class AuthorizationCodeFlowTest extends HttpTestCase
{
    private const REDIRECT_URI = 'https://rp.example.com/callback';

    private string $keyDir;

    private string $publicKey;

    private string $privateKey;

    public function setUp(): void
    {
        parent::setUp();

        // Use keys written to a temporary directory, not the panel's storage/ directory. Passport checks
        // file permissions by default, which does not hold for files created by the test process.
        $this->keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oauth-test-' . Str::random(12);
        mkdir($this->keyDir, 0700, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        file_put_contents($this->keyDir . DIRECTORY_SEPARATOR . 'oauth-private.key', $this->privateKey);
        file_put_contents($this->keyDir . DIRECTORY_SEPARATOR . 'oauth-public.key', $this->publicKey);

        Passport::loadKeysFrom($this->keyDir);
        Passport::$validateKeyPermissions = false;

        config([
            'passport.private_key' => null,
            'passport.public_key' => null,
            'pterodactyl.oauth.private_key' => null,
            'pterodactyl.oauth.public_key' => null,
            'app.url' => 'http://localhost',
        ]);

        // The approve/deny forms are CSRF protected in the browser; this test drives them directly.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function tearDown(): void
    {
        Passport::loadKeysFrom(storage_path());
        Passport::$validateKeyPermissions = true;

        @unlink($this->keyDir . DIRECTORY_SEPARATOR . 'oauth-private.key');
        @unlink($this->keyDir . DIRECTORY_SEPARATOR . 'oauth-public.key');
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    public function testConfidentialClientCompletesFlowWithConsentAndIssuesVerifiableIdToken(): void
    {
        $user = User::factory()->create(['username' => 'e2euser', 'name_first' => 'End', 'name_last' => 'To']);
        $secret = Str::random(40);
        $client = $this->createClient('RP with consent', $secret, skipConsent: false);

        $verifier = $this->codeVerifier();
        $nonce = 'nonce-' . Str::random(12);

        // 1. Authorization request: the consent screen is shown.
        $authorize = $this->actingAs($user)->get($this->authorizeUrl($client, $verifier, $nonce));
        $authorize->assertOk()->assertSee($client->name);
        $this->carryCookies($authorize);

        preg_match('/name="auth_token" value="([^"]+)"/', $authorize->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'The consent page did not include an auth_token.');

        // 2. The user approves.
        $approve = $this->actingAs($user)->post('/oauth/authorize', [
            'state' => 'xyz',
            'client_id' => $client->id,
            'auth_token' => $matches[1],
        ]);
        $approve->assertRedirect();
        $code = $this->codeFromRedirect($approve);

        // 3. Code exchange returns an ID token bound to this request.
        $tokens = $this->exchangeCode($client, $secret, $code, $verifier)->assertOk();
        $this->assertArrayHasKey('access_token', $tokens->json());
        $this->assertArrayHasKey('refresh_token', $tokens->json());
        $idToken = $tokens->json('id_token');
        $this->assertIsString($idToken);

        $this->assertIdTokenMatches($idToken, $client, $user, $nonce);

        // 4. Refresh grant: no ID token.
        $refresh = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->json('refresh_token'),
            'client_id' => $client->id,
            'client_secret' => $secret,
            'scope' => 'openid profile email',
        ])->assertOk();

        $this->assertArrayHasKey('access_token', $refresh->json());
        $this->assertArrayNotHasKey('id_token', $refresh->json());

        // 5. The authorization code cannot be used twice.
        $this->exchangeCode($client, $secret, $code, $verifier)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function testSkipConsentClientReceivesCodeDirectlyFromAuthorizeRequest(): void
    {
        $user = User::factory()->create(['username' => 'skipuser']);
        $secret = Str::random(40);
        $client = $this->createClient('First party', $secret, skipConsent: true);

        $verifier = $this->codeVerifier();
        $nonce = 'nonce-' . Str::random(12);

        $authorize = $this->actingAs($user)->get($this->authorizeUrl($client, $verifier, $nonce));
        $authorize->assertRedirect();

        $code = $this->codeFromRedirect($authorize);

        $tokens = $this->exchangeCode($client, $secret, $code, $verifier)->assertOk();
        $this->assertIdTokenMatches($tokens->json('id_token'), $client, $user, $nonce);
    }

    public function testPlainPkceIsRejected(): void
    {
        $user = User::factory()->create();
        $client = $this->createClient('Plain PKCE', Str::random(40), skipConsent: true);

        $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query([
                'client_id' => $client->id,
                'redirect_uri' => self::REDIRECT_URI,
                'response_type' => 'code',
                'scope' => 'openid',
                'code_challenge' => $this->codeVerifier(),
                'code_challenge_method' => 'plain',
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    private function createClient(string $name, string $secret, bool $skipConsent): OAuthClient
    {
        $client = new OAuthClient();
        $client->forceFill([
            'name' => $name,
            'secret' => $secret,
            'provider' => null,
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => $skipConsent,
        ])->save();

        return $client;
    }

    private function codeVerifier(): string
    {
        return Str::random(64);
    }

    private function authorizeUrl(OAuthClient $client, string $verifier, string $nonce): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return '/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'xyz',
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    private function exchangeCode(OAuthClient $client, string $secret, string $code, string $verifier): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'code_verifier' => $verifier,
        ]);
    }

    private function codeFromRedirect(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('xyz', $query['state'] ?? null);
        $this->assertArrayHasKey('code', $query);

        return $query['code'];
    }

    /**
     * Keeps the panel session between requests. The session cookie is already encrypted in the response, so it is
     * sent back unencrypted.
     */
    private function carryCookies(TestResponse $response): void
    {
        $name = config('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                $this->withUnencryptedCookie($name, $cookie->getValue());
            }
        }
    }

    private function assertIdTokenMatches(string $jwt, OAuthClient $client, User $user, string $nonce): void
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);

        // The signature must verify with the key that /oauth/jwks publishes (same key id and modulus).
        $jwk = $this->getJson('/oauth/jwks')->assertOk()->json('keys.0');
        $this->assertSame($jwk['kid'], $token->headers()->get('kid'));

        $rsa = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKey))['rsa'];
        $this->assertSame(rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='), $jwk['n']);

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey),
            InMemory::plainText($this->publicKey),
        );
        // Throws if the signature does not verify.
        $config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->publicKey)));

        $claims = $token->claims();
        $this->assertSame('http://localhost', $claims->get('iss'));
        $this->assertSame([(string) $client->id], $claims->get('aud'));
        $this->assertSame($user->uuid, $claims->get('sub'));
        $this->assertSame($nonce, $claims->get('nonce'));
        $this->assertSame($user->username, $claims->get('preferred_username'));
    }
}
