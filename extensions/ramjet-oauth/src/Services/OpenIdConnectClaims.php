<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionRepository;

/**
 * Builds the OpenID Connect claims for a user, based on the granted scopes. Used for both the ID token and
 * the /oauth/userinfo response.
 */
class OpenIdConnectClaims
{
    public const SCOPES = ['openid', 'profile', 'email'];

    /** The extension's id, which owns the settings below. */
    public const EXTENSION_ID = 'ramjet-oauth';

    /** Typed boolean setting in the admin extension settings: report every email address as verified. */
    public const EMAIL_VERIFIED_SETTING = 'email_verified';

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

    public function __construct(private readonly ExtensionRepository $extensions) {}

    /**
     * The issuer identifier. Must match the "iss" claim in ID tokens and the discovery document.
     */
    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  string[]  $scopes
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $scopes): array
    {
        $claims = ['sub' => (string) $user->uuid];

        if (in_array('profile', $scopes, true)) {
            $claims += [
                'preferred_username' => $user->username,
                'name' => trim($user->name_first.' '.$user->name_last),
                'given_name' => $user->name_first,
                'family_name' => $user->name_last,
                'groups' => $user->root_admin ? ['admin'] : [],
            ];
        }

        if (in_array('email', $scopes, true)) {
            $claims += [
                'email' => $user->email,
                'email_verified' => $this->emailVerified(),
            ];
        }

        return $claims;
    }

    /**
     * Read at the time of the request, from the extension's settings. Not from the environment: configuration can be
     * cached, and a value read from the environment would then be lost.
     */
    private function emailVerified(): bool
    {
        return (bool) $this->extensions->settings(self::EXTENSION_ID)->get(self::EMAIL_VERIFIED_SETTING, false);
    }
}
