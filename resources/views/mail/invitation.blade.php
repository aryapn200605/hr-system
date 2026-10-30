<x-mail::message>
# {{ $resent ? 'Your new invitation' : 'You are invited' }}

Hello {{ $name }},

@if ($resent)
Here is a new link to activate your {{ config('app.name') }} account. Any earlier link no longer works.
@else
An account has been created for you on {{ config('app.name') }}. Activate it to choose a password and set up two-factor authentication.
@endif

<x-mail::button :url="$url">
Activate account
</x-mail::button>

This link works once and expires in {{ $expiresInHours }} hours. If you were not expecting it, ignore this email.
</x-mail::message>
