<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records Kobo device traffic to a JSONL file so real sync sessions can be replayed
 * as test fixtures. Opt-in: the device sends credentials on every request, so this
 * stays off unless BOOKDROP_RECORD_KOBO_TRAFFIC is set.
 */
class RecordKoboTraffic
{
    /** Headers that identify the device or authorise it. Never recorded. */
    private const SECRET_HEADERS = [
        'authorization',
        'cookie',
        'x-kobo-userkey',
        'x-kobo-deviceid',
    ];

    /** JSON keys carrying credentials, at any depth of a request or response body. */
    private const SECRET_KEYS = [
        'AccessToken',
        'RefreshToken',
        'TrackingId',
        'UserKey',
    ];

    /** Bodies above this are truncated; a full sync response stays well under it. */
    private const MAX_BODY_BYTES = 524288;

    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('bookdrop.record_kobo_traffic')) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $response = $next($request);

        Storage::disk((string) config('bookdrop.storage_disk'))->append(
            (string) config('bookdrop.kobo_traffic_log'),
            (string) json_encode($this->record($request, $response, $startedAt), JSON_UNESCAPED_SLASHES)
        );

        return $response;
    }

    private function record(Request $request, Response $response, float $startedAt): array
    {
        return [
            'at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request' => [
                'method' => $request->method(),
                'path' => $this->scrub('/'.ltrim($request->path(), '/')),
                'query' => $this->scrub($request->getQueryString() ?? ''),
                'headers' => $this->headers($request->headers->all()),
                'body' => $this->body($request->getContent(), $request->header('content-type')),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => $this->headers($response->headers->all()),
                'body' => $this->body($this->content($response), $response->headers->get('content-type')),
            ],
        ];
    }

    /**
     * BinaryFileResponse (book downloads) and streamed responses have no in-memory
     * content; getContent() returns false for them.
     */
    private function content(Response $response): string
    {
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }

    /**
     * @param  array<string, array<int, string|null>>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $recorded = [];

        foreach ($headers as $name => $values) {
            $recorded[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true)
                ? '[redacted]'
                : $this->scrub(implode(', ', array_map(strval(...), $values)));
        }

        return $recorded;
    }

    /**
     * Cover images and EPUB downloads are binary; record their shape, not their bytes.
     */
    private function body(string $body, ?string $contentType): mixed
    {
        if ($body === '') {
            return null;
        }

        if ($contentType !== null && ! str_contains($contentType, 'json') && ! str_starts_with($contentType, 'text/')) {
            return ['omitted' => $contentType, 'bytes' => strlen($body)];
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['truncated' => true, 'bytes' => strlen($body)];
        }

        $decoded = json_decode($body, true);

        return $decoded === null
            ? $this->scrub($body)
            : $this->redactKeys($decoded);
    }

    private function redactKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? $this->scrub($value) : $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = in_array($key, self::SECRET_KEYS, true)
                ? '[redacted]'
                : $this->redactKeys($item);
        }

        return $redacted;
    }

    /**
     * The sync token is part of every URL the device calls and is echoed inside
     * download and cover URLs in sync responses. Replace it wherever it appears.
     */
    private function scrub(string $value): string
    {
        return str_replace($this->settings->koboToken(), '<token>', $value);
    }
}
