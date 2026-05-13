<button {{ $attributes->merge(['type' => 'submit', 'class' => 'bd-button']) }}>
    {{ $slot }}
</button>
