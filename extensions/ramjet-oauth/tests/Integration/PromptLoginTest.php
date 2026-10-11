<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Tests\OAuthTestCase;

/**
 * prompt=login signs the user out so that they sign in again. A third party must not be able to trigger that with a
 * request the panel would reject anyway.
 */
class PromptLoginTest extends OAuthTestCase
{
    private const REDIRECT_URI = 'https://rp.example.com/callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Validating an authorization request builds Passport's authorization server, which needs the signing keys.
        $this->useSigningKeys();
    }

    public function test_invalid_request_with_prompt_login_does_not_sign_the_user_out(): void
    {
        $user = User::factory()->create();

        // The client does not exist, so the request is invalid and Passport answers with an error.
        $this->actingAs($user)
            ->get($this->authorizeQuery('unknown-client', 'login'))
            ->assertStatus(401);

        $this->assertAuthenticated('web');
    }

    public function test_valid_request_with_prompt_login_signs_the_user_out_and_sends_them_to_login(): void
    {
        $user = User::factory()->create();
        $client = $this->createClient();

        $url = $this->authorizeQuery($client->id, 'login');

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect('/auth/login?redirect='.rawurlencode($url));

        $this->assertGuest('web');
    }

    private function createClient(): OAuthClient
    {
        $client = new OAuthClient();
        $client->forceFill([
            'name' => 'Prompt test',
            'secret' => Str::random(40),
            'provider' => null,
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => OAuthClient::GRANT_TYPES,
            'revoked' => false,
            'skip_authorization' => false,
        ])->save();

        return $client;
    }

    private function authorizeQuery(string $clientId, string $prompt): string
    {
        return '/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'xyz',
            'code_challenge' => Str::random(43),
            'code_challenge_method' => 'S256',
            'prompt' => $prompt,
        ]);
    }
}
