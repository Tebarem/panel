<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Events\User\Deleting;
use Pterodactyl\Events\User\PasswordChanged;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Tests\OAuthTestCase;

/**
 * A password change or an account deletion revokes the user's OAuth grants to every application.
 */
class RevokeUserTokensTest extends OAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useSigningKeys();
    }

    public function test_password_change_revokes_the_users_tokens_and_no_one_elses(): void
    {
        $changed = User::factory()->create();
        $other = User::factory()->create();

        $this->issueAccessToken($changed, ['openid']);
        $this->issueAccessToken($other, ['openid']);

        event(new PasswordChanged($changed));

        $this->assertTokensRevoked($changed, true);
        $this->assertTokensRevoked($other, false);
    }

    public function test_account_deletion_revokes_the_users_tokens(): void
    {
        $deleted = User::factory()->create();
        $other = User::factory()->create();

        $this->issueAccessToken($deleted, ['openid']);
        $this->issueAccessToken($other, ['openid']);

        event(new Deleting($deleted));

        $this->assertTokensRevoked($deleted, true);
        $this->assertTokensRevoked($other, false);
    }

    public function test_revoked_access_token_is_refused_at_userinfo(): void
    {
        $user = User::factory()->create();
        $token = $this->issueAccessToken($user, ['openid']);

        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk();

        event(new PasswordChanged($user));

        // Each request in a test shares the application, so the guard would keep the user it resolved for the first
        // request. A real request starts with no guard state; forget the guards to match that.
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_listener_is_registered_for_both_events(): void
    {
        $this->assertTrue(Event::hasListeners(PasswordChanged::class));
        $this->assertTrue(Event::hasListeners(Deleting::class));
    }

    /**
     * Every access token of the user is revoked, and so is every refresh token of those access tokens.
     */
    private function assertTokensRevoked(User $user, bool $revoked): void
    {
        $accessTokens = DB::table('oauth_access_tokens')->where('user_id', $user->id)->get();
        $this->assertNotEmpty($accessTokens, 'The user has no access tokens to check.');

        foreach ($accessTokens as $accessToken) {
            $this->assertSame($revoked, (bool) $accessToken->revoked);
        }

        $refreshTokens = DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $accessTokens->pluck('id'))
            ->get();
        $this->assertNotEmpty($refreshTokens);

        foreach ($refreshTokens as $refreshToken) {
            $this->assertSame($revoked, (bool) $refreshToken->revoked);
        }
    }
}
