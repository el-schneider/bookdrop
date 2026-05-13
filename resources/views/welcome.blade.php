<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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
        <div class="bd-page min-h-screen">
            <header class="border-b" style="border-color: var(--rule)">
                <div class="bd-container grid grid-cols-2 items-center gap-4 py-5 md:grid-cols-3">
                    <a href="/" class="bd-link no-underline">Bookdrop</a>

                    <p class="bd-eyebrow hidden text-center md:block">EPUB → Kobo</p>

                    <div class="flex items-center justify-end gap-3">
                        @if (Route::has('login'))
                            <livewire:welcome.navigation />
                        @endif
                        <button type="button" class="bd-theme-toggle" data-theme-toggle>Dark</button>
                    </div>
                </div>
            </header>

            <main class="bd-container">
                <section class="grid min-h-[calc(100vh-68px)] items-center gap-12 py-16 md:grid-cols-[1.35fr_0.65fr] md:py-24">
                    <div class="space-y-10">
                        <div class="space-y-6">
                            <p class="bd-eyebrow">A quiet route for your books</p>
                            <h1 class="bd-title">Drop an EPUB. Pick up your Kobo.</h1>
                        </div>

                        <p class="max-w-xl bd-subhead">
                            Bookdrop keeps a small private shelf for DRM-free EPUBs and serves it through Kobo sync. Upload from the browser, sync from the reader, keep the machinery out of sight.
                        </p>

                        <div class="flex flex-wrap gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="bd-button">Open shelf</a>
                            @else
                                <a href="{{ route('login') }}" class="bd-button">Log in</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="bd-button-secondary">Create shelf</a>
                                @endif
                            @endauth
                        </div>
                    </div>

                    <aside class="bd-rule-panel bd-section self-end">
                        <div class="space-y-10">
                            <div>
                                <p class="bd-eyebrow">How it works</p>
                                <ol class="mt-6 space-y-5 bd-subhead">
                                    <li><span class="text-[var(--ink)]">01</span> Upload DRM-free EPUB files.</li>
                                    <li><span class="text-[var(--ink)]">02</span> Add one endpoint to Kobo.</li>
                                    <li><span class="text-[var(--ink)]">03</span> Sync whenever the shelf changes.</li>
                                </ol>
                            </div>

                            <div class="border-t pt-6" style="border-color: var(--rule)">
                                <p class="bd-eyebrow">Private by default</p>
                                <p class="mt-4 bd-subhead">Files stay on your server. The reader gets only what the token allows.</p>
                            </div>
                        </div>
                    </aside>
                </section>
            </main>
        </div>
    </body>
</html>
