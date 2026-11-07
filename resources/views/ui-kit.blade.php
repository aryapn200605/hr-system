<x-layouts::app :title="__('Component gallery')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Component gallery')],
]">
    {{-- Local development only (UiKitController). Every base component in its variants, for the redesign. --}}
    <x-ui.page :title="__('Component gallery')" :subtitle="__('The base components in their variants. Shown only when APP_ENV is local.')">
        <x-slot:actions>
            <x-ui.button size="sm" x-on:click="$dispatch('open-modal', { name: 'kit-dialog' })" data-kit-open-dialog>{{ __('Open dialog') }}</x-ui.button>
        </x-slot:actions>

        <x-ui.card :title="__('Buttons')" data-kit="buttons">
            <div class="space-y-4 p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button>{{ __('Save') }}</x-ui.button>
                    <x-ui.button variant="secondary">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="muted">{{ __('Refresh') }}</x-ui.button>
                    <x-ui.button variant="danger">{{ __('Delete') }}</x-ui.button>
                    <x-ui.button variant="ghost">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button variant="link" href="#">{{ __('Open the request') }}</x-ui.button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button size="sm">{{ __('Save') }}</x-ui.button>
                    <x-ui.button size="lg">{{ __('Save') }}</x-ui.button>
                    <x-ui.button size="icon" variant="secondary" :aria-label="__('Download')"><x-ui.icon name="download" class="size-4" /></x-ui.button>
                    <x-ui.button variant="secondary"><x-ui.icon name="download" class="size-4" />{{ __('Export CSV') }}</x-ui.button>
                    <x-ui.button disabled>{{ __('Disabled') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Badges')" data-kit="badges">
            <div class="space-y-4 p-5">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (['gray', 'primary', 'purple', 'teal', 'green', 'amber', 'red', 'solid', 'outline'] as $tone)
                        <x-ui.badge :tone="$tone">{{ $tone }}</x-ui.badge>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (\App\Enums\RoleName::cases() as $role)
                        <x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge>
                    @endforeach
                    @foreach (\App\Enums\UserStatus::cases() as $status)
                        <x-ui.status-badge :status="$status" />
                    @endforeach
                    @foreach (\App\Enums\RequestStatus::cases() as $status)
                        <x-ui.status-badge :status="$status" />
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-ui.card :title="__('Form fields')" data-kit="fields">
                <div class="space-y-4 p-5">
                    <x-ui.input :label="__('Full name')" id="kit-name" name="kit-name" :placeholder="__('Full name')" />
                    <x-ui.input :label="__('Email')" id="kit-email" name="kit-email" type="email" :hint="__('Work address')" />
                    <x-ui.input :label="__('Username')" id="kit-error" name="kit-error" />
                    <x-ui.select :label="__('Status')" id="kit-status" name="kit-status" :options="collect(\App\Enums\UserStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()" />
                    <x-ui.textarea :label="__('Reason')" id="kit-reason" name="kit-reason" />
                    <x-ui.input :label="__('Evidence (optional)')" id="kit-file" name="kit-file" type="file" />
                    <x-ui.input :label="__('Disabled')" id="kit-disabled" name="kit-disabled" disabled :value="__('Disabled')" />
                </div>
            </x-ui.card>

            <div class="space-y-4">
                <x-ui.card :title="__('Alerts')" data-kit="alerts">
                    <div class="space-y-3 p-5">
                        <x-ui.alert>{{ __('You are viewing this request. You cannot decide it here.') }}</x-ui.alert>
                        <x-ui.alert tone="success" :title="__('Saved.')">{{ __('Two-factor authentication is protecting your account.') }}</x-ui.alert>
                        <x-ui.alert tone="warning">{{ __('Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.') }}</x-ui.alert>
                        <x-ui.alert tone="danger">{{ __('This decision is final. It cannot be undone or changed later.') }}</x-ui.alert>
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('Toasts')" :description="__('Shown under the header on phones and at the bottom right from 640 px.')" data-kit="toasts">
                    <div class="flex flex-wrap gap-2 p-5">
                        @foreach (['success' => __('Saved.'), 'info' => __('There are no other sessions.'), 'warning' => __('Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.'), 'danger' => __('That session has already ended.')] as $tone => $message)
                            <x-ui.button variant="secondary" size="sm" data-message="{{ $message }}" data-tone="{{ $tone }}" x-on:click="$dispatch('toast', { message: $el.dataset.message, tone: $el.dataset.tone })" data-kit-toast>{{ $tone }}</x-ui.button>
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('Menu')" data-kit="menu">
                    <div class="flex flex-wrap items-center gap-4 p-5">
                        <x-ui.dropdown id="kit-menu" :label="__('Actions')" trigger-class="h-8 border border-border px-3 shadow-xs" anchored align="bottom-start">
                            <x-slot:trigger>
                                <span>{{ __('Actions') }}</span>
                                <x-ui.icon name="chevron-down" class="size-4" />
                            </x-slot:trigger>
                            <x-ui.dropdown-item icon="user">{{ __('View details') }}</x-ui.dropdown-item>
                            <x-ui.dropdown-item icon="clipboard">{{ __('Edit') }}</x-ui.dropdown-item>
                            <x-ui.dropdown-item disabled>{{ __('Disabled') }}</x-ui.dropdown-item>
                            <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                            <x-ui.dropdown-item icon="log-out" variant="destructive">{{ __('Delete') }}</x-ui.dropdown-item>
                        </x-ui.dropdown>
                        <x-ui.avatar name="Rina Kartika" />
                        <x-ui.spinner />
                        <x-ui.logo variant="icon" class="size-8" />
                        <x-ui.logo />
                    </div>
                </x-ui.card>
            </div>
        </div>

        @php
            $kitToday = today();
            $kitStatuses = collect(\App\Enums\RequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all();
            $kitDepartments = collect(['Finance', 'Human Resources', 'Information Technology', 'Legal', 'Marketing', 'Operations', 'Procurement', 'Sales', 'Customer Support', 'Quality Assurance', 'Research', 'Logistics', 'Security', 'Facilities'])
                ->mapWithKeys(fn ($name, $i) => [(string) ($i + 1) => ['value' => (string) ($i + 1), 'label' => $name, 'hint' => __(':count employees', ['count' => 3 + $i]), 'disabled' => $i === 4]])->all();
            $kitBlocked = [$kitToday->copy()->addDays(2)->toDateString(), $kitToday->copy()->addDays(9)->toDateString()];
        @endphp

        <x-ui.card :title="__('Form components')" :description="__('A plain form: the values are sent as query parameters and shown below.')" data-kit="form-components">
            {{-- novalidate: the error example below holds an incomplete code on purpose. --}}
            <form method="GET" action="{{ route('ui-kit') }}" class="space-y-4 p-5" data-kit-form novalidate>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.select :label="__('Status')" id="kit-select" name="single" :options="$kitStatuses" :value="request('single')" :hint="__('A short list: type a letter to jump to it.')" />
                    <x-ui.select :label="__('Department')" id="kit-search" name="department" :options="$kitDepartments" :value="request('department')" />
                    <x-ui.select :label="__('Statuses')" id="kit-multi" name="statuses" :options="$kitStatuses" multiple :value="(array) request('statuses', [])" />
                    <x-ui.select :label="__('Departments')" id="kit-multi-search" name="departments" :options="$kitDepartments" multiple :value="(array) request('departments', [])" />
                    <x-ui.date-picker :label="__('Date')" id="kit-date" name="date" :value="request('date')" :min="$kitToday->toDateString()" :max="$kitToday->copy()->addMonths(3)->toDateString()" disable-weekends :disabled-dates="$kitBlocked" :hint="__('From today, three months ahead, working days only.')" />
                    <x-ui.date-picker :label="__('Date range')" id="kit-range" range start-name="from" end-name="to" :start-value="request('from')" :end-value="request('to')" />
                    <x-ui.hour-select :label="__('Hour')" id="kit-hour" name="hour" :value="request('hour', '17:00')" />
                    <x-ui.select :label="__('Disabled')" id="kit-select-disabled" name="disabled-select" :options="$kitStatuses" disabled />
                </div>

                <div class="flex flex-wrap items-start gap-6">
                    <x-ui.otp-input :label="__('Authentication code')" id="kit-otp" name="code" :value="request('code', '')" :hint="__('6 digits')" />
                    <x-ui.otp-input :label="__('Authentication code')" id="kit-otp-error" name="code-error" value="12" :error="__('This code is invalid.')" />
                    <x-ui.otp-input :label="__('Disabled')" id="kit-otp-disabled" name="code-disabled" value="123456" disabled />
                </div>

                <x-ui.button type="submit" data-kit-submit>{{ __('Submit') }}</x-ui.button>

                <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs" data-kit-submitted>{{ json_encode(\Illuminate\Support\Arr::only(request()->query(), ['single', 'department', 'statuses', 'departments', 'date', 'from', 'to', 'hour', 'code'])) }}</pre>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Form components with Livewire')" :description="__('Bound with wire:model, with array values, a server search and values changed by the server.')" data-kit="livewire-components">
            <livewire:ui-kit-demo />
        </x-ui.card>

        <x-ui.card :title="__('Inside a scrolling box')" data-kit="clip">
            <div class="h-28 overflow-hidden p-5" data-kit-clip>
                <div class="max-w-xs">
                    <x-ui.select :label="__('Department')" id="kit-clip-select" :options="$kitDepartments" />
                </div>
            </div>
        </x-ui.card>

        <section class="space-y-4" data-kit="stats">
            <h2 class="text-lg font-semibold tracking-tight">{{ __('Figures') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.stat :label="__('Annual leave available')" icon="calendar" :value="__(':count days', ['count' => 12])" :hint="__('Current period')" />
                <x-ui.stat :label="__('My pending requests')" icon="clipboard" value="2" />
                <x-ui.stat :label="__('Waiting for my decision')" icon="inbox" value="0" :hint="__('Nothing is waiting for your decision.')" />
                <x-ui.stat :label="__('Recovery codes left')" icon="shield" value="8" :hint="__('Each code works once')" />
            </div>
        </section>

        <x-ui.card :title="__('Table')" data-kit="table">
            <x-ui.table :caption="__('Table')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Full name') }}</th>
                        <th scope="col">{{ __('Role') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Member since') }}</th>
                        <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </x-slot:head>
                {{-- Phase 15e: below 1024 px each row is a card; every cell names its column (data-label). --}}
                @foreach ([['Rina Kartika', \App\Enums\RoleName::HrAdmin, \App\Enums\UserStatus::Active], ['Budi Santoso', \App\Enums\RoleName::Approver, \App\Enums\UserStatus::Pending], ['Siti Aminah', \App\Enums\RoleName::Employee, \App\Enums\UserStatus::Suspended]] as [$name, $role, $status])
                    <tr>
                        <td class="font-medium" data-card="title">{{ $name }}</td>
                        <td data-label="{{ __('Role') }}"><x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge></td>
                        <td data-label="{{ __('Status') }}"><x-ui.status-badge :status="$status" dot /></td>
                        <td data-label="{{ __('Member since') }}"><x-ui.date :value="now()->startOfYear()" /></td>
                        <td class="text-right" data-card="menu">
                            <x-ui.row-actions :id="'kit-row-'.$loop->index" :label="__('Actions for :name', ['name' => $name])">
                                <x-ui.dropdown-item icon="eye">{{ __('View details') }}</x-ui.dropdown-item>
                                <x-ui.dropdown-item icon="pencil">{{ __('Edit') }}</x-ui.dropdown-item>
                            </x-ui.row-actions>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <x-ui.pagination :paginator="new \Illuminate\Pagination\LengthAwarePaginator(range(1, 10), 42, 10, 2)" />
        </x-ui.card>

        {{-- Phase 15e: the pieces of the application pages (15d) and the auth pages (15c), so the gallery shows every final component. --}}
        <x-ui.card :title="__('Page parts')" icon="list" data-kit="parts">
            <x-ui.toolbar>
                <div class="flex items-end sm:col-span-2 xl:w-auto!">
                    <x-ui.segmented :label="__('Request type')" :options="['leave' => __('Leave'), 'overtime' => __('Overtime')]" value="leave" property="kitType" />
                </div>
                <x-ui.select :label="__('Status')" id="kit-toolbar-status" :options="$kitStatuses" />
                <x-slot:actions>
                    <x-ui.button variant="ghost" size="sm">{{ __('Clear filters') }}<x-ui.icon name="close" class="size-4" /></x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
            <div class="grid grid-cols-1 gap-6 p-5 lg:grid-cols-2">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2" data-kit-chips>
                        @foreach (['primary', 'green', 'amber', 'red', 'violet', 'teal', 'gray'] as $tone)
                            <x-ui.icon-chip icon="calendar" :tone="$tone" />
                        @endforeach
                    </div>
                    <x-ui.auth-heading :title="__('Log in')" icon="log-in" :level="2" class="mb-0!" />
                    <p class="flex flex-wrap items-center gap-2"><x-ui.role-badge :user="auth()->user()" /><x-ui.badge tone="amber">{{ __('No supervisor') }}</x-ui.badge></p>
                    <x-ui.steps :steps="[__('Password'), __('Authenticator'), __('Recovery codes')]" :current="2" />
                    <x-ui.password-input :label="__('Password')" id="kit-password" name="kit-password" autocomplete="new-password" />
                    <x-ui.submit-button icon="log-in">{{ __('Log in') }}</x-ui.submit-button>
                </div>
                <x-ui.card :title="__('Section card')" icon="wallet" tone="green">
                    <p class="px-5 py-4 text-sm text-muted-foreground">{{ __('The base components in their variants. Shown only when APP_ENV is local.') }}</p>
                    <x-slot:footer>
                        <x-ui.button variant="secondary" size="sm"><x-ui.icon name="pencil" class="size-4" />{{ __('Edit') }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </div>
            <x-ui.table-skeleton class="block!" :rows="2" :columns="4" />
        </x-ui.card>

        <x-ui.card :title="__('Error pages')" icon="alert-triangle" tone="red" data-kit="errors">
            <div class="space-y-4 p-5">
                <p class="text-sm text-muted-foreground">{{ __('Each link opens the page with its real status code.') }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Http\Controllers\UiKitController::ERROR_CODES as $code)
                        <x-ui.button variant="secondary" size="sm" :href="route('ui-kit.error', $code)" data-kit-error="{{ $code }}">{{ $code }}</x-ui.button>
                    @endforeach
                </div>
                <p class="text-sm text-muted-foreground">{{ __('A Livewire action that fails shows one of these dialogs instead of the raw response.') }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ([419, 403, 429, 500] as $code)
                        <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('request-failed', { status: {{ $code }} })" data-kit-failure="{{ $code }}">{{ $code }}</x-ui.button>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-ui.card :title="__('Empty state')" data-kit="empty">
                <x-ui.empty-state :title="__('No decision yet')" :description="__('When one of your requests is approved or rejected, it is shown here.')">
                    <x-ui.button size="sm" variant="secondary">{{ __('Refresh') }}</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>

            <x-ui.card :title="__('Details')" data-kit="details">
                <dl class="divide-y divide-border px-5 py-2">
                    <x-ui.detail :label="__('Full name')">Rina Kartika</x-ui.detail>
                    <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="\App\Enums\UserStatus::Active" /></x-ui.detail>
                    <x-ui.detail :label="__('Member since')"><x-ui.datetime :value="now()->startOfYear()" /></x-ui.detail>
                </dl>
            </x-ui.card>
        </div>
    </x-ui.page>

    <x-ui.modal name="kit-dialog" icon="alert-triangle" tone="danger" :title="__('Delete :name?', ['name' => 'Rina Kartika'])" :description="__('This decision is final. It cannot be undone or changed later.')">
        <p>{{ __('The day counts as a working day again in new requests. Existing requests keep their working days.') }}</p>
        <div class="mt-4 grid grid-cols-1 gap-4">
            <x-ui.select :label="__('Department')" id="kit-dialog-select" :options="$kitDepartments" />
            <x-ui.date-picker :label="__('Date')" id="kit-dialog-date" />
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false" data-kit-close-dialog>{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" x-on:click="open = false">{{ __('Delete') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-layouts::app>
