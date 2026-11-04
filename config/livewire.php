<?php

/*
 * Only the keys this project changes. Livewire merges its own defaults for every other key.
 */
return [

    'temporary_file_upload' => [
        'disk' => null,
        // A first check at upload time; the request services validate the stored file again.
        'rules' => ['required', 'file', 'max:'.max(1, (int) env('EVIDENCE_MAX_KB', 5120))],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'jpg', 'jpeg'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
