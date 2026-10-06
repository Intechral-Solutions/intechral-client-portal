{{--
    EPIC-016 WP1: the Blade twin of resources/js/components/ui/textarea.tsx. Identity, error and
    accessibility behaviour are exactly <x-ui.input>'s (§7.5). The value is the slot; write it on one
    line so no stray whitespace enters the field:

    <x-ui.textarea name="notes" rows="3">{{ old('notes', $model->notes) }}</x-ui.textarea>
--}}
@props([
    'name' => null,
    'id' => null,
    'errorKey' => null,
    'invalid' => false,
])

@php
    $state = \App\Support\FieldState::resolve($name, $id, $errorKey, $errors ?? null, $attributes->get('aria-describedby'), (bool) $invalid);

    $classes = 'block min-h-24 resize-y rounded-control border border-control-edge bg-surface px-3 py-2 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion placeholder:text-text-muted hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp

<textarea {{ $attributes->except('aria-describedby')->merge([
    'class' => $classes,
    'id' => $state->id,
    'name' => $name,
    'aria-invalid' => $state->invalid ? 'true' : null,
    'aria-describedby' => $state->describedBy,
]) }}>{{ $slot }}</textarea>
