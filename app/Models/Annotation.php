<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'id',
    'book_id',
    'type',
    'payload',
    'highlighted_text',
    'note_text',
    'chapter_filename',
    'chapter_progress',
    'client_last_modified',
])]
class Annotation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'chapter_progress' => 'float',
            'client_last_modified' => 'datetime',
        ];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Device timestamps arrive as ISO-8601 strings; an unparseable one is dropped rather than
     * failing the whole upload.
     */
    private static function deviceTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Builds one record from the device's own annotation object, keeping the original payload
     * intact and lifting only the fields needed for display.
     *
     * @param  array<string, mixed>  $annotation
     * @return array<string, mixed>|null
     */
    public static function fromDevice(string $bookId, array $annotation): ?array
    {
        $id = $annotation['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $span = $annotation['location']['span'] ?? [];

        return [
            'id' => $id,
            'book_id' => $bookId,
            'type' => is_string($annotation['type'] ?? null) ? $annotation['type'] : 'highlight',
            'payload' => $annotation,
            'highlighted_text' => $annotation['highlightedText'] ?? null,
            'note_text' => $annotation['noteText'] ?? null,
            'chapter_filename' => is_array($span) ? ($span['chapterFilename'] ?? null) : null,
            'chapter_progress' => is_array($span) && is_numeric($span['chapterProgress'] ?? null)
                ? (float) $span['chapterProgress']
                : null,
            'client_last_modified' => self::deviceTime($annotation['clientLastModifiedUtc'] ?? null),
        ];
    }
}
