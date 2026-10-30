@php($user = auth()->user())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Explicit, because pages without a Livewire component still need Alpine for the menus. --}}
        @livewireStyles

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
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium">Skip to content</a>

        <div
            x-data="{ sidebarOpen: false }"
            x-on:resize.window="if (window.innerWidth >= 1024) sidebarOpen = false"
        >
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-68 border-r border-slate-200 bg-white lg:block">
                @include('layouts.partials.sidebar', ['navLabel' => 'Main'])
            </aside>

            <div
                x-bind:class="{ hidden: ! sidebarOpen }"
                x-trap.noscroll="sidebarOpen"
                x-on:keydown.escape.window="sidebarOpen = false"
                class="fixed inset-0 z-40 hidden lg:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="Main menu"
            >
                <div class="fixed inset-0 bg-slate-900/50" x-on:click="sidebarOpen = false"></div>

                <div id="mobile-sidebar" class="relative h-full w-72 max-w-[85vw] bg-white">
                    @include('layouts.partials.sidebar', ['navLabel' => 'Main menu'])

                    <button type="button" class="absolute top-3 right-3 cursor-pointer rounded-lg p-1.5 text-slate-600 hover:bg-slate-100" aria-label="Close menu" x-on:click="sidebarOpen = false">
                        <x-ui.icon name="close" class="size-5" />
                    </button>
                </div>
            </div>

            <div class="lg:pl-68">
                <header class="sticky top-0 z-20 flex h-14 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            class="-ml-1.5 cursor-pointer rounded-lg p-1.5 text-slate-700 hover:bg-slate-100 lg:hidden"
                            aria-label="Open menu"
                            aria-controls="mobile-sidebar"
                            aria-expanded="false"
                            x-bind:aria-expanded="sidebarOpen.toString()"
                            x-on:click="sidebarOpen = true"
                        >
                            <x-ui.icon name="menu" class="size-6" />
                        </button>

                        <x-ui.breadcrumbs :items="$breadcrumbs ?? []" />
                    </div>

                    <x-ui.dropdown id="profile-menu" label="Profile menu">
                        <x-slot:trigger>
                            <x-ui.avatar :name="$user->name" class="size-8" />
                            <span class="hidden max-w-40 truncate font-medium sm:block">{{ $user->name }}</span>
                            <x-ui.icon name="chevron-down" class="size-4 text-slate-500" />
                        </x-slot:trigger>

                        <x-ui.dropdown-item :href="route('profile.show')">My Profile</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('security.index')">Security Settings</x-ui.dropdown-item>

                        <div class="my-1.5 border-t border-slate-200" role="none"></div>

                        <form method="POST" action="{{ route('logout') }}" role="none">
                            @csrf
                            <x-ui.dropdown-item type="submit">Logout</x-ui.dropdown-item>
                        </form>
                    </x-ui.dropdown>
                </header>

                <main id="main" tabindex="-1" class="mx-auto max-w-6xl px-4 py-6 outline-none sm:px-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>

            <x-ui.toast />
        </div>

        @livewireScripts
    </body>
</html>
