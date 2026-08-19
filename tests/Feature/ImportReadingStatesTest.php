<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportReadingStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
    }

    public function test_it_seeds_progress_for_books_that_have_none(): void
    {
        $book = $this->book();

        $this->artisan('bookdrop:import-reading-states', ['file' => $this->file([
            ['content_id' => $book->id, 'status' => 1, 'percent' => 33, 'seconds' => 6560],
        ])])->expectsOutputToContain('Imported 1, skipped 0 already reporting, 0 unknown.')
            ->assertSuccessful();

        $state = $book->fresh()->readingState;

        $this->assertSame('Reading', $state->status);
        $this->assertSame(33.0, $state->progress_percent);
        $this->assertSame(109, $state->spent_reading_minutes); // 6560s rounded down to minutes
        $this->assertSame(1, $state->times_started_reading);
    }

    public function test_it_never_overwrites_progress_the_device_already_reported(): void
    {
        $book = $this->book();
        ReadingState::query()->create([
            'book_id' => $book->id,
            'status' => ReadingState::STATUS_READING,
            'progress_percent' => 80,
        ]);

        // The snapshot is older than what the device has since reported; importing it would move
        // the reader backwards.
        $this->artisan('bookdrop:import-reading-states', ['file' => $this->file([
            ['content_id' => $book->id, 'status' => 1, 'percent' => 12, 'seconds' => 60],
        ])])->expectsOutputToContain('Imported 0, skipped 1 already reporting, 0 unknown.')
            ->assertSuccessful();

        $this->assertSame(80.0, $book->fresh()->readingState->progress_percent);
    }

    public function test_unknown_books_are_reported_not_silently_dropped(): void
    {
        $this->artisan('bookdrop:import-reading-states', ['file' => $this->file([
            ['content_id' => '00000000-0000-0000-0000-000000000000', 'status' => 2, 'percent' => 100],
        ])])->expectsOutputToContain('Imported 0, skipped 0 already reporting, 1 unknown.')
            ->assertSuccessful();

        $this->assertSame(0, ReadingState::query()->count());
    }

    public function test_finished_and_unread_statuses_map_correctly(): void
    {
        $finished = $this->book('finished.epub');
        $unread = $this->book('unread.epub');

        $this->artisan('bookdrop:import-reading-states', ['file' => $this->file([
            ['content_id' => $finished->id, 'status' => 2, 'percent' => 100, 'seconds' => 1032],
            ['content_id' => $unread->id, 'status' => 0, 'percent' => 0, 'seconds' => 0],
        ])])->assertSuccessful();

        $this->assertSame('Finished', $finished->fresh()->readingState->status);
        $this->assertSame('ReadyToRead', $unread->fresh()->readingState->status);
        $this->assertSame(0, $unread->fresh()->readingState->times_started_reading);
    }

    public function test_a_missing_file_fails_loudly(): void
    {
        $this->artisan('bookdrop:import-reading-states', ['file' => '/nonexistent.json'])
            ->assertFailed();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'states').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    private function book(string $filename = 'book.epub'): Book
    {
        $book = Book::query()->create([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'original_filename' => $filename,
            'stored_path' => 'books/'.$filename,
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => now(),
        ]);

        Storage::disk('local')->put($book->stored_path, 'epub');

        return $book;
    }
}
