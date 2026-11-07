@props(['title', 'description' => null, 'icon' => 'inbox'])

{{-- An empty list. Phase 15d: the icon sits on a small drawing made for this project (rings, a dotted orbit and
     two soft cards, all in the theme's colours, decorative only); the slot takes the call to action. --}}
<div {{ $attributes->class('flex flex-col items-center px-4 py-12 text-center') }} data-empty-state>
    <div class="relative flex h-28 w-40 items-center justify-center" aria-hidden="true">
        <svg class="absolute inset-0 size-full text-primary" viewBox="0 0 160 112" fill="none">
            <circle cx="80" cy="56" r="52" stroke="currentColor" stroke-opacity="0.08" />
            <circle cx="80" cy="56" r="38" stroke="currentColor" stroke-opacity="0.12" stroke-dasharray="2 5" stroke-linecap="round" />
            <rect x="14" y="30" width="34" height="22" rx="5" fill="currentColor" fill-opacity="0.05" stroke="currentColor" stroke-opacity="0.15" transform="rotate(-8 31 41)" />
            <rect x="20" y="37" width="16" height="3" rx="1.5" fill="currentColor" fill-opacity="0.2" transform="rotate(-8 31 41)" />
            <rect x="112" y="62" width="34" height="22" rx="5" fill="currentColor" fill-opacity="0.05" stroke="currentColor" stroke-opacity="0.15" transform="rotate(7 129 73)" />
            <rect x="118" y="69" width="20" height="3" rx="1.5" fill="currentColor" fill-opacity="0.2" transform="rotate(7 129 73)" />
            <circle cx="122" cy="26" r="3" fill="currentColor" fill-opacity="0.18" />
            <circle cx="36" cy="84" r="2" fill="currentColor" fill-opacity="0.18" />
        </svg>
        <span class="relative flex size-12 items-center justify-center rounded-xl border border-border bg-card text-primary shadow-md shadow-slate-900/[0.06]">
            <x-ui.icon :name="$icon" class="size-6" />
        </span>
    </div>
    <p class="mt-4 text-sm font-semibold text-foreground" data-announce>{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-muted-foreground" data-announce>{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
