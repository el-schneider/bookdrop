<?php

return [
    'storage_disk' => env('BOOKDROP_STORAGE_DISK', 'bookdrop'),
    'books_path' => trim(env('BOOKDROP_BOOKS_PATH', 'books'), '/'),
    'public_base_url' => env('BOOKDROP_PUBLIC_BASE_URL'),
];
