<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Listeners\RecordAuthenticationTime;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Tests\OAuthTestCase;

/**
 * End-to-end OpenID Connect authorization code flow through the real endpoints: consent, code exchange, ID token,
 * refresh and code reuse.
 */
class AuthorizationCodeFlowTest extends OAuthTestCase
{
    private const REDIRECT_URI = 'https://rp.example.com/callback';

    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = $this->useSigningKeys();
    }

    public function test_confidential_client_completes_flow_with_consent_and_issues_verifiable_id_token(): void
    {
        $user = User::factory()->create(['username' => 'e2euser', 'name_first' => 'End', 'name_last' => 'To']);
        $secret = Str::random(40);
        $client = $this->createClient('RP with consent', $secret, skipConsent: false);

        $verifier = $this->codeVerifier();
        $nonce = 'nonce-'.Str::random(12);

        // The user signed in a while ago. auth_time must carry that time into the ID token.
        $authenticatedAt = now()->subMinutes(5)->getTimestamp();
        $this->withSession([RecordAuthenticationTime::SESSION_KEY => $authenticatedAt]);

        // 1. Authorization request: the consent screen is shown.
        $authorize = $this->actingAs($user)->get($this->authorizeUrl($client, $verifier, $nonce));
        $authorize->assertOk()->assertSee($client->name);
        $this->carrySession();

        preg_match('/name="auth_token" value="([^"]+)"/', $authorize->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'The consent page did not include an auth_token.');
        preg_match('/name="_token" value="([^"]+)"/', $authorize->getContent(), $csrf);
        $this->assertArrayHasKey(1, $csrf, 'The consent page did not include a CSRF token.');

        // 2. The user approves, with the CSRF token from the consent form.
        $approve = $this->actingAs($user)->post('/oauth/authorize', [
            '_token' => $csrf[1],
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

        $this->assertIdTokenMatches($idToken, $client, $user, $nonce, $authenticatedAt);

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

    public function test_skip_consent_client_receives_code_directly_from_authorize_request(): void
    {
        $user = User::factory()->create(['username' => 'skipuser']);
        $secret = Str::random(40);
        $client = $this->createClient('First party', $secret, skipConsent: true);

        $verifier = $this->codeVerifier();
        $nonce = 'nonce-'.Str::random(12);

        $authorize = $this->actingAs($user)->get($this->authorizeUrl($client, $verifier, $nonce));
        $authorize->assertRedirect();

        $code = $this->codeFromRedirect($authorize);

        $tokens = $this->exchangeCode($client, $secret, $code, $verifier)->assertOk();
        $this->assertIdTokenMatches($tokens->json('id_token'), $client, $user, $nonce, null);
    }

    public function test_plain_pkce_is_rejected(): void
    {
        $user = User::factory()->create();
        $client = $this->createClient('Plain PKCE', Str::random(40), skipConsent: true);

        $this->actingAs($user)
            ->get('/oauth/authorize?'.http_build_query([
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

    public function test_guests_are_sent_to_login_with_the_authorization_request_as_redirect(): void
    {
        $client = $this->createClient('Guest', Str::random(40), skipConsent: false);
        $url = $this->authorizeUrl($client, $this->codeVerifier(), 'nonce-guest');

        $this->get($url)->assertRedirect('/auth/login?redirect='.rawurlencode($url));
    }

    public function test_prompt_none_returns_login_required_to_the_redirect_uri(): void
    {
        $client = $this->createClient('Silent', Str::random(40), skipConsent: false);

        $response = $this->get($this->authorizeUrl($client, $this->codeVerifier(), 'nonce-silent').'&prompt=none')
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI, $location);
        $this->assertStringContainsString('error=login_required', $location);
        $this->assertStringContainsString('state=xyz', $location);
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

        return '/oauth/authorize?'.http_build_query([
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
     * Keeps the panel session between the consent page and the approval. The test client does not return the
     * session cookie on its own, so the plain session ID of the last request is sent with the next one.
     */
    private function carrySession(): void
    {
        $this->withCookie(config('session.cookie'), app('session')->driver()->getId());
    }

    private function assertIdTokenMatches(string $jwt, OAuthClient $client, User $user, string $nonce, ?int $authTime): void
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);

        // The signature must verify with the key that /oauth/jwks publishes (same key id and modulus).
        $jwk = $this->getJson('/oauth/jwks')->assertOk()->json('keys.0');
        $this->assertSame($jwk['kid'], $token->headers()->get('kid'));

        $rsa = openssl_pkey_get_details(openssl_pkey_get_public($this->keys['public']))['rsa'];
        $this->assertSame(rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='), $jwk['n']);

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->keys['private']),
            InMemory::plainText($this->keys['public']),
        );
        // Throws if the signature does not verify.
        $config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->keys['public'])));

        $claims = $token->claims();
        $this->assertSame('http://localhost', $claims->get('iss'));
        $this->assertSame([(string) $client->id], $claims->get('aud'));
        $this->assertSame($user->uuid, $claims->get('sub'));
        $this->assertSame($nonce, $claims->get('nonce'));
        $this->assertSame($user->username, $claims->get('preferred_username'));

        if ($authTime === null) {
            $this->assertFalse($claims->has('auth_time'));
        } else {
            $this->assertSame($authTime, $claims->get('auth_time'));
        }
    }
}
