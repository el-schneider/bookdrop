<?php

return [
    'storage_disk' => env('BOOKDROP_STORAGE_DISK', 'bookdrop'),
    'books_path' => trim(env('BOOKDROP_BOOKS_PATH', 'books'), '/'),
    'covers_path' => trim(env('BOOKDROP_COVERS_PATH', 'covers'), '/'),

    // Display only. Timestamps are always stored in UTC (config/app.php pins the app timezone),
    // and are converted to this zone when rendered.
    'display_timezone' => env('BOOKDROP_DISPLAY_TIMEZONE', 'UTC'),

    // Kobo devices time out a sync after roughly 30 seconds, so responses are paged.
    'sync_item_limit' => (int) env('BOOKDROP_SYNC_ITEM_LIMIT', 100),
    'public_base_url' => env('BOOKDROP_PUBLIC_BASE_URL'),

    // Opt-in recording of Kobo device traffic, for building replayable test fixtures.
    // Off by default: the device sends credentials on every request.
    'record_kobo_traffic' => filter_var(env('BOOKDROP_RECORD_KOBO_TRAFFIC', false), FILTER_VALIDATE_BOOL),
    'kobo_traffic_log' => env('BOOKDROP_KOBO_TRAFFIC_LOG', 'kobo-traffic.jsonl'),
];
