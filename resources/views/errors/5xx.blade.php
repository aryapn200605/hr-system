@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'icon' => 'alert-triangle',
    'title' => 'Something went wrong',
    'message' => 'An unexpected problem stopped this page. Try again in a moment. If it keeps happening, contact your HR Admin.',
    'retry' => true,
])
