<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $status = Password::sendResetLink($this->only('email'));

        if ($status != Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('email');

        session()->flash('status', __($status));
    }
}; ?>

<div class="space-y-8">
    <p class="bd-subhead">Enter your email address and we will send a password reset link.</p>

    <x-auth-session-status :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="space-y-6">
        <div class="space-y-2">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div class="flex justify-end border-t pt-6" style="border-color: var(--rule)">
            <x-primary-button>
                {{ __('Send reset link') }}
            </x-primary-button>
        </div>
    </form>
</div>
