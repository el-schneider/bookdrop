<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav class="border-b" style="border-color: var(--rule)">
    <div class="bd-container">
        <div class="flex min-h-16 flex-wrap items-center justify-between gap-4 py-3">
            <div class="flex items-center gap-8">
                <a href="{{ route('dashboard') }}" class="bd-brand" wire:navigate>
                    <svg class="bd-brand-mark" aria-hidden="true" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="6.5" y="4.5" width="19" height="23" stroke="currentColor" stroke-width="1"/>
                        <path d="M11 9.5h6.5c3 0 4.5 1.3 4.5 3.3 0 1.6-.9 2.7-2.6 3.2 2 .4 3.1 1.6 3.1 3.5 0 2.2-1.7 3.5-4.9 3.5H11" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        <path d="M11 9.5v13.5M11 16h7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                    </svg>
                </a>

                <div class="hidden items-center gap-6 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('library')" :active="request()->routeIs('library')" wire:navigate>
                        {{ __('Library') }}
                    </x-nav-link>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" class="bd-theme-toggle" data-theme-toggle>Dark</button>

                <a href="{{ route('profile') }}" class="bd-link hidden sm:inline" wire:navigate>
                    {{ auth()->user()->name }}
                </a>

                <button wire:click="logout" class="bd-link">
                    {{ __('Log out') }}
                </button>
            </div>
        </div>

        <div class="flex gap-6 border-t py-3 sm:hidden" style="border-color: var(--rule)">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('library')" :active="request()->routeIs('library')" wire:navigate>
                {{ __('Library') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('profile')" wire:navigate>
                {{ __('Profile') }}
            </x-responsive-nav-link>
        </div>
    </div>
</nav>
