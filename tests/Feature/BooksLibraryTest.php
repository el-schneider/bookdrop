<?php

namespace Tests\Feature;

use App\Livewire\BooksLibrary;
use App\Models\Book;
use App\Models\ReadingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BooksLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        $this->actingAs(User::factory()->create());
    }

    public function test_it_shows_progress_for_books_the_device_has_reported(): void
    {
        $book = $this->book('Tracked Book');
        ReadingState::query()->create([
            'book_id' => $book->id,
            'status' => ReadingState::STATUS_READING,
            'progress_percent' => 33.4,
            'spent_reading_minutes' => 109,
        ]);

        Livewire::test(BooksLibrary::class)
            ->assertSee('Reading')
            ->assertSee('33%')       // fractional percentages are rounded for display
            ->assertSee('1 h 49 m'); // 109 minutes
    }

    public function test_a_book_with_no_reported_state_shows_a_dash_not_a_fake_zero(): void
    {
        $this->book('Untracked Book');

        // Showing "Unread · 0%" would imply the server knows the book is unread. It does not:
        // progress lives on the device until the device reports it.
        Livewire::test(BooksLibrary::class)
            ->assertSee('—')
            ->assertDontSee('0%');
    }

    public function test_timestamps_are_stored_in_utc_and_only_shifted_for_display(): void
    {
        config()->set('bookdrop.display_timezone', 'Europe/Berlin');
        config()->set('app.locale', 'de');
        Carbon::setLocale('de');

        $book = $this->book('Timed Book');
        $book->forceFill(['uploaded_at' => '2026-08-19 20:28:00'])->save();

        // Stored value must stay UTC; only the rendered string moves (+2h in August).
        $this->assertSame('2026-08-19 20:28:00', $book->fresh()->getRawOriginal('uploaded_at'));

        Livewire::test(BooksLibrary::class)->assertSee('19.08.2026 22:28');
    }

    public function test_finished_books_read_as_finished(): void
    {
        $book = $this->book('Done Book');
        ReadingState::query()->create([
            'book_id' => $book->id,
            'status' => ReadingState::STATUS_FINISHED,
            'progress_percent' => 100,
            'spent_reading_minutes' => 42,
        ]);

        Livewire::test(BooksLibrary::class)
            ->assertSee('Finished')
            ->assertSee('100%')
            ->assertSee('42 m');
    }

    public function test_a_sub_minute_session_does_not_read_as_zero_minutes(): void
    {
        $book = $this->book('Briefly Opened');
        ReadingState::query()->create([
            'book_id' => $book->id,
            'status' => ReadingState::STATUS_READING,
            'progress_percent' => 1,
            'spent_reading_minutes' => 0,
        ]);

        Livewire::test(BooksLibrary::class)->assertSee('<1 m');
    }

    private function book(string $title): Book
    {
        $book = Book::query()->create([
            'title' => $title,
            'author' => 'Test Author',
            'original_filename' => 'book.epub',
            'stored_path' => 'books/'.str_replace(' ', '-', strtolower($title)).'.epub',
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => now(),
        ]);

        Storage::disk('local')->put($book->stored_path, 'epub');

        return $book;
    }
}
