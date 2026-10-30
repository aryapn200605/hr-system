@props(['value', 'compact' => false])

@php($local = $value->copy()->setTimezone(config('app.display_timezone')))

<time class="tabular-nums" datetime="{{ $value->toIso8601String() }}" @if ($compact) title="{{ $local->format('j M Y, H:i T') }}" @endif {{ $attributes }}>{{ $local->format($compact ? 'j M Y' : 'j M Y, H:i T') }}</time>
