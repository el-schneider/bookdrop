<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\KepubConverter;
use Illuminate\Console\Command;

class ConvertBooksToKepub extends Command
{
    protected $signature = 'bookdrop:convert-kepubs {--force : Reconvert books that already have a KEPUB}';

    protected $description = 'Convert stored EPUBs to KEPUB so the device can track in-chapter progress and store highlights';

    public function handle(KepubConverter $converter): int
    {
        if (! $converter->isAvailable()) {
            $this->error('kepubify not found. Set BOOKDROP_KEPUBIFY_PATH or install it on PATH.');

            return self::FAILURE;
        }

        $books = Book::query()
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('kepub_path'))
            ->get();

        if ($books->isEmpty()) {
            $this->info('Nothing to convert.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($books as $book) {
            $path = $converter->convert($book->stored_path);

            if ($path === null) {
                $this->warn("Could not convert: {$book->title}");
                $failed++;

                continue;
            }

            $book->kepub_path = $path;
            $book->save();
            $converted++;
        }

        $this->info("Converted {$converted}, failed {$failed}.");

        if ($converted > 0) {
            // Each converted book changes format, so the device fetches it again. Reading position
            // is anchored to the file's structure and does not survive that swap; the percentage
            // held on the server does.
            $this->line('Converted books resync to the device and are downloaded again.');
        }

        return self::SUCCESS;
    }
}
