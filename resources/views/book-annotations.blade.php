<x-page
    eyebrow="Annotations"
    :title="$book->title"
    :lead="$book->author ?: 'Highlights and notes synced from the Kobo.'"
>
    <livewire:book-annotations :book="$book->id" />
</x-page>
