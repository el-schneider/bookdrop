<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ReadingState;
use App\Models\Setting;
use App\Services\EpubMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class KoboProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');
        config()->set('bookdrop.covers_path', 'covers');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => self::TOKEN,
            'public_base_url' => 'https://bookdrop.test',
        ]);
    }

    public function test_reading_state_is_returned_as_an_array(): void
    {
        $book = $this->book('Book', 'books/book.epub', '2026-05-14 06:00:00');
        ReadingState::query()->create([
            'book_id' => $book->id,
            'status' => ReadingState::STATUS_READING,
            'progress_percent' => 10,
        ]);

        $response = $this->getJson($this->url("v1/library/{$book->id}/state"));

        $response->assertOk()
            ->assertJsonPath('0.EntitlementId', $book->id)
            ->assertJsonPath('0.StatusInfo.Status', 'Reading');

        $this->assertIsArray($response->json());
        $this->assertArrayHasKey(0, $response->json(), 'Kobo expects a bare array of reading states');
    }

    public function test_deleting_an_entitlement_archives_it_and_confirms_the_removal(): void
    {
        $book = $this->book('Book', 'books/book.epub', '2026-05-14 06:00:00');
        Storage::disk('local')->put($book->stored_path, 'epub');

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertJsonCount(1);

        // The device reports that it dropped the book.
        $this->delete($this->url("v1/library/{$book->id}"))->assertNoContent();

        $this->assertTrue($book->fresh()->trashed(), 'the removal must be recorded, not just acknowledged');

        // Acknowledging without archiving would leave the book live and re-offer it as a normal
        // entitlement, leaving the device with a row claiming a file it no longer has.
        $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.ChangedEntitlement.BookEntitlement.IsRemoved', true);
    }

    public function test_deleting_an_unknown_entitlement_is_still_acknowledged(): void
    {
        $this->delete($this->url('v1/library/'.Str::uuid()->toString()))
            ->assertNoContent();
    }

    public function test_an_exhaustive_batch_does_not_ask_the_device_to_continue(): void
    {
        config()->set('bookdrop.sync_item_limit', 3);
        $this->books(3);

        // Exactly at the limit. Sending "continue" here pins the device cursor and
        // produces a sustained sync loop, so this boundary matters.
        $this->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertHeader('x-kobo-sync', 'complete');
    }

    public function test_an_oversized_batch_pages_and_resumes_where_it_stopped(): void
    {
        config()->set('bookdrop.sync_item_limit', 3);
        $books = $this->books(5);

        $first = $this->getJson($this->url('v1/library/sync'));

        $first->assertOk()
            ->assertJsonCount(3)
            ->assertHeader('x-kobo-sync', 'continue');

        // The token must identify the last book sent, not "now", or the remaining books are
        // skipped entirely. It carries an id so books sharing a timestamp stay distinguishable.
        $cursor = $this->decodeToken((string) $first->headers->get('x-kobo-synctoken'));
        $this->assertSame($books[2]->fresh()->revision, $cursor['revision']);

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));

        $second->assertOk()
            ->assertJsonCount(2)
            ->assertHeader('x-kobo-sync', 'complete')
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Title', 'Book 3');

        $delivered = array_merge(
            array_column(array_column($first->json(), 'NewEntitlement'), 'BookMetadata'),
            array_column(array_column($second->json(), 'NewEntitlement'), 'BookMetadata'),
        );
        $titles = array_column($delivered, 'Title');

        sort($titles);
        $this->assertSame(
            ['Book 0', 'Book 1', 'Book 2', 'Book 3', 'Book 4'],
            $titles,
            'every book must be delivered exactly once across the two pages'
        );
    }

    public function test_covers_are_extracted_once_and_then_served_from_cache(): void
    {
        $book = $this->bookWithCover();
        $expected = $this->pngBytes();

        $first = $this->get($this->url("{$book->id}/200/300/false/image.jpg"));
        $first->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($expected, $first->content());

        Storage::disk('local')->assertExists("covers/{$book->id}.png");

        // Corrupt the EPUB rather than deleting it: a deleted file short-circuits to the
        // placeholder, which is also a PNG, so the test would pass without the cache existing.
        Storage::disk('local')->put($book->stored_path, 'not-a-zip');

        $second = $this->get($this->url("{$book->id}/1400/1000/false/image.jpg"));
        $second->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($expected, $second->content(), 'second response must come from the cache');
    }

    public function test_books_sharing_an_upload_timestamp_are_never_skipped_by_paging(): void
    {
        config()->set('bookdrop.sync_item_limit', 2);

        // Three books in the same second, split across a page boundary. Resuming on the
        // timestamp alone would drop the third permanently.
        foreach (range(0, 2) as $i) {
            $book = $this->book("Same {$i}", "books/same{$i}.epub", '2026-05-14 06:00:00');
            Storage::disk('local')->put($book->stored_path, "epub-{$i}");
        }

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertJsonCount(2)->assertHeader('x-kobo-sync', 'continue');

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));
        $second->assertOk()->assertJsonCount(1)->assertHeader('x-kobo-sync', 'complete');

        $this->assertSame(['Same 0', 'Same 1', 'Same 2'], $this->titlesOf($first, $second));
    }

    public function test_a_missing_file_does_not_make_a_page_look_exhaustive(): void
    {
        config()->set('bookdrop.sync_item_limit', 2);

        $books = $this->books(4);
        // Book 1's file vanishes, so the first page under-fills. The cursor must not jump past
        // the books that were never delivered.
        Storage::disk('local')->delete($books[1]->stored_path);

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertHeader('x-kobo-sync', 'continue');

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));
        $second->assertOk();

        $this->assertSame(['Book 0', 'Book 2', 'Book 3'], $this->titlesOf($first, $second));
    }

    public function test_a_known_book_that_changes_is_reported_as_changed_not_new(): void
    {
        $book = $this->books(1)[0];

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertJsonCount(1)->assertJsonStructure([['NewEntitlement']]);

        $book->forceFill(['title' => 'Corrected Title', 'updated_at' => now()])->save();

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));

        $second->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([['ChangedEntitlement']])
            ->assertJsonPath('0.ChangedEntitlement.BookMetadata.Title', 'Corrected Title');
    }

    public function test_deleting_a_book_tells_the_device_to_remove_it(): void
    {
        $book = $this->books(1)[0];

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertJsonCount(1);

        // Deleting removes the file too, so the removal must survive the missing-file filter.
        Storage::disk('local')->delete($book->stored_path);
        $book->delete();

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));

        $second->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.ChangedEntitlement.BookEntitlement.IsRemoved', true)
            ->assertJsonPath('0.ChangedEntitlement.BookEntitlement.Id', $book->id);
    }

    public function test_extracted_metadata_reaches_the_device(): void
    {
        $book = $this->books(1)[0];
        $book->forceFill([
            'series' => 'The Culture',
            'series_index' => '2',
            'description' => 'A description.',
            'language' => 'de',
            'publisher' => 'Orbit',
        ])->save();

        $response = $this->getJson($this->url('v1/library/sync'));

        $response->assertOk()
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Series.Name', 'The Culture')
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Series.Number', 2)
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Language', 'de')
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Publisher.Name', 'Orbit')
            ->assertJsonPath('0.NewEntitlement.BookMetadata.Description', 'A description.');

        // Same series name must yield the same id on every sync, or the device sees a new series.
        $again = $this->getJson($this->url('v1/library/sync'));
        $this->assertSame(
            $response->json('0.NewEntitlement.BookMetadata.Series.Id'),
            $again->json('0.NewEntitlement.BookMetadata.Series.Id')
        );
    }

    public function test_books_stay_new_across_a_paged_first_sync(): void
    {
        config()->set('bookdrop.sync_item_limit', 2);
        $this->books(4);

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertHeader('x-kobo-sync', 'continue')->assertJsonStructure([['NewEntitlement']]);

        // Advancing the "created" watermark mid-run would demote the rest of the same first sync
        // to ChangedEntitlement, for books the device has never seen.
        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));

        $second->assertOk()->assertJsonStructure([['NewEntitlement']]);
    }

    public function test_the_created_watermark_survives_pages_ordered_differently_from_creation(): void
    {
        config()->set('bookdrop.sync_item_limit', 1);

        $newest = $this->book('Newest', 'books/newest.epub', '2026-06-01 00:00:00');
        $oldest = $this->book('Oldest', 'books/oldest.epub', '2026-05-01 00:00:00');
        Storage::disk('local')->put($newest->stored_path, 'a');
        Storage::disk('local')->put($oldest->stored_path, 'b');

        // Touch the older-created book last so it sorts last by revision: the final page then
        // holds the OLDEST creation date. A watermark read off the final page alone would settle
        // below the newest book and later re-announce it as new.
        $oldest->forceFill(['title' => 'Oldest'])->save();

        $token = null;
        do {
            $response = $this->withHeader('x-kobo-synctoken', (string) $token)
                ->getJson($this->url('v1/library/sync'));
            $response->assertOk();
            $token = $response->headers->get('x-kobo-synctoken');
        } while ($response->headers->get('x-kobo-sync') === 'continue');

        $this->assertSame(
            $newest->created_at->utc()->toIso8601String(),
            $this->decodeToken((string) $token)['created'],
            'the watermark must reach the newest creation date seen anywhere in the run'
        );

        // Proof of the consequence: editing the newest book must be a change, never a new book.
        $newest->forceFill(['title' => 'Newest Edited'])->save();

        $this->withHeader('x-kobo-synctoken', (string) $token)
            ->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.ChangedEntitlement.BookMetadata.Title', 'Newest Edited');
    }

    public function test_a_legacy_token_replays_the_library_without_re_announcing_known_books(): void
    {
        // A device in the field holds a bare ISO token predating revisions. It cannot be mapped
        // to a revision, so the library is replayed. Books the device already has must come back
        // as changes; only books created after its token are genuinely new to it.
        $books = $this->books(3);

        $response = $this->withHeader('x-kobo-synctoken', $books[0]->uploaded_at->toIso8601String())
            ->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(3);
        $this->assertSame(['Book 0', 'Book 1', 'Book 2'], $this->titlesOf($response));

        $this->assertArrayHasKey('ChangedEntitlement', $response->json('0'), 'already-held book');
        $this->assertArrayHasKey('NewEntitlement', $response->json('1'), 'uploaded after the token');
    }

    public function test_a_change_made_in_the_same_second_as_a_sync_still_reaches_the_device(): void
    {
        $book = $this->books(1)[0];

        $first = $this->getJson($this->url('v1/library/sync'));
        $first->assertOk()->assertJsonCount(1);

        // Same wall-clock second as the sync above. A timestamp cursor could not distinguish this
        // from "already delivered" and would drop the change permanently.
        $book->forceFill(['title' => 'Edited Same Second'])->save();

        $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.ChangedEntitlement.BookMetadata.Title', 'Edited Same Second');
    }

    public function test_a_removal_is_delivered_once_and_not_repeated(): void
    {
        $book = $this->books(1)[0];

        $first = $this->getJson($this->url('v1/library/sync'));
        $book->delete();

        $second = $this->withHeader('x-kobo-synctoken', (string) $first->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'));
        $second->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.ChangedEntitlement.BookEntitlement.IsRemoved', true);

        // A removal that repeats forever would make every sync non-empty.
        $this->withHeader('x-kobo-synctoken', (string) $second->headers->get('x-kobo-synctoken'))
            ->getJson($this->url('v1/library/sync'))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_an_empty_library_reports_a_cursor_that_replays_everything(): void
    {
        $response = $this->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(0);

        $cursor = $this->decodeToken((string) $response->headers->get('x-kobo-synctoken'));

        $this->assertSame(0, $cursor['revision'], 'an empty first sync must not advance past later uploads');
        $this->assertSame(Carbon::createFromTimestamp(0)->utc()->toIso8601String(), $cursor['created']);
    }

    public function test_an_unrecognised_cover_format_is_served_but_never_cached(): void
    {
        $book = $this->bookWithCover();

        // Caching an unknown MIME under .jpg would make later requests mislabel it as JPEG.
        $this->mock(EpubMetadataExtractor::class)
            ->shouldReceive('cover')
            ->andReturn(['data' => 'gif-bytes', 'mime' => 'image/gif']);

        $this->get($this->url("{$book->id}/200/300/false/image.jpg"))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');

        foreach (['jpg', 'png', 'webp'] as $extension) {
            Storage::disk('local')->assertMissing("covers/{$book->id}.{$extension}");
        }
    }

    public function test_an_empty_response_does_not_advance_the_devices_cursor(): void
    {
        $this->books(1);

        $first = $this->getJson($this->url('v1/library/sync'));
        $token = (string) $first->headers->get('x-kobo-synctoken');

        $second = $this->withHeader('x-kobo-synctoken', $token)->getJson($this->url('v1/library/sync'));

        $second->assertOk()->assertJsonCount(0);
        $this->assertSame($token, $second->headers->get('x-kobo-synctoken'));
    }

    public function test_loyalty_and_analytics_stubs_use_the_shapes_the_device_expects(): void
    {
        // Asserted on the raw body: an empty PHP array and an empty object both decode to [],
        // so a decoded comparison cannot tell {} from [] on the wire.
        $benefits = $this->getJson($this->url('v1/user/loyalty/benefits'));
        $benefits->assertOk();
        $this->assertSame('{"Benefits":{}}', $benefits->content());

        $tests = $this->withHeader('x-kobo-userkey', 'device-key')
            ->getJson($this->url('v1/analytics/gettests'));
        $tests->assertOk();
        $this->assertStringContainsString('"Tests":{}', $tests->content());
        $tests->assertJsonPath('Result', 'Success')->assertJsonPath('TestKey', 'device-key');
    }

    private function url(string $path): string
    {
        return '/kobo/'.self::TOKEN.'/'.$path;
    }

    /**
     * Titles delivered across the given sync responses, sorted, to assert exactly-once delivery.
     *
     * @return array<int, string>
     */
    private function titlesOf(TestResponse ...$responses): array
    {
        $titles = [];

        foreach ($responses as $response) {
            foreach ($response->json() as $item) {
                // An item is either a NewEntitlement or a ChangedEntitlement.
                $entitlement = $item['NewEntitlement'] ?? $item['ChangedEntitlement'];
                $titles[] = $entitlement['BookMetadata']['Title'];
            }
        }

        sort($titles);

        return $titles;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
    }

    /** @return array<int, Book> */
    private function books(int $count): array
    {
        $books = [];

        for ($i = 0; $i < $count; $i++) {
            $books[] = $this->book("Book {$i}", "books/book{$i}.epub", '2026-05-14 06:0'.$i.':00');
            Storage::disk('local')->put("books/book{$i}.epub", "epub-{$i}");
        }

        return $books;
    }

    private function bookWithCover(): Book
    {
        $book = $this->book('Covered', 'books/covered.epub', '2026-05-14 06:00:00');

        $path = Storage::disk('local')->path($book->stored_path);
        @mkdir(dirname($path), 0777, true);

        $png = $this->pngBytes();

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container/>');
        $zip->addFromString('OEBPS/cover.png', $png);
        $zip->close();

        return $book;
    }

    private function book(string $title, string $storedPath, string $uploadedAt): Book
    {
        $book = Book::query()->create([
            'title' => $title,
            'author' => 'Test Author',
            'original_filename' => basename($storedPath),
            'stored_path' => $storedPath,
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => $uploadedAt,
        ]);

        // A real upload stamps all three at once. Leaving created_at/updated_at at "now" while
        // uploaded_at sits in the past would make every fixture look freshly modified.
        return $book->forceFill(['created_at' => $uploadedAt, 'updated_at' => $uploadedAt])->saveQuietly()
            ? $book->refresh()
            : $book;
    }

    /** @return array<string, mixed> */
    private function decodeToken(string $token): array
    {
        return (array) json_decode((string) base64_decode($token, true), true);
    }
}
