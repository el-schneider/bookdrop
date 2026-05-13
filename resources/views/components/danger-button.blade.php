<button {{ $attributes->merge(['type' => 'submit', 'class' => 'bd-button-secondary']) }}>
    {{ $slot }}
</button>
