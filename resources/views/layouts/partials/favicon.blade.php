{{-- The round brand mark as the tab icon (PNG, cropped from design/brand/logo.jpg), only when the file exists. --}}
@if (is_file(public_path('brand/favicon-32.png')))
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('brand/logo-icon.png') }}">
@endif
