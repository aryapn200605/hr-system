@props(['id', 'label', 'trigger', 'anchored' => false, 'triggerClass' => 'p-1', 'align' => 'bottom-end', 'width' => 'w-56'])

{{-- shadcn's DropdownMenu. Shown with a class and not x-show, which waits for a frame and would miss the focus
     move below. Anchored menus are placed from the trigger with the fixed strategy, so a scrolling parent such
     as a table or the sidebar cannot clip them (with absolute placement, a row menu reaching below a short table
     was cut off by the table's overflow box and its items could not be clicked). --}}
<div
    {{ $attributes->class(['relative' => ! $anchored]) }}
    x-data="{ open: false, close() { this.open = false; this.$refs.trigger.focus(); } }"
    x-on:click.outside="open = false"
    x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
    x-on:keydown.escape.stop="close()"
>
    <button
        type="button"
        x-ref="trigger"
        class="flex items-center gap-2 rounded-md {{ $triggerClass }} text-sm text-foreground hover:bg-accent hover:text-accent-foreground"
        aria-label="{{ $label }}"
        aria-haspopup="menu"
        aria-controls="{{ $id }}"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        x-bind:data-state="open ? 'open' : 'closed'"
        x-on:click="open = ! open; if (open) $nextTick(() => $focus.within($refs.menu).first())"
    >
        {{ $trigger }}
    </button>

    <div
        id="{{ $id }}"
        x-ref="menu"
        x-bind:class="{ hidden: ! open }"
        role="menu"
        aria-label="{{ $label }}"
        @if ($anchored)
            x-anchor.fixed.{{ $align }}.offset.4="$refs.trigger"
            x-on:click="open = false"
        @endif
        @class([
            'z-50 hidden min-w-32 overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md',
            $width,
            'absolute right-0 mt-1' => ! $anchored,
        ])
        x-on:keydown.arrow-down.prevent="$focus.within($refs.menu).wrap().next()"
        x-on:keydown.arrow-up.prevent="$focus.within($refs.menu).wrap().previous()"
        x-on:keydown.home.prevent="$focus.within($refs.menu).first()"
        x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
    >
        {{ $slot }}
    </div>
</div>
