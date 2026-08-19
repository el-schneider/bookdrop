<div class="space-y-8">
    <section class="bd-rule-panel">
        <div class="bd-section">
            <div class="mb-6 grid gap-6 md:grid-cols-[1fr_auto] md:items-end">
                <div class="space-y-3">
                    <p class="bd-eyebrow">Highlights</p>
                    <h2 class="text-base font-normal">
                        {{ $annotations->count() }} {{ \Illuminate\Support\Str::plural('annotation', $annotations->count()) }}
                    </h2>
                </div>
                <a href="{{ route('library') }}" class="bd-page-link">Back to library</a>
            </div>

            <div class="space-y-6">
                @forelse ($annotations as $annotation)
                    <article class="bd-rule-panel p-4 space-y-3">
                        <div class="bd-subhead flex flex-wrap gap-3">
                            <span>{{ ucfirst($annotation->type) }}</span>
                            @if ($annotation->chapter_filename)
                                <span class="bd-muted">{{ $annotation->chapter_filename }}</span>
                            @endif
                            @if ($annotation->chapter_progress !== null)
                                <span class="bd-muted">{{ round($annotation->chapter_progress * 100) }}% into chapter</span>
                            @endif
                            @if ($annotation->client_last_modified)
                                <span class="bd-muted">
                                    {{ $annotation->client_last_modified->timezone(config('bookdrop.display_timezone'))->isoFormat('L LT') }}
                                </span>
                            @endif
                        </div>

                        @if (filled($annotation->highlighted_text))
                            <blockquote class="border-l pl-4">{{ $annotation->highlighted_text }}</blockquote>
                        @endif

                        @if (filled($annotation->note_text))
                            <p class="bd-subhead">Note: {{ $annotation->note_text }}</p>
                        @endif
                    </article>
                @empty
                    <p class="py-10 text-center bd-subhead">
                        No annotations yet. Highlight a passage on the Kobo, then sync.
                    </p>
                @endforelse
            </div>
        </div>
    </section>
</div>
