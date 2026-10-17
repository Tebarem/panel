<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ramjet\Theme\Http\EmailAssetController;

// Mounted at /extensions/ramjet-theme (RamjetThemeProvider::boot). Public, no session: email clients fetch these anonymously.
Route::get('email/{asset}', EmailAssetController::class)
    ->whereIn('asset', EmailAssetController::ASSETS)
    ->name('email.asset');
