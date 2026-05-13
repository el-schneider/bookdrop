<?php

namespace App\Services;

use SebLucas\EPubMeta\EPub;
use Throwable;

class EpubMetadataExtractor
{
    /**
     * @return array{title: string|null, author: string|null}
     */
    public function extract(string $path): array
    {
        try {
            $epub = new EPub($path);

            return [
                'title' => $this->clean($epub->getTitle()),
                'author' => $this->clean($this->formatAuthors($epub->getAuthors())),
            ];
        } catch (Throwable) {
            return [
                'title' => null,
                'author' => null,
            ];
        }
    }

    /**
     * @return array{data: string, mime: string}|null
     */
    public function cover(string $path): ?array
    {
        try {
            $cover = (new EPub($path))->getCoverInfo();

            if (! $cover['found'] || ! is_string($cover['data']) || $cover['data'] === '') {
                return null;
            }

            return [
                'data' => $cover['data'],
                'mime' => is_string($cover['mime']) && $cover['mime'] !== '' ? $cover['mime'] : 'image/jpeg',
            ];
        } catch (Throwable) {
            return null;
        }
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
