<?php

namespace App\Livewire;

use App\Models\Book;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class BooksLibrary extends Component
{
    use WithPagination;

    #[Url]
    public string $view = 'extended';

    public function setView(string $view): void
    {
        $this->view = $view === 'compact' ? 'compact' : 'extended';
        $this->resetPage();
    }

    public function delete(string $bookId): void
    {
        $book = Book::query()->findOrFail($bookId);

        Storage::disk((string) config('bookdrop.storage_disk'))->delete($book->stored_path);
        $book->delete();

        session()->flash('status', 'Book deleted.');
    }

    public function render(): View
    {
        return view('livewire.books-library', [
            'books' => $this->books(),
            'disk' => (string) config('bookdrop.storage_disk'),
            'totalBooks' => Book::query()->count(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Book>
     */
    private function books(): LengthAwarePaginator
    {
        return Book::query()
            ->orderByDesc('uploaded_at')
            ->paginate($this->view === 'compact' ? 25 : 10);
    }
}
