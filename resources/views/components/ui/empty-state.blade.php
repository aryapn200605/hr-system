@props(['title', 'description' => null])

<div {{ $attributes->class('py-6') }}>
    <p class="text-sm font-medium text-ink">{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-prose text-sm text-muted">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
