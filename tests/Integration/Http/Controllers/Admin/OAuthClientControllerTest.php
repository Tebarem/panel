<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\OAuthClient;
use PHPUnit\Framework\Attributes\TestWith;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Pterodactyl\Http\Controllers\Admin\OAuthClientController;

class OAuthClientControllerTest extends HttpTestCase
{
    public function testAdminCanCreateConfidentialClientAndSecretIsShownOnce(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.oauth.store'), [
                'name' => 'Grafana',
                'redirect_uris' => "https://grafana.example.com/login/generic_oauth\nhttps://grafana-2.example.com/cb",
                'confidential' => '1',
            ])
            ->assertSessionHas(OAuthClientController::SECRET_FLASH_KEY);

        $client = OAuthClient::query()->where('name', 'Grafana')->firstOrFail();

        $this->assertTrue($client->isConfidential());
        $this->assertFalse($client->skipsAuthorization(User::factory()->make(), []));
        $this->assertSame(
            ['https://grafana.example.com/login/generic_oauth', 'https://grafana-2.example.com/cb'],
            $client->redirectUriList(),
        );
        $this->assertSame(OAuthClient::GRANT_TYPES, $client->grant_types);
    }

    public function testPublicClientHasNoSecretAndCanSkipConsent(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.oauth.store'), [
                'name' => 'Native App',
                'redirect_uris' => 'com.example.app:/callback',
                'skip_authorization' => '1',
            ])
            ->assertRedirect()
            ->assertSessionMissing(OAuthClientController::SECRET_FLASH_KEY);

        $client = OAuthClient::query()->where('name', 'Native App')->firstOrFail();

        $this->assertFalse($client->isConfidential());
        $this->assertTrue($client->skipsAuthorization(User::factory()->make(), []));
        $this->assertSame(OAuthClient::GRANT_TYPES, $client->grant_types);
    }

    #[TestWith(['https://example.com/cb#fragment'])]
    #[TestWith(['not a url'])]
    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['http://example.com/callback'])]
    public function testInvalidRedirectUrisAreRejected(string $uri): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->withHeaders(['Accept' => 'text/html'])
            ->post(route('admin.oauth.store'), [
                'name' => 'Bad',
                'redirect_uris' => $uri,
                'confidential' => '1',
            ])
            ->assertSessionHasErrors('redirect_uris');

        $this->assertDatabaseMissing('oauth_clients', ['name' => 'Bad']);
    }

    public function testRegeneratingSecretChangesItAndRevokesTokens(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.oauth.store'), [
            'name' => 'Rotate',
            'redirect_uris' => 'https://rotate.example.com/cb',
            'confidential' => '1',
        ]);

        $client = OAuthClient::query()->where('name', 'Rotate')->firstOrFail();
        $original = $client->secret;

        // One access token, its refresh token and an unused authorization code, all issued to this client.
        $accessTokenId = Str::random(80);
        $refreshTokenId = Str::random(80);
        $authCodeId = Str::random(80);

        DB::table('oauth_access_tokens')->insert([
            'id' => $accessTokenId,
            'user_id' => $admin->id,
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
            'user_id' => $admin->id,
            'client_id' => $client->id,
            'scopes' => '["openid"]',
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.oauth.regenerate', $client))
            ->assertSessionHas(OAuthClientController::SECRET_FLASH_KEY);

        $this->assertNotSame($original, $client->refresh()->secret);

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $accessTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->where('id', $authCodeId)->value('revoked'));
    }

    public function testNonAdminCannotManageOAuthApps(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.oauth'))
            ->assertForbidden();
    }
}
