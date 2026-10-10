<?php

namespace Pterodactyl\Services\OAuth;

use Pterodactyl\Models\User;

/**
 * Builds the OpenID Connect claims for a user, based on the scopes that were granted.
 * Used for both the ID token and the /oauth/userinfo response.
 */
class OpenIdConnectClaims
{
    public const SCOPES = ['openid', 'profile', 'email'];

    public const CLAIMS = [
        'sub',
        'iss',
        'aud',
        'azp',
        'exp',
        'iat',
        'auth_time',
        'nonce',
        'preferred_username',
        'name',
        'given_name',
        'family_name',
        'groups',
        'email',
        'email_verified',
    ];

    /**
     * The issuer identifier. Must match the "iss" claim in ID tokens and the discovery document.
     */
    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $scopes): array
    {
        $claims = ['sub' => (string) $user->uuid];

        if (in_array('profile', $scopes, true)) {
            $claims += [
                'preferred_username' => $user->username,
                'name' => trim($user->name_first . ' ' . $user->name_last),
                'given_name' => $user->name_first,
                'family_name' => $user->name_last,
                'groups' => $user->root_admin ? ['admin'] : [],
            ];
        }

        if (in_array('email', $scopes, true)) {
            $claims += [
                'email' => $user->email,
                'email_verified' => (bool) config('pterodactyl.oauth.email_verified', false),
            ];
        }

        return $claims;
    }
}
