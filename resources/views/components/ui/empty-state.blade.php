@props(['title', 'description' => null, 'icon' => 'inbox'])

<div {{ $attributes->class('flex flex-col items-center px-4 py-10 text-center') }}>
    <span class="flex size-10 items-center justify-center rounded-full bg-slate-100 text-slate-600">
        <x-ui.icon :name="$icon" class="size-5" />
    </span>
    <p class="mt-3 text-sm font-medium text-slate-900">{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-600">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
