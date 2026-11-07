{{-- The steps of a request so far, drawn from its own dates: submitted, then cancelled, decided or still waiting.
     Needs $record. Decoration only adds icons; every step is also written out. --}}
@php
    $status = $record->status;
    $steps = [['icon' => 'send', 'tone' => 'primary', 'title' => __('Submitted'), 'at' => $record->created_at, 'note' => null]];

    if ($record->cancelled_at) {
        $steps[] = ['icon' => 'x-circle', 'tone' => 'gray', 'title' => $status->label(), 'at' => $record->cancelled_at, 'note' => null];
    } elseif ($record->decided_at) {
        $steps[] = ['icon' => $status === \App\Enums\RequestStatus::Approved ? 'check-circle' : 'x-circle', 'tone' => $status->tone(), 'title' => $status->label(), 'at' => $record->decided_at, 'note' => $record->deciderLabel()];
    } else {
        $steps[] = ['icon' => 'hourglass', 'tone' => 'amber', 'title' => $status->label(), 'at' => null, 'note' => null];
    }
@endphp

<x-ui.card :title="__('Progress')" icon="history" tone="gray" data-progress>
    <ol class="px-5 py-4">
        @foreach ($steps as $step)
            <li class="relative flex gap-3 pb-5 last:pb-0" data-step="{{ $loop->index }}">
                @unless ($loop->last)
                    <span class="absolute top-9 bottom-1 left-4 w-px -translate-x-1/2 bg-border" aria-hidden="true"></span>
                @endunless
                <x-ui.icon-chip :icon="$step['icon']" :tone="$step['tone']" size="sm" />
                <div class="min-w-0 pt-1">
                    <p class="text-sm font-medium text-foreground">{{ $step['title'] }}</p>
                    @if ($step['at'])
                        <p class="mt-0.5 text-sm text-muted-foreground"><x-ui.datetime :value="$step['at']" />@if ($step['note']) · {{ $step['note'] }}@endif</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</x-ui.card>
