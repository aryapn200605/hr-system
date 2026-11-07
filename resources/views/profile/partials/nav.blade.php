{{-- The profile sections as shadcn's Tabs list: a muted track with the current page raised. Plain links (each tab is
     its own page); aria-current marks the one shown. The track scrolls sideways inside itself on a narrow phone. --}}
<nav aria-label="{{ __('My Profile') }}" class="no-scrollbar -mx-1 overflow-x-auto px-1" data-profile-nav>
    <div class="inline-flex h-10 items-center rounded-lg bg-muted p-1">
        @foreach ([
            'profile.show' => ['user', __('Details')],
            'profile.sessions' => ['monitor', __('Active sessions')],
            'profile.login-history' => ['history', __('Login history')],
        ] as $route => [$icon, $label])
            <a
                href="{{ route($route) }}"
                @if (request()->routeIs($route)) aria-current="page" @endif
                @class([
                    'inline-flex h-full items-center gap-2 rounded-md px-3 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] motion-reduce:transition-none',
                    'bg-background text-foreground shadow-sm' => request()->routeIs($route),
                    'text-slate-600 hover:text-foreground' => ! request()->routeIs($route),
                ])
            ><x-ui.icon :name="$icon" class="size-4" />{{ $label }}</a>
        @endforeach
    </div>
</nav>
