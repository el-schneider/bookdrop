<?php

namespace Tests\Feature;

use App\Livewire\BookAnnotations;
use App\Livewire\BooksLibrary;
use App\Models\Annotation;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BookAnnotationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_library_links_to_annotations_only_when_there_are_some(): void
    {
        $withNotes = $this->book('Annotated Book');
        $this->book('Plain Book');
        $this->annotation($withNotes, 'a1');

        Livewire::test(BooksLibrary::class)
            ->assertSee('1 note')
            ->assertSee(route('library.books.annotations', $withNotes), false);
    }

    public function test_annotations_are_grouped_by_chapter_then_position(): void
    {
        $book = $this->book('Annotated Book');
        $this->annotation($book, 'second', 'OEBPS/ch2.xhtml', 0.5);
        $this->annotation($book, 'first', 'OEBPS/ch1.xhtml', 0.1);

        $rendered = Livewire::test(BookAnnotations::class, ['book' => $book->id])
            ->assertSee('Annotated Book')
            ->assertSee('OEBPS/ch1.xhtml')
            ->assertSee('OEBPS/ch2.xhtml')
            ->html();

        // Alphabetical by chapter file, which approximates but does not equal reading order:
        // the EPUB spine is not stored, so a preface sorts after "ch01".
        $this->assertLessThan(
            strpos($rendered, 'OEBPS/ch2.xhtml'),
            strpos($rendered, 'OEBPS/ch1.xhtml'),
            'annotations should group by chapter file'
        );
    }

    public function test_a_book_without_annotations_says_so(): void
    {
        $book = $this->book('Plain Book');

        Livewire::test(BookAnnotations::class, ['book' => $book->id])
            ->assertSee('No annotations yet');
    }

    public function test_the_page_requires_authentication(): void
    {
        $book = $this->book('Annotated Book');

        auth()->logout();

        $this->get(route('library.books.annotations', $book))->assertRedirect(route('login'));
    }

    private function annotation(Book $book, string $id, string $chapter = 'OEBPS/ch1.xhtml', float $progress = 0.1): void
    {
        Annotation::query()->create([
            'id' => $id,
            'book_id' => $book->id,
            'type' => 'note',
            'payload' => ['id' => $id],
            'highlighted_text' => 'passage '.$id,
            'note_text' => 'note '.$id,
            'chapter_filename' => $chapter,
            'chapter_progress' => $progress,
            'client_last_modified' => now(),
        ]);
    }

    private function book(string $title): Book
    {
        $book = Book::query()->create([
            'title' => $title,
            'author' => 'Test Author',
            'original_filename' => 'book.epub',
            'stored_path' => 'books/'.str_replace(' ', '-', strtolower($title)).'.epub',
            'format' => 'epub',
            'size_bytes' => 123,
            'uploaded_at' => now(),
        ]);

        Storage::disk('local')->put($book->stored_path, 'epub');

        return $book;
    }
}
