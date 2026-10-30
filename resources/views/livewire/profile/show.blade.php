<div class="space-y-6">
    <x-ui.page-header title="My Profile" description="Your account details." />

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <x-ui.card title="Account information">
            <dl class="divide-y divide-rule">
                <x-ui.detail label="Full name">{{ $user->name }}</x-ui.detail>
                <x-ui.detail label="Username">{{ $user->username }}</x-ui.detail>
                <x-ui.detail label="Email">{{ $user->email }}</x-ui.detail>
                <x-ui.detail label="Role"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail label="Status"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail label="Member since"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>

            <x-slot:footer>
                <x-ui.button :href="route('profile.identity')" variant="secondary" size="sm">Change username or email</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Your name">
            <form wire:submit="save" class="space-y-4 py-2">
                <x-ui.input label="Full name" id="profile-name" autocomplete="name" wire:model="name" />

                <x-ui.button type="submit" loading="save">Save name</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
