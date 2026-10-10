<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\OAuth;

use Pterodactyl\Models\User;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Pterodactyl\Services\OAuth\IdTokenIssuer;
use Pterodactyl\Services\OAuth\OidcKeyService;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

class OpenIdControllerTest extends HttpTestCase
{
    private string $privateKey;

    private string $publicKey;

    public function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        // Passport builds its own key objects (token guard, auth server) from the passport.* config, so it needs the
        // same PEMs as the panel's ID token signer.
        config([
            'pterodactyl.oauth.private_key' => $this->privateKey,
            'pterodactyl.oauth.public_key' => $this->publicKey,
            'passport.private_key' => $this->privateKey,
            'passport.public_key' => $this->publicKey,
        ]);
    }

    public function testDiscoveryDocumentDescribesProvider(): void
    {
        $issuer = rtrim(config('app.url'), '/');

        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('issuer', $issuer)
            ->assertJsonPath('authorization_endpoint', $issuer . '/oauth/authorize')
            ->assertJsonPath('token_endpoint', $issuer . '/oauth/token')
            ->assertJsonPath('userinfo_endpoint', $issuer . '/oauth/userinfo')
            ->assertJsonPath('jwks_uri', $issuer . '/oauth/jwks')
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
            ->assertJsonPath('subject_types_supported', ['public'])
            ->assertJsonPath('id_token_signing_alg_values_supported', ['RS256'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('scopes_supported', ['openid', 'profile', 'email']);
    }

    public function testJwksPublishesRsaKeyWithRfc7638Thumbprint(): void
    {
        $response = $this->getJson('/oauth/jwks')->assertOk();

        $jwk = $response->json('keys.0');
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);

        $rsa = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKey))['rsa'];
        $n = rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '=');

        $this->assertSame($n, $jwk['n']);
        $this->assertSame($e, $jwk['e']);

        $thumbprint = json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], JSON_UNESCAPED_SLASHES);
        $expectedKid = rtrim(strtr(base64_encode(hash('sha256', $thumbprint, true)), '+/', '-_'), '=');

        $this->assertSame($expectedKid, $jwk['kid']);
        $this->assertSame(app(OidcKeyService::class)->kid(), $jwk['kid']);
    }

    public function testIdTokenIsSignedWithPublishedKeyAndCarriesClaims(): void
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
            InMemory::plainText($this->privateKey),
            InMemory::plainText($this->publicKey),
        );

        // Throws if the signature does not verify against the published public key.
        $config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->publicKey)));

        $this->assertSame(app(OidcKeyService::class)->kid(), $token->headers()->get('kid'));

        $claims = $token->claims();
        $this->assertSame(rtrim(config('app.url'), '/'), $claims->get('iss'));
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
        $this->assertGreaterThan(time(), $exp instanceof \DateTimeInterface ? $exp->getTimestamp() : (int) $exp);
    }

    public function testIdTokenOmitsScopeClaimsNotGranted(): void
    {
        $user = User::factory()->create();

        $jwt = app(IdTokenIssuer::class)->issue($user, '42', ['openid'], null, time());
        $claims = (new Parser(new JoseEncoder()))->parse($jwt)->claims();

        $this->assertFalse($claims->has('nonce'));
        $this->assertFalse($claims->has('email'));
        $this->assertFalse($claims->has('preferred_username'));
    }

    public function testUserInfoReturnsClaimsForGrantedScopes(): void
    {
        $user = User::factory()->create(['username' => 'infouser', 'root_admin' => false]);

        Passport::actingAs($user, ['openid', 'profile', 'email'], 'oauth');

        $this->getJson('/oauth/userinfo')
            ->assertOk()
            ->assertJsonPath('sub', $user->uuid)
            ->assertJsonPath('preferred_username', 'infouser')
            ->assertJsonPath('groups', [])
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('email_verified', false);
    }

    public function testUserInfoOmitsProfileAndEmailWhenNotGranted(): void
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['openid'], 'oauth');

        $response = $this->getJson('/oauth/userinfo')->assertOk();

        $this->assertSame($user->uuid, $response->json('sub'));
        $this->assertArrayNotHasKey('email', $response->json());
        $this->assertArrayNotHasKey('preferred_username', $response->json());
    }

    public function testUserInfoRequiresOpenidScope(): void
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['profile', 'email'], 'oauth');

        $this->getJson('/oauth/userinfo')
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
    }

    public function testUserInfoRejectsRequestWithoutToken(): void
    {
        $this->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function testOAuthTokensCannotAccessClientApi(): void
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['openid', 'profile', 'email'], 'oauth');

        // The client API authenticates through the Sanctum-backed "api" group, so a token issued by
        // the "oauth" guard must not be accepted there.
        $this->getJson('/api/client/account')->assertUnauthorized();
    }
}
