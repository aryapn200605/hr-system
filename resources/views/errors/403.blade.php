@include('errors.layout', [
    'code' => 403,
    'icon' => 'lock',
    'title' => 'You do not have access to this page',
    'message' => 'Your role does not allow this page or action. If you think you need it, ask your HR Admin.',
])
