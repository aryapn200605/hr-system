<x-mail::message>
# {{ __('Reset your password') }}

{{ __('Hello :name,', ['name' => $name]) }}

{{ __('We received a request to reset the password of your :app account.', ['app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('Reset password') }}
</x-mail::button>

{{ __('This link expires in :minutes minutes. You will still need your authenticator app to log in. If you did not ask for this, ignore this email and your password stays the same.', ['minutes' => $expiresInMinutes]) }}
</x-mail::message>
