<div class="space-y-8">
    @if (session('status'))
        <div class="bd-rule-panel p-4 bd-subhead">
            {{ session('status') }}
        </div>
    @endif

    <section class="bd-rule-panel">
        <div class="bd-section">
            <div class="mb-6 grid gap-6 md:grid-cols-[1fr_auto] md:items-end">
                <div class="space-y-3">
                    <p class="bd-eyebrow">Library</p>
                    <h2 class="text-base font-normal">Books</h2>
                    <p class="bd-subhead">{{ $totalBooks }} {{ \Illuminate\Support\Str::plural('file', $totalBooks) }} on the private shelf.</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" wire:click="setView('compact')" class="{{ $view === 'compact' ? 'bd-view-toggle bd-view-toggle-active' : 'bd-view-toggle' }}">
                        Compact
                    </button>
                    <button type="button" wire:click="setView('extended')" class="{{ $view === 'extended' ? 'bd-view-toggle bd-view-toggle-active' : 'bd-view-toggle' }}">
                        Extended
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                @if ($view === 'compact')
                    <table class="bd-table bd-table-compact min-w-full">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Sync</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($books as $book)
                                <tr>
                                    <td class="font-medium">{{ $book->title }}</td>
                                    <td class="bd-muted">{{ $book->author ?: '—' }}</td>
                                    <td>
                                        @if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($book->stored_path))
                                            <span class="bd-icon-status" title="Active" aria-label="Active"><span class="material-symbols-outlined" aria-hidden="true">check</span></span>
                                        @else
                                            <span class="bd-status">Missing file</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <button
                                            type="button"
                                            wire:click="delete('{{ $book->id }}')"
                                            wire:confirm="Delete this book?"
                                            class="bd-icon-button"
                                            aria-label="Delete {{ $book->title }}"
                                            title="Delete"
                                        >
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-10 text-center bd-subhead">No books uploaded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                @else
                    <table class="bd-table min-w-full">
                        <thead>
                            <tr>
                                <th>Cover</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Filename</th>
                                <th>Uploaded</th>
                                <th>Sync</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($books as $book)
                                <tr>
                                    <td>
                                        <div class="bd-cover-frame" aria-hidden="true">
                                            <span>{{ \Illuminate\Support\Str::of($book->title)->substr(0, 1)->upper() }}</span>
                                            <img
                                                src="{{ route('library.books.cover', $book) }}"
                                                alt=""
                                                loading="lazy"
                                                onerror="this.remove()"
                                            >
                                        </div>
                                    </td>
                                    <td class="font-medium">{{ $book->title }}</td>
                                    <td class="bd-muted">{{ $book->author ?: '—' }}</td>
                                    <td class="bd-muted">{{ $book->original_filename }}</td>
                                    <td class="bd-muted">{{ $book->uploaded_at?->toDayDateTimeString() }}</td>
                                    <td>
                                        @if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($book->stored_path))
                                            <span class="bd-icon-status" title="Active" aria-label="Active"><span class="material-symbols-outlined" aria-hidden="true">check</span></span>
                                        @else
                                            <span class="bd-status">Missing file</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <button
                                            type="button"
                                            wire:click="delete('{{ $book->id }}')"
                                            wire:confirm="Delete this book?"
                                            class="bd-icon-button"
                                            aria-label="Delete {{ $book->title }}"
                                            title="Delete"
                                        >
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-10 text-center bd-subhead">No books uploaded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($books->hasPages())
                <div class="bd-pagination mt-8">
                    <div class="bd-subhead">
                        Showing {{ $books->firstItem() }}–{{ $books->lastItem() }} of {{ $books->total() }}
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($books->onFirstPage())
                            <span class="bd-page-link bd-page-link-disabled">Previous</span>
                        @else
                            <button type="button" wire:click="previousPage" class="bd-page-link">Previous</button>
                        @endif

                        <span class="bd-subhead">Page {{ $books->currentPage() }} / {{ $books->lastPage() }}</span>

                        @if ($books->hasMorePages())
                            <button type="button" wire:click="nextPage" class="bd-page-link">Next</button>
                        @else
                            <span class="bd-page-link bd-page-link-disabled">Next</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>
