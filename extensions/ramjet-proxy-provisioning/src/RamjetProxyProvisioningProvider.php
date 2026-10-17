<?php

declare(strict_types=1);

namespace Ramjet\ProxyProvisioning;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Extensions\ExtensionProvider;

/**
 * Entry point of the ramjet-proxy-provisioning extension: the customer endpoints, the admin settings and the
 * rate limit on creation. The dashboard card is a client bundle registered from src/client.
 */
final class RamjetProxyProvisioningProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // routes/client.php -> /api/client/extensions/ramjet-proxy-provisioning
        $this->registerClientApiRoutes($this->extensionPath('routes', 'client.php'));

        $this->registerSettings(ProxySettings::definition($this->settings()));

        // Three creations a minute per customer. Guests never reach it: the route is authenticated first.
        RateLimiter::for(ProxyProvisioner::RATE_LIMITER, function (Request $request): Limit {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(3)->by('ramjet-proxy-create:'.$key);
        });
    }
}
