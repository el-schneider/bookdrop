<?php

return [
    'storage_disk' => env('BOOKDROP_STORAGE_DISK', 'bookdrop'),
    'books_path' => trim(env('BOOKDROP_BOOKS_PATH', 'books'), '/'),
    'public_base_url' => env('BOOKDROP_PUBLIC_BASE_URL'),

    // Opt-in recording of Kobo device traffic, for building replayable test fixtures.
    // Off by default: the device sends credentials on every request.
    'record_kobo_traffic' => filter_var(env('BOOKDROP_RECORD_KOBO_TRAFFIC', false), FILTER_VALIDATE_BOOL),
    'kobo_traffic_log' => env('BOOKDROP_KOBO_TRAFFIC_LOG', 'kobo-traffic.jsonl'),
];
