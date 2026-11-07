<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- Pages here carry tokens in their session step or show secrets: no referrer leaves them. --}}
        <meta name="referrer" content="no-referrer">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }} - {{ config('app.company') }}</title>
        @include('layouts.partials.favicon')

        {{-- No Livewire and no Alpine on these public pages: auth.js is plain JavaScript and only enhances. --}}
        @vite(['resources/css/app.css', 'resources/js/auth.js'])
    </head>
    {{-- After the reference's sign-in-2: the form card on one side, the brand panel on the other from lg; one column
         with the logo above the card below lg. The brand panel: logo, one sentence and three product points on a
         soft blue gradient with a line pattern drawn here in SVG (no image file). --}}
    <body class="min-h-svh bg-background text-foreground antialiased">
        <div class="grid min-h-svh lg:grid-cols-2">
            <main id="main" class="flex min-w-0 flex-col bg-muted/50 px-4 py-6 sm:px-8 lg:px-12 print:bg-transparent">
                <div class="flex justify-center lg:hidden print:hidden" data-auth-panel="logo">
                    <x-ui.logo class="text-lg" />
                </div>

                <div class="flex flex-1 items-start py-6 sm:items-center sm:py-10">
                    <div class="mx-auto w-full max-w-md motion-safe:animate-auth-enter" data-page>
                        <div class="rounded-2xl border border-border bg-card p-5 text-card-foreground shadow-xl shadow-slate-900/5 sm:p-8 print:border-0 print:shadow-none" data-card data-auth-card>
                            {{ $slot }}
                        </div>

                        <p class="mt-6 text-center text-sm text-muted-foreground print:hidden">{{ __('Need help? Contact your HR Admin.') }}</p>
                    </div>
                </div>

                <footer class="text-center text-xs text-muted-foreground print:hidden">&copy; {{ now()->year }} {{ config('app.company_legal') }} &middot; {{ config('app.name') }}</footer>
            </main>

            <aside class="relative hidden overflow-hidden bg-linear-to-br from-(--brand-from) to-(--brand-to) text-white lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16 print:hidden" data-auth-panel="brand">
                <svg class="pointer-events-none absolute inset-0 size-full" aria-hidden="true" focusable="false">
                    <defs>
                        <pattern id="auth-grid" width="32" height="32" patternUnits="userSpaceOnUse">
                            <path d="M32 0H0v32" fill="none" stroke="white" stroke-opacity="0.08" />
                        </pattern>
                        <linearGradient id="auth-fade" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="white" stop-opacity="1" />
                            <stop offset="0.75" stop-color="white" stop-opacity="0" />
                        </linearGradient>
                        <mask id="auth-mask"><rect width="100%" height="100%" fill="url(#auth-fade)" /></mask>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#auth-grid)" mask="url(#auth-mask)" />
                    <g fill="none" stroke="white">
                        <circle cx="100%" cy="100%" r="160" stroke-opacity="0.12" />
                        <circle cx="100%" cy="100%" r="260" stroke-opacity="0.09" />
                        <circle cx="100%" cy="100%" r="360" stroke-opacity="0.06" />
                        <circle cx="100%" cy="100%" r="460" stroke-opacity="0.04" />
                    </g>
                </svg>

                {{-- The logo (blue mark and dark company name) sits on a light plate, so it stays readable on the blue. --}}
                <div class="relative flex">
                    <div class="rounded-xl bg-white px-4 py-3 shadow-sm">
                        <x-ui.logo class="text-lg" />
                    </div>
                </div>

                <div class="relative max-w-lg">
                    <p class="text-sm font-medium tracking-wide uppercase">{{ config('app.name') }}</p>
                    <p class="mt-3 text-3xl leading-tight font-semibold tracking-tight text-balance">{{ __('Leave, overtime and approvals for every employee, in one secure place.') }}</p>

                    <ul class="mt-10 grid gap-6">
                        @foreach ([
                            ['calendar', __('Leave and permission'), __('Request leave or permission and see your remaining balance.')],
                            ['clock', __('Overtime'), __('Submit overtime by the hour, decided by your supervisor.')],
                            ['check-circle', __('Approvals'), __('Supervisors and HR decide in one place, with a clear history.')],
                        ] as [$icon, $point, $text])
                            <li class="flex gap-4">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/25">
                                    <x-ui.icon :name="$icon" class="size-5" />
                                </span>
                                <span>
                                    <span class="block font-medium">{{ $point }}</span>
                                    <span class="mt-0.5 block text-sm text-white">{{ $text }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative flex items-center gap-2 text-sm text-white">
                    <x-ui.icon name="shield" class="size-4" />
                    {{ __('Every login is protected by two-factor authentication.') }}
                </p>
            </aside>
        </div>
    </body>
</html>
