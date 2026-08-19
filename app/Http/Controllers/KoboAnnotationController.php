<?php

namespace App\Http\Controllers;

use App\Models\Annotation;
use App\Models\Book;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kobo's annotation sync, which is a separate service from the store API. The device resolves its
 * base from the `reading_services_host` entry of /v1/initialization.
 *
 * Protocol, reverse engineered:
 *   POST  /api/v3/content/checkforchanges       -> flat array of ContentIds that changed
 *   GET   /api/v3/content/{contentId}/annotations
 *   PATCH /api/v3/content/{contentId}/annotations
 *
 * The ETag handshake is load-bearing and easy to get catastrophically wrong: answering a GET with
 * an empty annotation list AND an ETag tells the device its local copy is superseded, and it drops
 * the annotations. Empty list with NO ETag is the signal that makes the device upload instead.
 */
class KoboAnnotationController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function checkForChanges(Request $request): JsonResponse
    {
        if (! $this->authorized($request, null)) {
            return response()->json([]);
        }

        $changed = [];

        foreach ((array) $request->json()->all() as $item) {
            $contentId = is_array($item) ? ($item['ContentId'] ?? null) : null;

            if (! is_string($contentId)) {
                continue;
            }

            $stored = $this->annotationsFor($contentId);
            $deviceEtag = is_array($item) ? (string) ($item['etag'] ?? '') : '';

            // Nothing stored yet but the device claims annotations: report it as changed so the
            // GET happens and the device is prompted to upload them.
            if ($stored->isEmpty()) {
                if ($deviceEtag !== '' && $deviceEtag !== 'W/"0"') {
                    $changed[] = $contentId;
                }

                continue;
            }

            if ($deviceEtag !== $this->etagFor($contentId)) {
                $changed[] = $contentId;
            }
        }

        return response()->json($changed);
    }

    public function index(Request $request, string $contentId): Response
    {
        // Never 404 here: an unrecognised caller gets the same shape as an empty store, which is
        // the response that cannot cause the device to delete anything.
        if (! $this->authorized($request, $contentId)) {
            return response()->json(['annotations' => [], 'nextPageOffsetToken' => null]);
        }

        $stored = $this->annotationsFor($contentId);
        $body = [
            'annotations' => $stored->map(fn (Annotation $a): array => $a->payload)->values()->all(),
            'nextPageOffsetToken' => null,
        ];

        if ($stored->isEmpty()) {
            // Deliberately no ETag: see the class comment. This is what invites the upload.
            return response()->json($body);
        }

        $etag = $this->etagFor($contentId);

        if ((string) $request->header('If-None-Match') === $etag) {
            return response()->noContent(304)->header('etag', $etag);
        }

        return response()->json($body)->header('etag', $etag);
    }

    public function update(Request $request, string $contentId): Response
    {
        if (! $this->authorized($request, $contentId)) {
            return response()->json(['result' => 'ok']);
        }

        $incoming = (array) $request->input('updatedAnnotations', []);
        $book = Book::query()->withTrashed()->whereKey($contentId)->first();

        if (! $book) {
            // Storing would violate the foreign key, and rejecting could make the device retry
            // forever, so this is acknowledged and recorded instead.
            logger()->warning('Kobo annotations for unknown book', ['content_id' => $contentId]);

            return response()->json(['result' => 'ok']);
        }

        $rows = [];

        foreach ($incoming as $annotation) {
            if (! is_array($annotation)) {
                continue;
            }

            $row = Annotation::fromDevice($book->id, $annotation);

            if ($row !== null) {
                // upsert() goes through the query builder, which does not apply model casts, so
                // the payload has to be encoded here rather than handed over as an array.
                $row['payload'] = (string) json_encode($row['payload']);
                $rows[] = $row + ['created_at' => now(), 'updated_at' => now()];
            }
        }

        if ($rows !== []) {
            // The device owns annotation identity, so an id it sends again is the same annotation.
            Annotation::query()->upsert($rows, ['id'], [
                'type', 'payload', 'highlighted_text', 'note_text',
                'chapter_filename', 'chapter_progress', 'client_last_modified', 'updated_at',
            ]);
        }

        logger()->warning('Kobo annotations stored', [
            'book_id' => $book->id,
            'received' => count($incoming),
            'stored' => count($rows),
        ]);

        return response()->json(['result' => 'ok'])->header('etag', $this->etagFor($book->id));
    }

    /**
     * @return Collection<int, Annotation>
     */
    private function annotationsFor(string $contentId): Collection
    {
        return Annotation::query()
            ->where('book_id', $contentId)
            ->orderBy('chapter_progress')
            ->orderBy('id')
            ->get();
    }

    /**
     * Derived from the stored set, so it changes whenever any annotation does and stays stable
     * when nothing has.
     */
    private function etagFor(string $contentId): string
    {
        $fingerprint = DB::table('annotations')
            ->where('book_id', $contentId)
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->map(fn ($row): string => $row->id.'@'.$row->updated_at)
            ->implode('|');

        return 'W/"'.substr(sha1($fingerprint), 0, 16).'"';
    }

    /**
     * Reading services live at the site root, so the sync token cannot travel in the path. The
     * device sends the Authorization header it received from /v1/auth/device, which Bookdrop
     * derives deterministically and can therefore verify without storing anything.
     *
     * A request that fails that check is still served when it names a real book: rejecting it
     * would make the device drop the annotations it is trying to hand over, and a book UUID is
     * unguessable. Both outcomes are logged so the real auth posture can be tightened once the
     * device's behaviour is known.
     */
    private function authorized(Request $request, ?string $contentId): bool
    {
        $expected = 'Bearer '.hash_hmac('sha256', 'kobo-auth', $this->settings->koboToken());
        $presented = (string) $request->header('authorization', '');

        if (hash_equals($expected, $presented)) {
            return true;
        }

        // Falling back to "names a real book" keeps a device whose Authorization header differs
        // from ours working, without letting an anonymous caller write arbitrary rows. Book UUIDs
        // are unguessable. Every fallback is logged so the posture can be tightened once the
        // device's actual header is confirmed.
        $knownBook = $contentId !== null
            && Book::query()->withTrashed()->whereKey($contentId)->exists();

        logger()->warning('Kobo reading services unverified request', [
            'content_id' => $contentId,
            'authorization_present' => $presented !== '',
            'known_book' => $knownBook,
        ]);

        return $knownBook;
    }
}
