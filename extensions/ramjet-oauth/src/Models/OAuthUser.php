<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Pterodactyl\Models\Attributes\Identifiable;
use Pterodactyl\Models\User;

/**
 * The panel user as seen by the "oauth" guard.
 *
 * Passport's token guard attaches the access token that authenticated the request with withAccessToken(), which the
 * panel's User model inherits from Sanctum (it stores the token in Sanctum's $accessToken property). The userinfo
 * endpoint reads the granted scopes from that token through oauthAccessToken().
 *
 * PHP class attributes are not inherited, so the ones the panel's User model declares are repeated here: the realtime
 * identifier, hidden attributes and route key. Only the oauth guard uses this class (see RamjetOAuthProvider), so the
 * panel's own User model stays untouched.
 */
#[Identifiable('user')]
#[Hidden(['password', 'remember_token', 'totp_secret', 'totp_authenticated_at'])]
#[RouteKey('uuid')]
class OAuthUser extends User
{
    /** The table name would otherwise be derived from this class name. */
    protected $table = 'users';

    public function oauthAccessToken(): ?ScopeAuthorizable
    {
        return $this->accessToken instanceof ScopeAuthorizable ? $this->accessToken : null;
    }
}
