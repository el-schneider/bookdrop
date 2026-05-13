<x-app-layout>
    <x-slot name="header">
        <div class="grid gap-8 md:grid-cols-[1fr_auto] md:items-end">
            <div class="space-y-5">
                <p class="bd-eyebrow">Private shelf</p>
                <h1 class="bd-heading">Your Kobo shelf.</h1>
            </div>
            <p class="max-w-sm bd-subhead md:text-right">Upload EPUBs here. Sync them from the reader when the shelf changes.</p>
        </div>
    </x-slot>

    <div class="py-10 md:py-14">
        <div class="bd-container">
            <livewire:books-dashboard />
        </div>
    </div>
</x-app-layout>
