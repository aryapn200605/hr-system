@props(['items'])

<nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes->class('min-w-0') }}>
    <ol class="flex min-w-0 items-center gap-1.5 text-sm break-words text-muted-foreground sm:gap-2.5">
        @foreach ($items as $item)
            <li @class(['flex min-w-0 items-center gap-1.5', 'hidden sm:flex' => ! $loop->last])>
                @if (! $loop->first)
                    <x-ui.icon name="chevron-right" class="hidden size-3.5 shrink-0 sm:block" />
                @endif

                @if (! $loop->last && isset($item['href']))
                    <a href="{{ $item['href'] }}" class="truncate transition-colors hover:text-foreground">{{ $item['label'] }}</a>
                @else
                    <span class="truncate font-normal text-foreground" @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
