<x-mail::message>
# {{ __('A request is waiting for your decision') }}

{{ __('Hello :name,', ['name' => $recipientName]) }}

{{ __(':name submitted a request that you can decide.', ['name' => $requesterName]) }}

- **{{ __('Type') }}:** {{ $type }}
@if ($category !== null)
- **{{ __('Category') }}:** {{ $category }}
@endif
- **{{ __('When') }}:** {{ $when }} ({{ $length }})

<x-mail::button :url="$url">
{{ __('Review request') }}
</x-mail::button>

{{ __('Sign in to see the details and decide. Another decider may have decided it already.') }}
</x-mail::message>
