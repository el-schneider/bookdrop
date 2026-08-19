<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'book_id',
    'status',
    'times_started_reading',
    'last_time_started_reading',
    'progress_percent',
    'content_source_progress_percent',
    'location_source',
    'location_type',
    'location_value',
    'spent_reading_minutes',
    'remaining_time_minutes',
    'last_modified',
    'priority_timestamp',
])]
class ReadingState extends Model
{
    use HasUuids;

    public const STATUS_UNREAD = 'ReadyToRead';

    public const STATUS_READING = 'Reading';

    public const STATUS_FINISHED = 'Finished';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_time_started_reading' => 'datetime',
            'last_modified' => 'datetime',
            'priority_timestamp' => 'datetime',
            'progress_percent' => 'float',
            'content_source_progress_percent' => 'float',
            'times_started_reading' => 'integer',
            'spent_reading_minutes' => 'integer',
            'remaining_time_minutes' => 'integer',
            'revision' => 'integer',
        ];
    }

    /**
     * Same monotonic scheme as Book::nextRevision(); see the note there for the invariant and its
     * ceiling. Reading states are never deleted, only overwritten, so no revision is ever freed.
     */
    protected static function booted(): void
    {
        static::saving(function (ReadingState $state): void {
            $state->revision = (int) static::query()->max('revision') + 1;
            $state->last_modified ??= now();
            $state->priority_timestamp = $state->last_modified;
        });
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function isRead(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    /**
     * Whole percentages read better in the UI, and Kobo reports fractional values.
     */
    public function percent(): int
    {
        return (int) round($this->progress_percent ?? 0);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_READING => 'Reading',
            self::STATUS_FINISHED => 'Finished',
            default => 'Unread',
        };
    }

    /**
     * Kobo reports whole minutes; anything under a minute reads better as "<1 m" than "0 m".
     */
    public function timeSpentLabel(): ?string
    {
        $minutes = $this->spent_reading_minutes;

        if ($minutes === null) {
            return null;
        }

        if ($minutes < 1) {
            return '<1 m';
        }

        return $minutes < 60
            ? $minutes.' m'
            : intdiv($minutes, 60).' h '.($minutes % 60).' m';
    }
}
