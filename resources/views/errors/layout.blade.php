{{--
    Phase 15e: the one layout of every error page (403, 404, 419, 429, 500, 503).

    Safe text only: a fixed title and explanation per status, never the exception's message, a trace or a request
    detail. Standalone: no Livewire, no Alpine and no app script, so it renders the same whatever failed. Every call
    that could fail again (the translations and the user both read the database, the stylesheet needs the Vite
    manifest) goes through rescue(): a broken database gives an English page, not a second error.

    Expects: $code (int), $icon (an x-ui.icon name), $title and $message (English keys, translated here), and
    optionally $retry (true: offer "Try again", which reloads the page).
--}}
@php
    $t = static fn (string $text): string => rescue(fn () => __($text), $text, false);

    // A fully logged-in user goes back to the dashboard, anyone else to the login page. The session may not
    // have started (maintenance) and the user lookup may fail: both count as a guest.
    $user = rescue(fn () => request()->user(), null, false);
    $signedIn = (bool) rescue(fn () => $user?->canAccessApplication(), false, false);

    // A failed CSRF check (419) stops the request before the language middleware runs.
    rescue(fn () => $user?->preferredLocale() && app()->setLocale($user->preferredLocale()), null, false);

    // An address that matches no route is answered before the session starts, so who is asking is unknown
    // there: the start page sends a guest to the login page and a user to the dashboard.
    $known = (bool) rescue(fn () => request()->hasSession() && request()->session()->isStarted(), false, false);

    [$home, $homeLabel, $homeIcon] = match (true) {
        $signedIn => [rescue(fn () => route('dashboard'), '/dashboard', false), $t('Go to dashboard'), 'home'],
        $known => [rescue(fn () => route('login'), '/login', false), $t('Go to login'), 'log-in'],
        default => [url('/'), $t('Go to the home page'), 'home'],
    };
    $styles = rescue(fn () => app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css'])->toHtml(), '', false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- An error can be shown at a URL that holds a token (an activation link): no referrer leaves this page. --}}
        <meta name="referrer" content="no-referrer">
        <meta name="robots" content="noindex">

        <title>{{ $code }} · {{ $t($title) }} - {{ config('app.name') }} - {{ config('app.company') }}</title>
        @include('layouts.partials.favicon')

        {!! $styles !!}
    </head>
    <body class="min-h-svh bg-muted/50 font-sans text-foreground antialiased">
        <main id="main" class="flex min-h-svh flex-col px-4 py-6 sm:px-8" data-error-page="{{ $code }}">
            <div class="flex justify-center">
                <x-ui.logo class="text-lg" />
            </div>

            <div class="flex flex-1 items-start justify-center py-8 sm:items-center sm:py-12">
                <div class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 text-center text-card-foreground shadow-xl shadow-slate-900/5 motion-safe:animate-auth-enter sm:p-8" data-error-card>
                    {{-- Drawn for this project: rings, a dotted orbit and the status code behind the icon. Decorative. --}}
                    <div class="relative mx-auto flex h-32 w-48 items-center justify-center" aria-hidden="true">
                        <svg class="absolute inset-0 size-full text-primary" viewBox="0 0 192 128" fill="none">
                            <circle cx="96" cy="64" r="60" stroke="currentColor" stroke-opacity="0.08" />
                            <circle cx="96" cy="64" r="44" stroke="currentColor" stroke-opacity="0.14" stroke-dasharray="2 6" stroke-linecap="round" />
                            <path d="M14 98c20-14 44-14 64 0" stroke="currentColor" stroke-opacity="0.12" stroke-linecap="round" />
                            <path d="M118 30c18-10 40-10 58 2" stroke="currentColor" stroke-opacity="0.12" stroke-linecap="round" />
                            <circle cx="160" cy="92" r="3" fill="currentColor" fill-opacity="0.2" />
                            <circle cx="30" cy="34" r="2.5" fill="currentColor" fill-opacity="0.2" />
                            <circle cx="148" cy="18" r="1.5" fill="currentColor" fill-opacity="0.25" />
                        </svg>
                        <span class="relative flex size-14 items-center justify-center rounded-2xl border border-border bg-card text-primary shadow-md shadow-slate-900/[0.06]">
                            <x-ui.icon :name="$icon" class="size-7" />
                        </span>
                    </div>

                    <p class="mt-2 text-sm font-semibold tracking-widest text-primary-hover tabular-nums">{{ $t('Error') }} {{ $code }}</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-balance">{{ $t($title) }}</h1>
                    <p class="mt-3 text-sm text-pretty text-muted-foreground">{{ $t($message) }}</p>

                    <div class="mt-8 flex flex-col-reverse justify-center gap-2 sm:flex-row sm:flex-wrap">
                        {{-- Shown by the script below only when there is a page to go back to. --}}
                        <button type="button" class="hidden h-11 items-center justify-center gap-2 rounded-md whitespace-nowrap border border-border bg-background px-4 text-sm font-medium shadow-xs hover:bg-accent sm:h-9" data-error-back>
                            <x-ui.icon name="arrow-left" class="size-4" />{{ $t('Go back') }}
                        </button>
                        @if (! empty($retry))
                            <button type="button" class="hidden h-11 items-center justify-center gap-2 rounded-md whitespace-nowrap border border-border bg-background px-4 text-sm font-medium shadow-xs hover:bg-accent sm:h-9" data-error-retry>
                                <x-ui.icon name="refresh" class="size-4" />{{ $t('Try again') }}
                            </button>
                        @endif
                        <a href="{{ $home }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-primary whitespace-nowrap px-4 text-sm font-medium text-primary-foreground shadow-xs hover:bg-primary-hover sm:h-9" data-error-home>
                            <x-ui.icon :name="$homeIcon" class="size-4" />{{ $homeLabel }}
                        </a>
                    </div>
                </div>
            </div>

            <footer class="text-center text-xs text-muted-foreground">&copy; {{ now()->year }} {{ config('app.company_legal') }} &middot; {{ config('app.name') }}</footer>
        </main>

        <script>
            (() => {
                let back = document.querySelector('[data-error-back]');
                if (back && window.history.length > 1) {
                    back.classList.replace('hidden', 'inline-flex');
                    back.addEventListener('click', () => window.history.back());
                }
                let retry = document.querySelector('[data-error-retry]');
                if (retry) {
                    retry.classList.replace('hidden', 'inline-flex');
                    retry.addEventListener('click', () => window.location.reload());
                }
            })();
        </script>
    </body>
</html>
