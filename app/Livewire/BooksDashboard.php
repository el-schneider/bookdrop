<?php

namespace App\Livewire;

use App\Models\Book;
use App\Services\EpubMetadataExtractor;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class BooksDashboard extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function saveUploads(): void
    {
        $metadataExtractor = app(EpubMetadataExtractor::class);

        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['file', 'max:102400'],
        ]);

        foreach ($this->uploads as $upload) {
            if (strtolower($upload->getClientOriginalExtension()) !== 'epub') {
                $this->reset('uploads');

                throw ValidationException::withMessages([
                    'uploads' => 'Only .epub files can be uploaded.',
                ]);
            }
        }

        $count = 0;

        foreach ($this->uploads as $upload) {
            $this->storeBook($upload, $metadataExtractor);
            $count++;
        }

        $this->reset('uploads');

        session()->flash('status', trans_choice(':count EPUB uploaded.|:count EPUBs uploaded.', $count, ['count' => $count]));
    }

    public function render(): View
    {
        $settings = app(SettingsService::class);

        return view('livewire.books-dashboard', [
            'apiEndpoint' => $settings->koboEndpoint(request()),
        ]);
    }

    private function storeBook(TemporaryUploadedFile $upload, EpubMetadataExtractor $metadataExtractor): void
    {
        $disk = (string) config('bookdrop.storage_disk');
        $directory = trim((string) config('bookdrop.books_path'), '/');
        $storedPath = $directory.'/'.(string) Str::uuid().'.epub';

        $upload->storeAs($directory, basename($storedPath), $disk);

        if (! Storage::disk($disk)->exists($storedPath)) {
            throw ValidationException::withMessages([
                'uploads' => 'The uploaded file could not be stored.',
            ]);
        }

        $originalFilename = Str::limit($upload->getClientOriginalName(), 255, '');
        $metadata = $metadataExtractor->extract(Storage::disk($disk)->path($storedPath));

        Book::query()->create([
            'title' => Str::limit($metadata['title'] ?: $this->titleFromFilename($originalFilename), 255, ''),
            'author' => $metadata['author'] ? Str::limit($metadata['author'], 255, '') : null,
            'original_filename' => $originalFilename,
            'stored_path' => $storedPath,
            'format' => 'epub',
            'size_bytes' => (int) $upload->getSize(),
            'uploaded_at' => now(),
        ]);
    }

    private function titleFromFilename(string $filename): string
    {
        $title = pathinfo($filename, PATHINFO_FILENAME);

        return trim($title) !== '' ? $title : 'Untitled EPUB';
    }
}
