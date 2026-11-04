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

    /*
    | How long the soft review lock of a decider lasts without a heartbeat, in minutes
    | (DECISIONS.md section 6). The approval page renews it every minute while it is open.
    */
    'review_lock_minutes' => 5,

    /*
    | How often the approval page renews its soft lock, in seconds. Must stay well below the lock.
    */
    'review_heartbeat_seconds' => 60,

    /*
    | CSV exports (history and recap). The separator defaults to a semicolon, which Excel expects in
    | Indonesian (and other comma-decimal) locales. One character other than a quote or a line break;
    | anything else falls back to the semicolon.
    */
    'csv_delimiter' => env('CSV_DELIMITER', ';'),

    /*
    | How many CSV exports one user may start per minute.
    */
    'exports_per_minute' => 10,

];
