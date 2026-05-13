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
        <div class="bd-page">
            <livewire:layout.navigation />

            @if (isset($header))
                <header class="border-b" style="border-color: var(--rule)">
                    <div class="bd-container py-10 md:py-14">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
