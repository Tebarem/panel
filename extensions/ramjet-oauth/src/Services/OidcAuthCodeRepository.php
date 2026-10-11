<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Stores the OpenID Connect context (nonce and auth_time) alongside each authorization code, so the ID
 * token issued for the code exchange carries the values from the matching authorization request.
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
        return 'ramjet_oauth:oidc_code:'.$codeId;
    }
}
