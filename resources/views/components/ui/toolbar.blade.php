@props(['actions' => null])

{{-- The data table toolbar of shadcn-admin: search and filters on the left, actions (export, reset) on the right.
     Filters wrap onto new lines on narrow screens; on phones everything is one column. --}}
<div {{ $attributes->class('flex flex-col gap-4 border-b border-border p-4 sm:p-5 xl:flex-row xl:items-end xl:justify-between') }} data-toolbar>
    <div class="grid min-w-0 flex-1 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:flex xl:flex-wrap xl:items-end xl:*:w-44 xl:*:first:w-60 *:min-w-0">
        {{ $slot }}
    </div>

    @if ($actions !== null && ! ($actions instanceof \Illuminate\View\ComponentSlot && $actions->isEmpty()))
        <div class="flex shrink-0 flex-wrap items-center gap-2" data-toolbar-actions>{{ $actions }}</div>
    @endif
</div>
