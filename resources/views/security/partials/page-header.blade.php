<header>
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>

    @isset($description)
        <p class="mt-2 text-sm text-slate-600">{{ $description }}</p>
    @endisset
</header>
