<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Converts stored EPUBs to Kobo's KEPUB format using the kepubify binary.
 *
 * The device needs KEPUB span markup for two things a plain EPUB cannot do: track progress within
 * a chapter, and create highlights at all. Measured on firmware 4.45.23697 — highlighting a
 * sync-delivered EPUB silently stores nothing.
 */
class KepubConverter
{
    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Converts a stored book and returns the new path on the storage disk, or null when
     * conversion is unavailable or the file cannot be converted.
     *
     * A failure is not fatal: the original EPUB stays downloadable, so the book still reaches the
     * device, just without KEPUB features. Failures are logged rather than swallowed silently.
     */
    public function convert(string $storedPath): ?string
    {
        $binary = $this->binary();

        if ($binary === null) {
            return null;
        }

        $disk = Storage::disk((string) config('bookdrop.storage_disk'));
        $source = $disk->path($storedPath);

        if (! is_file($source)) {
            logger()->warning('Kepubify source missing', ['path' => $storedPath]);

            return null;
        }

        $directory = trim((string) config('bookdrop.kepubs_path'), '/');
        $disk->makeDirectory($directory);

        $target = $directory.'/'.pathinfo($storedPath, PATHINFO_FILENAME).'.kepub.epub';

        // kepubify names its own output, so it writes into a scratch directory that is then
        // moved to a predictable path.
        $scratch = $disk->path($directory).'/.tmp-'.bin2hex(random_bytes(6));

        try {
            $process = new Process([$binary, '--output', $scratch, $source]);
            $process->setTimeout((float) config('bookdrop.kepubify_timeout'));
            $process->mustRun();

            $produced = glob($scratch.'/*.kepub.epub') ?: [];

            if ($produced === []) {
                logger()->warning('Kepubify produced no output', ['path' => $storedPath]);

                return null;
            }

            $disk->put($target, (string) file_get_contents($produced[0]));

            return $target;
        } catch (ProcessFailedException $exception) {
            logger()->warning('Kepubify conversion failed', [
                'path' => $storedPath,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return null;
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    private function binary(): ?string
    {
        $configured = (string) config('bookdrop.kepubify_path');

        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $found = trim((string) @shell_exec('command -v kepubify 2>/dev/null'));

        return $found !== '' && is_executable($found) ? $found : null;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
