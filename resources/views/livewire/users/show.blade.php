<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight wrap-anywhere text-slate-900">{{ $user->name }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <x-ui.role-badge :user="$user" />
                <x-ui.status-badge :status="$user->status" />
            </p>
        </div>

        <x-ui.button variant="secondary" size="sm" class="self-start" :href="route('users.index')">Back to users</x-ui.button>
    </header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Account information">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail label="ID">{{ $user->id }}</x-ui.detail>
                <x-ui.detail label="Full name">{{ $user->name }}</x-ui.detail>
                <x-ui.detail label="Username">{{ $user->username }}</x-ui.detail>
                <x-ui.detail label="Email">{{ $user->email }}</x-ui.detail>
                <x-ui.detail label="Role"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail label="Status"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail label="Created"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>
        </x-ui.card>

        <x-ui.card title="Security">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail label="Two-factor authentication">
                    @if ($user->hasCompletedTwoFactorEnrollment())
                        <x-ui.badge tone="green">Enabled</x-ui.badge>
                    @else
                        <x-ui.badge>Not enabled</x-ui.badge>
                    @endif
                </x-ui.detail>
                @if ($user->two_factor_enrolled_at)
                    <x-ui.detail label="Enabled on"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                @endif
                <x-ui.detail label="Last login">
                    @if ($user->last_login_at)
                        <x-ui.datetime :value="$user->last_login_at" />
                    @else
                        Never
                    @endif
                </x-ui.detail>
            </dl>
        </x-ui.card>
    </div>
</div>
