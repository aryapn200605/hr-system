@php($user = auth()->user())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        @include('layouts.partials.favicon')

        <script>
            // The folded sidebar, set before the first paint so the page does not jump. Without storage the
            // sidebar simply starts open.
            try {
                if (localStorage.getItem('sidebar-collapsed') === '1') {
                    document.documentElement.dataset.sidebarState = 'collapsed';
                }
            } catch (e) {}
        </script>

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

            document.addEventListener('alpine:init', () => {
                Alpine.store('sidebar', {
                    collapsed: document.documentElement.dataset.sidebarState === 'collapsed',
                    // Folding only exists from 768 px; below that the menu is a drawer.
                    isCollapsed() {
                        return this.collapsed && window.matchMedia('(min-width: 48rem)').matches;
                    },
                    toggle() {
                        this.collapsed = ! this.collapsed;
                        document.documentElement.dataset.sidebarState = this.collapsed ? 'collapsed' : 'expanded';
                        try {
                            localStorage.setItem('sidebar-collapsed', this.collapsed ? '1' : '0');
                        } catch (e) {}
                    },
                });
            });
        </script>
    </head>
    <body class="min-h-svh bg-sidebar font-sans text-foreground antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:shadow-md">{{ __('Skip to content') }}</a>

        <div
            class="flex min-h-svh w-full"
            x-data="{ sidebarOpen: false }"
            x-on:resize.window="if (window.innerWidth >= 768) sidebarOpen = false"
            x-on:keydown.window.ctrl.b.prevent="$store.sidebar.toggle()"
            x-on:keydown.window.meta.b.prevent="$store.sidebar.toggle()"
        >
            {{-- Desktop sidebar, "inset" variant: it sits on the sidebar colour and the page is a raised panel. --}}
            <div class="group peer hidden text-sidebar-foreground md:block" data-variant="inset" data-sidebar-desktop>
                {{-- Keeps the room the fixed sidebar takes. --}}
                <div class="relative w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear motion-reduce:transition-none sidebar-collapsed:w-[calc(var(--sidebar-width-icon)+1rem)]"></div>
                <aside class="fixed inset-y-0 left-0 z-10 hidden h-svh w-(--sidebar-width) p-2 transition-[width] duration-200 ease-linear motion-reduce:transition-none md:flex sidebar-collapsed:w-[calc(var(--sidebar-width-icon)+1rem+2px)]">
                    @include('layouts.partials.sidebar', ['navLabel' => __('Main'), 'menuId' => 'account-menu', 'menuAlign' => 'right-end'])

                    {{-- The rail: a mouse target along the edge, like the reference. The header button does the same for keyboards. --}}
                    <button type="button" tabindex="-1" aria-hidden="true" x-on:click="$store.sidebar.toggle()" class="absolute inset-y-0 -right-2 z-20 hidden w-4 cursor-w-resize after:absolute after:inset-y-0 after:left-1/2 after:w-0.5 hover:after:bg-sidebar-border md:flex sidebar-collapsed:cursor-e-resize"></button>
                </aside>
            </div>

            {{-- Phone and small tablet drawer. --}}
            <div
                x-bind:class="{ hidden: ! sidebarOpen }"
                x-trap.noscroll="sidebarOpen"
                x-on:keydown.escape.window="sidebarOpen = false"
                class="fixed inset-0 z-50 hidden md:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="{{ __('Main menu') }}"
            >
                <div class="fixed inset-0 bg-black/50" x-on:click="sidebarOpen = false"></div>

                <div id="mobile-sidebar" class="relative h-full w-(--sidebar-width-mobile) max-w-[85vw] bg-sidebar shadow-lg">
                    @include('layouts.partials.sidebar', ['navLabel' => __('Main menu'), 'menuId' => 'account-menu-mobile', 'menuAlign' => 'top-start'])

                    <button type="button" class="absolute top-4 right-3 rounded-sm p-1.5 text-muted-foreground hover:bg-sidebar-accent hover:text-foreground" aria-label="{{ __('Close menu') }}" x-on:click="sidebarOpen = false">
                        <x-ui.icon name="close" class="size-4" />
                    </button>
                </div>
            </div>

            {{-- SidebarInset: the page panel. --}}
            <div class="relative flex w-full min-w-0 flex-1 flex-col bg-background md:m-2 md:ms-0 md:rounded-xl md:shadow-sm sidebar-collapsed:ms-2" data-content>
                <header
                    class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 bg-background/95 px-4 backdrop-blur md:rounded-t-xl sm:gap-4"
                    x-data="{ scrolled: false }"
                    x-on:scroll.window.passive="scrolled = window.scrollY > 10"
                    x-bind:class="{ 'shadow-sm': scrolled }"
                >
                    <button
                        type="button"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-background shadow-xs hover:bg-accent md:hidden"
                        aria-label="{{ __('Open menu') }}"
                        aria-controls="mobile-sidebar"
                        aria-expanded="false"
                        x-bind:aria-expanded="sidebarOpen.toString()"
                        x-on:click="sidebarOpen = true"
                    >
                        <x-ui.icon name="menu" class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="hidden size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background shadow-xs hover:bg-accent md:inline-flex"
                        aria-label="{{ __('Toggle sidebar') }}"
                        aria-expanded="true"
                        x-bind:aria-expanded="(! $store.sidebar.collapsed).toString()"
                        x-on:click="$store.sidebar.toggle()"
                        data-sidebar-trigger
                    >
                        <x-ui.icon name="panel-left" class="size-4" />
                    </button>

                    <div class="h-6 w-px shrink-0 bg-border" role="none"></div>

                    <x-ui.breadcrumbs :items="$breadcrumbs ?? []" class="flex-1" />

                    <x-ui.dropdown id="profile-menu" :label="__('Profile menu')" trigger-class="rounded-full p-0" class="shrink-0">
                        <x-slot:trigger>
                            <x-ui.avatar :name="$user->name" />
                        </x-slot:trigger>

                        <div class="flex flex-col gap-1.5 px-2 py-1.5" role="none">
                            <p class="truncate text-sm leading-none font-medium">{{ $user->name }}</p>
                            <p class="truncate text-xs leading-none text-muted-foreground">{{ $user->email }}</p>
                        </div>
                        <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                        <x-ui.dropdown-item :href="route('profile.show')">{{ __('My Profile') }}</x-ui.dropdown-item>
                        <x-ui.dropdown-item :href="route('security.index')">{{ __('Security Settings') }}</x-ui.dropdown-item>
                        <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                        <form method="POST" action="{{ route('logout') }}" role="none">
                            @csrf
                            <x-ui.dropdown-item type="submit" variant="destructive">{{ __('Logout') }}</x-ui.dropdown-item>
                        </form>
                    </x-ui.dropdown>
                </header>

                {{-- Width, centring and padding belong to x-ui.page, the only page container. --}}
                <main id="main" tabindex="-1" class="flex min-w-0 flex-1 flex-col outline-none">
                    {{ $slot }}
                </main>
            </div>

            <x-ui.toast />
        </div>

        @livewireScripts
    </body>
</html>
