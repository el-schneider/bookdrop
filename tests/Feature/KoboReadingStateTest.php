<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ReadingState;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KoboReadingStateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => self::TOKEN,
            'public_base_url' => 'https://bookdrop.test',
        ]);
    }

    /**
     * Verbatim payload captured from a real Kobo on firmware 4.45.23697. Note StatusInfo carries
     * no TimesStartedReading: an implementation requiring it rejects the device's first write.
     */
    public function test_it_persists_the_payload_a_real_device_sends(): void
    {
        $book = $this->book();

        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [[
                'CurrentBookmark' => [
                    'ContentSourceProgressPercent' => 0,
                    'LastModified' => '2026-08-19T20:20:39Z',
                    'Location' => [
                        'Source' => 'OEBPS/Text/content0002b.xhtml',
                        'Type' => 'KoboSpan',
                        'Value' => 'kobo.1.1',
                    ],
                    'ProgressPercent' => 0,
                ],
                'EntitlementId' => $book->id,
                'LastModified' => '2026-08-19T20:20:39Z',
                'Statistics' => [
                    'LastModified' => '2026-08-19T20:20:39Z',
                    'RemainingTimeMinutes' => 321,
                    'SpentReadingMinutes' => 0,
                ],
                'StatusInfo' => [
                    'LastModified' => '2026-08-19T20:20:39Z',
                    'Status' => 'Reading',
                ],
            ]],
        ])->assertOk()->assertJsonPath('RequestResult', 'Success');

        $state = $book->fresh()->readingState;

        $this->assertNotNull($state, 'the device write must be persisted, not acknowledged and dropped');
        $this->assertSame('Reading', $state->status);
        $this->assertSame(0.0, $state->progress_percent);
        $this->assertSame(321, $state->remaining_time_minutes);
        $this->assertSame(0, $state->spent_reading_minutes);
        $this->assertSame('kobo.1.1', $state->location_value);
        $this->assertSame('KoboSpan', $state->location_type);
        $this->assertSame('OEBPS/Text/content0002b.xhtml', $state->location_source);
        $this->assertSame(1, $state->times_started_reading);
    }

    public function test_a_partial_update_only_touches_what_it_reports(): void
    {
        $book = $this->book();
        $this->putState($book, ['ProgressPercent' => 42], 'Reading', ['SpentReadingMinutes' => 10]);

        // Statistics only: progress and status must survive untouched.
        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [['Statistics' => ['SpentReadingMinutes' => 99]]],
        ])->assertOk()->assertJsonMissingPath('UpdateResults.0.CurrentBookmarkResult');

        $state = $book->fresh()->readingState;
        $this->assertSame(99, $state->spent_reading_minutes);
        $this->assertSame(42.0, $state->progress_percent);
        $this->assertSame('Reading', $state->status);
    }

    public function test_reading_state_is_returned_to_the_device_as_an_array(): void
    {
        $book = $this->book();
        $this->putState($book, ['ProgressPercent' => 37.5], 'Reading');

        $response = $this->getJson($this->url("v1/library/{$book->id}/state"));

        $response->assertOk()
            ->assertJsonPath('0.EntitlementId', $book->id)
            ->assertJsonPath('0.StatusInfo.Status', 'Reading')
            ->assertJsonPath('0.CurrentBookmark.ProgressPercent', 37.5);
    }

    public function test_whole_percentages_are_emitted_as_integers(): void
    {
        $book = $this->book();
        $this->putState($book, ['ProgressPercent' => 50.0], 'Reading');

        // Decoded, not substring-matched: "ProgressPercent":50.5 contains "ProgressPercent":50.
        $percent = $this->getJson($this->url("v1/library/{$book->id}/state"))
            ->json('0.CurrentBookmark.ProgressPercent');

        $this->assertSame(50, $percent);
    }

    public function test_an_unreported_book_gets_no_invented_state_on_a_direct_read(): void
    {
        $book = $this->book();

        // Answering with a synthetic ReadyToRead would hand the device empty progress for a book
        // whose real position only the device knows.
        $this->getJson($this->url("v1/library/{$book->id}/state"))
            ->assertOk()
            ->assertExactJson([]);

        $this->assertSame(0, ReadingState::query()->count());
    }

    public function test_a_partial_bookmark_does_not_wipe_the_location(): void
    {
        $book = $this->book();
        $this->putState($book, [
            'ProgressPercent' => 20,
            'Location' => ['Value' => 'kobo.5.1', 'Type' => 'KoboSpan', 'Source' => 'OEBPS/ch5.xhtml'],
        ], 'Reading');

        // A later report carrying only a percentage must not erase the position.
        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [['CurrentBookmark' => ['ProgressPercent' => 24]]],
        ])->assertOk();

        $state = $book->fresh()->readingState;
        $this->assertSame(24.0, $state->progress_percent);
        $this->assertSame('kobo.5.1', $state->location_value);
        $this->assertSame('OEBPS/ch5.xhtml', $state->location_source);
    }

    public function test_an_unrecognised_status_leaves_the_stored_one_alone(): void
    {
        $book = $this->book();
        $this->putState($book, ['ProgressPercent' => 80], 'Finished');

        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [['StatusInfo' => ['Status' => 'SomethingElse']]],
        ])->assertOk();

        $this->assertSame('Finished', $book->fresh()->readingState->status);
    }

    public function test_a_device_supplied_reading_count_wins_over_inference(): void
    {
        $book = $this->book();

        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [['StatusInfo' => ['Status' => 'Reading', 'TimesStartedReading' => 7]]],
        ])->assertOk();

        $this->assertSame(7, $book->fresh()->readingState->times_started_reading);
    }

    public function test_progress_for_an_unknown_book_fails_loudly_instead_of_vanishing(): void
    {
        // Silently acknowledging would discard the device's only copy of that progress.
        $this->putJson($this->url('v1/library/'.Str::uuid()->toString().'/state'), [
            'ReadingStates' => [['StatusInfo' => ['Status' => 'Reading']]],
        ])->assertNotFound()->assertJsonPath('RequestResult', 'Failure');
    }

    public function test_a_progress_update_reaches_the_device_once_then_stops(): void
    {
        $book = $this->book();

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk();

        $this->putState($book, ['ProgressPercent' => 25], 'Reading');

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));

        $second->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.ChangedReadingState.ReadingState.CurrentBookmark.ProgressPercent', 25)
            ->assertJsonPath('0.ChangedReadingState.ReadingState.EntitlementId', $book->id);

        $this->withHeader('x-kobo-synctoken', (string) $second->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_books_and_states_share_the_page_budget_without_spinning(): void
    {
        config()->set('bookdrop.sync_item_limit', 1);

        $first = $this->book();
        $second = $this->book('second.epub');
        $this->putState($first, ['ProgressPercent' => 15], 'Reading');

        // Walk the pages the way a device does, with a hard stop: a budget-exhausted response
        // that always claims "continue" would loop here forever.
        $token = null;
        $records = [];
        $requests = 0;

        do {
            $response = $this->withHeader('x-kobo-synctoken', (string) $token)
                ->getJson($this->url('v1/library/sync'));
            $response->assertOk();

            $this->assertLessThanOrEqual(1, count($response->json()), 'a page must respect the limit');

            $records = array_merge($records, $response->json());
            $token = $response->headers->get('x-kobo-synctoken');
            $requests++;
        } while ($response->headers->get('x-kobo-sync') === 'continue' && $requests < 10);

        $this->assertLessThan(10, $requests, 'sync must terminate rather than spin');

        $kinds = array_map(fn (array $record): string => array_key_first($record), $records);
        $this->assertSame(2, count(array_filter($kinds, fn ($k) => $k === 'NewEntitlement')));
        $this->assertSame(1, count(array_filter($kinds, fn ($k) => $k === 'ChangedReadingState')));
        $this->assertNotNull($second->fresh());
    }

    public function test_a_book_the_device_never_reported_gets_no_reading_state_pushed_down(): void
    {
        // The device holds the only copy of its progress. Inventing a state here and syncing it
        // down would overwrite real reading progress with an empty one.
        $this->book();

        $response = $this->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(1);
        $this->assertArrayNotHasKey('ChangedReadingState', $response->json('0'));
        $this->assertSame(0, ReadingState::query()->count(), 'sync must never create a reading state');
    }

    public function test_times_started_counts_runs_not_updates(): void
    {
        $book = $this->book();

        $this->putState($book, ['ProgressPercent' => 5], 'Reading');
        $this->putState($book, ['ProgressPercent' => 9], 'Reading');
        $this->assertSame(1, $book->fresh()->readingState->times_started_reading);

        $this->putState($book, ['ProgressPercent' => 100], 'Finished');
        $this->putState($book, ['ProgressPercent' => 2], 'Reading');
        $this->assertSame(2, $book->fresh()->readingState->times_started_reading);
    }

    private function putState(Book $book, array $bookmark, string $status, array $statistics = []): void
    {
        $this->putJson($this->url("v1/library/{$book->id}/state"), [
            'ReadingStates' => [array_filter([
                'CurrentBookmark' => $bookmark,
                'StatusInfo' => ['Status' => $status],
                'Statistics' => $statistics ?: null,
            ])],
        ])->assertOk();
    }

    private function url(string $path): string
    {
        return '/kobo/'.self::TOKEN.'/'.$path;
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
            'uploaded_at' => '2026-05-14 06:00:00',
        ]);

        Storage::disk('local')->put($book->stored_path, 'epub');

        return $book;
    }
}
