@props(['caption', 'head'])

{{-- The region is focusable so a keyboard can scroll a table wider than the screen. --}}
<div {{ $attributes->class('overflow-x-auto') }} role="region" aria-label="{{ $caption }}" tabindex="0">
    <table class="w-full text-left text-sm [&_td]:px-5 [&_td]:py-3 [&_td]:whitespace-nowrap [&_th]:px-5 [&_th]:py-2.5 [&_th]:font-medium [&_th]:whitespace-nowrap">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-700">
            {{ $head }}
        </thead>
        <tbody class="divide-y divide-slate-200 text-slate-800">
            {{ $slot }}
        </tbody>
    </table>
</div>
