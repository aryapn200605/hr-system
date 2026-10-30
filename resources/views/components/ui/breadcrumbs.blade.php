@props(['items'])

<nav aria-label="Breadcrumb" {{ $attributes->class('min-w-0') }}>
    <ol class="flex items-center gap-1 text-sm text-muted">
        @foreach ($items as $item)
            <li @class(['min-w-0 items-center gap-1', 'flex' => $loop->last, 'hidden sm:flex' => ! $loop->last])>
                @if (! $loop->first)
                    <x-ui.icon name="chevron-right" class="hidden size-3.5 shrink-0 text-muted sm:block" />
                @endif

                @if (! $loop->last && isset($item['href']))
                    <a href="{{ $item['href'] }}" class="truncate hover:text-ink hover:underline">{{ $item['label'] }}</a>
                @else
                    <span class="truncate font-medium text-ink" @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
