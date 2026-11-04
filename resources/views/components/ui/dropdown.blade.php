@props(['id', 'label', 'trigger', 'anchored' => false, 'triggerClass' => 'p-1'])

{{-- Shown with a class and not x-show, which waits for a frame and would miss the focus move below.
     Anchored menus are placed from the trigger, so a scrolling parent such as a table cannot clip them. --}}
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
        class="flex cursor-pointer items-center gap-2 rounded-lg {{ $triggerClass }} text-sm text-slate-800 hover:bg-slate-100"
        aria-label="{{ $label }}"
        aria-haspopup="menu"
        aria-controls="{{ $id }}"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
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
            x-anchor.bottom-end.offset.4="$refs.trigger"
            x-on:click="open = false"
        @endif
        @class([
            'z-40 hidden w-60 rounded-xl border border-slate-200 bg-white py-1.5 shadow-lg',
            'absolute right-0 mt-2' => ! $anchored,
        ])
        x-on:keydown.arrow-down.prevent="$focus.within($refs.menu).wrap().next()"
        x-on:keydown.arrow-up.prevent="$focus.within($refs.menu).wrap().previous()"
        x-on:keydown.home.prevent="$focus.within($refs.menu).first()"
        x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
    >
        {{ $slot }}
    </div>
</div>
