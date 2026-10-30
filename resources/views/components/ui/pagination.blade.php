@props(['paginator'])

<nav aria-label="Pagination" {{ $attributes->class('flex flex-col gap-3 border-t border-rule px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5') }}>
    <p class="text-sm text-muted tabular-nums">Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>

    @if ($paginator->hasPages())
        <div class="flex gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$paginator->onFirstPage()">Previous</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $paginator->hasMorePages()">Next</x-ui.button>
        </div>
    @endif
</nav>
