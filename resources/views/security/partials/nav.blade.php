<nav aria-label="Security Settings" class="flex flex-wrap gap-1 border-b border-slate-200 pb-3">
    @foreach (['security.index' => 'Overview', 'security.sessions' => 'Active sessions', 'security.login-history' => 'Login history'] as $route => $label)
        <a
            href="{{ route($route) }}"
            @if (request()->routeIs($route)) aria-current="page" @endif
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium',
                'bg-blue-50 text-blue-800' => request()->routeIs($route),
                'text-slate-700 hover:bg-slate-100' => ! request()->routeIs($route),
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
