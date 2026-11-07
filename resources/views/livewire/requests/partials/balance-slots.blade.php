{{-- The annual leave balance as figure tiles, one panel per period (the current one and, near the anchor date, the
     next). Needs $periods; $decider true shows what a decider needs (remaining after used days) instead of what the
     requester may still ask for (available). The data attributes are what the tests and the browser checks read. --}}
@php
    $decider ??= false;
@endphp
@php
    $two = count($periods) > 1;
@endphp
<div @class(['grid grid-cols-1 divide-y divide-border', 'xl:grid-cols-2 xl:divide-x xl:divide-y-0' => $two])>
    @foreach ($periods as $slot)
        @php
            $left = $decider ? max(0, $slot->entitlement - $slot->used) : $slot->available();
            $share = $slot->entitlement > 0 ? min(100, max(0, (int) round($left / $slot->entitlement * 100))) : 0;
        @endphp
        <div class="px-5 py-4" data-slot="{{ $loop->index }}">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium text-foreground">{{ $loop->first ? __('Current period') : __('Next period') }}</span>
                <span class="text-muted-foreground"><x-ui.date :value="$slot->startsOn" /> – <x-ui.date :value="$slot->endsOn" /></span>
                @if ($slot->isProjected())
                    <x-ui.badge>{{ __('Projected') }}</x-ui.badge>
                @endif
            </p>

            <dl @class(['mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4', 'xl:grid-cols-2' => $two])>
                <div class="rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                    <dt class="text-xs text-muted-foreground">{{ __('Entitlement') }}</dt>
                    <dd class="mt-1 text-sm font-semibold">{{ __(':count days', ['count' => $slot->entitlement]) }}</dd>
                </div>
                <div class="rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                    <dt class="text-xs text-muted-foreground">{{ __('Used') }}</dt>
                    <dd class="mt-1 text-sm font-semibold"><span @if ($decider) data-used @endif>{{ __(':count days', ['count' => $slot->used]) }}</span></dd>
                </div>
                <div class="rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                    <dt class="text-xs text-muted-foreground">{{ __('In pending requests') }}</dt>
                    <dd class="mt-1 text-sm font-semibold">{{ __(':count days', ['count' => $slot->pending]) }}</dd>
                </div>
                <div class="rounded-lg border border-green-200 bg-green-50 px-3 py-2.5">
                    <dt class="text-xs text-green-800">{{ $decider ? __('Remaining after used days') : __('Available') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-green-900"><span @if ($decider) data-remaining @else data-available @endif>{{ __(':count days', ['count' => $left]) }}</span></dd>
                </div>
            </dl>

            <span class="mt-3 block h-1.5 w-full overflow-hidden rounded-full bg-muted" aria-hidden="true">
                <span class="block h-full rounded-full bg-success" style="width: {{ $share }}%"></span>
            </span>
        </div>
    @endforeach
</div>
