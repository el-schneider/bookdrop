@props(['active'])

@php
$classes = $active ?? false ? 'bd-nav-link bd-nav-link-active' : 'bd-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
