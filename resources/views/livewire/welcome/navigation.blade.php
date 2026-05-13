<nav class="flex items-center gap-3">
    @auth
        <a href="{{ url('/dashboard') }}" class="bd-link" wire:navigate>Dashboard</a>
    @else
        <a href="{{ route('login') }}" class="bd-link" wire:navigate>Log in</a>

        @if (Route::has('register'))
            <a href="{{ route('register') }}" class="bd-link hidden sm:inline" wire:navigate>Register</a>
        @endif
    @endauth
</nav>
