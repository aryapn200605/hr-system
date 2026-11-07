{{-- How a deducting leave request is split over leave periods. Needs $record with its allocations loaded. --}}
<x-ui.table :caption="__('Leave balance used per period')">
    <x-slot:head>
        <tr>
            <th scope="col">{{ __('Period') }}</th>
            <th scope="col">{{ __('Dates in this period') }}</th>
            <th scope="col">{{ __('Working days') }}</th>
            <th scope="col">{{ __('Balance') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($record->allocations as $allocation)
        <tr wire:key="allocation-{{ $allocation->id }}" data-allocation="{{ $allocation->id }}">
            <td data-label="{{ __('Period') }}">
                <x-ui.date :value="$allocation->period_starts_on" /> – <x-ui.date :value="$allocation->period_ends_on" />
                @if ($allocation->isProjected())
                    <x-ui.badge class="ml-1">{{ __('Projected') }}</x-ui.badge>
                @endif
            </td>
            <td data-label="{{ __('Dates in this period') }}"><x-ui.date :value="$allocation->starts_on" /> – <x-ui.date :value="$allocation->ends_on" /></td>
            <td data-label="{{ __('Working days') }}">{{ $allocation->days }}</td>
            <td data-allocation-state data-label="{{ __('Balance') }}">
                @if ($allocation->charged_at)
                    {{ __('Deducted') }}
                @elseif ($record->status === \App\Enums\RequestStatus::Approved)
                    {{ __('Deducted when the period opens') }}
                @elseif ($record->status === \App\Enums\RequestStatus::Pending)
                    {{ __('Reserved') }}
                @else
                    {{ __('Not deducted') }}
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>
