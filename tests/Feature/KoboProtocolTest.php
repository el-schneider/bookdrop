<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Setting;
use App\Services\EpubMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

        $response = $this->getJson($this->url("v1/library/{$book->id}/state"));

        $response->assertOk()
            ->assertJsonPath('0.EntitlementId', $book->id)
            ->assertJsonPath('0.StatusInfo.Status', 'ReadyToRead');

        $this->assertIsArray($response->json());
        $this->assertArrayHasKey(0, $response->json(), 'Kobo expects a bare array of reading states');
    }

    public function test_deleting_an_entitlement_returns_no_content(): void
    {
        $book = $this->book('Book', 'books/book.epub', '2026-05-14 06:00:00');

        $this->delete($this->url("v1/library/{$book->id}"))
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
        // skipped entirely. It is composite so books sharing a timestamp stay distinguishable.
        $this->assertSame(
            $books[2]->uploaded_at->toIso8601String().'|'.$books[2]->id,
            $first->headers->get('x-kobo-synctoken')
        );

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

    public function test_a_legacy_bare_timestamp_token_still_resumes_correctly(): void
    {
        // Devices in the field hold a bare ISO token issued before the composite format existed.
        // Their next sync must resume, not restart or skip.
        $books = $this->books(3);

        $response = $this->withHeader('x-kobo-synctoken', $books[0]->uploaded_at->toIso8601String())
            ->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame(['Book 1', 'Book 2'], $this->titlesOf($response));
    }

    public function test_an_empty_library_reports_a_cursor_that_replays_everything(): void
    {
        $response = $this->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(0);
        $this->assertSame(
            Carbon::createFromTimestamp(0)->toIso8601String(),
            $response->headers->get('x-kobo-synctoken'),
            'an empty first sync must not advance past books uploaded later'
        );
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
        $books = $this->books(1);
        $token = $books[0]->uploaded_at->toIso8601String().'|'.$books[0]->id;

        $response = $this->withHeader('x-kobo-synctoken', $token)->getJson($this->url('v1/library/sync'));

        $response->assertOk()->assertJsonCount(0);
        $this->assertSame($token, $response->headers->get('x-kobo-synctoken'));
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
                $titles[] = $item['NewEntitlement']['BookMetadata']['Title'];
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
