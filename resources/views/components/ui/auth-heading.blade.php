@props(['title'])

<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>

    @if (! $slot->isEmpty())
        <p class="mt-2 text-sm text-slate-600">{{ $slot }}</p>
    @endif
</div>
