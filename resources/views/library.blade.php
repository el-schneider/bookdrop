<x-app-layout>
    <x-slot name="header">
        <div class="grid gap-8 md:grid-cols-[1fr_auto] md:items-end">
            <div class="space-y-5">
                <p class="bd-eyebrow">Private shelf</p>
                <h1 class="bd-heading">Library.</h1>
            </div>
            <p class="max-w-sm bd-subhead md:text-right">Browse uploaded EPUBs and keep the shelf clean before the next Kobo sync.</p>
        </div>
    </x-slot>

    <div class="py-10 md:py-14">
        <div class="bd-container">
            <livewire:books-library />
        </div>
    </div>
</x-app-layout>
