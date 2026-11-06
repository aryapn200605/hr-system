@props(['title' => null, 'description' => null])

{{-- shadcn's Card: rounded-xl, border, card colours and the soft shadow of the auth card (15c), lighter here because
     a page holds several. The body keeps its own padding. --}}
<section {{ $attributes->class('rounded-xl border border-border bg-card text-card-foreground shadow-md shadow-slate-900/[0.04]') }}>
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
