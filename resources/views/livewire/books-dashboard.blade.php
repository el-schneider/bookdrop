<div class="space-y-8">
    @if (session('status'))
        <div class="bd-rule-panel p-4 bd-subhead">
            {{ session('status') }}
        </div>
    @endif

    <section class="bd-rule-panel">
        <div class="bd-section grid gap-8 md:grid-cols-[0.36fr_0.64fr]">
            <div class="space-y-3">
                <p class="bd-eyebrow">Reader endpoint</p>
                <h2 class="text-base font-normal">Kobo configuration</h2>
                <p class="bd-subhead">Put this in <code>.kobo/Kobo/Kobo eReader.conf</code> under <code>[OneStoreServices]</code>.</p>
            </div>

            <pre class="bd-code overflow-x-auto">[OneStoreServices]
api_endpoint={{ $apiEndpoint }}</pre>
        </div>
    </section>

    <section class="bd-rule-panel">
        <div data-bookdrop-upload class="bd-section space-y-6">
            <div class="grid gap-4 md:grid-cols-[0.36fr_0.64fr]">
                <div class="space-y-3">
                    <p class="bd-eyebrow">Add books</p>
                    <h2 class="text-base font-normal">Upload EPUBs</h2>
                </div>
                <p class="bd-subhead">Drop one or more DRM-free <code>.epub</code> files here. Upload starts automatically. Files stay private and sync through the Kobo token.</p>
            </div>

            <div
                data-testid="bookdrop-dropzone"
                data-bookdrop-dropzone
                role="button"
                tabindex="0"
                class="cursor-pointer border border-dashed px-6 py-12 text-center transition-colors"
                style="border-color: var(--rule); background: var(--field)"
            >
                <input
                    data-bookdrop-input
                    wire:model="uploads"
                    type="file"
                    multiple
                    accept=".epub,application/epub+zip"
                    class="sr-only"
                >

                <div class="display-type text-3xl md:text-4xl">Drop EPUB files here</div>
                <div class="mt-4 bd-subhead">or click to choose files · max 100 MB per file</div>
            </div>

            <div data-bookdrop-queue class="bd-rule-panel hidden p-4">
                <div class="bd-eyebrow">Queued files</div>
                <ul data-bookdrop-file-list class="mt-3 space-y-2 bd-subhead"></ul>
            </div>

            <div data-bookdrop-progress class="hidden space-y-3">
                <div class="flex justify-between bd-subhead">
                    <span>Uploading...</span>
                    <span data-bookdrop-progress-label>0%</span>
                </div>
                <div class="h-px bg-[var(--rule)]">
                    <div data-bookdrop-progress-bar class="h-px bg-[var(--ink)] transition-all" style="width: 0%"></div>
                </div>
            </div>

            <button type="button" wire:click="saveUploads" data-bookdrop-save class="hidden">Save uploaded books</button>

            <div wire:loading wire:target="saveUploads" class="bd-subhead">Saving metadata and adding books...</div>

            <x-input-error :messages="$errors->get('uploads')" />
            <x-input-error :messages="$errors->get('uploads.*')" />
        </div>
    </section>

    <section class="bd-rule-panel">
        <div class="bd-section">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div class="space-y-3">
                    <p class="bd-eyebrow">Library</p>
                    <h2 class="text-base font-normal">Books</h2>
                </div>
                <p class="bd-subhead">{{ $books->count() }} {{ \Illuminate\Support\Str::plural('file', $books->count()) }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="bd-table min-w-full">
                    <thead>
                        <tr>
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
                                <td class="font-medium">{{ $book->title }}</td>
                                <td class="bd-muted">{{ $book->author ?: '—' }}</td>
                                <td class="bd-muted">{{ $book->original_filename }}</td>
                                <td class="bd-muted">{{ $book->uploaded_at?->toDayDateTimeString() }}</td>
                                <td>
                                    @if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($book->stored_path))
                                        <span class="bd-status">Active</span>
                                    @else
                                        <span class="bd-status">Missing file</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <button
                                        type="button"
                                        wire:click="delete('{{ $book->id }}')"
                                        wire:confirm="Delete this book?"
                                        class="bd-link"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center bd-subhead">No books uploaded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
