<?php

namespace Pterodactyl\Http\Controllers\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\Events\Failed;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Event;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

abstract class AbstractLoginController extends Controller
{
    use AuthenticatesUsers;

    protected AuthManager $auth;

    /**
     * Lockout time for failed login requests.
     */
    protected int $lockoutTime;

    /**
     * After how many attempts should logins be throttled and locked.
     */
    protected int $maxLoginAttempts;

    /**
     * Where to redirect users after login / registration.
     */
    protected string $redirectTo = '/';

    /**
     * LoginController constructor.
     */
    public function __construct()
    {
        $this->lockoutTime = config('auth.lockout.time');
        $this->maxLoginAttempts = config('auth.lockout.attempts');
        $this->auth = Container::getInstance()->make(AuthManager::class);
    }

    /**
     * Get the failed login response instance.
     *
     * @return never-return
     *
     * @throws DisplayException
     */
    protected function sendFailedLoginResponse(Request $request, ?Authenticatable $user = null, ?string $message = null)
    {
        $this->incrementLoginAttempts($request);
        $this->fireFailedLoginEvent($user, [
            $this->getField($request->input('user')) => $request->input('user'),
        ]);

        if ($request->route()->named('auth.login-checkpoint')) {
            throw new DisplayException($message ?? trans('auth.two_factor.checkpoint_failed'));
        }

        throw new DisplayException(trans('auth.failed'));
    }

    /**
     * Send the response after the user was authenticated.
     */
    protected function sendLoginResponse(User $user, Request $request): JsonResponse
    {
        $request->session()->remove('auth_confirmation_token');
        $request->session()->regenerate();

        $this->clearLoginAttempts($request);

        $this->auth->guard()->login($user, true);

        // Used as "auth_time" when an OpenID Connect ID token is issued for this session.
        $request->session()->put('auth.authenticated_at', CarbonImmutable::now()->getTimestamp());

        Event::dispatch(new DirectLogin($user, true));

        return new JsonResponse([
            'data' => [
                'complete' => true,
                'intended' => $this->resolveIntendedUrl($request),
                'user' => $user->toVueObject(),
            ],
        ]);
    }

    /**
     * Returns the URL to send the user to after signing in. This is the URL that was intended before the
     * login redirect (e.g. an /oauth/authorize request). It is only honoured when it points at this panel.
     *
     * An absolute URL is accepted when its host and port match APP_URL, ignoring the scheme (a TLS-terminating
     * proxy may report http:// for an https:// panel). Only its path and query are returned, so the browser
     * always stays on this origin. Anything else falls back to the dashboard.
     */
    protected function resolveIntendedUrl(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        if (!is_string($intended) || $intended === '') {
            return '/';
        }

        // Backslashes, whitespace and control characters have no place in a URL we send the browser to.
        if (str_contains($intended, '\\') || preg_match('/[^\x21-\x7E]/', $intended) === 1) {
            return '/';
        }

        // Path-only values stay on this host. "//host" would be protocol-relative, so it is rejected.
        if (str_starts_with($intended, '/')) {
            return str_starts_with($intended, '//') ? '/' : $intended;
        }

        $target = parse_url($intended);
        $app = parse_url((string) config('app.url'));

        if (!is_array($target) || !is_array($app) || !isset($target['scheme'], $target['host'], $app['host'])) {
            return '/';
        }

        if (!in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
            return '/';
        }

        if (strtolower($target['host']) !== strtolower($app['host']) || ($target['port'] ?? null) !== ($app['port'] ?? null)) {
            return '/';
        }

        $path = $target['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path . (isset($target['query']) ? '?' . $target['query'] : '');
    }

    /**
     * Determine if the user is logging in using an email or username.
     */
    protected function getField(?string $input = null): string
    {
        return ($input && str_contains($input, '@')) ? 'email' : 'username';
    }

    /**
     * Fire a failed login event.
     */
    protected function fireFailedLoginEvent(?Authenticatable $user = null, array $credentials = [])
    {
        Event::dispatch(new Failed('auth', $user, $credentials));
    }
}
