<x-layouts::auth :title="__('Recovery codes')">
    {{-- Reached by a POST (activation, forced enrollment, new codes from Security); the controllers set
         Cache-Control: no-store. The codes are only in this response: copy, download and print happen in the
         browser (resources/js/auth.js), nothing is sent back or kept. --}}
    @if (request()->routeIs('activation.two-factor.store'))
        @include('auth.partials.activation-steps', ['current' => 3])
    @elseif (request()->routeIs('two-factor.enrollment.store'))
        <x-ui.steps class="print:hidden" :steps="[__('Authenticator'), __('Recovery codes')]" :current="2" />
    @endif

    <x-ui.auth-heading icon="key" :title="__('Save your recovery codes')">{{ __('If you lose your authenticator app, a recovery code lets you log in instead.') }}</x-ui.auth-heading>

    @isset($notice)
        <x-ui.alert class="mb-4 print:hidden">{{ $notice }}</x-ui.alert>
    @endisset

    <div
        data-recovery-codes
        data-file-name="{{ str(config('app.name'))->slug() }}-recovery-codes.txt"
        data-file-title="{{ config('app.company') }} {{ config('app.name') }}: {{ __('Recovery codes') }}"
        data-file-note="{{ __('Each code works once. Keep this file somewhere safe and private.') }}"
        data-text-copied="{{ __('Copied to the clipboard.') }}"
        data-text-selected="{{ __('This browser does not allow copying from this page. The codes are selected: press Ctrl+C (or Cmd+C) to copy them.') }}"
        data-text-downloaded="{{ __('The file was created in your browser. Keep it somewhere safe.') }}"
    >
        <ul class="grid grid-cols-1 gap-x-4 gap-y-2 rounded-lg border border-border bg-muted px-4 py-3 font-mono text-sm text-foreground outline-none sm:grid-cols-2" tabindex="-1" aria-label="{{ __('Recovery codes') }}" data-recovery-list>
            @foreach ($recoveryCodes as $recoveryCode)
                <li>{{ $recoveryCode }}</li>
            @endforeach
        </ul>

        <div class="mt-3 grid grid-cols-3 gap-2 print:hidden" data-recovery-tools hidden>
            <x-ui.button variant="secondary" size="sm" data-recovery-copy>
                <x-ui.icon name="copy" class="size-4" />
                {{ __('Copy') }}
            </x-ui.button>
            <x-ui.button variant="secondary" size="sm" data-recovery-download>
                <x-ui.icon name="download" class="size-4" />
                {{ __('Download') }}
            </x-ui.button>
            <x-ui.button variant="secondary" size="sm" data-recovery-print>
                <x-ui.icon name="printer" class="size-4" />
                {{ __('Print') }}
            </x-ui.button>
        </div>
        <p class="mt-2 min-h-5 text-sm text-muted-foreground print:hidden" role="status" aria-live="polite" data-recovery-status></p>

        <x-ui.alert tone="warning" class="mt-2">
            <ul class="grid list-disc gap-1 ps-4">
                <li>{{ __('Shown once: these codes cannot be displayed again after you leave this page.') }}</li>
                <li>{{ __('Single use: each code works only once.') }}</li>
            </ul>
        </x-ui.alert>

        <div class="mt-6 grid gap-4 print:hidden">
            <label class="flex items-start gap-3 text-sm text-foreground">
                <input type="checkbox" class="mt-0.5 size-4 shrink-0 rounded border-input accent-primary" data-recovery-ack>
                <span>{{ __('I have saved my recovery codes somewhere safe.') }}</span>
            </label>

            <x-ui.button :href="$continueUrl ?? route('dashboard')" block class="data-disabled:cursor-not-allowed data-disabled:opacity-50" data-recovery-continue>{{ $continueLabel ?? __('I have saved them') }}</x-ui.button>
        </div>
    </div>
</x-layouts::auth>
