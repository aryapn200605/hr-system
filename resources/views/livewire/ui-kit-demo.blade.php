<div class="space-y-4 p-5" data-kit-livewire>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.select :label="__('Status')" id="lw-status" :options="$statusOptions" wire:model.live="status" />
        <x-ui.select :label="__('Statuses')" id="lw-statuses" :options="$statusOptions" multiple wire:model.live="statuses" />
        <x-ui.select :label="__('Person (server search)')" id="lw-person" remote="searchPeople" :labels="$personLabels" wire:model.live="person" />
        <x-ui.select :label="__('People (server search)')" id="lw-people" remote="searchPeople" :labels="$personLabels" multiple wire:model.live="people" />
        <x-ui.date-picker :label="__('Date')" id="lw-date" wire:model.live="date" />
        <x-ui.date-picker :label="__('Date range')" id="lw-range" range wire-start="from" wire-end="to" live />
        <x-ui.hour-select :label="__('Hour')" id="lw-hour" wire:model.live="hour" />
        <x-ui.date-hour :legend="__('Start')" id="lw-start" date="date" hour="hour" :date-label="__('Start date')" :hour-label="__('Start time')" />
        <x-ui.otp-input :label="__('Authentication code')" id="lw-code" wire:model.live="code" />
    </div>

    <div class="flex flex-wrap gap-2">
        <x-ui.button variant="secondary" size="sm" wire:click="setFromServer" loading="setFromServer" data-kit-set>{{ __('Set values from the server') }}</x-ui.button>
        <x-ui.button variant="ghost" size="sm" wire:click="clearAll" loading="clearAll" data-kit-reset>{{ __('Clear filters') }}</x-ui.button>
    </div>

    {{-- What the server holds now, so a check can compare it with what the components show. --}}
    <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs" data-kit-state>{{ json_encode($this->only(['status', 'statuses', 'person', 'people', 'date', 'from', 'to', 'hour', 'code'])) }}</pre>
</div>
