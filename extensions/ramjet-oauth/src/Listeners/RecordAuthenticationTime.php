<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Listeners;

use Carbon\CarbonImmutable;
use Pterodactyl\Events\Auth\DirectLogin;

/**
 * Stores the time of the most recent sign in in the session. It becomes "auth_time" in OpenID Connect ID tokens
 * issued for that session. DirectLogin is dispatched after the session is regenerated, once the user is signed in
 * (including after the two-factor checkpoint).
 */
class RecordAuthenticationTime
{
    public const SESSION_KEY = 'ramjet_oauth.auth_time';

    public function handle(DirectLogin $event): void
    {
        session()->put(self::SESSION_KEY, CarbonImmutable::now()->getTimestamp());
    }
}
