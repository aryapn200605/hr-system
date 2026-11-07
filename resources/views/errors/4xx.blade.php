@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'icon' => 'alert-triangle',
    'title' => 'This request could not be completed',
    'message' => 'Check the address or go back, then try again.',
])
