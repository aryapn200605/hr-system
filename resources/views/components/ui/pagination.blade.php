@props(['paginator', 'label' => null])

{{-- The table footer of shadcn-admin's data table: the range on the left; on the right the page number and
     icon buttons (first, previous, next, last), each named for screen readers. Works with Livewire's
     WithPagination (previousPage, nextPage, gotoPage). A slot before the buttons takes extra controls, such
     as the rows-per-page select. Rendered only when there is a row. --}}
@php
    $lengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    $current = $paginator->currentPage();
    $last = $lengthAware ? max(1, $paginator->lastPage()) : null;
    $iconButton = 'inline-flex size-8 items-center justify-center rounded-md border border-border bg-background text-foreground shadow-xs transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-50';
@endphp

@if ($paginator->count() > 0)
    <nav aria-label="{{ $label ?? __('Pagination') }}" {{ $attributes->class('flex flex-col gap-3 border-t border-border px-5 py-3 sm:flex-row sm:items-center sm:justify-between') }} data-pagination>
        <p class="text-sm text-muted-foreground" data-pagination-range>
            @if ($lengthAware)
                {{ __('Showing :first to :last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            @else
                {{ __('Page :current', ['current' => $current]) }}
            @endif
        </p>

        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 sm:justify-end">
            {{ $slot }}

            @if ($paginator->hasPages())
                <div class="flex items-center gap-4">
                    @if ($lengthAware)
                        <span class="text-sm font-medium whitespace-nowrap" data-pagination-page>{{ __('Page :current of :last', ['current' => $current, 'last' => $last]) }}</span>
                    @endif
                    <div class="flex items-center gap-1.5">
                        @if ($lengthAware)
                            <button type="button" class="{{ $iconButton }} max-sm:hidden" wire:click="gotoPage(1)" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>
                                <span class="sr-only">{{ __('Go to first page') }}</span>
                                <x-ui.icon name="chevrons-left" class="size-4" />
                            </button>
                        @endif
                        <button type="button" class="{{ $iconButton }}" wire:click="previousPage" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) data-pagination-previous>
                            <span class="sr-only">{{ __('Previous') }}</span>
                            <x-ui.icon name="chevron-left" class="size-4" />
                        </button>
                        <button type="button" class="{{ $iconButton }}" wire:click="nextPage" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages()) data-pagination-next>
                            <span class="sr-only">{{ __('Next') }}</span>
                            <x-ui.icon name="chevron-right" class="size-4" />
                        </button>
                        @if ($lengthAware)
                            <button type="button" class="{{ $iconButton }} max-sm:hidden" wire:click="gotoPage({{ $last }})" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages())>
                                <span class="sr-only">{{ __('Go to last page') }}</span>
                                <x-ui.icon name="chevrons-right" class="size-4" />
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </nav>
@endif
