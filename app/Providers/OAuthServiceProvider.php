<?php

namespace Pterodactyl\Providers;

use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Pterodactyl\Models\OAuthClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Services\OAuth\OidcAuthCodeRepository;
use Pterodactyl\Services\OAuth\OidcBearerTokenResponse;
use Pterodactyl\Services\OAuth\OidcAuthorizationContext;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;

/**
 * Configures the OAuth2 / OpenID Connect provider built on Laravel Passport 13.
 *
 * Only the authorization code grant (with PKCE, S256 only) and refresh tokens are used:
 *  - Passport's own routes are not registered (see routes/oauth.php for the endpoints).
 *  - The device code grant is disabled. Passport keeps client_credentials enabled server-wide; each
 *    client's grant_types column controls whether it may use it, and panel clients are restricted to
 *    authorization_code and refresh_token.
 *  - The password and implicit grants are never enabled.
 */
class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Must run before Passport boots its routes and grants.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;

        // Per-request state that links an authorization request to the code it produces.
        $this->app->scoped(OidcAuthorizationContext::class);
        $this->app->bind(PassportAuthCodeRepository::class, OidcAuthCodeRepository::class);
    }

    public function boot(): void
    {
        Passport::useClientModel(OAuthClient::class);

        Passport::tokensCan([
            'openid' => 'Verify your identity',
            'profile' => 'Your username and name',
            'email' => 'Your email address',
        ]);

        Passport::authorizationView('oauth.authorize');

        // Access tokens are short lived and refreshed; the lifetime of the authorization code is fixed by Passport (10 minutes).
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        // Adds the OpenID Connect id_token to authorization code token responses.
        Passport::useAuthorizationServerResponseType($this->app->make(OidcBearerTokenResponse::class));

        RateLimiter::for('oauth', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip() . '|' . $this->clientIdentifier($request));
        });

        $this->loadRoutesFrom(base_path('routes/oauth.php'));
    }

    /**
     * The client a token or userinfo request is for, from the form body or HTTP Basic credentials. Used
     * only as a rate limiting key, so it is not verified here.
     */
    private function clientIdentifier(Request $request): string
    {
        $clientId = $request->input('client_id');
        if (is_string($clientId) && $clientId !== '') {
            return $clientId;
        }

        $header = (string) $request->header('Authorization');
        if (str_starts_with(strtolower($header), 'basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded)) {
                return explode(':', $decoded, 2)[0];
            }
        }

        return '';
    }
}
