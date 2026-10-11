<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Ramjet\OAuth\Tests\OAuthTestCase;

/**
 * Checks the middleware each OAuth route is registered with. The test runner skips CSRF checks, so a request-level
 * test cannot show that a route is protected or exempt; the route definitions are asserted instead.
 */
class RouteMiddlewareTest extends OAuthTestCase
{
    private const PREFIX = 'extensions.ramjet-oauth.root.oauth.';

    private const PANEL_THROTTLE = 'throttle:extensions.root';

    private const OAUTH_THROTTLE = 'throttle:ramjet-oauth';

    /** Clients post here without a session, so the CSRF check must not run for them. */
    public function test_token_and_userinfo_are_exempt_from_csrf(): void
    {
        foreach (['token', 'userinfo'] as $name) {
            $this->assertNotContains(PreventRequestForgery::class, $this->resolvedMiddleware($name), "{$name} must not run the CSRF check.");
        }
    }

    /** The consent screen's approve and deny forms are browser forms, so their CSRF check must run. */
    public function test_approve_and_deny_are_protected_by_csrf(): void
    {
        foreach (['authorize.approve', 'authorize.deny'] as $name) {
            $this->assertContains(PreventRequestForgery::class, $this->resolvedMiddleware($name), "{$name} must run the CSRF check.");
        }
    }

    /** Public, read-only and bearer-authenticated endpoints are limited per client by the extension, not by the panel-wide root limiter. */
    public function test_token_userinfo_and_jwks_use_the_extension_limiter_instead_of_the_root_limiter(): void
    {
        foreach (['token', 'userinfo', 'jwks'] as $name) {
            $this->assertSame([], $this->limiters($name, 'extensions.root'), "{$name} must not use the panel-wide root limiter.");
        }

        foreach (['token', 'userinfo', 'jwks'] as $name) {
            $this->assertCount(1, $this->limiters($name, 'ramjet-oauth'), "{$name} must use the extension limiter.");
        }
    }

    /** The authorization endpoint keeps the panel-wide limiter, as every root route does. */
    public function test_authorize_keeps_the_root_limiter(): void
    {
        $this->assertCount(1, $this->limiters('authorize', 'extensions.root'));
    }

    /**
     * The throttle middleware of the route that names the given limiter. Excluded middleware is removed when the
     * route's middleware is resolved, which is what runs, so the resolved list is what is checked.
     *
     * @return string[]
     */
    private function limiters(string $name, string $limiter): array
    {
        return array_values(array_filter(
            $this->resolvedMiddleware($name),
            fn (string $middleware): bool => str_contains($middleware, 'ThrottleRequests') && str_ends_with($middleware, ':'.$limiter),
        ));
    }

    private function route(string $name): Route
    {
        $route = app('router')->getRoutes()->getByName(self::PREFIX.$name);

        $this->assertInstanceOf(Route::class, $route, "The route {$name} is not registered.");

        return $route;
    }

    /**
     * @return string[] the middleware the route runs, with route groups expanded to the classes they contain
     */
    private function resolvedMiddleware(string $name): array
    {
        return app('router')->gatherRouteMiddleware($this->route($name));
    }
}
