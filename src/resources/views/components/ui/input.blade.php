{{--
    EPIC-016 WP1: the Blade twin of resources/js/components/ui/input.tsx, carrying the field identity
    contract (§7.5) and the accessibility contract (§10).

    <x-ui.input name="title" :value="old('title')" required class="w-full" />
    <x-ui.input name="items[3][description]" id="items-3-description" error-key="items.3.description" />

    - `name`, `id`, `error-key`, `aria-describedby`: a simple name (^[A-Za-z][A-Za-z0-9_-]*$) is its own
      `id` and `error-key`; a nested or array name MUST pass an explicit `id` (it throws otherwise); an
      explicit `id` always wins. `error-key` may be one key or an ordered list, wildcards allowed.
    - When any error key has an error (or `invalid` is set) the field gets `aria-invalid="true"`, the
      `border-danger` edge, and `{id}-error` appended to the caller's own `aria-describedby` tokens.
    - Height, boundary (`control-edge`), surface, hover, disabled and the focus-visible outline belong to
      the component. Callers add layout classes only (width, flex, margin). Covers text, search, date,
      number, email, url and file inputs.
--}}
@props([
    'name' => null,
    'id' => null,
    'errorKey' => null,
    'invalid' => false,
])

@php
    $state = \App\Support\FieldState::resolve($name, $id, $errorKey, $errors ?? null, $attributes->get('aria-describedby'), (bool) $invalid);

    $classes = 'block min-w-0 h-9 pointer-coarse:h-11 rounded-control border border-control-edge bg-surface px-3 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion placeholder:text-text-muted hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

    if ($attributes->get('type') === 'file') {
        $classes .= ' file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-text';
    }
@endphp

<input {{ $attributes->except('aria-describedby')->merge([
    'class' => $classes,
    'id' => $state->id,
    'name' => $name,
    'aria-invalid' => $state->invalid ? 'true' : null,
    'aria-describedby' => $state->describedBy,
]) }}>
