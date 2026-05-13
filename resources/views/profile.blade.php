<x-app-layout>
    <x-slot name="header">
        <div class="space-y-5">
            <p class="bd-eyebrow">Account</p>
            <h1 class="bd-heading">Profile.</h1>
        </div>
    </x-slot>

    <div class="py-10 md:py-14">
        <div class="bd-container space-y-8">
            <div class="bd-rule-panel bd-section">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="bd-rule-panel bd-section">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="bd-rule-panel bd-section">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
