<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-timezone" content="{{ config('app.business_timezone') }}">
        <meta name="color-scheme" content="light dark">
        {{-- Installable as an app (see public/manifest.webmanifest and public/sw.js). The colour of
             the app's title bar follows the theme; the script below switches it for dark. --}}
        <meta name="theme-color" content="{{ ($appearance ?? 'system') == 'dark' ? '#0e1116' : '#f7f7f4' }}">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Sidra Desk') }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        {{-- The theme is decided before anything is drawn, so the page never flashes the wrong
             colours. "dark" is already on <html> from the server; "system" needs the browser. --}}
        <script>
            (function() {
                var root = document.documentElement;

                if (root.dataset.appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    root.classList.add('dark');
                    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0e1116');
                }
            })();
        </script>

        {{-- The page background and the browser's own controls (scrollbars, date pickers,
             autofill) before the stylesheet has loaded. Same colours as app.css. --}}
        <style>
            html {
                background-color: #f7f7f4;
                color-scheme: light;
            }

            html.dark {
                background-color: #0e1116;
                color-scheme: dark;
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|manrope:600,700,800" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
