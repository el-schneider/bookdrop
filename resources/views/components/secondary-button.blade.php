<button {{ $attributes->merge(['type' => 'button', 'class' => 'bd-button-secondary']) }}>
    {{ $slot }}
</button>
