<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use DateTimeInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Ramjet\OAuth\Services\IdTokenIssuer;
use Ramjet\OAuth\Services\OidcKeyService;
use Ramjet\OAuth\Services\OpenIdConnectClaims;
use Ramjet\OAuth\Tests\OAuthTestCase;

class OpenIdControllerTest extends OAuthTestCase
{
    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = $this->useSigningKeys();
    }

    public function test_discovery_document_describes_provider(): void
    {
        $issuer = rtrim((string) config('app.url'), '/');

        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('issuer', $issuer)
            ->assertJsonPath('authorization_endpoint', $issuer.'/oauth/authorize')
            ->assertJsonPath('token_endpoint', $issuer.'/oauth/token')
            ->assertJsonPath('userinfo_endpoint', $issuer.'/oauth/userinfo')
            ->assertJsonPath('jwks_uri', $issuer.'/oauth/jwks')
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
            ->assertJsonPath('subject_types_supported', ['public'])
            ->assertJsonPath('id_token_signing_alg_values_supported', ['RS256'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('scopes_supported', ['openid', 'profile', 'email']);
    }

    public function test_jwks_publishes_rsa_key_with_rfc7638_thumbprint(): void
    {
        $response = $this->getJson('/oauth/jwks')->assertOk();

        $jwk = $response->json('keys.0');
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);

        $rsa = openssl_pkey_get_details(openssl_pkey_get_public($this->keys['public']))['rsa'];
        $n = rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '=');

        $this->assertSame($n, $jwk['n']);
        $this->assertSame($e, $jwk['e']);

        $thumbprint = json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], JSON_UNESCAPED_SLASHES);
        $expectedKid = rtrim(strtr(base64_encode(hash('sha256', $thumbprint, true)), '+/', '-_'), '=');

        $this->assertSame($expectedKid, $jwk['kid']);
        $this->assertSame(app(OidcKeyService::class)->kid(), $jwk['kid']);
    }

    public function test_id_token_is_signed_with_published_key_and_carries_claims(): void
    {
        $user = User::factory()->create([
            'username' => 'oidcuser',
            'name_first' => 'Ada',
            'name_last' => 'Lovelace',
            'email' => 'ada@example.com',
            'root_admin' => true,
        ]);

        $jwt = app(IdTokenIssuer::class)->issue($user, '42', ['openid', 'profile', 'email'], 'nonce-value', 1700000000);

        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->keys['private']),
            InMemory::plainText($this->keys['public']),
        );

        // Throws if the signature does not verify against the published public key.
        $config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->keys['public'])));

        $this->assertSame(app(OidcKeyService::class)->kid(), $token->headers()->get('kid'));

        $claims = $token->claims();
        $this->assertSame(rtrim((string) config('app.url'), '/'), $claims->get('iss'));
        $this->assertSame($user->uuid, $claims->get('sub'));
        $this->assertSame(['42'], $claims->get('aud'));
        $this->assertSame('nonce-value', $claims->get('nonce'));
        $this->assertSame(1700000000, $claims->get('auth_time'));
        $this->assertSame('oidcuser', $claims->get('preferred_username'));
        $this->assertSame('Ada Lovelace', $claims->get('name'));
        $this->assertSame(['admin'], $claims->get('groups'));
        $this->assertSame('ada@example.com', $claims->get('email'));
        $this->assertFalse($claims->get('email_verified'));
        $exp = $claims->get('exp');
        $this->assertGreaterThan(time(), $exp instanceof DateTimeInterface ? $exp->getTimestamp() : (int) $exp);
    }

    public function test_id_token_omits_scope_claims_not_granted(): void
    {
        $user = User::factory()->create();

        $jwt = app(IdTokenIssuer::class)->issue($user, '42', ['openid'], null, time());
        $claims = (new Parser(new JoseEncoder()))->parse($jwt)->claims();

        $this->assertFalse($claims->has('nonce'));
        $this->assertFalse($claims->has('email'));
        $this->assertFalse($claims->has('preferred_username'));
    }

    public function test_user_info_returns_claims_for_granted_scopes(): void
    {
        $user = User::factory()->create(['username' => 'infouser', 'root_admin' => false]);

        $this->actingAsOAuthUser($user, ['openid', 'profile', 'email']);

        $this->getJson('/oauth/userinfo')
            ->assertOk()
            ->assertJsonPath('sub', $user->uuid)
            ->assertJsonPath('preferred_username', 'infouser')
            ->assertJsonPath('groups', [])
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('email_verified', false);
    }

    public function test_user_info_omits_profile_and_email_when_not_granted(): void
    {
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, ['openid']);

        $response = $this->getJson('/oauth/userinfo')->assertOk();

        $this->assertSame($user->uuid, $response->json('sub'));
        $this->assertArrayNotHasKey('email', $response->json());
        $this->assertArrayNotHasKey('preferred_username', $response->json());
    }

    public function test_user_info_requires_openid_scope(): void
    {
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, ['profile', 'email']);

        $this->getJson('/oauth/userinfo')
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"');
    }

    public function test_user_info_rejects_request_without_token_with_bearer_challenge(): void
    {
        $this->getJson('/oauth/userinfo')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_user_info_names_an_invalid_token_when_one_was_presented(): void
    {
        $this->withToken('not-a-valid-token')
            ->getJson('/oauth/userinfo')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function test_email_verified_follows_the_extension_setting(): void
    {
        $user = User::factory()->create(['email' => 'verified@example.com']);
        $claims = app(OpenIdConnectClaims::class);

        $this->assertFalse($claims->forUser($user, ['email'])['email_verified']);

        app(ExtensionRepository::class)->settings('ramjet-oauth')->set('email_verified', true);

        $this->assertTrue(app(OpenIdConnectClaims::class)->forUser($user, ['email'])['email_verified']);
    }

    public function test_o_auth_tokens_cannot_access_client_api(): void
    {
        $user = User::factory()->create();

        $this->actingAsOAuthUser($user, ['openid', 'profile', 'email']);

        // The client API authenticates through the Sanctum-backed "api" group, so a token issued by the "oauth"
        // guard must not be accepted there.
        $this->getJson('/api/client/account')->assertUnauthorized();
    }
}
