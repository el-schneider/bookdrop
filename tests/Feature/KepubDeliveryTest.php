<?php

namespace Tests\Feature;

use App\Livewire\BooksLibrary;
use App\Models\Book;
use App\Models\Setting;
use App\Models\User;
use App\Services\KepubConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class KepubDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');
        config()->set('bookdrop.kepubs_path', 'kepubs');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => self::TOKEN,
            'public_base_url' => 'https://bookdrop.test',
        ]);
    }

    public function test_a_converted_book_is_offered_only_as_kepub(): void
    {
        $book = $this->book();
        $this->attachKepub($book);

        $formats = $this->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->json('0.NewEntitlement.BookMetadata.DownloadUrls.*.Format');

        // Offering EPUB alongside lets the device choose it, which is the case that loses
        // in-chapter progress and highlights.
        $this->assertSame(['KEPUB'], $formats);
    }

    public function test_an_unconverted_book_is_still_offered_as_epub(): void
    {
        $this->book();

        $formats = $this->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->json('0.NewEntitlement.BookMetadata.DownloadUrls.*.Format');

        $this->assertSame(['EPUB3', 'EPUB'], $formats);
    }

    public function test_a_kepub_recorded_but_missing_on_disk_falls_back_to_epub(): void
    {
        $book = $this->book();
        $book->forceFill(['kepub_path' => 'kepubs/gone.kepub.epub'])->save();

        $formats = $this->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->json('0.NewEntitlement.BookMetadata.DownloadUrls.*.Format');

        $this->assertSame(['EPUB3', 'EPUB'], $formats);
        $this->get($this->url("v1/books/{$book->id}/download"))->assertOk();
    }

    public function test_downloading_a_converted_book_serves_the_kepub_under_a_kepub_filename(): void
    {
        $book = $this->book();
        $this->attachKepub($book);

        $response = $this->get($this->url("v1/books/{$book->id}/download"));

        $response->assertOk();
        // Kobo keys off the .kepub.epub suffix; a plain .epub name makes it treat the file as a
        // normal EPUB and discard the KEPUB features.
        $this->assertStringContainsString('.kepub.epub', (string) $response->headers->get('content-disposition'));
        $this->assertSame('kepub-bytes', $response->streamedContent());
    }

    public function test_deleting_a_book_removes_both_files(): void
    {
        $this->actingAs(User::factory()->create());

        $book = $this->book();
        $this->attachKepub($book);

        Livewire::test(BooksLibrary::class)->call('delete', $book->id);

        Storage::disk('local')->assertMissing($book->stored_path);
        Storage::disk('local')->assertMissing('kepubs/book.kepub.epub');
    }

    public function test_conversion_is_skipped_cleanly_when_kepubify_is_absent(): void
    {
        config()->set('bookdrop.kepubify_path', '/nonexistent/kepubify');

        // Absence must not fail an upload: the book still syncs, just without KEPUB features.
        $converter = new KepubConverter;

        $this->assertNull($converter->convert('books/book.epub'));
    }

    private function attachKepub(Book $book): void
    {
        Storage::disk('local')->put('kepubs/book.kepub.epub', 'kepub-bytes');
        $book->forceFill(['kepub_path' => 'kepubs/book.kepub.epub'])->save();
    }

    private function url(string $path): string
    {
        return '/kobo/'.self::TOKEN.'/'.$path;
    }

    private function book(): Book
    {
        $book = Book::query()->create([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'original_filename' => 'book.epub',
            'stored_path' => 'books/book.epub',
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => now(),
        ]);

        Storage::disk('local')->put($book->stored_path, 'epub-bytes');

        return $book;
    }
}
