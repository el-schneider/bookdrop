<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading services run at the site root because the device resolves `reading_services_host` as an
 * origin and discards any path. These routes are in "parking" mode: they must never produce a
 * response that makes the device delete its annotations.
 */
class KoboReadingServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bookdrop.public_base_url', 'https://bookdrop.test');

        Setting::query()->create([
            'id' => 1,
            'kobo_token' => 'test-token',
            'public_base_url' => 'https://bookdrop.test',
        ]);
    }

    public function test_reading_services_host_is_an_origin_with_no_path(): void
    {
        // A path here does not survive: the device discarded it, called the site root and got
        // 404s. It must be the bare origin, which is where the root routes live.
        $host = $this->getJson('/kobo/test-token/v1/initialization')
            ->assertOk()
            ->json('Resources.reading_services_host');

        $this->assertSame('https://bookdrop.test', $host);
        $this->assertSame('', (string) parse_url($host, PHP_URL_PATH));
    }

    public function test_checkforchanges_reports_nothing_so_no_reconciliation_can_delete(): void
    {
        $this->postJson('/api/v3/content/checkforchanges', [
            ['ContentId' => '019e24d5-13f9-7246-9ff6-31ddff35e754', 'etag' => 'W/"abc"'],
        ])->assertOk()->assertExactJson([]);
    }

    public function test_reading_annotations_never_sends_an_etag_while_the_store_is_empty(): void
    {
        $response = $this->getJson('/api/v3/content/019e24d5-13f9-7246-9ff6-31ddff35e754/annotations');

        $response->assertOk()
            ->assertJsonPath('annotations', [])
            ->assertJsonPath('nextPageOffsetToken', null);

        // An ETag alongside an empty list tells the device its copy is superseded, and it deletes.
        $this->assertNull($response->headers->get('etag'), 'an empty store must never send an ETag');
    }

    public function test_uploads_are_acknowledged_without_claiming_storage(): void
    {
        $response = $this->patchJson('/api/v3/content/019e24d5-13f9-7246-9ff6-31ddff35e754/annotations', [
            'updatedAnnotations' => [['id' => 'a1', 'type' => 'highlight']],
        ]);

        $response->assertOk()->assertJsonPath('result', 'ok');
        $this->assertNull($response->headers->get('etag'));
    }

    public function test_the_three_annotation_routes_never_404(): void
    {
        // 404 on these specific routes is the condition present when the device wiped a highlight.
        $id = 'unknown-content-id';

        $this->postJson('/api/v3/content/checkforchanges', [])->assertOk();
        $this->getJson("/api/v3/content/{$id}/annotations")->assertOk();
        $this->patchJson("/api/v3/content/{$id}/annotations", ['updatedAnnotations' => []])->assertOk();
    }

    public function test_other_reading_services_paths_are_logged_and_404(): void
    {
        $this->getJson('/api/v3/something/else')->assertNotFound();
    }

    public function test_application_routes_still_work(): void
    {
        // A previous edit to routes/web.php dropped the auth routes entirely.
        $this->get('/login')->assertOk();
    }
}
