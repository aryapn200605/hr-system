@props(['title' => null])

<section {{ $attributes->class('rounded-xl border border-slate-200 bg-white') }}>
    @if ($title)
        <h2 class="border-b border-slate-200 px-5 py-3.5 text-sm font-semibold text-slate-900">{{ $title }}</h2>
    @endif

    {{ $slot }}
</section>
