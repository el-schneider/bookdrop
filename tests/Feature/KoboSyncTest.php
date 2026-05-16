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

    public function test_auth_response_uses_stable_long_lived_tokens(): void
    {
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => 'test-token',
            'public_base_url' => 'https://bookdrop.test',
        ]);

        $first = $this->postJson('/kobo/test-token/v1/auth/device', ['UserKey' => 'user-key']);
        $second = $this->postJson('/kobo/test-token/v1/auth/refresh', ['UserKey' => 'user-key']);

        $first->assertOk()
            ->assertJsonPath('TokenType', 'Bearer')
            ->assertJsonPath('UserKey', 'user-key')
            ->assertJsonPath('ExpiresIn', 315_360_000)
            ->assertJsonStructure(['AccessToken', 'RefreshToken', 'TrackingId', 'AccessTokenExpiry']);

        $this->assertSame($first->json('AccessToken'), $second->json('AccessToken'));
        $this->assertSame($first->json('RefreshToken'), $second->json('RefreshToken'));
        $this->assertSame($first->json('TrackingId'), $second->json('TrackingId'));
    }

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

    public function test_invalid_sync_token_falls_back_to_full_sync(): void
    {
        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => 'test-token',
            'public_base_url' => 'https://bookdrop.test',
        ]);

        $book = $this->book('Book', 'books/book.epub', '2026-05-14 06:00:00');
        Storage::disk('local')->put($book->stored_path, 'book');

        $response = $this->withHeader('x-kobo-synctoken', 'corrupt-token')
            ->getJson('/kobo/test-token/v1/library/sync');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Title', 'Book');
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
