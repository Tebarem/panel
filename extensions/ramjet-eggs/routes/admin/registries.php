<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ramjet\Eggs\Registries\Http\Controllers\DockerRegistryController;

// Base: /api/admin/extensions/ramjet-eggs
Route::get('/registries', [DockerRegistryController::class, 'index'])->name('registries.index');
Route::post('/registries', [DockerRegistryController::class, 'store'])->name('registries.store');
Route::patch('/registries/{id}', [DockerRegistryController::class, 'update'])->whereNumber('id')->name('registries.update');
Route::delete('/registries/{id}', [DockerRegistryController::class, 'destroy'])->whereNumber('id')->name('registries.destroy');
