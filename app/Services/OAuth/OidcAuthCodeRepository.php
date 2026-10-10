<?php

namespace Pterodactyl\Services\OAuth;

use Illuminate\Support\Facades\Cache;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;

/**
 * Stores the OpenID Connect context (nonce and auth_time) alongside each authorization code, so the
 * ID token issued for the code exchange carries the values from the matching authorization request.
 */
class OidcAuthCodeRepository extends PassportAuthCodeRepository
{
    private const TTL_MINUTES = 10;

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);

        $context = app(OidcAuthorizationContext::class)->pending;
        if (is_array($context)) {
            Cache::put(self::key($authCodeEntity->getIdentifier()), $context, now()->addMinutes(self::TTL_MINUTES));
        }
    }

    public function revokeAuthCode(string $codeId): void
    {
        parent::revokeAuthCode($codeId);

        // Called when the code is exchanged, which is the only time it is consumed.
        $context = Cache::pull(self::key($codeId));
        app(OidcAuthorizationContext::class)->exchanged = is_array($context) ? $context : null;
    }

    private static function key(string $codeId): string
    {
        return 'oidc_code:' . $codeId;
    }
}
