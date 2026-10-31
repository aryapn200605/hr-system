<x-layouts::app title="Request overtime" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'My requests', 'href' => route('requests.index')],
    ['label' => 'Request overtime'],
]">
    <div class="mx-auto w-full max-w-md space-y-6">
        <x-ui.page-header title="Request overtime" :description="'Whole hours in WIB, at most '.$maxHours.' hours. It can run past midnight.'" />

        @if ($blockedReason)
            <x-ui.alert tone="warning">{{ $blockedReason }}</x-ui.alert>
        @endif

        @error('form')
            <x-ui.alert tone="danger">{{ $message }}</x-ui.alert>
        @enderror

        <x-ui.card>
            <form method="POST" action="{{ route('requests.overtime.store') }}" enctype="multipart/form-data" class="space-y-4 py-2">
                @csrf

                {{-- min sets where the browser counts its hour steps from. Without it, that is the value the form came back with. --}}
                <x-ui.input label="Start" name="start_at" type="datetime-local" step="3600" :min="$earliest" required :value="old('start_at')" />
                <x-ui.input label="End" name="end_at" type="datetime-local" step="3600" :min="$earliest" required :value="old('end_at')" />
                <x-ui.textarea label="Reason" name="reason" required maxlength="1000" hint="10 to 1000 characters." :value="old('reason')" />

                @include('requests.partials.evidence-field')

                <x-ui.button type="submit" block :disabled="$blockedReason !== null">Submit request</x-ui.button>
            </form>
        </x-ui.card>

        <p>
            <x-ui.button :href="route('requests.index')" variant="link">Cancel</x-ui.button>
        </p>
    </div>
</x-layouts::app>
