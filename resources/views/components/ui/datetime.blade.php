@props(['value'])

<time datetime="{{ $value->toIso8601String() }}" {{ $attributes }}>{{ $value->format('j M Y, H:i T') }}</time>
