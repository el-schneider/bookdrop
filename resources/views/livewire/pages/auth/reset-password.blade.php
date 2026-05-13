<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = request()->string('email');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status != Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        Session::flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<form wire:submit="resetPassword" class="space-y-6">
    <div class="space-y-2">
        <x-input-label for="email" :value="__('Email')" />
        <x-text-input wire:model="email" id="email" type="email" name="email" required autofocus autocomplete="username" />
        <x-input-error :messages="$errors->get('email')" />
    </div>

    <div class="space-y-2">
        <x-input-label for="password" :value="__('Password')" />
        <x-text-input wire:model="password" id="password" type="password" name="password" required autocomplete="new-password" />
        <x-input-error :messages="$errors->get('password')" />
    </div>

    <div class="space-y-2">
        <x-input-label for="password_confirmation" :value="__('Confirm password')" />
        <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
        <x-input-error :messages="$errors->get('password_confirmation')" />
    </div>

    <div class="flex justify-end border-t pt-6" style="border-color: var(--rule)">
        <x-primary-button>
            {{ __('Reset password') }}
        </x-primary-button>
    </div>
</form>
