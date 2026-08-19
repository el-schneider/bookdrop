<?php

namespace App\Livewire;

use App\Models\Book;
use Illuminate\View\View;
use Livewire\Component;

class BookAnnotations extends Component
{
    /**
     * Only the id is held as component state: a full model property would have to survive
     * Livewire's hydration round-trip on every request.
     */
    public string $bookId = '';

    public function mount(string $book): void
    {
        $this->bookId = $book;
    }

    public function render(): View
    {
        $book = Book::query()->with([])->findOrFail($this->bookId);

        return view('livewire.book-annotations', [
            'book' => $book,
            // Grouped by chapter file, then by position within it. This approximates reading
            // order but is not it: true order lives in the EPUB spine, which is not stored, so a
            // preface sorts after "ch01" alphabetically.
            'annotations' => $book->annotations()
                ->orderBy('chapter_filename')
                ->orderBy('chapter_progress')
                ->get(),
        ]);
    }
}
