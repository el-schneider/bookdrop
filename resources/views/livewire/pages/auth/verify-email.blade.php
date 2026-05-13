<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="space-y-8">
    <p class="bd-subhead">Verify your email address through the link we sent. If it did not arrive, ask for another.</p>

    @if (session('status') == 'verification-link-sent')
        <div class="bd-rule-panel p-4 bd-subhead">
            {{ __('A new verification link has been sent.') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-6" style="border-color: var(--rule)">
        <x-primary-button wire:click="sendVerification">
            {{ __('Resend email') }}
        </x-primary-button>

        <button wire:click="logout" type="submit" class="bd-link">
            {{ __('Log out') }}
        </button>
    </div>
</div>
