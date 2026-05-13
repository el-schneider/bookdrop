<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function register(): void
    {
        abort_if(User::query()->exists(), 404);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);
        Session::regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<form wire:submit="register" class="space-y-6">
    <div class="space-y-2">
        <x-input-label for="name" :value="__('Name')" />
        <x-text-input wire:model="name" id="name" type="text" name="name" required autofocus autocomplete="name" />
        <x-input-error :messages="$errors->get('name')" />
    </div>

    <div class="space-y-2">
        <x-input-label for="email" :value="__('Email')" />
        <x-text-input wire:model="email" id="email" type="email" name="email" required autocomplete="username" />
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

    <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-6" style="border-color: var(--rule)">
        <a class="bd-link" href="{{ route('login') }}" wire:navigate>
            {{ __('Already registered?') }}
        </a>

        <x-primary-button>
            {{ __('Register') }}
        </x-primary-button>
    </div>
</form>
