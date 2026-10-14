<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID keys
    |--------------------------------------------------------------------------
    |
    | Identify this site to the browsers' push services. Generate them once per
    | environment with "php artisan webpush:vapid --show" and keep them: changing
    | them stops every browser's alerts until it turns them on again.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => \App\Models\PushSubscription::class,

    'table_name' => 'push_subscriptions',

    'database_connection' => env('DB_CONNECTION', 'pgsql'),

    'client_options' => [
        'timeout' => 10,
    ],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

    /*
    |--------------------------------------------------------------------------
    | Push services
    |--------------------------------------------------------------------------
    |
    | The server sends each alert to the address the browser gives it, so only the
    | browsers' own push services are accepted (Chrome, Edge, Android and Samsung
    | Internet use Google's; Firefox Mozilla's; Edge on Windows may use Microsoft's;
    | Safari on Mac, iPhone and iPad Apple's). Anything else is refused, so the server
    | can never be made to send requests to an address of someone's choosing.
    |
    */

    'allowed_hosts' => [
        'fcm.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        '*.push.apple.com',
    ],

];
