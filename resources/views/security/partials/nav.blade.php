<nav aria-label="Security Settings" class="flex flex-wrap gap-x-5 border-b border-rule">
    @foreach (['security.index' => 'Overview', 'security.sessions' => 'Active sessions', 'security.login-history' => 'Login history'] as $route => $label)
        <a
            href="{{ route($route) }}"
            @if (request()->routeIs($route)) aria-current="page" @endif
            @class([
                '-mb-px border-b-2 py-2 text-sm',
                'border-accent font-semibold text-ink' => request()->routeIs($route),
                'border-transparent text-ink-soft hover:border-rule-strong hover:text-ink' => ! request()->routeIs($route),
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
