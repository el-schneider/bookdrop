<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading services run at the site root because the device resolves `reading_services_host` as an
 * origin and discards any path. These tests cover the wiring and the safety rules; the storage
 * behaviour itself is covered by KoboAnnotationSyncTest.
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

    public function test_the_annotation_routes_are_exempt_from_csrf(): void
    {
        // Kobo devices send no CSRF token, so POST/PATCH uploads were rejected with 419 in
        // production. This cannot be caught by a normal request test: PreventRequestForgery
        // short-circuits via runningUnitTests(), so the exemption list is asserted directly.
        $property = new \ReflectionProperty(PreventRequestForgery::class, 'neverVerify');

        $this->assertContains('api/v3/*', $property->getValue(), 'reading-services uploads would 419');
        $this->assertContains('kobo/*', $property->getValue());
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
