@props(['variant' => 'full', 'decorative' => false])

{{-- The company logo, served from public/brand/ in this project. Until the brand files exist (design/brand/
     holds none yet) it shows the company name as text: no logo is drawn here.
     full: brand/logo.svg; icon: brand/logo-icon.svg (the folded sidebar and the favicon). --}}
@php
    $file = $variant === 'icon' ? 'brand/logo-icon.svg' : 'brand/logo.svg';
    $company = config('app.company');
@endphp

@if (is_file(public_path($file)))
    <img src="{{ asset($file) }}" alt="{{ $decorative ? '' : $company }}" {{ $attributes->class('shrink-0 object-contain') }}>
@elseif ($variant === 'icon')
    {{-- Text, not a mark: the initials of the company name, beside or instead of the full name. --}}
    <span {{ $attributes->class('flex shrink-0 items-center justify-center rounded-lg border border-border bg-background text-[0.625rem] font-semibold tracking-tight text-foreground') }} aria-hidden="true">{{ str($company)->replaceFirst('PT ', '')->explode(' ')->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(3)->implode('') }}</span>
@else
    <span {{ $attributes->class('font-semibold tracking-tight text-foreground') }}>{{ $company }}</span>
@endif
