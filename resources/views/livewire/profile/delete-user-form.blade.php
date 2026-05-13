<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header class="space-y-3">
        <p class="bd-eyebrow">Closure</p>
        <h2 class="text-base font-normal">Delete account</h2>
        <p class="bd-subhead">Deleting the account permanently removes its data. Enter your password to confirm.</p>
    </header>

    <form wire:submit="deleteUser" class="space-y-6 border-t pt-6" style="border-color: var(--rule)">
        <div class="space-y-2">
            <x-input-label for="delete_password" :value="__('Password')" />
            <x-text-input wire:model="password" id="delete_password" name="password" type="password" autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <x-danger-button wire:confirm="Delete this account and its data?">
            {{ __('Delete account') }}
        </x-danger-button>
    </form>
</section>
