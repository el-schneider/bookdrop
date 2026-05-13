<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="space-y-8">
    <x-auth-session-status :status="session('status')" />

    <form wire:submit="login" class="space-y-6">
        <div class="space-y-2">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" />
        </div>

        <div class="space-y-2">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="form.password" id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('form.password')" />
        </div>

        <label for="remember" class="inline-flex items-center gap-3 bd-subhead">
            <input wire:model="form.remember" id="remember" type="checkbox" class="border-[var(--rule)] bg-[var(--field)] text-[var(--ink)] focus:ring-0 focus:ring-offset-0" name="remember">
            <span>{{ __('Remember me') }}</span>
        </label>

        <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-6" style="border-color: var(--rule)">
            @if (Route::has('password.request'))
                <a class="bd-link" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif

            <x-primary-button>
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</div>
