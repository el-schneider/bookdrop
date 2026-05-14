<?php

namespace Tests\Feature;

use App\Livewire\BooksDashboard;
use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BooksDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_epub_upload_creates_book(): void
    {
        Storage::fake('local');
        config()->set('bookdrop.storage_disk', 'local');
        config()->set('bookdrop.books_path', 'books');

        $this->mock(EpubMetadataExtractor::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn([
                'title' => 'Uploaded Book',
                'author' => 'Test Author',
            ]);

        Livewire::test(BooksDashboard::class)
            ->set('uploads', [UploadedFile::fake()->create('uploaded-book.epub', 12, 'application/epub+zip')])
            ->call('saveUploads')
            ->assertHasNoErrors();

        $book = Book::query()->sole();

        $this->assertSame('Uploaded Book', $book->title);
        $this->assertSame('Test Author', $book->author);
        $this->assertSame('uploaded-book.epub', $book->original_filename);
        Storage::disk('local')->assertExists($book->stored_path);
    }
}
