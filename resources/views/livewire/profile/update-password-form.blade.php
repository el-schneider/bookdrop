<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="space-y-6">
    <header class="space-y-3">
        <p class="bd-eyebrow">Security</p>
        <h2 class="text-base font-normal">Update password</h2>
        <p class="bd-subhead">Use a long, random password for this private shelf.</p>
    </header>

    <form wire:submit="updatePassword" class="space-y-6 border-t pt-6" style="border-color: var(--rule)">
        <div class="space-y-2">
            <x-input-label for="update_password_current_password" :value="__('Current password')" />
            <x-text-input wire:model="current_password" id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" />
            <x-input-error :messages="$errors->get('current_password')" />
        </div>

        <div class="space-y-2">
            <x-input-label for="update_password_password" :value="__('New password')" />
            <x-text-input wire:model="password" id="update_password_password" name="password" type="password" autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="space-y-2">
            <x-input-label for="update_password_password_confirmation" :value="__('Confirm password')" />
            <x-text-input wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" />
        </div>

        <x-primary-button>{{ __('Save') }}</x-primary-button>
    </form>
</section>
