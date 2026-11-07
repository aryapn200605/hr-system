@include('errors.layout', [
    'code' => 503,
    'icon' => 'refresh',
    'title' => 'Down for maintenance',
    'message' => 'The system is being updated and will be back shortly. Please try again in a few minutes.',
    'retry' => true,
])
