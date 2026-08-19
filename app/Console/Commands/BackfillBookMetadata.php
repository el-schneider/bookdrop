<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackfillBookMetadata extends Command
{
    protected $signature = 'bookdrop:backfill-metadata {--force : Re-extract books that already have metadata}';

    protected $description = 'Extract series, description, language and publisher for books uploaded before those fields existed';

    public function handle(EpubMetadataExtractor $extractor): int
    {
        $disk = Storage::disk((string) config('bookdrop.storage_disk'));

        $books = Book::query()
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('series')
                ->whereNull('description')
                ->whereNull('language')
                ->whereNull('publisher'))
            ->get();

        if ($books->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($books as $book) {
            if (! $disk->exists($book->stored_path)) {
                $this->warn("Skipped {$book->title}: file missing.");
                $skipped++;

                continue;
            }

            $metadata = $extractor->extract($disk->path($book->stored_path));

            $book->fill([
                'series' => $this->limited($metadata['series'] ?? null),
                'series_index' => $this->limited($metadata['series_index'] ?? null),
                'description' => $metadata['description'] ?? null,
                'language' => $this->limited($metadata['language'] ?? null),
                'publisher' => $this->limited($metadata['publisher'] ?? null),
                'published_at' => $metadata['published_at'] ?? null,
            ]);

            // Only touch the row if something actually changed: saving bumps updated_at, which
            // makes the book resync to the device as a changed entitlement.
            if ($book->isDirty()) {
                $book->save();
                $updated++;
            }
        }

        $this->info("Backfilled {$updated} book(s), skipped {$skipped}.");

        if ($updated > 0) {
            $this->line('Updated books will resync to the device as changed entitlements.');
        }

        return self::SUCCESS;
    }

    private function limited(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }
}
