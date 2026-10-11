<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ramjet\Eggs\Sources\Http\AdminEggSourceController;

// Egg sources. Base: /api/admin/extensions/ramjet-eggs (root administrators only, see RamjetEggsProvider).
Route::get('/sources', [AdminEggSourceController::class, 'index'])->name('sources.index');
Route::post('/sources', [AdminEggSourceController::class, 'store'])->name('sources.store');
Route::patch('/sources/{id}', [AdminEggSourceController::class, 'update'])->whereNumber('id')->name('sources.update');
Route::delete('/sources/{id}', [AdminEggSourceController::class, 'destroy'])->whereNumber('id')->name('sources.destroy');
Route::post('/sources/{id}/sync', [AdminEggSourceController::class, 'sync'])->whereNumber('id')->name('sources.sync');
