<x-layouts::app title="Request leave" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'My requests', 'href' => route('requests.index')],
    ['label' => 'Request leave'],
]">
    <div class="mx-auto w-full max-w-md space-y-6">
        <x-ui.page-header title="Request leave" description="Working days are Monday to Friday, without public holidays." />

        @if ($blockedReason)
            <x-ui.alert tone="warning">{{ $blockedReason }}</x-ui.alert>
        @endif

        @error('form')
            <x-ui.alert tone="danger">{{ $message }}</x-ui.alert>
        @enderror

        <x-ui.card>
            <form method="POST" action="{{ route('requests.leave.store') }}" enctype="multipart/form-data" class="space-y-4 py-2">
                @csrf

                <x-ui.select label="Category" name="leave_category_id" required>
                    <option value="">Choose a category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('leave_category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input label="First day" name="start_date" type="date" required :value="old('start_date')" />
                <x-ui.input label="Last day" name="end_date" type="date" required :value="old('end_date')" />
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
