{{--
    EPIC-016 WP1: the field error message (the React `FormFieldError`), Blade side.

    <x-ui.field-error for="title" />
    <x-ui.field-error for="items-2-description" error-key="items.2.description" />
    <x-ui.field-error for="permissions-group" :error-key="['permissions', 'permissions.*']" />

    `for` is the field's DOM id: the error element is `id="{for}-error"`, the id the field's
    `aria-describedby` points at. `error-key` is the same key(s) the field uses (default: `for`); with a
    list, the FIRST message in key order is shown. Renders nothing when no key has an error. It reads the
    shared `$errors` bag only.
--}}
@props([
    'for',
    'errorKey' => null,
])

@php
    $message = \App\Support\FieldState::firstError($errors ?? null, $errorKey ?? $for);
@endphp

@if ($message !== null)
<p id="{{ $for }}-error" role="alert" {{ $attributes->merge(['class' => 'text-sm text-danger']) }}>{{ $message }}</p>
@endif
