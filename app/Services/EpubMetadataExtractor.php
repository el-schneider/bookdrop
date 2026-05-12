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
