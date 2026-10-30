@props(['for'])

@error($for)
    <p {{ $attributes->class('mt-1.5 text-sm text-red-700') }}>{{ $message }}</p>
@enderror
