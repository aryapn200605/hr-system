@props(['items'])

<nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes->class('min-w-0') }}>
    <ol class="flex items-center gap-1.5 text-sm text-slate-600">
        @foreach ($items as $item)
            <li class="flex min-w-0 items-center gap-1.5">
                @if (! $loop->first)
                    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-slate-500" />
                @endif

                @if (! $loop->last && isset($item['href']))
                    <a href="{{ $item['href'] }}" class="truncate hover:text-slate-900 hover:underline">{{ $item['label'] }}</a>
                @else
                    <span class="truncate font-medium text-slate-900" @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
