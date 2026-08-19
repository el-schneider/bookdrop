<?php

namespace App\Services;

use SebLucas\EPubMeta\EPub;
use Throwable;
use ZipArchive;

class EpubMetadataExtractor
{
    /**
     * Best effort: a book that yields no metadata is still usable, so every field degrades to null
     * rather than failing the upload.
     *
     * @return array{title: string|null, author: string|null, series: string|null, series_index: string|null, description: string|null, language: string|null, publisher: string|null, published_at: string|null}
     */
    public function extract(string $path): array
    {
        try {
            $epub = new EPub($path);

            return [
                'title' => $this->clean($epub->getTitle()),
                'author' => $this->clean($this->formatAuthors($epub->getAuthors())),
                'series' => $this->clean($this->stringOrNull($epub->getSeries())),
                'series_index' => $this->clean($this->stringOrNull($epub->getSeriesIndex())),
                'description' => $this->clean($this->stringOrNull($epub->getDescription())),
                'language' => $this->clean($this->stringOrNull($epub->getLanguage())),
                'publisher' => $this->clean($this->stringOrNull($epub->getPublisher())),
                'published_at' => $this->publicationDate($epub),
            ];
        } catch (Throwable) {
            return $this->emptyMetadata();
        }
    }

    /**
     * @return array{title: null, author: null, series: null, series_index: null, description: null, language: null, publisher: null, published_at: null}
     */
    private function emptyMetadata(): array
    {
        return [
            'title' => null,
            'author' => null,
            'series' => null,
            'series_index' => null,
            'description' => null,
            'language' => null,
            'publisher' => null,
            'published_at' => null,
        ];
    }

    /**
     * Publication dates in the wild range from a bare year to a full timestamp, and some are not
     * dates at all. Anything unparseable is dropped rather than guessed at.
     */
    private function publicationDate(EPub $epub): ?string
    {
        $raw = $this->clean($this->stringOrNull($epub->getCreationDate()));

        if ($raw === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * @return array{data: string, mime: string}|null
     */
    public function cover(string $path): ?array
    {
        try {
            $cover = (new EPub($path))->getCoverInfo();

            if ($cover['found'] && is_string($cover['data']) && $cover['data'] !== '') {
                return [
                    'data' => $cover['data'],
                    'mime' => is_string($cover['mime']) && $cover['mime'] !== '' ? $cover['mime'] : 'image/jpeg',
                ];
            }
        } catch (Throwable) {
            // Fall back to scanning image entries below.
        }

        return $this->coverFromZipImages($path);
    }

    /**
     * @return array{data: string, mime: string}|null
     */
    private function coverFromZipImages(string $path): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $images = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('/\.(jpe?g|png|webp)$/i', $name) !== 1) {
                continue;
            }

            $images[] = $name;
        }

        usort($images, fn (string $left, string $right): int => $this->coverScore($right) <=> $this->coverScore($left));

        foreach ($images as $name) {
            $data = $zip->getFromName($name);

            if (is_string($data) && $data !== '') {
                $zip->close();

                return [
                    'data' => $data,
                    'mime' => $this->mimeFromPath($name),
                ];
            }
        }

        $zip->close();

        return null;
    }

    private function coverScore(string $path): int
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_contains($name, 'cover') => 100,
            str_contains($name, 'front') => 90,
            str_contains($name, 'title') => 80,
            default => 0,
        };
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * @param  array<mixed>  $authors
     */
    private function formatAuthors(array $authors): ?string
    {
        $names = [];

        foreach ($authors as $key => $value) {
            $name = is_string($value) && filled($value) ? $value : $key;

            if (is_string($name) && filled($name)) {
                $names[] = $name;
            }
        }

        return $names === [] ? null : implode(', ', array_unique($names));
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
