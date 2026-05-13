<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    <section class="bg-white shadow-sm sm:rounded-lg">
        <div class="space-y-4 p-6">
            <div>
                <h3 class="text-lg font-medium text-gray-900">Kobo configuration</h3>
                <p class="mt-1 text-sm text-gray-600">Put this in <code>.kobo/Kobo/Kobo eReader.conf</code> under <code>[OneStoreServices]</code>.</p>
            </div>

            <pre class="overflow-x-auto rounded-md bg-gray-900 p-4 text-sm text-gray-100">[OneStoreServices]
api_endpoint={{ $apiEndpoint }}</pre>
        </div>
    </section>

    <section class="bg-white shadow-sm sm:rounded-lg">
        <div data-bookdrop-upload class="space-y-4 p-6">
            <div>
                <h3 class="text-lg font-medium text-gray-900">Upload EPUBs</h3>
                <p class="mt-1 text-sm text-gray-600">Drop one or more DRM-free <code>.epub</code> files here. Upload starts automatically.</p>
            </div>

            <div
                data-testid="bookdrop-dropzone"
                data-bookdrop-dropzone
                role="button"
                tabindex="0"
                class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-12 text-center transition hover:border-indigo-400 hover:bg-indigo-50"
            >
                <input
                    data-bookdrop-input
                    wire:model="uploads"
                    type="file"
                    multiple
                    accept=".epub,application/epub+zip"
                    class="sr-only"
                >

                <div class="text-base font-semibold text-gray-900">Drop EPUB files here</div>
                <div class="mt-1 text-sm text-gray-600">or click to choose files</div>
                <div class="mt-3 text-xs text-gray-500">Max 100 MB per file. Files stay private and sync through the Kobo token.</div>
            </div>

            <div data-bookdrop-queue class="hidden rounded-md border border-gray-200 bg-white p-4">
                <div class="text-sm font-medium text-gray-900">Queued files</div>
                <ul data-bookdrop-file-list class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700"></ul>
            </div>

            <div data-bookdrop-progress class="hidden space-y-2">
                <div class="flex justify-between text-sm text-gray-700">
                    <span>Uploading...</span>
                    <span data-bookdrop-progress-label>0%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                    <div data-bookdrop-progress-bar class="h-full rounded-full bg-indigo-600 transition-all" style="width: 0%"></div>
                </div>
            </div>

            <button type="button" wire:click="saveUploads" data-bookdrop-save class="hidden">Save uploaded books</button>

            <div wire:loading wire:target="saveUploads" class="text-sm text-gray-600">Saving metadata and adding books...</div>

            <x-input-error :messages="$errors->get('uploads')" />
            <x-input-error :messages="$errors->get('uploads.*')" />
        </div>
    </section>

    <section class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900">Books</h3>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <th class="py-3 pe-4">Title</th>
                            <th class="px-4 py-3">Author</th>
                            <th class="px-4 py-3">Filename</th>
                            <th class="px-4 py-3">Uploaded</th>
                            <th class="px-4 py-3">Sync</th>
                            <th class="py-3 ps-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($books as $book)
                            <tr>
                                <td class="py-3 pe-4 font-medium text-gray-900">{{ $book->title }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $book->author ?: '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $book->original_filename }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $book->uploaded_at?->toDayDateTimeString() }}</td>
                                <td class="px-4 py-3">
                                    @if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($book->stored_path))
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">Active</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800">Missing file</span>
                                    @endif
                                </td>
                                <td class="py-3 ps-4 text-right">
                                    <button
                                        type="button"
                                        wire:click="delete('{{ $book->id }}')"
                                        wire:confirm="Delete this book?"
                                        class="text-sm font-medium text-red-600 hover:text-red-900"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-gray-500">No books uploaded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
