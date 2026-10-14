<?php

use Illuminate\Foundation\Inspiring;
use App\Models\PersistentLogin;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remembered browsers that have not been used for the idle period (they are refused anyway)
// and alert subscriptions of browsers that stopped opening the app
Schedule::command('model:prune', ['--model' => [PersistentLogin::class, PushSubscription::class]])->daily();
