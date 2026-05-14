<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class LibraryCoverController
{
    public function __invoke(Book $book, EpubMetadataExtractor $metadataExtractor): Response
    {
        $disk = (string) config('bookdrop.storage_disk');

        if (! Storage::disk($disk)->exists($book->stored_path)) {
            abort(404);
        }

        $cover = $metadataExtractor->cover(Storage::disk($disk)->path($book->stored_path));

        if ($cover === null) {
            abort(404);
        }

        return response($cover['data'], 200, [
            'Content-Type' => $cover['mime'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
