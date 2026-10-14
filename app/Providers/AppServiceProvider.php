<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\Impersonation;
use App\Support\PersistentLogins;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Never allow a wipe or reset of the production database (migrate:fresh, migrate:refresh, db:wipe ...)
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->registerAuditLogging();

        // "Remember me" ends everywhere else when the password changes (from the settings page,
        // "Forgot password" or an admin), and everywhere when the account is deactivated
        User::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                PersistentLogins::revokeAll($user, except: PersistentLogins::currentFor($user));
            }

            if ($user->wasChanged('deactivated_at') && $user->deactivated_at !== null) {
                PersistentLogins::revokeAll($user);
            }
        });

        // An invited user who sets their password through "Forgot password" has also finished setting up
        Event::listen(function (PasswordReset $event) {
            if ($event->user->email_verified_at === null) {
                $event->user->forceFill(['email_verified_at' => now()])->save();
            }
        });
    }

    /**
     * Record changes to the main records and sign-in activity in the audit log.
     */
    protected function registerAuditLogging(): void
    {
        User::observe(AuditObserver::class);
        Project::observe(AuditObserver::class);
        Report::observe(AuditObserver::class);
        ReportComment::observe(AuditObserver::class);
        Company::observe(AuditObserver::class);

        // Switching to another user and back with "Sign in as" is recorded by
        // ImpersonationController, with the Super Admin as the one who did it
        Event::listen(function (Login $event) {
            if (Impersonation::isActive()) {
                return;
            }

            AuditLog::record('auth.login', 'Logged in', 'user', $event->user->getAuthIdentifier(), $event->user->email, null, $event->user);
        });

        Event::listen(function (Logout $event) {
            // Logging out while signed in as someone ends that, and is the Super Admin's doing
            if ($event->user && ($superAdmin = Impersonation::impersonator())) {
                AuditLog::record(
                    'auth.impersonation_ended',
                    "Logged out while signed in as {$event->user->name} ({$event->user->email})",
                    'user',
                    $event->user->getAuthIdentifier(),
                    $event->user->email,
                    null,
                    $superAdmin
                );

                return;
            }

            if ($event->user) {
                AuditLog::record('auth.logout', 'Logged out', 'user', $event->user->getAuthIdentifier(), $event->user->email, null, $event->user);
            }
        });

        Event::listen(function (Failed $event) {
            $email = (string) ($event->credentials['email'] ?? 'unknown');

            AuditLog::record('auth.failed', 'Failed login attempt for ' . $email, 'user', $event->user?->getAuthIdentifier(), $email);
        });
    }
}
