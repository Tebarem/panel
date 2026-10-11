<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Ramjet\OAuth\Http\Controllers\OpenIdController;
use Ramjet\OAuth\Http\Middleware\CaptureAuthorizationContext;
use Ramjet\OAuth\Http\Middleware\ResumeAuthorizationContext;

/*
|--------------------------------------------------------------------------
| OAuth 2.0 / OpenID Connect endpoints
|--------------------------------------------------------------------------
|
| Mounted at /oauth by RamjetOAuthProvider::registerRootRoutes(): web group, throttled by the panel's
| extensions.root limiter, and unavailable while the extension is disabled. Names are prefixed with
| extensions.ramjet-oauth.root.oauth.
|
*/

// Authorization endpoint. Guests and users Passport asks to sign in again are sent to the login page; the
// consent screen is shown otherwise. Passport handles prompt=none itself.
Route::get('/authorize', [AuthorizationController::class, 'authorize'])
    ->middleware(['auth.session', RequireTwoFactorAuthentication::class, CaptureAuthorizationContext::class])
    ->name('authorize');

// Approve and deny buttons on the consent screen. CSRF protected by the web group, as every panel form is.
Route::middleware(['auth.session', 'auth', RequireTwoFactorAuthentication::class, ResumeAuthorizationContext::class])
    ->group(function (): void {
        Route::post('/authorize', [ApproveAuthorizationController::class, 'approve'])->name('authorize.approve');
        Route::delete('/authorize', [DenyAuthorizationController::class, 'deny'])->name('authorize.deny');
    });

// Token endpoint. Clients authenticate with their client secret or PKCE verifier, not with a session, so the CSRF
// check does not apply to it. The panel-wide root limiter is replaced by the extension's own per-client limiter.
Route::post('/token', [AccessTokenController::class, 'issueToken'])
    ->withoutMiddleware([PreventRequestForgery::class, 'throttle:extensions.root'])
    ->middleware(['throttle:ramjet-oauth'])
    ->name('token');

// Public keys for verifying ID tokens and access tokens. Read-only, so no CSRF check.
Route::get('/jwks', [OpenIdController::class, 'jwks'])
    ->withoutMiddleware(['throttle:extensions.root'])
    ->middleware(['throttle:ramjet-oauth'])
    ->name('jwks');

// Claims for the holder of an access token that includes the openid scope. Bearer-authenticated, so the CSRF check
// does not apply: an API client posting here has no session cookie to protect.
Route::match(['get', 'post'], '/userinfo', [OpenIdController::class, 'userInfo'])
    ->withoutMiddleware([PreventRequestForgery::class, 'throttle:extensions.root'])
    ->middleware(['throttle:ramjet-oauth'])
    ->name('userinfo');
