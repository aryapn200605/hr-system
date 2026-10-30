@props(['title' => null, 'flush' => false, 'footer' => null])

{{-- One section of a page. flush removes the padding, for tables and lists that run edge to edge. --}}
<section {{ $attributes->class('rounded-sm border border-rule bg-surface') }}>
    @if ($title)
        <h2 class="border-b border-rule px-4 py-3 text-base font-semibold text-ink sm:px-5">{{ $title }}</h2>
    @endif

    <div @class(['px-4 py-2 sm:px-5' => ! $flush])>
        {{ $slot }}
    </div>

    @if ($footer)
        <div class="flex flex-col gap-2 border-t border-rule px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:px-5">{{ $footer }}</div>
    @endif
</section>
