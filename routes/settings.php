<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // A Super Admin signed in as someone else must not change or delete that person's account
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(BlockWhileImpersonating::class)
        ->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware(BlockWhileImpersonating::class)
        ->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware(['throttle:6,1', BlockWhileImpersonating::class])
        ->name('user-password.update');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
    // A Super Admin signed in as someone else must not change that person's saved theme
    Route::patch('settings/appearance', [AppearanceController::class, 'update'])
        ->middleware(BlockWhileImpersonating::class)
        ->name('appearance.update');
});
