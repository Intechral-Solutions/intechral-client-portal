{{--
    EPIC-016 WP1: <label> (the React `Label`: text-sm font-medium text-text).

    <x-ui.label for="title" required>Subject</x-ui.label>

    `for` is the field's DOM id (never its name). It is required unless the label wraps its control.
    `required` renders the existing `*` marker in `text-danger`; the field keeps its own `required`
    attribute. `class="sr-only"` is allowed (a visually hidden label still names the field).
--}}
@props([
    'for' => null,
    'required' => false,
])

<label @if ($for !== null) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'block text-sm font-medium text-text']) }}>{{ $slot }}@if ($required) <span class="text-danger">*</span>@endif</label>
