@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'bd-rule-panel p-4 bd-subhead']) }}>
        {{ $status }}
    </div>
@endif
