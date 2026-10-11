<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Listeners\RecordAuthenticationTime;
use Ramjet\OAuth\Tests\OAuthTestCase;

class RecordAuthenticationTimeTest extends OAuthTestCase
{
    public function test_direct_login_records_the_time_of_sign_in(): void
    {
        $user = User::factory()->create();
        $before = now()->getTimestamp();

        // The listener is registered by the extension's provider.
        $this->assertTrue(Event::hasListeners(DirectLogin::class));

        Event::dispatch(new DirectLogin($user, true));

        $recorded = session()->get(RecordAuthenticationTime::SESSION_KEY);
        $this->assertIsInt($recorded);
        $this->assertGreaterThanOrEqual($before, $recorded);
        $this->assertLessThanOrEqual(now()->getTimestamp(), $recorded);
    }
}
