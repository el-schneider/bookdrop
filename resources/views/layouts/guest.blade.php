<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Bookdrop</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <script>
            (() => {
                const stored = localStorage.getItem('bookdrop-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored ? stored === 'dark' : prefersDark);
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="bd-page flex min-h-screen flex-col">
            <header class="border-b" style="border-color: var(--rule)">
                <div class="bd-container flex items-center justify-between py-5">
                    <a href="/" wire:navigate class="bd-link no-underline">Bookdrop</a>
                    <button type="button" class="bd-theme-toggle" data-theme-toggle>Dark</button>
                </div>
            </header>

            <main class="flex flex-1 items-center py-12 md:py-20">
                <div class="bd-container-narrow">
                    <div class="mb-10 space-y-5">
                        <p class="bd-eyebrow">Private shelf</p>
                        <h1 class="display-type text-5xl md:text-6xl">Send books to your reader.</h1>
                    </div>

                    <div class="bd-rule-panel bd-section">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
