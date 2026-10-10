<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Auth;

use Pterodactyl\Models\User;
use PHPUnit\Framework\Attributes\TestWith;
use Pterodactyl\Http\Middleware\VerifyReCaptcha;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

class LoginIntendedUrlTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // The same-origin check compares the host and port against APP_URL.
        config(['app.url' => 'http://localhost']);
    }

    /**
     * After a successful login the SPA navigates to the "intended" URL returned by the server. That URL comes from
     * the session (set by the redirect to login for an unauthenticated request, e.g. /oauth/authorize). Absolute
     * URLs are reduced to their path and query, and only when host and port match APP_URL (scheme is ignored).
     */
    #[TestWith(['http://localhost/oauth/authorize?client_id=1&response_type=code', '/oauth/authorize?client_id=1&response_type=code'])]
    #[TestWith(['https://localhost/oauth/authorize?client_id=1', '/oauth/authorize?client_id=1'])]
    #[TestWith(['/oauth/authorize?client_id=1', '/oauth/authorize?client_id=1'])]
    #[TestWith(['https://evil.example.com/steal', '/'])]
    #[TestWith(['http://localhost:8080/oauth/authorize', '/'])]
    #[TestWith(['//evil.example.com/steal', '/'])]
    #[TestWith(['javascript:alert(1)', '/'])]
    #[TestWith(['http://localhost\\evil.example.com/steal', '/'])]
    public function testIntendedUrlIsOnlyHonouredForSameOrigin(string $sessionValue, string $expected): void
    {
        $user = User::factory()->create([
            'use_totp' => false,
            'password' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
        ]);

        $this->withoutMiddleware(VerifyReCaptcha::class)
            ->withSession(['url.intended' => $sessionValue])
            ->postJson('/auth/login', [
                'user' => $user->username,
                'password' => 'correct-horse-battery',
            ])
            ->assertOk()
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.intended', $expected);
    }

    public function testIntendedUrlIsConsumedOnLogin(): void
    {
        $user = User::factory()->create([
            'use_totp' => false,
            'password' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
        ]);

        $this->withoutMiddleware(VerifyReCaptcha::class)
            ->withSession(['url.intended' => 'http://localhost/oauth/authorize?client_id=1'])
            ->postJson('/auth/login', [
                'user' => $user->username,
                'password' => 'correct-horse-battery',
            ])
            ->assertSessionMissing('url.intended');
    }
}
