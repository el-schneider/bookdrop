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

            <div class="min-w-0 space-y-3">
                <pre class="bd-code overflow-x-auto" data-copy-source="kobo-endpoint">[OneStoreServices]
api_endpoint={{ $apiEndpoint }}</pre>
                <button type="button" class="bd-button-secondary" data-copy-button data-copy-target="kobo-endpoint">Copy endpoint</button>
            </div>
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

                <div class="text-3xl md:text-4xl">Drop EPUB files here</div>
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
</div>
