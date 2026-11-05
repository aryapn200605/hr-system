@props(['href' => null, 'icon', 'active' => false, 'disabled' => false, 'tooltip' => null])

{{-- shadcn's SidebarMenuButton. When the sidebar is folded to icons, the label stays for screen readers
     (sr-only) and a tooltip names the link on hover and focus. --}}
@php
    $tooltip ??= trim(strip_tags((string) $slot));
    $classes = 'relative flex h-8 w-full items-center gap-2 overflow-hidden rounded-md p-2 text-start text-sm text-sidebar-foreground transition-[width,height,padding] sidebar-collapsed:size-8';
@endphp

<li class="relative" x-data="{ tip: false }">
    @if ($disabled)
        <span {{ $attributes->class([$classes, 'cursor-not-allowed opacity-50']) }} aria-disabled="true">
            <x-ui.icon :name="$icon" class="size-4 shrink-0" />
            <span class="min-w-0 flex-1 truncate sidebar-collapsed:sr-only">{{ $slot }}</span>
        </span>
    @else
        <a
            href="{{ $href }}"
            x-ref="link"
            data-active="{{ $active ? 'true' : 'false' }}"
            @if ($active) aria-current="page" @endif
            x-on:mouseenter="tip = $store.sidebar?.isCollapsed()"
            x-on:mouseleave="tip = false"
            x-on:focus="tip = $store.sidebar?.isCollapsed()"
            x-on:blur="tip = false"
            {{ $attributes->class([$classes, 'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground', 'bg-sidebar-accent font-medium text-sidebar-accent-foreground' => $active]) }}
        >
            <x-ui.icon :name="$icon" class="size-4 shrink-0" />
            <span class="min-w-0 flex-1 truncate sidebar-collapsed:sr-only">{{ $slot }}</span>
            @isset($badge)
                {{-- Beside the label; folded, it shrinks to a dot on the icon and the number stays for screen readers. --}}
                <span class="ms-auto sidebar-collapsed:sr-only">{{ $badge }}</span>
                <span class="absolute top-1 right-1 hidden size-2 rounded-full bg-warning sidebar-collapsed:block" aria-hidden="true"></span>
            @endisset
        </a>
        {{-- Moved to <body>, so the sidebar's overflow cannot clip it. --}}
        <template x-teleport="body">
            <span
                x-show="tip"
                x-anchor.right.offset.8="$refs.link"
                style="display: none"
                class="z-[60] rounded-md bg-foreground px-3 py-1.5 text-xs whitespace-nowrap text-background"
                aria-hidden="true"
                data-nav-tooltip
            >{{ $tooltip }}</span>
        </template>
    @endif
</li>
