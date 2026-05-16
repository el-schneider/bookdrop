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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class KoboController extends Controller
{
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
        $books = $this->booksForSync($syncToken)
            ->filter(fn (Book $book): bool => $disk->exists($book->stored_path))
            ->map(fn (Book $book): array => [
                'NewEntitlement' => [
                    'BookEntitlement' => $this->bookEntitlement($book),
                    'BookMetadata' => $this->bookMetadata($book, $request, $token),
                ],
            ])
            ->values();

        $this->logKoboRequest('library.sync', $request, [
            'sync_token_present' => $syncToken !== null,
            'sync_mode' => $syncToken === null ? 'full' : 'delta',
            'book_count' => $books->count(),
        ]);

        return response()->json($books)
            ->header('x-kobo-sync', 'complete')
            ->header('x-kobo-synctoken', now()->toIso8601String());
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

        return response()->json([
            'ReadingState' => [
                'EntitlementId' => $book?->id ?? $bookId,
                'StatusInfo' => [
                    'Status' => 'ReadyToRead',
                ],
                'CurrentBookmark' => null,
                'Statistics' => [
                    'SpentReadingMinutes' => 0,
                ],
            ],
        ]);
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

    public function deleteEntitlement(string $token, string $bookId): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json(['Result' => 'Success']);
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

        $cover = app(EpubMetadataExtractor::class)->cover(
            Storage::disk((string) config('bookdrop.storage_disk'))->path($book->stored_path)
        );

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

    private function booksForSync(?Carbon $syncToken): Collection
    {
        $query = Book::query()->orderBy('uploaded_at');

        if ($syncToken !== null) {
            $query->where('uploaded_at', '>', $syncToken);
        }

        return $query->get();
    }

    private function syncToken(Request $request): ?Carbon
    {
        $syncToken = $request->header('x-kobo-synctoken');

        if (blank($syncToken)) {
            return null;
        }

        try {
            return Carbon::parse($syncToken);
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
        $uploadedAt = $this->koboDate($book);

        return [
            'Accessibility' => 'Full',
            'ActivePeriod' => [
                'From' => $uploadedAt,
            ],
            'Created' => $uploadedAt,
            'CrossRevisionId' => $book->id,
            'Id' => $book->id,
            'IsHiddenFromArchive' => false,
            'IsLocked' => false,
            'IsRemoved' => false,
            'LastModified' => $uploadedAt,
            'OriginCategory' => 'Imported',
            'RevisionId' => $book->id,
            'Status' => 'Active',
        ];
    }

    private function bookMetadata(Book $book, Request $request, string $token): array
    {
        return [
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
            'Description' => '',
            'DownloadUrls' => $this->downloadUrls($book, $request, $token),
            'EntitlementId' => $book->id,
            'ExternalIds' => [],
            'Genre' => '00000000-0000-0000-0000-000000000001',
            'IsEligibleForKoboLove' => false,
            'IsInternetArchive' => false,
            'IsPreOrder' => false,
            'IsSocialEnabled' => true,
            'Language' => 'en',
            'PhoneticPronunciations' => [],
            'PublicationDate' => $this->koboDate($book),
            'Publisher' => [
                'Imprint' => '',
                'Name' => 'Bookdrop',
            ],
            'RevisionId' => $book->id,
            'Title' => $book->title,
            'WorkId' => $book->id,
        ];
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
