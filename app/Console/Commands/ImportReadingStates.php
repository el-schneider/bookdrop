<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\ReadingState;
use Illuminate\Console\Command;

/**
 * One-off import of reading progress that predates reading-state sync, exported from a Kobo's
 * own KoboReader.sqlite. Books already reporting progress are never touched.
 */
class ImportReadingStates extends Command
{
    protected $signature = 'bookdrop:import-reading-states {file : JSON array of {content_id, status, percent, seconds}}';

    protected $description = 'Seed reading progress captured from a Kobo device, for books that have none';

    public function handle(): int
    {
        if (! is_file($file = (string) $this->argument('file'))) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($file), true);

        if (! is_array($rows)) {
            $this->error('File is not a JSON array.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $unknown = 0;

        foreach ($rows as $row) {
            $book = Book::query()->with('readingState')->find($row['content_id'] ?? null);

            if (! $book) {
                $this->warn("No book for {$row['content_id']}");
                $unknown++;

                continue;
            }

            // The device is authoritative for anything it has already reported: overwriting a
            // synced state with a snapshot would move progress backwards.
            if ($book->readingState !== null) {
                $skipped++;

                continue;
            }

            ReadingState::query()->create([
                'book_id' => $book->id,
                'status' => $this->status($row['status'] ?? 0),
                'progress_percent' => isset($row['percent']) ? (float) $row['percent'] : null,
                // Kobo records seconds locally but reports minutes over the API.
                'spent_reading_minutes' => isset($row['seconds']) ? intdiv((int) $row['seconds'], 60) : null,
                'times_started_reading' => ((int) ($row['status'] ?? 0)) > 0 ? 1 : 0,
                'last_modified' => now(),
            ]);

            $imported++;
        }

        $this->info("Imported {$imported}, skipped {$skipped} already reporting, {$unknown} unknown.");

        if ($imported > 0) {
            $this->line('Imported states will sync to the device as changed reading states.');
        }

        return self::SUCCESS;
    }

    /**
     * KoboReader.sqlite content.ReadStatus: 0 unread, 1 reading, 2 finished.
     */
    private function status(mixed $readStatus): string
    {
        return match ((int) $readStatus) {
            1 => ReadingState::STATUS_READING,
            2 => ReadingState::STATUS_FINISHED,
            default => ReadingState::STATUS_UNREAD,
        };
    }
}
