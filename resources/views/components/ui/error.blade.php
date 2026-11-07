@props(['for'])

@error($for)
    {{-- Phase 15e: role=alert, so an error that appears after a Livewire check is read out at once. --}}
    <p {{ $attributes->class('mt-2 text-sm text-destructive') }} role="alert">{{ $message }}</p>
@enderror
