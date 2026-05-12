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
        <form wire:submit="upload" class="space-y-4 p-6">
            <div>
                <h3 class="text-lg font-medium text-gray-900">Upload EPUBs</h3>
                <p class="mt-1 text-sm text-gray-600">Only DRM-free <code>.epub</code> files are accepted.</p>
            </div>

            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-6 py-10 text-center hover:border-indigo-400">
                <span class="text-sm font-medium text-gray-700">Choose one or more EPUB files</span>
                <span class="mt-1 text-xs text-gray-500">Files are stored privately and exposed only through the tokenized Kobo endpoint.</span>
                <input wire:model="uploads" type="file" multiple accept=".epub" class="sr-only">
            </label>

            <div wire:loading wire:target="uploads" class="text-sm text-gray-600">Preparing upload...</div>
            <div wire:loading wire:target="upload" class="text-sm text-gray-600">Saving...</div>

            <x-input-error :messages="$errors->get('uploads')" />
            <x-input-error :messages="$errors->get('uploads.*')" />

            @if ($uploads !== [])
                <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
                    @foreach ($uploads as $upload)
                        <li>{{ $upload->getClientOriginalName() }}</li>
                    @endforeach
                </ul>
            @endif

            <x-primary-button type="submit">Upload</x-primary-button>
        </form>
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
