<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Session Expired - {{ config('app.name') }}</title>
    
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-gray-50 dark:bg-gray-900">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-md w-full">
            <div class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-8 text-center">
                <!-- Icon -->
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-yellow-100 dark:bg-yellow-900/20 mb-6">
                    <svg class="h-8 w-8 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                </div>

                <!-- Title -->
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">
                    Session Expired
                </h1>

                <!-- Message -->
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Your session has expired for security reasons. Please refresh the page to get a new session and try again.
                </p>

                <!-- Actions -->
                <div class="space-y-3">
                    <button 
                        onclick="window.location.reload()" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors"
                    >
                        Refresh Page
                    </button>
                    
                    <button 
                        onclick="window.history.back()" 
                        class="w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 px-4 rounded-lg transition-colors"
                    >
                        Go Back
                    </button>
                </div>

                <!-- Additional Info -->
                <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-600">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        This usually happens when you've been inactive for more than 8 hours, or if you opened the page in multiple tabs.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>