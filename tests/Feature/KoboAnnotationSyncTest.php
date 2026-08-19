<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\Book;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KoboAnnotationSyncTest extends TestCase
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

    public function test_reading_services_host_is_not_advertised_from_here(): void
    {
        // Advertising a tokenised path for reading services sent the device's annotation calls to
        // the site root, where they 404'd, and a highlight was destroyed. The device reaches the
        // root routes instead; these tokenised endpoints exist only to keep this logic tested.
        $this->getJson($this->url('v1/initialization'))
            ->assertOk()
            ->assertJsonMissingPath('Resources.reading_services_host');
    }

    public function test_an_empty_store_answers_without_an_etag_so_the_device_uploads(): void
    {
        $book = $this->book();

        $response = $this->getJson($this->url("api/v3/content/{$book->id}/annotations"));

        $response->assertOk()->assertJsonPath('annotations', [])->assertJsonPath('nextPageOffsetToken', null);

        // An ETag here would tell the device its local annotations are superseded, and it would
        // delete them. Absence of the header is what invites the upload instead.
        $this->assertNull($response->headers->get('etag'), 'an empty store must never send an ETag');
    }

    public function test_uploaded_annotations_are_stored_verbatim(): void
    {
        $book = $this->book();

        $this->patchJson($this->url("api/v3/content/{$book->id}/annotations"), [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk()->assertJsonPath('result', 'ok');

        $stored = Annotation::query()->sole();

        $this->assertSame('anno-1', $stored->id);
        $this->assertSame($book->id, $stored->book_id);
        $this->assertSame('note', $stored->type);
        $this->assertSame('OEBPS/ch2.xhtml', $stored->chapter_filename);
        $this->assertSame(0.0909, round((float) $stored->chapter_progress, 4));
        // The original object survives intact, so nothing is lost to a schema we guessed at.
        $this->assertSame('span#kobo.2.1', $stored->payload['location']['span']['spanId']);
    }

    public function test_stored_annotations_come_back_with_an_etag_and_then_304(): void
    {
        $book = $this->book();
        $this->patchJson($this->url("api/v3/content/{$book->id}/annotations"), [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk();

        $response = $this->getJson($this->url("api/v3/content/{$book->id}/annotations"));
        $response->assertOk()->assertJsonCount(1, 'annotations');

        $etag = (string) $response->headers->get('etag');
        $this->assertNotSame('', $etag);

        // Unchanged data must answer 304 so the device stops re-fetching.
        $this->withHeader('If-None-Match', $etag)
            ->getJson($this->url("api/v3/content/{$book->id}/annotations"))
            ->assertStatus(304);
    }

    public function test_the_etag_changes_when_an_annotation_does(): void
    {
        $book = $this->book();
        $path = $this->url("api/v3/content/{$book->id}/annotations");

        $this->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();
        $first = (string) $this->getJson($path)->headers->get('etag');

        $this->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-2')]])->assertOk();
        $second = (string) $this->getJson($path)->headers->get('etag');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Annotation::query()->count());
    }

    public function test_resending_the_same_annotation_updates_rather_than_duplicates(): void
    {
        $book = $this->book();
        $path = $this->url("api/v3/content/{$book->id}/annotations");

        $this->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();

        $edited = $this->annotation('anno-1');
        $edited['noteText'] = 'edited note';
        $this->patchJson($path, ['updatedAnnotations' => [$edited]])->assertOk();

        $this->assertSame(1, Annotation::query()->count());
        $this->assertSame('edited note', Annotation::query()->sole()->note_text);
    }

    public function test_checkforchanges_reports_books_whose_annotations_differ(): void
    {
        $book = $this->book();
        $path = $this->url("api/v3/content/{$book->id}/annotations");

        // Device holds annotations, server has none: must be reported so the upload is triggered.
        $this->postJson($this->url('api/v3/content/checkforchanges'), [
            ['ContentId' => $book->id, 'etag' => 'W/"something"'],
        ])->assertOk()->assertExactJson([$book->id]);

        $this->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();
        $etag = (string) $this->getJson($path)->headers->get('etag');

        // Matching ETag: nothing to do.
        $this->postJson($this->url('api/v3/content/checkforchanges'), [
            ['ContentId' => $book->id, 'etag' => $etag],
        ])->assertOk()->assertExactJson([]);
    }

    public function test_a_device_with_nothing_to_report_is_not_asked_to_upload(): void
    {
        $book = $this->book();

        // W/"0" is the device saying it holds no annotations either.
        $this->postJson($this->url('api/v3/content/checkforchanges'), [
            ['ContentId' => $book->id, 'etag' => 'W/"0"'],
        ])->assertOk()->assertExactJson([]);
    }

    public function test_annotations_for_an_unknown_book_are_acknowledged_and_logged(): void
    {
        $this->patchJson($this->url('api/v3/content/'.Str::uuid()->toString().'/annotations'), [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk()->assertJsonPath('result', 'ok');

        $this->assertSame(0, Annotation::query()->count());
    }

    public function test_annotation_routes_require_the_sync_token(): void
    {
        $book = $this->book();

        $this->getJson('/kobo/wrong-token/api/v3/content/'.$book->id.'/annotations')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function annotation(string $id): array
    {
        // Shaped like the device's own payload. Text kept deliberately trivial.
        return [
            'id' => $id,
            'type' => 'note',
            'highlightedText' => 'sample',
            'noteText' => 'a note',
            'clientLastModifiedUtc' => '2026-08-19T21:34:55Z',
            'location' => [
                'span' => [
                    'chapterFilename' => 'OEBPS/ch2.xhtml',
                    'chapterProgress' => 0.0909,
                    'spanId' => 'span#kobo.2.1',
                ],
            ],
        ];
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

        Storage::disk('local')->put($book->stored_path, 'epub');

        return $book;
    }
}
