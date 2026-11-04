<div class="space-y-6">
    @include('security.partials.page-header', ['title' => 'My Profile', 'description' => 'Your account details.'])
    @include('profile.partials.nav')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Account information">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail label="Full name">{{ $user->name }}</x-ui.detail>
                <x-ui.detail label="Username">{{ $user->username }}</x-ui.detail>
                <x-ui.detail label="Email">{{ $user->email }}</x-ui.detail>
                <x-ui.detail label="Role"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail label="Status"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail label="Member since"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>

            <div class="border-t border-slate-200 px-5 py-3.5">
                <x-ui.button :href="route('profile.identity')" variant="secondary" size="sm">Change username or email</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="Your name" class="self-start">
            <form wire:submit="save" class="space-y-4 p-5">
                <x-ui.input label="Full name" id="profile-name" autocomplete="name" wire:model="name" />

                <x-ui.button type="submit" loading="save">Save name</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
