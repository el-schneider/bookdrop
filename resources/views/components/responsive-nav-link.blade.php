@props(['active'])

@php
$classes = $active ?? false ? 'bd-link underline' : 'bd-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
