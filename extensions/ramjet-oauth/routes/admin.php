<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ramjet\OAuth\Http\Controllers\Admin\OAuthClientController;

/*
|--------------------------------------------------------------------------
| Admin API: OAuth applications
|--------------------------------------------------------------------------
|
| Base: /api/admin/extensions/ramjet-oauth (admin-api group, root administrators only).
|
*/

Route::get('/clients', [OAuthClientController::class, 'index'])->name('clients.index');
Route::post('/clients', [OAuthClientController::class, 'store'])->name('clients.store');
Route::patch('/clients/{client}', [OAuthClientController::class, 'update'])->whereUuid('client')->name('clients.update');
Route::post('/clients/{client}/secret', [OAuthClientController::class, 'regenerateSecret'])->whereUuid('client')->name('clients.secret');
Route::post('/clients/{client}/revoke', [OAuthClientController::class, 'revoke'])->whereUuid('client')->name('clients.revoke');
Route::delete('/clients/{client}', [OAuthClientController::class, 'destroy'])->whereUuid('client')->name('clients.destroy');
