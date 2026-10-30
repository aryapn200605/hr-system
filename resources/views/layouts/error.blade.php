{{-- Reads no session, user or database, because these pages must render when those are the problem. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} - {{ config('app.name') }}</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <main class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ $slot }}</p>
                <p class="mt-6"><a href="{{ url('/') }}" class="text-sm text-blue-700 underline underline-offset-2">Go to the home page</a></p>
            </div>
        </main>
    </body>
</html>
