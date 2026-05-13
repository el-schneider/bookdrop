@props(['value'])

<label {{ $attributes->merge(['class' => 'bd-label']) }}>
    {{ $value ?? $slot }}
</label>
