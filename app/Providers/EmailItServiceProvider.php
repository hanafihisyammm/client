<?php

namespace App\Providers;

use App\Mail\Transport\EmailItTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class EmailItServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->app->afterResolving(MailManager::class, function (MailManager $manager) {
            $manager->extend('emailit', function (array $config) {
                return new EmailItTransport(
                    $config['api_key'] ?? config('services.emailit.api_key'),
                    $config['api_host'] ?? config('services.emailit.api_host'), 
                    $config['api_proto'] ?? config('services.emailit.api_proto'),
                    $config['api_path'] ?? config('services.emailit.api_path')
                );
            });
        });
    }
}
