@props(['icon' => null])

{{-- The main button of a plain (non-Livewire) form: full width, soft hover shadow, a pressed state and, while the
     form is being sent, a spinner (resources/js/auth.js sets data-loading on the button and ignores a second
     submit). Without JavaScript it is an ordinary submit button. --}}
<button
    type="submit"
    data-submit
    {{ $attributes->class('group/submit inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground shadow-sm transition-[background-color,box-shadow,transform] duration-150 hover:bg-primary/90 hover:shadow-md active:translate-y-px data-loading:cursor-progress data-loading:bg-primary/85 motion-reduce:transition-none motion-reduce:active:translate-y-0 [&_svg]:shrink-0') }}
>
    <x-ui.spinner class="hidden group-data-loading/submit:block" />
    @if ($icon)
        <x-ui.icon :name="$icon" class="size-4 group-data-loading/submit:hidden" />
    @endif
    <span>{{ $slot }}</span>
</button>
