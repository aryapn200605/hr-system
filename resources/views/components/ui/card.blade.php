@props(['title' => null])

{{-- A section of the page, set off by a rule and not by a box. --}}
<section {{ $attributes->class('border-t border-ink') }}>
    @if ($title)
        <h2 class="border-b border-rule py-2 text-sm font-semibold text-ink">{{ $title }}</h2>
    @endif

    {{ $slot }}
</section>
