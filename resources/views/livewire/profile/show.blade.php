<x-ui.page :title="__('My Profile')" :subtitle="__('Your account details.')">
    @include('profile.partials.nav')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Account information')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Full name')">{{ $user->name }}</x-ui.detail>
                <x-ui.detail :label="__('Username')">{{ $user->username }}</x-ui.detail>
                <x-ui.detail :label="__('Email')">{{ $user->email }}</x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail :label="__('Member since')"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>

            <div class="border-t border-slate-200 px-5 py-3.5">
                <x-ui.button :href="route('profile.identity')" variant="secondary" size="sm">{{ __('Change username or email') }}</x-ui.button>
            </div>
        </x-ui.card>

        <div class="space-y-6 self-start">
            <x-ui.card :title="__('Your name')">
                <form wire:submit="save" class="space-y-4 p-5">
                    <x-ui.input :label="__('Full name')" id="profile-name" autocomplete="name" wire:model="name" />

                    <x-ui.button type="submit" loading="save">{{ __('Save name') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Language')">
                <form wire:submit="saveLocale" class="space-y-4 p-5">
                    <x-ui.select :label="__('Interface language')" id="profile-locale" wire:model="locale" :hint="__('Pages and emails to you use this language.')">
                        @foreach ($languages as $language)
                            <option value="{{ $language->code }}" lang="{{ $language->code }}">{{ $language->native_name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.button type="submit" loading="saveLocale">{{ __('Save language') }}</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-ui.page>
