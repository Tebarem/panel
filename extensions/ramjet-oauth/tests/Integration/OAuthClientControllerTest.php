<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Tests\OAuthTestCase;

/**
 * Admin API for OAuth applications: /api/admin/extensions/ramjet-oauth/clients.
 */
class OAuthClientControllerTest extends OAuthTestCase
{
    private const GRAFANA_URI = 'https://grafana.example.com/login/generic_oauth';

    public function test_admin_can_create_confidential_client_and_secret_is_returned_once(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson($this->adminRoute('clients.store'), [
            'name' => 'Grafana',
            'redirect_uris' => [self::GRAFANA_URI, 'https://grafana-2.example.com/cb'],
            'confidential' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Grafana')
            ->assertJsonPath('data.confidential', true)
            ->assertJsonPath('data.skip_authorization', false)
            ->assertJsonPath('data.revoked', false)
            ->assertJsonPath('data.redirect_uris', [self::GRAFANA_URI, 'https://grafana-2.example.com/cb']);

        $secret = $response->json('data.secret');
        $this->assertIsString($secret);
        $this->assertNotEmpty($secret);

        $client = OAuthClient::query()->where('name', 'Grafana')->firstOrFail();
        $this->assertTrue($client->isConfidential());
        $this->assertTrue(Hash::check($secret, $client->secret), 'The returned secret must match the stored hash.');
        $this->assertNotSame($secret, $client->secret, 'The secret must be stored hashed, not in plain text.');
        $this->assertSame(OAuthClient::GRANT_TYPES, $client->grant_types);

        // Later reads never include the secret.
        $this->actingAs($admin)->getJson($this->adminRoute('clients.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.secret');
    }

    public function test_public_client_has_no_secret_and_can_skip_consent(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson($this->adminRoute('clients.store'), [
            'name' => 'Native App',
            'redirect_uris' => ['com.example.app:/callback'],
            'confidential' => false,
            'skip_authorization' => true,
        ])->assertCreated()
            ->assertJsonPath('data.secret', null)
            ->assertJsonPath('data.confidential', false)
            ->assertJsonPath('data.skip_authorization', true);

        $client = OAuthClient::query()->where('name', 'Native App')->firstOrFail();
        $this->assertFalse($client->isConfidential());
        $this->assertTrue($client->skipsAuthorization(User::factory()->make(), []));
    }

    public function test_validation_errors_use_the_standard_error_format(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson($this->adminRoute('clients.store'), [
            'name' => 'Bad',
            'redirect_uris' => ['https://example.com/cb#fragment'],
        ])->assertUnprocessable();

        $error = collect($response->json('errors'))->firstWhere('meta.source_field', 'redirect_uris.0');
        $this->assertNotNull($error, 'The error must name the offending redirect URI field.');
        $this->assertIsString($error['detail']);

        $this->assertDatabaseMissing('oauth_clients', ['name' => 'Bad']);
    }

    #[TestWith(['https://example.com/cb#fragment'])]
    #[TestWith(['not a url'])]
    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['data:text/html,hi'])]
    #[TestWith(['file:///etc/passwd'])]
    #[TestWith(['http://example.com/callback'])]
    public function test_invalid_redirect_uris_are_rejected(string $uri): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson($this->adminRoute('clients.store'), [
                'name' => 'Bad',
                'redirect_uris' => [$uri],
                'confidential' => true,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('oauth_clients', ['name' => 'Bad']);
    }

    public function test_update_changes_name_redirect_uris_and_consent_without_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->createClient('Old name', true);

        $this->actingAs($admin)->patchJson($this->adminRoute('clients.update', ['client' => $client->id]), [
            'name' => 'New name',
            'redirect_uris' => ['https://new.example.com/cb'],
            'skip_authorization' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.redirect_uris', ['https://new.example.com/cb'])
            ->assertJsonPath('data.skip_authorization', true)
            ->assertJsonMissingPath('data.secret');

        $client->refresh();
        $this->assertSame('New name', $client->name);
        $this->assertSame(['https://new.example.com/cb'], $client->redirectUriList());
        $this->assertTrue($client->skipsAuthorization(User::factory()->make(), []));
    }

    public function test_regenerating_secret_changes_it_and_revokes_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->createClient('Rotate', true);
        $original = $client->secret;

        // One access token, its refresh token and an unused authorization code, all issued to this client.
        [$accessTokenId, $refreshTokenId, $authCodeId] = $this->issueTokens($admin, $client);

        $response = $this->actingAs($admin)
            ->postJson($this->adminRoute('clients.secret', ['client' => $client->id]))
            ->assertOk();

        $newSecret = $response->json('data.secret');
        $this->assertIsString($newSecret);
        $this->assertNotSame($original, $client->refresh()->secret);
        $this->assertTrue(Hash::check($newSecret, $client->secret));

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $accessTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->where('id', $authCodeId)->value('revoked'));
    }

    public function test_regenerating_secret_of_public_client_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->createClient('Public', false);

        $this->actingAs($admin)
            ->postJson($this->adminRoute('clients.secret', ['client' => $client->id]))
            ->assertStatus(400);
    }

    public function test_revoke_revokes_tokens_but_keeps_the_client_active(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->createClient('Revoke', true);
        [$accessTokenId, $refreshTokenId, $authCodeId] = $this->issueTokens($admin, $client);

        $this->actingAs($admin)
            ->postJson($this->adminRoute('clients.revoke', ['client' => $client->id]))
            ->assertNoContent();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $accessTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->where('id', $authCodeId)->value('revoked'));

        // The application itself is not revoked.
        $this->assertFalse((bool) $client->refresh()->revoked);
    }

    public function test_delete_removes_client_and_revokes_everything_issued_to_it(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->createClient('Delete me', true);
        [$accessTokenId] = $this->issueTokens($admin, $client);

        $this->actingAs($admin)
            ->deleteJson($this->adminRoute('clients.destroy', ['client' => $client->id]))
            ->assertNoContent();

        $this->assertDatabaseMissing('oauth_clients', ['id' => $client->id]);
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $accessTokenId)->value('revoked'));
    }

    public function test_non_admin_cannot_manage_o_auth_apps(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($this->adminRoute('clients.index'))
            ->assertForbidden();
    }

    private function adminRoute(string $name, array $parameters = []): string
    {
        return route('extensions.ramjet-oauth.admin.'.$name, $parameters);
    }

    private function createClient(string $name, bool $confidential): OAuthClient
    {
        $client = new OAuthClient();
        $client->forceFill([
            'name' => $name,
            'secret' => $confidential ? Str::random(40) : null,
            'provider' => null,
            'redirect_uris' => [self::GRAFANA_URI],
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => false,
        ])->save();

        return $client;
    }

    /**
     * @return array{0: string, 1: string, 2: string} ids of the access token, refresh token and authorization code
     */
    private function issueTokens(User $user, OAuthClient $client): array
    {
        $accessTokenId = Str::random(80);
        $refreshTokenId = Str::random(80);
        $authCodeId = Str::random(80);

        DB::table('oauth_access_tokens')->insert([
            'id' => $accessTokenId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => '["openid"]',
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => $refreshTokenId,
            'access_token_id' => $accessTokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        DB::table('oauth_auth_codes')->insert([
            'id' => $authCodeId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => '["openid"]',
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        return [$accessTokenId, $refreshTokenId, $authCodeId];
    }
}
