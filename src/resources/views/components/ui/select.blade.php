{{--
    EPIC-016 WP1: the Blade twin of resources/js/components/ui/native-select.tsx. A native <select>
    (keyboard and touch accessible). Identity, error and accessibility behaviour are exactly
    <x-ui.input>'s (§7.5); the <option>s are the slot.

    <x-ui.select name="status" aria-label="Status"> <option>...</option> </x-ui.select>
--}}
@props([
    'name' => null,
    'id' => null,
    'errorKey' => null,
    'invalid' => false,
])

@php
    $state = \App\Support\FieldState::resolve($name, $id, $errorKey, $errors ?? null, $attributes->get('aria-describedby'), (bool) $invalid);

    $classes = 'block h-9 pointer-coarse:h-11 rounded-control border border-control-edge bg-surface px-3 py-0 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp

<select {{ $attributes->except('aria-describedby')->merge([
    'class' => $classes,
    'id' => $state->id,
    'name' => $name,
    'aria-invalid' => $state->invalid ? 'true' : null,
    'aria-describedby' => $state->describedBy,
]) }}>{{ $slot }}</select>
