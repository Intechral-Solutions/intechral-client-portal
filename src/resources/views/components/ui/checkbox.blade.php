{{--
    EPIC-016 WP1: a native checkbox in the semantic selection accent (`accent-accent`, as the React
    `company-selector` and `login`). A checked box is a SELECTION colour, never a primary-action colour.

    <x-ui.checkbox name="is_internal" value="1">Internal note (not visible to submitter)</x-ui.checkbox>
    <x-ui.checkbox name="ticket_ids[]" id="ticket-cb-7" value="7" aria-label="Select ticket TKT-0007" />

    - With slot content the checkbox is WRAPPED in its own <label> (that label names it, so it needs no
      `id`); `class` then goes on the wrapper (layout only) and every other attribute on the <input>.
      Without slot content only the <input> is rendered and it needs an accessible name from the caller
      (`aria-label`, `aria-labelledby` or an associated <x-ui.label>).
    - Identity, `aria-invalid` and `aria-describedby` follow <x-ui.input> / §7.5, except that a checkbox with
      a non-simple name (`roles[]`) may omit `id`: its wrapping <label> names it and the group, not the
      box, carries the error. A checkbox GROUP
      (`permissions[]`, `roles[]`) puts `id`, `role="group"` and `aria-labelledby` on its container and
      renders one <x-ui.field-error> after it (§7.5 rule 8).
    - `disabled` keeps the label at `text-text-muted`.
--}}
@props([
    'name' => null,
    'id' => null,
    'errorKey' => null,
    'invalid' => false,
])

@php
    $state = \App\Support\FieldState::resolve($name, $id, $errorKey, $errors ?? null, $attributes->get('aria-describedby'), (bool) $invalid, requireId: false);
    $wrapped = ! $slot->isEmpty();

    $inputClasses = 'size-4 shrink-0 cursor-pointer accent-accent disabled:cursor-not-allowed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
    $inputAttributes = [
        'id' => $state->id,
        'name' => $name,
        'type' => 'checkbox',
        'aria-invalid' => $state->invalid ? 'true' : null,
        'aria-describedby' => $state->describedBy,
    ];
@endphp

@if ($wrapped)
<label class="{{ trim('inline-flex cursor-pointer items-center gap-2 text-sm text-text has-[:disabled]:cursor-not-allowed has-[:disabled]:text-text-muted '.$attributes->get('class')) }}">
    <input {{ $attributes->except(['class', 'aria-describedby'])->merge($inputAttributes + ['class' => $inputClasses]) }}>
    <span>{{ $slot }}</span>
</label>
@else
<input {{ $attributes->except('aria-describedby')->merge($inputAttributes + ['class' => $inputClasses]) }}>
@endif
