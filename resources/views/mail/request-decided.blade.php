<x-mail::message>
# {{ $approved ? __('Your request was approved') : __('Your request was rejected') }}

{{ __('Hello :name,', ['name' => $recipientName]) }}

{{ __('Your request has been decided.') }}

- **{{ __('Type') }}:** {{ $type }}
@if ($category !== null)
- **{{ __('Category') }}:** {{ $category }}
@endif
- **{{ __('When') }}:** {{ $when }} ({{ $length }})
- **{{ __('Status') }}:** {{ $outcome }}

<x-mail::button :url="$url">
{{ __('View request') }}
</x-mail::button>

{{ __('Sign in to see who decided and any note.') }}
</x-mail::message>
