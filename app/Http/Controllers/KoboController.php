<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json($this->authPayload($request));
    }

    public function authRefresh(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        return response()->json($this->authPayload($request));
    }

    public function initialization(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        $base = $this->settings->publicBaseUrl($request).'/kobo/'.$token;

        return response()->json([
            'Resources' => [
                'library_sync' => $base.'/v1/library/sync',
                'library_metadata' => $base.'/v1/library/{Ids}/metadata',
                'reading_state' => $base.'/v1/library/{Ids}/state',
                'delete_entitlement' => $base.'/v1/library/{Ids}',
                'post_analytics_event' => $base.'/v1/analytics/event',
                'image_host' => $this->settings->publicBaseUrl($request),
                'image_url_template' => $base.'/{ImageId}/{width}/{height}/false/image.jpg',
                'image_url_quality_template' => $base.'/{ImageId}/{width}/{height}/{Quality}/false/image.jpg',
            ],
        ])->header('x-kobo-apitoken', $token);
    }

    public function sync(Request $request, string $token): JsonResponse
    {
        $this->ensureValidToken($token);

        $disk = Storage::disk((string) config('bookdrop.storage_disk'));
        $books = Book::query()
            ->orderBy('uploaded_at')
            ->get()
            ->filter(fn (Book $book): bool => $disk->exists($book->stored_path))
            ->map(fn (Book $book): array => [
                'NewEntitlement' => [
                    'BookEntitlement' => $this->bookEntitlement($book),
                    'BookMetadata' => $this->bookMetadata($book, $request, $token),
                ],
            ])
            ->values();

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
        return [
            'AccessToken' => $this->settings->koboToken(),
            'RefreshToken' => $this->settings->koboToken(),
            'TokenType' => 'Bearer',
            'TrackingId' => (string) Str::uuid(),
            'UserKey' => $request->input('UserKey', 'bookdrop'),
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
