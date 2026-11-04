<?php

return [

    /*
    | Largest evidence file (pdf, jpg, png) a request may carry, in kilobytes. Default 5 MB.
    | Livewire's temporary upload limit (config/livewire.php) follows this value.
    */
    'evidence_max_kb' => max(1, (int) env('EVIDENCE_MAX_KB', 5120)),

    /*
    | How many leave and overtime requests one user may submit per minute.
    */
    'submissions_per_minute' => 10,

];
