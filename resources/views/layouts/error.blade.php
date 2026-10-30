{{-- Reads no session, user or database, because these pages must render when those are the problem. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} - {{ config('app.name') }}</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-paper text-ink antialiased">
        <x-ui.public-page>
            <x-ui.auth-heading :title="$title">{{ $slot }}</x-ui.auth-heading>

            <x-ui.button variant="link" :href="url('/')">Go to the home page</x-ui.button>
        </x-ui.public-page>
    </body>
</html>
