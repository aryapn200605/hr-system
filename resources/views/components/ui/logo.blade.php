@props(['variant' => 'full', 'decorative' => false])

{{-- The company logo. The round mark (public/brand/logo-icon.png) is cropped from design/brand/logo.jpg; the
     source file is a square on a patterned grey background, so only the mark is used and the company name is set
     as text beside it (the source's curved lettering is too small to read at these sizes).
     full: mark + company name, sized by the font size (class "text-lg" makes both larger);
     icon: the mark alone (the sidebar and the favicon). Without the file, the company name stays text. --}}
@php
    $file = 'brand/logo-icon.png';
    $company = config('app.company');
    $hasMark = is_file(public_path($file));
@endphp

@if ($variant === 'icon')
    @if ($hasMark)
        <img src="{{ asset($file) }}" alt="{{ $decorative ? '' : $company }}" width="32" height="32" {{ $attributes->class('shrink-0 rounded-full object-contain') }} data-logo-mark>
    @else
        {{-- Text, not a mark: the initials of the company name, beside or instead of the full name. --}}
        <span {{ $attributes->class('flex shrink-0 items-center justify-center rounded-lg border border-border bg-background text-[0.625rem] font-semibold tracking-tight text-foreground') }} aria-hidden="true">{{ str($company)->replaceFirst('PT ', '')->explode(' ')->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(3)->implode('') }}</span>
    @endif
@else
    <span {{ $attributes->class('inline-flex items-center gap-[0.5em] font-semibold tracking-tight text-foreground') }} data-logo>
        @if ($hasMark)
            <img src="{{ asset($file) }}" alt="" width="32" height="32" class="size-[1.75em] shrink-0 rounded-full" data-logo-mark>
        @endif
        <span>{{ $company }}</span>
    </span>
@endif
