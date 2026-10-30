@props(['for'])

@error($for)
    <p {{ $attributes->class('mt-1 text-sm text-tone-red') }}>{{ $message }}</p>
@enderror
