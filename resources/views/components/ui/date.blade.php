@props(['value'])

{{-- A calendar date, without time or zone. --}}
<time datetime="{{ $value->toDateString() }}" {{ $attributes }}>{{ $value->translatedFormat('j M Y') }}</time>
