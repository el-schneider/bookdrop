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
                <a href="{{ route('dashboard') }}" class="bd-link no-underline" wire:navigate>Bookdrop</a>

                <div class="hidden items-center gap-6 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
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
            <x-responsive-nav-link :href="route('profile')" wire:navigate>
                {{ __('Profile') }}
            </x-responsive-nav-link>
        </div>
    </div>
</nav>
