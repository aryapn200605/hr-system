@props(['title' => null, 'description' => null])

{{-- shadcn's Card: rounded-xl, border, card colours and a small shadow. The body keeps its own padding. --}}
<section {{ $attributes->class('rounded-xl border border-border bg-card text-card-foreground shadow-sm') }}>
    @if ($title)
        <header class="border-b border-border px-5 py-4">
            <h2 class="leading-none font-semibold">{{ $title }}</h2>
            @if ($description)
                <p class="mt-1.5 text-sm text-muted-foreground">{{ $description }}</p>
            @endif
        </header>
    @endif

    {{ $slot }}
</section>
