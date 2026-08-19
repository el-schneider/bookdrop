<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'author',
    'original_filename',
    'stored_path',
    'format',
    'size_bytes',
    'uploaded_at',
    'series',
    'series_index',
    'description',
    'language',
    'publisher',
    'published_at',
    'kepub_path',
])]
class Book extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Revisions must never be reused or go backwards: a device holding revision N asks for
     * everything above N, so a reused number is silently never delivered.
     *
     * ponytail: derived from MAX(revision), which holds only because books are soft-deleted and
     * always written through the model. Hard deletes (forceDelete) of the highest row, or writes
     * that bypass model events (bulk query-builder updates, saveQuietly, raw inserts), would free
     * or skip a number. If any of those become necessary, move the counter into the settings row
     * instead. Concurrent writers would collide loudly on the unique index rather than corrupt
     * the sequence, and this app is single-instance by design.
     */
    public function nextRevision(): int
    {
        return (int) static::withTrashed()->max('revision') + 1;
    }

    /** @return HasOne<ReadingState, $this> */
    public function readingState(): HasOne
    {
        return $this->hasOne(ReadingState::class);
    }

    /** @return HasMany<Annotation, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'deleted_at' => 'datetime',
            'published_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /**
     * Every write gets a higher revision than any before it, including soft deletes. Kobo sync
     * pages over this, so a change must never reuse or lower a revision or the device will not
     * see it.
     */
    protected static function booted(): void
    {
        static::saving(function (Book $book): void {
            // ponytail: one MAX query per write; a sequence table would be needed only under
            // concurrent writers, and this app is single-instance by design.
            $book->revision = $book->nextRevision();
        });

        // A soft delete writes deleted_at straight through the query builder and never fires
        // saving, so the revision has to be advanced separately or the removal would never
        // reach the device.
        static::deleted(function (Book $book): void {
            if (! $book->trashed()) {
                return;
            }

            $revision = $book->nextRevision();

            static::withTrashed()->whereKey($book->getKey())->update(['revision' => $revision]);
            $book->revision = $revision;
        });
    }
}
