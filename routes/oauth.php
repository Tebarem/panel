<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\OAuth\OpenIdController;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Middleware\OAuth\ResumeAuthorizationContext;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Pterodactyl\Http\Middleware\OAuth\CaptureAuthorizationContext;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;

/*
|--------------------------------------------------------------------------
| OAuth2 / OpenID Connect Provider Routes
|--------------------------------------------------------------------------
|
| Loaded by OAuthServiceProvider. Only these endpoints are exposed; Passport's
| client management and personal access token routes are intentionally absent.
|
*/

// OpenID Connect discovery and public keys.
Route::get('/.well-known/openid-configuration', [OpenIdController::class, 'configuration'])
    ->name('oauth.discovery');

Route::get('/oauth/jwks', [OpenIdController::class, 'jwks'])
    ->name('oauth.jwks');

// Authorization endpoint. Runs on the panel's session (web guard). No "auth" middleware here: Passport
// handles guests itself (throws an authentication exception that sends them to the login page with
// url.intended set to this URL, and returns login_required for prompt=none).
Route::middleware(['web', 'auth.session', RequireTwoFactorAuthentication::class, CaptureAuthorizationContext::class])
    ->get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
    ->name('passport.authorizations.authorize');

Route::middleware(['web', 'auth.session', 'auth', RequireTwoFactorAuthentication::class, ResumeAuthorizationContext::class])->group(function () {
    Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
        ->name('passport.authorizations.approve');

    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])
        ->name('passport.authorizations.deny');
});

// Token endpoint. Not in the "web" group, so no session or CSRF token is involved; clients
// authenticate with their client credentials or PKCE verifier.
Route::middleware(['throttle:oauth'])
    ->post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->name('passport.token');

// UserInfo endpoint. Bearer token only, validated by the "oauth" (Passport) guard.
Route::middleware(['throttle:oauth', 'auth:oauth'])
    ->match(['get', 'post'], '/oauth/userinfo', [OpenIdController::class, 'userInfo'])
    ->name('oauth.userinfo');
