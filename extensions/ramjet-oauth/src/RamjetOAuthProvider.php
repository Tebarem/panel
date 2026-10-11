<?php

declare(strict_types=1);

namespace Ramjet\OAuth;

use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Events\User\Deleting;
use Pterodactyl\Events\User\PasswordChanged;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Http\Middleware\EnsureExtensionIsAvailable;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Ramjet\OAuth\Console\KeysCommand;
use Ramjet\OAuth\Http\Controllers\OpenIdController;
use Ramjet\OAuth\Listeners\RecordAuthenticationTime;
use Ramjet\OAuth\Listeners\RevokeUserTokens;
use Ramjet\OAuth\Models\OAuthClient;
use Ramjet\OAuth\Models\OAuthUser;
use Ramjet\OAuth\Services\OidcAuthCodeRepository;
use Ramjet\OAuth\Services\OidcAuthorizationContext;
use Ramjet\OAuth\Services\OidcBearerTokenResponse;
use Ramjet\OAuth\Services\OpenIdConnectClaims;
use Throwable;

/**
 * OAuth 2.0 and OpenID Connect provider on Laravel Passport.
 *
 * Only the authorization code grant (PKCE with S256 only) and refresh tokens are used:
 *  - Passport's own routes are not registered. The endpoints live in routes/root.php under /oauth, plus the
 *    discovery document, which is registered directly because /.well-known is not a claimable root prefix.
 *  - The device code grant is disabled. Passport keeps client_credentials enabled server-wide; every client's
 *    grant_types is authorization_code and refresh_token only, so that grant is refused for them.
 *  - The password and implicit grants are never enabled.
 *
 * Passport is not auto-discovered by the panel (core composer.json marks it dont-discover), so this provider is
 * what boots it, and only while the extension is enabled.
 */
final class RamjetOAuthProvider extends ExtensionProvider
{
    /** Name of the rate limiter for the token and userinfo endpoints. */
    public const RATE_LIMITER = 'ramjet-oauth';

    /** Requests per minute for each client identifier and IP address. */
    private const REQUESTS_PER_MINUTE = 120;

    public function register(): void
    {
        // Must run before Passport boots its routes and grants.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;

        $this->app->register(PassportServiceProvider::class);

        // The "oauth" guard authenticates bearer tokens for /oauth/userinfo. Its users are OAuthUser instances, which
        // can carry the access token that authenticated the request.
        config()->set('auth.providers.ramjet-oauth', ['driver' => 'eloquent', 'model' => OAuthUser::class]);
        config()->set('auth.guards.oauth', ['driver' => 'passport', 'provider' => 'ramjet-oauth']);

        $this->normalisePassportKeys();

        // Per-request state that links an authorization request to the code it produces.
        $this->app->scoped(OidcAuthorizationContext::class);
        $this->app->bind(PassportAuthCodeRepository::class, OidcAuthCodeRepository::class);
    }

    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->loadExtensionViews();

        Passport::useClientModel(OAuthClient::class);

        Passport::tokensCan([
            'openid' => 'Verify your identity',
            'profile' => 'Your username and name',
            'email' => 'Your email address',
        ]);

        Passport::authorizationView('ext-ramjet-oauth::authorize');

        // Access tokens are short lived and refreshed. The lifetime of the authorization code is fixed by Passport (10 minutes).
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        // Adds the OpenID Connect id_token to authorization code token responses.
        Passport::useAuthorizationServerResponseType($this->app->make(OidcBearerTokenResponse::class));

        RateLimiter::for(self::RATE_LIMITER, function (Request $request): Limit {
            return Limit::perMinute(self::REQUESTS_PER_MINUTE)->by($request->ip().'|'.$this->clientIdentifier($request));
        });

        Event::listen(DirectLogin::class, RecordAuthenticationTime::class);

        // A password change or an account deletion ends every OAuth grant the user gave to applications.
        Event::listen([PasswordChanged::class, Deleting::class], RevokeUserTokens::class);

        // Discovery is outside the claimed "oauth" prefix, so it is registered directly. It is gated like the root routes.
        Route::get('/.well-known/openid-configuration', [OpenIdController::class, 'configuration'])
            ->middleware(['throttle:extensions.root', EnsureExtensionIsAvailable::class.':'.$this->id()])
            ->name('extensions.'.$this->id().'.discovery');

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make(OpenIdConnectClaims::EMAIL_VERIFIED_SETTING, OpenIdConnectClaims::EMAIL_VERIFIED_SETTING, false, ['boolean'])
                ->label('Report email addresses as verified')
                ->help('Enable only if every email address on this panel has been verified. Applications then receive email_verified: true for every user.'),
        ]));

        $this->registerRootRoutes($this->extensionPath('routes', 'root.php'));
        $this->registerAdminApiRoutes($this->extensionPath('routes', 'admin.php'));

        $this->registerCommands([KeysCommand::class]);
        $this->registerSchedule(fn (Schedule $schedule) => $schedule->command('passport:purge')->daily());

        $this->keepOAuthErrorsOutOfReports();
    }

    /**
     * Client errors from the protocol (invalid client, invalid grant, ...) are answers to the client, not panel
     * faults. Server errors are still reported.
     */
    private function keepOAuthErrorsOutOfReports(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if ($handler instanceof Handler) {
            $handler->dontReportWhen(fn (Throwable $exception): bool => $exception instanceof LeagueOAuthServerException && $exception->getHttpStatusCode() < 500);
        }
    }

    /**
     * Environment files often store PEM newlines as a literal "\n" sequence. Passport reads the keys as given.
     */
    private function normalisePassportKeys(): void
    {
        foreach (['passport.private_key', 'passport.public_key'] as $key) {
            $value = config($key);

            if (is_string($value) && $value !== '') {
                config()->set($key, str_replace('\\n', "\n", trim($value)));
            }
        }
    }

    /**
     * The client a token or userinfo request is for, from the form body or HTTP Basic credentials. Used only as
     * a rate limiting key, so it is not verified here.
     */
    private function clientIdentifier(Request $request): string
    {
        $clientId = $request->input('client_id');
        if (is_string($clientId) && $clientId !== '') {
            return $clientId;
        }

        $header = (string) $request->header('Authorization');
        if (str_starts_with(mb_strtolower($header), 'basic ')) {
            $decoded = base64_decode(mb_substr($header, 6), true);
            if (is_string($decoded)) {
                return explode(':', $decoded, 2)[0];
            }
        }

        return '';
    }
}
