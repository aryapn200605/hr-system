<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request Evidence
    |--------------------------------------------------------------------------
    |
    | The disk keeps evidence out of the public folder and has no serve option,
    | so a file only ever leaves through the controller that checks who asks.
    |
    */

    'evidence_disk' => 'evidence',

    'evidence_max_kb' => (int) env('REQUEST_EVIDENCE_MAX_KB', 5120),

];
