<nav aria-label="{{ __('My Profile') }}" class="flex flex-wrap gap-1 border-b border-slate-200 pb-3">
    @foreach (['profile.show' => __('Details'), 'profile.sessions' => __('Active sessions'), 'profile.login-history' => __('Login history')] as $route => $label)
        <a
            href="{{ route($route) }}"
            @if (request()->routeIs($route)) aria-current="page" @endif
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium',
                'bg-primary/5 text-primary-hover' => request()->routeIs($route),
                'text-slate-700 hover:bg-slate-100' => ! request()->routeIs($route),
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
