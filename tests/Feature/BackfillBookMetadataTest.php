<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillBookMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
    }

    public function test_it_fills_metadata_for_books_uploaded_before_the_fields_existed(): void
    {
        $book = $this->book('books/old.epub');
        Storage::disk('local')->put($book->stored_path, 'epub');

        $this->mock(EpubMetadataExtractor::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn($this->metadata());

        $this->artisan('bookdrop:backfill-metadata')
            ->expectsOutputToContain('Backfilled 1 book(s), skipped 0.')
            ->assertSuccessful();

        $book->refresh();
        $this->assertSame('The Culture', $book->series);
        $this->assertSame('Orbit', $book->publisher);
    }

    public function test_a_book_whose_file_is_gone_is_skipped_not_failed(): void
    {
        $this->book('books/missing.epub');

        $this->mock(EpubMetadataExtractor::class)->shouldReceive('extract')->never();

        $this->artisan('bookdrop:backfill-metadata')
            ->expectsOutputToContain('Backfilled 0 book(s), skipped 1.')
            ->assertSuccessful();
    }

    public function test_books_that_already_have_metadata_are_left_alone(): void
    {
        $book = $this->book('books/done.epub');
        Storage::disk('local')->put($book->stored_path, 'epub');
        $book->forceFill(['series' => 'Existing'])->save();
        $before = $book->fresh()->updated_at;

        $this->mock(EpubMetadataExtractor::class)->shouldReceive('extract')->never();

        $this->artisan('bookdrop:backfill-metadata')->assertSuccessful();

        // updated_at must not move, or every book would resync to the device for nothing.
        $this->assertEquals($before, $book->fresh()->updated_at);
    }

    public function test_force_re_extracting_identical_metadata_does_not_touch_the_row(): void
    {
        $book = $this->book('books/same.epub');
        Storage::disk('local')->put($book->stored_path, 'epub');

        $this->mock(EpubMetadataExtractor::class)
            ->shouldReceive('extract')
            ->twice()
            ->andReturn($this->metadata());

        $this->artisan('bookdrop:backfill-metadata')->assertSuccessful();
        $after = $book->fresh();

        // Re-running with identical metadata must be a no-op. Saving would bump revision and
        // resend every book to the device for nothing.
        $this->artisan('bookdrop:backfill-metadata', ['--force' => true])
            ->expectsOutputToContain('Backfilled 0 book(s), skipped 0.')
            ->assertSuccessful();

        $this->assertEquals($after->updated_at, $book->fresh()->updated_at);
        $this->assertSame($after->revision, $book->fresh()->revision);
    }

    /** @return array<string, string|null> */
    private function metadata(): array
    {
        return [
            'title' => 'Old Book',
            'author' => 'A',
            'series' => 'The Culture',
            'series_index' => '2',
            'description' => 'A description.',
            'language' => 'en',
            'publisher' => 'Orbit',
            'published_at' => '2020-01-02 00:00:00',
        ];
    }

    private function book(string $storedPath): Book
    {
        return Book::query()->create([
            'title' => 'Old Book',
            'author' => 'A',
            'original_filename' => basename($storedPath),
            'stored_path' => $storedPath,
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => '2026-05-13 04:38:17',
        ]);
    }
}
