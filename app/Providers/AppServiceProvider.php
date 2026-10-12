<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
        $this->registerAuditLogging();
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

        Event::listen(function (Login $event) {
            AuditLog::record('auth.login', 'Logged in', 'user', $event->user->getAuthIdentifier(), $event->user->email, null, $event->user);
        });

        Event::listen(function (Logout $event) {
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
