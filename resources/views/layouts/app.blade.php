<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <script>
            // The server already refused the action. This only replaces Livewire's error modal with the login page.
            document.addEventListener('livewire:init', () => {
                Livewire.interceptRequest(({ onError }) => {
                    onError(({ response, preventDefault }) => {
                        if (response.status === 401) {
                            preventDefault();
                            window.location.href = @js(route('login'));
                        }
                    });
                });
            });
        </script>
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900">
        <main class="mx-auto max-w-md px-4 py-10">
            {{ $slot }}
        </main>
    </body>
</html>
