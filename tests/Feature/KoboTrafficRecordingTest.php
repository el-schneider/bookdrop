<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KoboTrafficRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'super-secret-sync-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');
        config()->set('bookdrop.kobo_traffic_log', 'kobo-traffic.jsonl');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => self::TOKEN,
            'public_base_url' => 'https://bookdrop.test',
        ]);
    }

    public function test_nothing_is_recorded_while_recording_is_disabled(): void
    {
        config()->set('bookdrop.record_kobo_traffic', false);

        $this->getJson('/kobo/'.self::TOKEN.'/v1/initialization')->assertOk();

        Storage::disk('local')->assertMissing('kobo-traffic.jsonl');
    }

    public function test_recorded_traffic_never_contains_the_sync_token(): void
    {
        config()->set('bookdrop.record_kobo_traffic', true);

        $book = $this->book();
        Storage::disk('local')->put($book->stored_path, 'epub-bytes');

        // The sync response embeds the token in every download and cover URL.
        $this->getJson('/kobo/'.self::TOKEN.'/v1/library/sync')->assertOk();
        $this->getJson('/kobo/'.self::TOKEN.'/v1/initialization')->assertOk();

        $log = Storage::disk('local')->get('kobo-traffic.jsonl');

        $this->assertStringNotContainsString(self::TOKEN, $log);
        $this->assertStringContainsString('<token>', $log);
    }

    public function test_credentials_in_headers_and_bodies_are_redacted(): void
    {
        config()->set('bookdrop.record_kobo_traffic', true);

        $this->withHeaders([
            'x-kobo-userkey' => 'device-user-key',
            'x-kobo-deviceid' => 'device-serial',
        ])->postJson('/kobo/'.self::TOKEN.'/v1/auth/device', ['UserKey' => 'device-user-key'])
            ->assertOk();

        $log = Storage::disk('local')->get('kobo-traffic.jsonl');
        $entry = json_decode(trim($log), true);

        $this->assertStringNotContainsString('device-user-key', $log);
        $this->assertStringNotContainsString('device-serial', $log);

        $this->assertSame('[redacted]', $entry['request']['headers']['x-kobo-userkey']);
        $this->assertSame('[redacted]', $entry['request']['headers']['x-kobo-deviceid']);
        $this->assertSame('[redacted]', $entry['request']['body']['UserKey']);

        // The auth response hands out long-lived tokens; they must not be logged either.
        foreach (['AccessToken', 'RefreshToken', 'TrackingId', 'UserKey'] as $key) {
            $this->assertSame('[redacted]', $entry['response']['body'][$key]);
        }
    }

    public function test_binary_responses_are_described_rather_than_dumped(): void
    {
        config()->set('bookdrop.record_kobo_traffic', true);

        $this->get('/kobo/'.self::TOKEN.'/missing-book/200/300/false/image.jpg')->assertOk();

        $entry = json_decode(trim(Storage::disk('local')->get('kobo-traffic.jsonl')), true);

        $this->assertArrayHasKey('omitted', $entry['response']['body']);
        $this->assertStringContainsString('image/png', $entry['response']['body']['omitted']);
        $this->assertGreaterThan(0, $entry['response']['body']['bytes']);
    }

    public function test_each_request_appends_one_replayable_line(): void
    {
        config()->set('bookdrop.record_kobo_traffic', true);

        $this->getJson('/kobo/'.self::TOKEN.'/v1/initialization')->assertOk();
        $this->getJson('/kobo/'.self::TOKEN.'/v1/library/sync')->assertOk();

        $lines = array_values(array_filter(explode("\n", Storage::disk('local')->get('kobo-traffic.jsonl'))));

        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            $this->assertIsArray($entry, 'every line must be standalone JSON');
            $this->assertSame('GET', $entry['request']['method']);
            $this->assertSame(200, $entry['response']['status']);
        }

        $this->assertSame('/kobo/<token>/v1/initialization', json_decode($lines[0], true)['request']['path']);
    }

    private function book(): Book
    {
        return Book::query()->create([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'original_filename' => 'book.epub',
            'stored_path' => 'books/book.epub',
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => '2026-05-14 06:00:00',
        ]);
    }
}
