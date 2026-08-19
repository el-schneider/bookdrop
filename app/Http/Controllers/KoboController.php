<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class KoboController extends Controller
{
    /** Cover formats the extractor can emit, keyed by the extension used on disk. */
    private const COVER_EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function authDevice(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);
        $this->logKoboRequest('auth.device', $request, [
            'user_key_present' => filled($request->input('UserKey')),
            'device_id_present' => filled($request->header('x-kobo-deviceid')),
        ]);

        return response()->json($this->authPayload($request));
    }

    public function authRefresh(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);
        $this->logKoboRequest('auth.refresh', $request, [
            'user_key_present' => filled($request->input('UserKey')),
            'device_id_present' => filled($request->header('x-kobo-deviceid')),
        ]);

        return response()->json($this->authPayload($request));
    }

    public function initialization(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);
        $this->logKoboRequest('initialization', $request);

        $base = $this->settings->publicBaseUrl($request).'/kobo/'.$token;

        return response()->json([
            'Resources' => [
                'device_auth' => $base.'/v1/auth/device',
                'device_refresh' => $base.'/v1/auth/refresh',
                'library_sync' => $base.'/v1/library/sync',
                'library_metadata' => $base.'/v1/library/{Ids}/metadata',
                'reading_state' => $base.'/v1/library/{Ids}/state',
                'delete_entitlement' => $base.'/v1/library/{Ids}',
                'post_analytics_event' => $base.'/v1/analytics/event',
                'image_host' => $this->settings->publicBaseUrl($request),
                'image_url_template' => $base.'/{ImageId}/{width}/{height}/false/image.jpg',
                'image_url_quality_template' => $base.'/{ImageId}/{width}/{height}/{Quality}/false/image.jpg',
            ],
        ])->header('x-kobo-apitoken', 'e30=');
    }

    public function sync(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        $disk = Storage::disk((string) config('bookdrop.storage_disk'));
        $syncToken = $this->syncToken($request);
        $limit = max(1, (int) config('bookdrop.sync_item_limit'));

        // Books whose file is missing are dropped, so the limit cannot be applied in SQL: an
        // under-filled page would look exhaustive and the cursor would advance past books that
        // were never delivered. Removed books are kept regardless, since their file is gone by
        // design and the device still needs to be told they went away.
        // ponytail: loads all pending rows; fine for a personal library, revisit past ~10k books.
        $candidates = $this->booksForSync($syncToken)
            ->filter(fn (Book $book): bool => $book->trashed() || $disk->exists($book->stored_path))
            ->values();

        $hasMore = $candidates->count() > $limit;
        $page = $candidates->take($limit)->values();

        $entitlements = $page->map(function (Book $book) use ($request, $token, $syncToken): array {
            $entitlement = [
                'BookEntitlement' => $this->bookEntitlement($book),
                'BookMetadata' => $this->bookMetadata($book, $request, $token),
            ];

            // A book the device has never been offered is new; anything else is a change to a
            // book it already knows about, including its removal.
            return $this->isNewToDevice($book, $syncToken)
                ? ['NewEntitlement' => $entitlement]
                : ['ChangedEntitlement' => $entitlement];
        })->values();

        $this->logKoboRequest('library.sync', $request, [
            'sync_token_present' => $syncToken !== null,
            'sync_mode' => $syncToken === null ? 'full' : 'delta',
            'book_count' => $entitlements->count(),
            'removed_count' => $page->filter(fn (Book $book): bool => $book->trashed())->count(),
            'has_more' => $hasMore,
        ]);

        return response()->json($entitlements)
            ->header('x-kobo-sync', $hasMore ? 'continue' : 'complete')
            ->header('x-kobo-synctoken', $this->nextSyncToken($request, $syncToken, $page, $hasMore));
    }

    public function metadata(Request $request, string $token, string $bookId): JsonResponse
    {
        $this->ensureValidToken($token);
        $book = Book::query()->whereKey($bookId)->first();

        if (! $book || ! $this->bookFileExists($book)) {
            return response()->json([]);
        }

        return response()->json([$this->bookMetadata($book, $request, $token)]);
    }

    public function getState(string $token, string $bookId): JsonResponse
    {
        $this->ensureValidToken($token);
        $book = Book::query()->whereKey($bookId)->first();

        // Kobo expects a bare array of reading states, not an object wrapping one.
        return response()->json([[
            'EntitlementId' => $book?->id ?? $bookId,
            'Created' => $this->koboTimestamp($book?->uploaded_at),
            'LastModified' => $this->koboTimestamp(null),
            'PriorityTimestamp' => $this->koboTimestamp(null),
            'StatusInfo' => [
                'LastModified' => $this->koboTimestamp(null),
                'Status' => 'ReadyToRead',
                'TimesStartedReading' => 0,
            ],
            'Statistics' => [
                'LastModified' => $this->koboTimestamp(null),
            ],
            'CurrentBookmark' => [
                'LastModified' => $this->koboTimestamp(null),
            ],
        ]]);
    }

    public function putState(string $token, string $bookId): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json([
            'RequestResult' => 'Success',
            'UpdateResults' => [[
                'EntitlementId' => $bookId,
                'CurrentBookmarkResult' => ['Result' => 'Success'],
                'StatisticsResult' => ['Result' => 'Success'],
                'StatusInfoResult' => ['Result' => 'Success'],
                'LastModified' => now()->toIso8601String(),
                'PriorityTimestamp' => now()->toIso8601String(),
            ]],
        ]);
    }

    public function deleteEntitlement(string $token, string $bookId): Response
    {
        $this->ensureValidToken($token);

        return response()->noContent();
    }

    public function loyaltyBenefits(string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json(['Benefits' => (object) []]);
    }

    public function analyticsTests(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json([
            'Result' => 'Success',
            'TestKey' => (string) $request->header('x-kobo-userkey', ''),
            'Tests' => (object) [],
        ]);
    }

    public function analytics(string $token, ?string $path = null): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json([]);
    }

    public function download(string $token, string $bookId): BinaryFileResponse
    {
        $this->ensureValidToken($token);
        $book = $this->findBook($bookId);

        $this->abortIfMissingFile($book);

        return response()->download(
            Storage::disk((string) config('bookdrop.storage_disk'))->path($book->stored_path),
            $book->original_filename,
            ['Content-Type' => 'application/epub+zip']
        );
    }

    public function cover(string $token, string $bookId, string $width, string $height, ?string $quality = null, ?string $isGreyscale = null): Response
    {
        $this->ensureValidToken($token);
        $book = Book::query()->whereKey($bookId)->first();

        if (! $book || ! $this->bookFileExists($book)) {
            return $this->placeholderCover();
        }

        $cover = $this->cachedCover($book);

        if (! $cover) {
            return $this->placeholderCover();
        }

        return response($cover['data'])
            ->header('Content-Type', $cover['mime'])
            ->header('Cache-Control', 'public, max-age=31536000');
    }

    public function stub(Request $request, string $token, ?string $path = null): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json([]);
    }

    private function ensureValidToken(string $token): void
    {
        abort_unless(hash_equals($this->settings->koboToken(), $token), 404);
    }

    private function authPayload(Request $request): array
    {
        $authToken = hash_hmac('sha256', 'kobo-auth', $this->settings->koboToken());

        return [
            'AccessToken' => $authToken,
            'RefreshToken' => $authToken,
            'TokenType' => 'Bearer',
            'TrackingId' => hash_hmac('sha256', 'kobo-tracking', $this->settings->koboToken()),
            'UserKey' => $request->input('UserKey', 'bookdrop'),
            'ExpiresIn' => 315_360_000,
            'AccessTokenExpiry' => now()->addYears(10)->toIso8601String(),
        ];
    }

    private function findBook(string $bookId): Book
    {
        return Book::query()->whereKey($bookId)->firstOrFail();
    }

    private function abortIfMissingFile(Book $book): void
    {
        abort_unless($this->bookFileExists($book), 404);
    }

    private function bookFileExists(Book $book): bool
    {
        return Storage::disk((string) config('bookdrop.storage_disk'))->exists($book->stored_path);
    }

    private function placeholderCover(): Response
    {
        return response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='))
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * @param  array{revision: int, created: Carbon}|null  $cursor
     */
    private function booksForSync(?array $cursor): Collection
    {
        // Removed books must be included, so soft deletes are not filtered out here. Paging over
        // the monotonic revision means a change made in the same second as the sync that
        // delivered it still lands after the cursor.
        return Book::query()
            ->withTrashed()
            ->when($cursor !== null, fn ($query) => $query->where('revision', '>', $cursor['revision']))
            ->orderBy('revision')
            ->get();
    }

    /**
     * @param  array{revision: int, created: Carbon}|null  $cursor
     */
    private function isNewToDevice(Book $book, ?array $cursor): bool
    {
        if ($book->trashed()) {
            return false;
        }

        if ($cursor === null) {
            return true;
        }

        return ($book->created_at ?? $book->uploaded_at)->greaterThan($cursor['created']);
    }

    /**
     * The device replays this token on its next request. It must identify the last book actually
     * delivered, never "now".
     *
     * @param  array{revision: int, created: Carbon}|null  $cursor
     * @param  \Illuminate\Support\Collection<int, Book>  $page
     */
    private function nextSyncToken(Request $request, ?array $cursor, $page, bool $hasMore): string
    {
        if ($page->isEmpty()) {
            // Nothing was delivered, so the device's position is unchanged. Echoing its own token
            // back avoids advancing past books it has never seen.
            $current = (string) $request->header('x-kobo-synctoken', '');

            return $current !== '' ? $current : $this->encodeSyncToken(0, $this->epoch(), $this->epoch());
        }

        $previous = $cursor['created'] ?? $this->epoch();

        $newest = $page->reduce(
            fn (Carbon $carry, Book $book): Carbon => $carry->max($book->created_at ?? $book->uploaded_at),
            $this->epoch(),
        );

        // Pages are ordered by revision, which need not match creation order, so the newest
        // creation date seen so far is accumulated across the whole run rather than read off the
        // final page alone.
        $pending = ($cursor['pending'] ?? $this->epoch())->max($newest);

        // While pages remain the watermark must stay put: advancing it mid-run would make later
        // pages of the same run report never-seen books as mere changes. Once the run finishes it
        // takes the accumulated maximum, and can only move forward.
        return $hasMore
            ? $this->encodeSyncToken((int) $page->last()->revision, $previous, $pending)
            : $this->encodeSyncToken((int) $page->last()->revision, $previous->max($pending), $this->epoch());
    }

    private function encodeSyncToken(int $revision, Carbon $created, ?Carbon $pending = null): string
    {
        return base64_encode((string) json_encode([
            'v' => 2,
            'revision' => $revision,
            'created' => $created->utc()->toIso8601String(),
            'pending' => ($pending ?? $this->epoch())->utc()->toIso8601String(),
        ]));
    }

    private function epoch(): Carbon
    {
        return Carbon::createFromTimestamp(0)->utc();
    }

    /**
     * The device requests the same cover at several sizes on every sync. Extracting it means
     * unzipping the EPUB, so keep the extracted image on disk and reuse it.
     *
     * @return array{data: string, mime: string}|null
     */
    private function cachedCover(Book $book): ?array
    {
        $disk = Storage::disk((string) config('bookdrop.storage_disk'));
        $directory = trim((string) config('bookdrop.covers_path'), '/');

        foreach (self::COVER_EXTENSIONS as $extension => $mime) {
            $cached = $directory.'/'.$book->id.'.'.$extension;

            if ($disk->exists($cached)) {
                return ['data' => (string) $disk->get($cached), 'mime' => $mime];
            }
        }

        $cover = app(EpubMetadataExtractor::class)->cover($disk->path($book->stored_path));

        if (! $cover) {
            return null;
        }

        // The extractor can return whatever MIME the EPUB declares. Caching an unrecognised type
        // under .jpg would make every later request serve it as image/jpeg regardless of content,
        // so only known formats are cached; the rest are served straight through.
        $extension = array_search($cover['mime'], self::COVER_EXTENSIONS, true);

        if ($extension !== false) {
            $disk->put($directory.'/'.$book->id.'.'.$extension, $cover['data']);
        }

        return $cover;
    }

    private function koboTimestamp(?Carbon $moment): string
    {
        return ($moment ?? now())->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Accepts, in order: the current base64 JSON token; the composite "<iso>|<book id>" token; and
     * the bare ISO token issued before paging existed, which devices in the field still hold. A
     * token minted by Kobo's own store (shape "blob.blob") is unparseable here and correctly
     * degrades to a full sync.
     *
     * @return array{modified: Carbon, id: string|null, created: Carbon}|null
     */
    private function syncToken(Request $request): ?array
    {
        $syncToken = (string) $request->header('x-kobo-synctoken', '');

        if (blank($syncToken)) {
            return null;
        }

        $decoded = json_decode((string) base64_decode($syncToken, true), true);

        if (is_array($decoded) && ($decoded['v'] ?? null) === 2) {
            try {
                return [
                    'revision' => (int) ($decoded['revision'] ?? 0),
                    'created' => Carbon::parse($decoded['created'])->utc(),
                    'pending' => Carbon::parse($decoded['pending'] ?? $decoded['created'])->utc(),
                ];
            } catch (\Throwable) {
                // Fall through to the legacy handling below.
            }
        }

        // Legacy tokens predate revisions: a bare ISO timestamp, or "<iso>|<book id>". Neither can
        // be mapped to a revision, so the library is replayed from the start. Their timestamp is
        // still used as the "created" watermark, so books the device already holds come back as
        // changes rather than being announced as new.
        [$timestamp] = array_pad(explode('|', $syncToken, 2), 2, null);

        try {
            $at = Carbon::parse($timestamp)->utc();

            return ['revision' => 0, 'created' => $at, 'pending' => $at];
        } catch (\Throwable $exception) {
            $this->logKoboRequest('library.sync.invalid_token', $request, [
                'error' => $exception::class,
            ]);

            return null;
        }
    }

    private function logKoboRequest(string $event, Request $request, array $context = []): void
    {
        logger()->warning('Kobo '.$event, $context + [
            'method' => $request->method(),
            'user_agent' => Str::limit((string) $request->userAgent(), 120, ''),
        ]);
    }

    private function bookEntitlement(Book $book): array
    {
        $createdAt = $this->koboTimestamp($book->created_at ?? $book->uploaded_at);

        return [
            'Accessibility' => 'Full',
            'ActivePeriod' => [
                'From' => $createdAt,
            ],
            'Created' => $createdAt,
            'CrossRevisionId' => $book->id,
            'Id' => $book->id,
            'IsHiddenFromArchive' => false,
            'IsLocked' => false,
            // The only way to retire a book from the device.
            'IsRemoved' => $book->trashed(),
            'LastModified' => $this->koboTimestamp($book->updated_at ?? $book->uploaded_at),
            'OriginCategory' => 'Imported',
            'RevisionId' => $book->id,
            'Status' => 'Active',
        ];
    }

    private function bookMetadata(Book $book, Request $request, string $token): array
    {
        $metadata = [
            'Categories' => ['00000000-0000-0000-0000-000000000001'],
            'ContributorRoles' => $book->author ? [[
                'Name' => $book->author,
            ]] : [],
            'Contributors' => $book->author ? [$book->author] : [],
            'CoverImageId' => $book->id,
            'CrossRevisionId' => $book->id,
            'CurrentDisplayPrice' => [
                'CurrencyCode' => 'USD',
                'TotalAmount' => 0,
            ],
            'CurrentLoveDisplayPrice' => [
                'TotalAmount' => 0,
            ],
            'Description' => $book->description ?? '',
            'DownloadUrls' => $this->downloadUrls($book, $request, $token),
            'EntitlementId' => $book->id,
            'ExternalIds' => [],
            'Genre' => '00000000-0000-0000-0000-000000000001',
            'IsEligibleForKoboLove' => false,
            'IsInternetArchive' => false,
            'IsPreOrder' => false,
            'IsSocialEnabled' => true,
            'Language' => $book->language ?: 'en',
            'PhoneticPronunciations' => (object) [],
            'PublicationDate' => $this->koboTimestamp($book->published_at ?? $book->uploaded_at),
            'Publisher' => [
                'Imprint' => '',
                'Name' => $book->publisher ?: 'Bookdrop',
            ],
            'RevisionId' => $book->id,
            'Title' => $book->title,
            'WorkId' => $book->id,
        ];

        if (filled($book->series)) {
            $metadata['Series'] = [
                'Name' => $book->series,
                'Number' => (int) $book->series_index,
                'NumberFloat' => (float) $book->series_index,
                // Deterministic so the same series keeps one identity across books and syncs.
                'Id' => Uuid::uuid5(Uuid::NAMESPACE_DNS, $book->series)->toString(),
            ];
        }

        return $metadata;
    }

    private function downloadUrls(Book $book, Request $request, string $token): array
    {
        $url = $this->settings->publicBaseUrl($request).'/kobo/'.$token.'/v1/books/'.$book->id.'/download';

        return collect(['EPUB3', 'EPUB'])
            ->map(fn (string $format): array => [
                'DrmType' => 'None',
                'Format' => $format,
                'Size' => $book->size_bytes,
                'Platform' => 'Generic',
                'Url' => $url,
            ])
            ->all();
    }

    private function koboDate(Book $book): string
    {
        return $book->uploaded_at->toIso8601String();
    }
}
