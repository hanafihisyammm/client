<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    
    // User Management (Super Admin & Admin only)
    Route::resource('users', App\Http\Controllers\UserController::class);
    Route::post('/users/{user}/invitation', [App\Http\Controllers\UserController::class, 'resendInvitation'])->name('users.invitation.resend');
    Route::post('/users/{user}/deactivate', [App\Http\Controllers\UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/reactivate', [App\Http\Controllers\UserController::class, 'reactivate'])->name('users.reactivate');

    // Client companies: admins manage all of them, a manager can open only their own
    Route::get('/my-company', [App\Http\Controllers\CompanyController::class, 'mine'])->name('clients.mine');
    Route::get('/clients', [App\Http\Controllers\CompanyController::class, 'index'])->name('clients.index');
    Route::post('/clients', [App\Http\Controllers\CompanyController::class, 'store'])->name('clients.store');
    Route::get('/clients/{company}', [App\Http\Controllers\CompanyController::class, 'show'])->name('clients.show');
    Route::patch('/clients/{company}', [App\Http\Controllers\CompanyController::class, 'update'])->name('clients.update');

    // Staff of a client company, managed by its manager or an admin
    Route::post('/clients/{company}/staff', [App\Http\Controllers\StaffController::class, 'store'])->name('clients.staff.store');
    Route::patch('/clients/{company}/staff/{user}', [App\Http\Controllers\StaffController::class, 'update'])->name('clients.staff.update');
    Route::post('/clients/{company}/staff/{user}/deactivate', [App\Http\Controllers\StaffController::class, 'deactivate'])->name('clients.staff.deactivate');
    Route::post('/clients/{company}/staff/{user}/reactivate', [App\Http\Controllers\StaffController::class, 'reactivate'])->name('clients.staff.reactivate');
    Route::post('/clients/{company}/staff/{user}/invitation', [App\Http\Controllers\StaffController::class, 'resendInvitation'])->name('clients.staff.invitation');
    
    // Project Management (Super Admin & Admin only)
    Route::resource('projects', App\Http\Controllers\ProjectController::class)->except(['edit', 'update']);
    Route::get('/projects/view/{uuid}', [App\Http\Controllers\ProjectController::class, 'showByUuid'])->name('projects.view');
    Route::get('/projects/{uuid}/edit', [App\Http\Controllers\ProjectController::class, 'editByUuid'])->name('projects.edit.uuid');
    Route::patch('/projects/{uuid}/update', [App\Http\Controllers\ProjectController::class, 'updateByUuid'])->name('projects.update.uuid');
    
    // Audit Log (Super Admin & Admin only)
    Route::get('/audit-logs', [App\Http\Controllers\AuditLogController::class, 'index'])->name('audit-logs.index');

    // Report routes
    Route::get('/reports/create', [App\Http\Controllers\ReportViewController::class, 'create'])->name('reports.create');
    Route::get('/reports/{report}', [App\Http\Controllers\ReportViewController::class, 'show'])->name('reports.show');
    // Route::get('/reports/{report}/history', [App\Http\Controllers\ReportViewController::class, 'history'])->name('reports.history'); // Removed - activity timeline is now shown inline
});

// Invitation links from the "set up your account" email (signed, valid for a limited time)
Route::middleware(['guest', 'signed:relative'])->group(function () {
    Route::get('/invitation/{user}', [App\Http\Controllers\Auth\InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{user}', [App\Http\Controllers\Auth\InvitationController::class, 'store'])->name('invitation.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
