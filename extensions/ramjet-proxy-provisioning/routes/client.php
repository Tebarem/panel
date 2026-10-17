<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ramjet\ProxyProvisioning\Http\Controllers\ProxyController;
use Ramjet\ProxyProvisioning\ProxyProvisioner;

// Base: /api/client/extensions/ramjet-proxy-provisioning (authenticated customers, see RamjetProxyProvisioningProvider).
Route::get('status', [ProxyController::class, 'status'])->name('status');

Route::post('create', [ProxyController::class, 'create'])
    ->middleware('throttle:'.ProxyProvisioner::RATE_LIMITER)
    ->name('create');
