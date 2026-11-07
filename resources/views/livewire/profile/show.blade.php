<x-ui.page :title="__('My Profile')" :subtitle="__('Your account details.')" icon="user">
    @include('profile.partials.nav')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Account information')" icon="user">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Full name')">{{ $user->name }}</x-ui.detail>
                <x-ui.detail :label="__('Username')">{{ $user->username }}</x-ui.detail>
                <x-ui.detail :label="__('Email')">{{ $user->email }}</x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="$user->status" dot /></x-ui.detail>
                <x-ui.detail :label="__('Member since')"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>

            <x-slot:footer>
                <x-ui.button :href="route('profile.identity')" variant="secondary" size="sm"><x-ui.icon name="pencil" class="size-4" />{{ __('Change username or email') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <div class="space-y-6 self-start">
            <x-ui.card :title="__('Your name')" icon="pencil">
                <form id="profile-name-form" wire:submit="save" class="p-5">
                    <x-ui.input :label="__('Full name')" id="profile-name" icon="user" autocomplete="name" wire:model="name" />
                </form>

                <x-slot:footer>
                    <x-ui.button type="submit" form="profile-name-form" size="sm" loading="save">{{ __('Save name') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card :title="__('Language')" icon="globe" tone="teal">
                <form id="profile-locale-form" wire:submit="saveLocale" class="p-5">
                    <x-ui.select :label="__('Interface language')" id="profile-locale" wire:model="locale" :hint="__('Pages and emails to you use this language.')"
                        :options="$languages->map(fn ($language) => ['value' => $language->code, 'label' => $language->native_name, 'lang' => $language->code])->all()" />
                </form>

                <x-slot:footer>
                    <x-ui.button type="submit" form="profile-locale-form" size="sm" loading="saveLocale">{{ __('Save language') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </div>
    </div>
</x-ui.page>
