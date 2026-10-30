<header>
    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>

    @isset($description)
        <p class="mt-1 max-w-prose text-sm text-ink-soft">{{ $description }}</p>
    @endisset
</header>
