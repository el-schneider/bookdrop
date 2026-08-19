<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\Book;
use App\Models\Setting;
use App\Services\SettingsService;
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

    public function test_reading_services_host_never_points_at_these_tokenised_routes(): void
    {
        // The device discards any path in reading_services_host, so it can only ever reach the
        // root routes. These tokenised endpoints exist solely to keep the storage logic tested.
        $host = $this->getJson($this->url('v1/initialization'))
            ->assertOk()
            ->json('Resources.reading_services_host');

        $this->assertSame('', (string) parse_url($host, PHP_URL_PATH), 'a path here is silently dropped by the device');
    }

    public function test_an_empty_store_answers_without_an_etag_so_the_device_uploads(): void
    {
        $book = $this->book();

        $response = $this->withHeaders($this->deviceAuth())->getJson($this->url("api/v3/content/{$book->id}/annotations"));

        $response->assertOk()->assertJsonPath('annotations', [])->assertJsonPath('nextPageOffsetToken', null);

        // An ETag here would tell the device its local annotations are superseded, and it would
        // delete them. Absence of the header is what invites the upload instead.
        $this->assertNull($response->headers->get('etag'), 'an empty store must never send an ETag');
    }

    public function test_uploaded_annotations_are_stored_verbatim(): void
    {
        $book = $this->book();

        $this->withHeaders($this->deviceAuth())->patchJson($this->url("api/v3/content/{$book->id}/annotations"), [
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
        $this->withHeaders($this->deviceAuth())->patchJson($this->url("api/v3/content/{$book->id}/annotations"), [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk();

        $response = $this->withHeaders($this->deviceAuth())->getJson($this->url("api/v3/content/{$book->id}/annotations"));
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

        $this->withHeaders($this->deviceAuth())->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();
        $first = (string) $this->withHeaders($this->deviceAuth())->getJson($path)->headers->get('etag');

        $this->withHeaders($this->deviceAuth())->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-2')]])->assertOk();
        $second = (string) $this->withHeaders($this->deviceAuth())->getJson($path)->headers->get('etag');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Annotation::query()->count());
    }

    public function test_resending_the_same_annotation_updates_rather_than_duplicates(): void
    {
        $book = $this->book();
        $path = $this->url("api/v3/content/{$book->id}/annotations");

        $this->withHeaders($this->deviceAuth())->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();

        $edited = $this->annotation('anno-1');
        $edited['noteText'] = 'edited note';
        $this->withHeaders($this->deviceAuth())->patchJson($path, ['updatedAnnotations' => [$edited]])->assertOk();

        $this->assertSame(1, Annotation::query()->count());
        $this->assertSame('edited note', Annotation::query()->sole()->note_text);
    }

    public function test_checkforchanges_reports_books_whose_annotations_differ(): void
    {
        $book = $this->book();
        $path = $this->url("api/v3/content/{$book->id}/annotations");

        // Device holds annotations, server has none: must be reported so the upload is triggered.
        $this->withHeaders($this->deviceAuth())->postJson($this->url('api/v3/content/checkforchanges'), [
            ['ContentId' => $book->id, 'etag' => 'W/"something"'],
        ])->assertOk()->assertExactJson([$book->id]);

        $this->withHeaders($this->deviceAuth())->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();
        $etag = (string) $this->withHeaders($this->deviceAuth())->getJson($path)->headers->get('etag');

        // Matching ETag: nothing to do.
        $this->withHeaders($this->deviceAuth())->postJson($this->url('api/v3/content/checkforchanges'), [
            ['ContentId' => $book->id, 'etag' => $etag],
        ])->assertOk()->assertExactJson([]);
    }

    public function test_a_device_with_nothing_to_report_is_not_asked_to_upload(): void
    {
        $book = $this->book();

        // W/"0" is the device saying it holds no annotations either.
        $this->withHeaders($this->deviceAuth())->postJson($this->url('api/v3/content/checkforchanges'), [
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

    public function test_an_unknown_caller_gets_a_safe_empty_answer_never_a_404(): void
    {
        // A 404 on these routes was present when a highlight was destroyed, so an unrecognised
        // caller receives the empty-store shape instead: no ETag, nothing deleted, nothing stored.
        $response = $this->getJson('/api/v3/content/'.Str::uuid()->toString().'/annotations');

        $response->assertOk()->assertJsonPath('annotations', []);
        $this->assertNull($response->headers->get('etag'));
    }

    public function test_the_devices_own_credential_is_pinned_on_first_use(): void
    {
        $book = $this->book();
        $deviceHeader = ['Authorization' => 'Bearer device-issued-token'];

        // Nothing pinned yet: the request is trusted because it names a real book, and the
        // credential is remembered.
        $this->withHeaders($deviceHeader)->patchJson('/api/v3/content/'.$book->id.'/annotations', [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk();

        $this->assertSame('Bearer device-issued-token', app(SettingsService::class)->readingServicesAuth());
        $this->assertSame(1, Annotation::query()->count());
    }

    public function test_a_different_credential_is_refused_once_one_is_pinned(): void
    {
        $book = $this->book();
        $path = '/api/v3/content/'.$book->id.'/annotations';

        $this->withHeaders(['Authorization' => 'Bearer device-issued-token'])
            ->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-1')]])->assertOk();

        // A different credential must not write, even though it names a real book.
        $this->withHeaders(['Authorization' => 'Bearer someone-else'])
            ->patchJson($path, ['updatedAnnotations' => [$this->annotation('anno-2')]])->assertOk();

        $this->assertSame(1, Annotation::query()->count(), 'a mismatched credential must not store');

        // ...and must not read them back either.
        $this->withHeaders(['Authorization' => 'Bearer someone-else'])
            ->getJson($path)->assertOk()->assertJsonPath('annotations', []);
    }

    public function test_an_unauthorised_upload_is_acknowledged_but_not_stored(): void
    {
        $this->patchJson('/api/v3/content/'.Str::uuid()->toString().'/annotations', [
            'updatedAnnotations' => [$this->annotation('anno-1')],
        ])->assertOk();

        $this->assertSame(0, Annotation::query()->count());
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

    /**
     * Store-API paths keep the tokenised prefix; reading-services paths are at the root, because
     * the device discards any path in reading_services_host.
     */
    private function url(string $path): string
    {
        return str_starts_with($path, 'api/v3/')
            ? '/'.$path
            : '/kobo/'.self::TOKEN.'/'.$path;
    }

    /** The Authorization header the device receives from /v1/auth/device. */
    private function deviceAuth(): array
    {
        return ['Authorization' => 'Bearer '.hash_hmac('sha256', 'kobo-auth', self::TOKEN)];
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
