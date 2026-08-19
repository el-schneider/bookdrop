@props([
    'eyebrow',
    'title',
    // Optional right-aligned intro line; the header collapses to a single column without it.
    'lead' => null,
    // Extra classes for the content container, e.g. spacing between stacked panels.
    'containerClass' => '',
])

<x-app-layout>
    <x-slot name="header">
        @if ($lead)
            <div class="grid gap-8 md:grid-cols-[1fr_auto] md:items-end">
                <div class="space-y-5">
                    <p class="bd-eyebrow">{{ $eyebrow }}</p>
                    <h1 class="bd-heading">{{ $title }}</h1>
                </div>
                <p class="max-w-sm bd-subhead md:text-right">{{ $lead }}</p>
            </div>
        @else
            <div class="space-y-5">
                <p class="bd-eyebrow">{{ $eyebrow }}</p>
                <h1 class="bd-heading">{{ $title }}</h1>
            </div>
        @endif
    </x-slot>

    <div class="py-10 md:py-14">
        <div class="{{ trim('bd-container '.$containerClass) }}">
            {{ $slot }}
        </div>
    </div>
</x-app-layout>
