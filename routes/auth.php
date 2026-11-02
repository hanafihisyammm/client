<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangeTemporaryPasswordController;
use App\Http\Middleware\BlockWhileImpersonating;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // Choosing your own password after logging in with one an admin gave you
    Route::get('change-password', [ChangeTemporaryPasswordController::class, 'show'])
        ->name('password.change');

    Route::put('change-password', [ChangeTemporaryPasswordController::class, 'update'])
        ->middleware(['throttle:6,1', BlockWhileImpersonating::class])
        ->name('password.change.update');
});