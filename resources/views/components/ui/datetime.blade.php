@props(['value'])

<time datetime="{{ $value->toIso8601String() }}" {{ $attributes }}>{{ $value->translatedFormat('j M Y, H:i T') }}</time>
