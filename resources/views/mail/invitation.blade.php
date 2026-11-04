<x-mail::message>
# {{ $resent ? __('Your new invitation') : __('You are invited') }}

{{ __('Hello :name,', ['name' => $name]) }}

@if ($resent)
{{ __('Here is a new link to activate your :app account. Any earlier link no longer works.', ['app' => config('app.name')]) }}
@else
{{ __('An account has been created for you on :app. Activate it to choose a password and set up two-factor authentication.', ['app' => config('app.name')]) }}
@endif

<x-mail::button :url="$url">
{{ __('Activate account') }}
</x-mail::button>

{{ __('This link works once and expires in :hours hours. If you were not expecting it, ignore this email.', ['hours' => $expiresInHours]) }}
</x-mail::message>
