@props(['for'])

@error($for)
    <p {{ $attributes->class('mt-2 text-sm text-destructive') }}>{{ $message }}</p>
@enderror
