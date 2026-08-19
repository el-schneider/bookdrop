<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ReadingState;
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
                // reading_services_host is deliberately NOT advertised. It is an ORIGIN, not a
                // base URL: firmware 4.45.23697 discarded the tokenised path, sent annotation
                // calls to the site root, received 404s, and destroyed a highlight on the device.
                // Do not re-add until the root endpoints are proven by an observed sync.
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

        // Reading states travel as standalone records, never inline on an entitlement, so the
        // same state can never be announced twice in one response. They share the page budget
        // with entitlements: the device's ~30s sync timeout applies to the whole response, not
        // to each record type.
        $states = $this->changedReadingStates($syncToken, max(0, $limit - $page->count()));
        $hasMore = $hasMore || $states['has_more'];

        $this->logKoboRequest('library.sync', $request, [
            'sync_token_present' => $syncToken !== null,
            'sync_mode' => $syncToken === null ? 'full' : 'delta',
            'book_count' => $entitlements->count(),
            'removed_count' => $page->filter(fn (Book $book): bool => $book->trashed())->count(),
            'reading_state_count' => count($states['records']),
            'has_more' => $hasMore,
        ]);

        $entitlements = $entitlements->concat($states['records']);

        return response()->json($entitlements)
            ->header('x-kobo-sync', $hasMore ? 'continue' : 'complete')
            ->header('x-kobo-synctoken', $this->nextSyncToken($request, $syncToken, $page, $hasMore, $states['revision']));
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
        $book = Book::query()->with('readingState')->whereKey($bookId)->first();
        $state = $book?->readingState;

        // Nothing invented here: answering with a synthetic ReadyToRead would hand the device an
        // empty progress record for a book whose real position only the device knows.
        if ($state === null) {
            return response()->json([]);
        }

        // Kobo expects a bare array of reading states, not an object wrapping one.
        return response()->json([
            $this->readingStateResponse($book->id, $book->uploaded_at, $state),
        ]);
    }

    public function putState(Request $request, string $token, string $bookId): JsonResponse
    {
        $this->ensureValidToken($token);
        $book = Book::query()->whereKey($bookId)->first();

        if (! $book) {
            // The device holds the only copy of this progress. Acknowledging success while
            // discarding it would lose it silently, so fail visibly instead.
            $this->logKoboRequest('library.state.unknown_book', $request, ['book_id' => $bookId]);

            return response()->json([
                'RequestResult' => 'Failure',
                'UpdateResults' => [[
                    'EntitlementId' => $bookId,
                    'Result' => 'Failure',
                ]],
            ], 404);
        }

        $reported = (array) $request->input('ReadingStates.0', []);
        $state = $book->readingState ?? new ReadingState(['book_id' => $book->id]);
        $results = ['EntitlementId' => $book->id];

        // Every section is optional, and so is every field inside it: firmware 4.45.x sends
        // StatusInfo without TimesStartedReading. Only keys actually present are written, or a
        // partial report would wipe values the device still relies on.
        if (is_array($bookmark = $reported['CurrentBookmark'] ?? null)) {
            if (array_key_exists('ProgressPercent', $bookmark)) {
                $state->progress_percent = $this->nullableFloat($bookmark['ProgressPercent']);
            }

            if (array_key_exists('ContentSourceProgressPercent', $bookmark)) {
                $state->content_source_progress_percent = $this->nullableFloat($bookmark['ContentSourceProgressPercent']);
            }

            if (is_array($location = $bookmark['Location'] ?? null)) {
                $state->location_value = $location['Value'] ?? null;
                $state->location_type = $location['Type'] ?? null;
                $state->location_source = $location['Source'] ?? null;
            }

            $results['CurrentBookmarkResult'] = ['Result' => 'Success'];
        }

        if (is_array($statistics = $reported['Statistics'] ?? null)) {
            if (array_key_exists('SpentReadingMinutes', $statistics)) {
                $state->spent_reading_minutes = $this->nullableInt($statistics['SpentReadingMinutes']);
            }

            if (array_key_exists('RemainingTimeMinutes', $statistics)) {
                $state->remaining_time_minutes = $this->nullableInt($statistics['RemainingTimeMinutes']);
            }

            $results['StatisticsResult'] = ['Result' => 'Success'];
        }

        if (is_array($statusInfo = $reported['StatusInfo'] ?? null)) {
            $status = $this->readStatus($statusInfo['Status'] ?? null);

            // An unrecognised or absent Status leaves the stored one alone; defaulting it to
            // unread would invent a regression and sync it back to the device.
            if ($status !== null) {
                if ($status === ReadingState::STATUS_READING && $state->status !== ReadingState::STATUS_READING) {
                    $state->times_started_reading = (int) $state->times_started_reading + 1;
                    $state->last_time_started_reading = now();
                }

                $state->status = $status;
            }

            // Devices that report their own counters are authoritative; the inference above only
            // covers firmware that omits them.
            if (array_key_exists('TimesStartedReading', $statusInfo) && is_numeric($statusInfo['TimesStartedReading'])) {
                $state->times_started_reading = (int) $statusInfo['TimesStartedReading'];
            }

            if (filled($statusInfo['LastTimeStartedReading'] ?? null)) {
                $state->last_time_started_reading = Carbon::parse($statusInfo['LastTimeStartedReading']);
            }

            $results['StatusInfoResult'] = ['Result' => 'Success'];
        }

        $state->last_modified = now();
        $state->save();

        $this->logKoboRequest('library.state.update', $request, [
            'status' => $state->status,
            'progress_percent' => $state->progress_percent,
        ]);

        return response()->json([
            'RequestResult' => 'Success',
            'UpdateResults' => [$results + [
                'LastModified' => $this->koboTimestamp($state->last_modified),
                'PriorityTimestamp' => $this->koboTimestamp($state->priority_timestamp),
            ]],
        ]);
    }

    /**
     * Null means "not reported", which is different from "reported as unread".
     */
    private function readStatus(mixed $status): ?string
    {
        return match ($status) {
            ReadingState::STATUS_READING => ReadingState::STATUS_READING,
            ReadingState::STATUS_FINISHED => ReadingState::STATUS_FINISHED,
            ReadingState::STATUS_UNREAD => ReadingState::STATUS_UNREAD,
            default => null,
        };
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Whole numbers are emitted as integers: Kobo expects that shape for percentages.
     */
    private function readingStateResponse(string $bookId, ?Carbon $created, ?ReadingState $state): array
    {
        $lastModified = $this->koboTimestamp($state?->last_modified);

        $bookmark = ['LastModified' => $lastModified];

        if ($state?->progress_percent !== null) {
            $bookmark['ProgressPercent'] = $this->cleanProgress($state->progress_percent);
        }

        if ($state?->content_source_progress_percent !== null) {
            $bookmark['ContentSourceProgressPercent'] = $this->cleanProgress($state->content_source_progress_percent);
        }

        if (filled($state?->location_value)) {
            $bookmark['Location'] = [
                'Value' => $state->location_value,
                'Type' => $state->location_type,
                'Source' => $state->location_source,
            ];
        }

        $statistics = ['LastModified' => $lastModified];

        if ($state?->spent_reading_minutes !== null) {
            $statistics['SpentReadingMinutes'] = $state->spent_reading_minutes;
        }

        if ($state?->remaining_time_minutes !== null) {
            $statistics['RemainingTimeMinutes'] = $state->remaining_time_minutes;
        }

        $statusInfo = [
            'LastModified' => $lastModified,
            'Status' => $state?->status ?? ReadingState::STATUS_UNREAD,
            'TimesStartedReading' => (int) ($state?->times_started_reading ?? 0),
        ];

        if ($state?->last_time_started_reading !== null) {
            $statusInfo['LastTimeStartedReading'] = $this->koboTimestamp($state->last_time_started_reading);
        }

        return [
            'EntitlementId' => $bookId,
            'Created' => $this->koboTimestamp($created),
            'LastModified' => $lastModified,
            'PriorityTimestamp' => $this->koboTimestamp($state?->priority_timestamp),
            'StatusInfo' => $statusInfo,
            'Statistics' => $statistics,
            'CurrentBookmark' => $bookmark,
        ];
    }

    private function cleanProgress(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }

    public function deleteEntitlement(string $token, string $bookId): Response
    {
        $this->ensureValidToken($token);

        // The device is telling us it dropped this book. Archiving it here is what makes the next
        // sync confirm the removal with IsRemoved: true. Acknowledging without archiving leaves
        // the book live on the server, which then re-offers it and leaves the device holding a
        // row that claims the book is downloaded while its file is gone.
        Book::query()->whereKey($bookId)->first()?->delete();

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
        $kepub = $this->kepubPath($book);

        if ($kepub === null) {
            $this->abortIfMissingFile($book);
        }

        $disk = Storage::disk((string) config('bookdrop.storage_disk'));

        // Kobo recognises the .kepub.epub suffix; serving converted bytes under a plain .epub
        // name makes the device treat it as a normal EPUB and discard the KEPUB features.
        $filename = $kepub === null
            ? $book->original_filename
            : pathinfo($book->original_filename, PATHINFO_FILENAME).'.kepub.epub';

        return response()->download(
            $disk->path($kepub ?? $book->stored_path),
            $filename,
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
     * Reading states only exist once a device has reported one, so nothing here can invent a
     * state and push it down over progress the server has never been told about.
     *
     * @param  array{revision: int, created: Carbon, state: int}|null  $cursor
     * @return array{records: array<int, array<string, mixed>>, revision: int, has_more: bool}
     */
    private function changedReadingStates(?array $cursor, int $limit): array
    {
        $since = $cursor['state'] ?? 0;

        if ($limit < 1) {
            // No budget left in this page. The cursor stays put so nothing is skipped, and the
            // device is only asked to continue if states are genuinely still pending: claiming
            // "more" on an exhausted-but-empty queue pins the cursor and spins the device.
            return [
                'records' => [],
                'revision' => $since,
                'has_more' => ReadingState::query()->where('revision', '>', $since)->exists(),
            ];
        }

        $states = ReadingState::query()
            ->with('book')
            ->where('revision', '>', $since)
            ->orderBy('revision')
            ->get()
            ->filter(fn (ReadingState $state): bool => $state->book !== null);

        $hasMore = $states->count() > $limit;
        $page = $states->take($limit)->values();

        $records = $page->map(fn (ReadingState $state): array => [
            'ChangedReadingState' => [
                'ReadingState' => $this->readingStateResponse(
                    $state->book->id,
                    $state->book->uploaded_at,
                    $state,
                ),
            ],
        ])->all();

        return [
            'records' => $records,
            'revision' => $page->isEmpty() ? $since : (int) $page->last()->revision,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array{revision: int, created: Carbon, state: int}|null  $cursor
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
     * @param  array{revision: int, created: Carbon, state: int}|null  $cursor
     * @param  \Illuminate\Support\Collection<int, Book>  $page
     */
    private function nextSyncToken(Request $request, ?array $cursor, $page, bool $hasMore, int $stateRevision): string
    {
        if ($page->isEmpty()) {
            // No book changed, but a reading state may still have moved, so the state cursor has
            // to advance even when the book cursor stands still.
            return $this->encodeSyncToken(
                $cursor['revision'] ?? 0,
                $cursor['created'] ?? $this->epoch(),
                $cursor['pending'] ?? $this->epoch(),
                $stateRevision,
            );
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
            ? $this->encodeSyncToken((int) $page->last()->revision, $previous, $pending, $stateRevision)
            : $this->encodeSyncToken((int) $page->last()->revision, $previous->max($pending), $this->epoch(), $stateRevision);
    }

    private function encodeSyncToken(int $revision, Carbon $created, ?Carbon $pending, int $stateRevision): string
    {
        return base64_encode((string) json_encode([
            'v' => 2,
            'revision' => $revision,
            'created' => $created->utc()->toIso8601String(),
            'pending' => ($pending ?? $this->epoch())->utc()->toIso8601String(),
            'state' => $stateRevision,
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
                    // Absent in tokens minted before reading states existed; replaying every
                    // known state once is harmless.
                    'state' => (int) ($decoded['state'] ?? 0),
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

            return ['revision' => 0, 'created' => $at, 'pending' => $at, 'state' => 0];
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

        // A KEPUB is offered on its own: advertising both lets the device pick the plain EPUB,
        // which is exactly the case that loses in-chapter progress and highlights.
        $formats = $this->kepubPath($book) !== null ? ['KEPUB'] : ['EPUB3', 'EPUB'];

        return collect($formats)
            ->map(fn (string $format): array => [
                'DrmType' => 'None',
                'Format' => $format,
                'Size' => $book->size_bytes,
                'Platform' => 'Generic',
                'Url' => $url,
            ])
            ->all();
    }

    /**
     * The converted file, when one exists and is still on disk.
     */
    private function kepubPath(Book $book): ?string
    {
        if (blank($book->kepub_path)) {
            return null;
        }

        $disk = Storage::disk((string) config('bookdrop.storage_disk'));

        return $disk->exists($book->kepub_path) ? $book->kepub_path : null;
    }

    private function koboDate(Book $book): string
    {
        return $book->uploaded_at->toIso8601String();
    }
}
