<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KoboSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_token_returns_only_newer_books(): void
    {
        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => 'test-token',
            'public_base_url' => 'https://bookdrop.test',
        ]);

        $olderBook = $this->book('Older Book', 'books/older.epub', '2026-05-14 06:00:00');
        $newerBook = $this->book('Newer Book', 'books/newer.epub', '2026-05-14 09:00:00');

        Storage::disk('local')->put($olderBook->stored_path, 'older');
        Storage::disk('local')->put($newerBook->stored_path, 'newer');

        $response = $this->withHeader('x-kobo-synctoken', '2026-05-14T06:48:29+00:00')
            ->getJson('/kobo/test-token/v1/library/sync');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Title', 'Newer Book');
    }

    private function book(string $title, string $storedPath, string $uploadedAt): Book
    {
        return Book::query()->create([
            'title' => $title,
            'author' => 'Test Author',
            'original_filename' => basename($storedPath),
            'stored_path' => $storedPath,
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => $uploadedAt,
        ]);
    }
}
