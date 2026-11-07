@props(['id', 'label'])

{{-- A row's action menu, as on shadcn-admin's tables: a small icon button with three dots that opens the menu.
     Anchored with the fixed strategy, so the table's scroll box never clips it (CLAUDE.md lesson). --}}
<x-ui.dropdown :id="$id" :label="$label" anchored trigger-class="size-8 justify-center p-0 data-[state=open]:bg-muted" {{ $attributes->class('inline-block') }}>
    <x-slot:trigger>
        <x-ui.icon name="more-horizontal" class="size-4 fill-current" />
    </x-slot:trigger>

    {{ $slot }}
</x-ui.dropdown>
