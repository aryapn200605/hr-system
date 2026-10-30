@props(['title', 'description' => null, 'actions' => null])

<header {{ $attributes->class('flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight wrap-anywhere text-ink">{{ $title }}</h1>

        @if ($description)
            <p class="mt-1 max-w-prose text-sm text-muted">{{ $description }}</p>
        @endif

        {{ $slot }}
    </div>

    @if ($actions)
        <div class="shrink-0">{{ $actions }}</div>
    @endif
</header>
