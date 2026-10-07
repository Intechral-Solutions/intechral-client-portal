{{--
    EPIC-016 WP2: the Blade twin of resources/js/components/ui/alert.tsx (Direction D banner, §15.3).

    <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    <x-ui.alert variant="danger" class="mb-6">{{ $errors->first() }}</x-ui.alert>

    Four semantic variants plus a plain `neutral` panel (no glyph, no live-region role). Meaning never rests
    on colour alone: each semantic variant carries its own glyph (lucide's own path data, as
    layouts/partials/shell/icon.blade.php does) and an `sr-only` kind label; the body stays `text` ink on a
    soft tint, and the glyph takes the semantic text colour. `success` has no soft token in Direction D, so it
    sits on `surface`.

    Roles: `danger` is `role="alert"` (assertive; genuine errors only); `info`, `success` and `warning` are
    `role="status"` (polite). `neutral` has no role. Pass `role` to override.

    The message lives in `[data-alert-body]`, so a script that fills the alert at runtime (the Stripe error
    region) can set that element's text without touching the glyph. The optional `action` slot renders
    outside the message (for example a dismiss control).
--}}
@props([
    'variant' => 'neutral',
])

@php
    $surfaces = [
        'neutral' => 'border-rule-control bg-surface',
        'info' => 'border-accent-line/40 bg-accent-soft',
        'success' => 'border-rule-control bg-surface',
        'warning' => 'border-warning-glyph/50 bg-warning-soft',
        'danger' => 'border-danger/50 bg-danger-soft',
    ];

    // `node` is lucide's own path data (lucide-react 1.47.0, ISC); 24x24, 2px round stroke.
    $kinds = [
        'info' => [
            'colour' => 'text-accent',
            'label' => 'Notice',
            'role' => 'status',
            'node' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        ],
        'success' => [
            'colour' => 'text-success',
            'label' => 'Success',
            'role' => 'status',
            'node' => '<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
        ],
        'warning' => [
            'colour' => 'text-warning',
            'label' => 'Warning',
            'role' => 'status',
            'node' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        ],
        'danger' => [
            'colour' => 'text-danger',
            'label' => 'Error',
            'role' => 'alert',
            'node' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
        ],
    ];

    if (! isset($surfaces[$variant])) {
        throw new InvalidArgumentException("Unknown x-ui.alert variant \"{$variant}\".");
    }

    $kind = $kinds[$variant] ?? null;
    $classes = 'rounded-control border px-4 py-3 text-sm text-text '.$surfaces[$variant].($kind ? ' flex items-start gap-3' : '');
@endphp

<div {{ $attributes->merge(['role' => $kind['role'] ?? null, 'class' => $classes]) }} data-variant="{{ $variant }}">
    @if ($kind)
    <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0 {{ $kind['colour'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $kind['node'] !!}</svg>
    <span class="sr-only">{{ $kind['label'] }}: </span>
    <div class="min-w-0 flex-1" data-alert-body>{{ $slot }}</div>
    @else
    {{ $slot }}
    @endif
    @isset($action)
    {{ $action }}
    @endisset
</div>
