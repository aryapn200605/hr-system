<x-mail::message>
# Reset your password

Hello {{ $name }},

We received a request to reset the password of your {{ config('app.name') }} account.

<x-mail::button :url="$url">
Reset password
</x-mail::button>

This link expires in {{ $expiresInMinutes }} minutes. You will still need your authenticator app to log in. If you did not ask for this, ignore this email and your password stays the same.
</x-mail::message>
