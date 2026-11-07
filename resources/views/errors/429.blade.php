@include('errors.layout', [
    'code' => 429,
    'icon' => 'clock',
    'title' => 'Too many attempts',
    'message' => 'You tried this too often in a short time. Wait a minute, then try again.',
])
