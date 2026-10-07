{{--
    EPIC-016 WP2: the Blade twin of resources/js/components/ui/status.tsx (Direction D §10).

    <x-ui.status tone="success" glyph="check">Resolved</x-ui.status>

    An inline glyph + a visible text label + a semantic colour. Colour is never the signal on its own: the
    glyph SHAPE differs by state and the label is always visible text. This is a presentation primitive; it
    knows nothing about tickets or invoices. Domain code (tickets/_status_badge, billing/_invoice_status,
    operator/cms/_state, ...) decides the `tone` and, where the domain has a specific shape, the `glyph`.

    - `tone`: `neutral` (default), `info`, `success`, `warning`, `danger`, `live`.
    - `glyph`: `circle`, `half`, `dot`, `check`, `triangle`, `square`, `dashed`. Defaults to the tone's own
      shape (neutral circle, info half, success check, warning triangle, danger square, live dot).
    - Statuses render inline, never as filled pills (pills are for tags and filter chips).

    The label uses the text-safe token (`success`, `warning`, `accent`, `danger`, `live-text`), the glyph the
    shape-only token (`success-glyph`, `warning-glyph`, `live`), exactly as the React primitive does.
    Callers add layout classes only (EPIC-016 §7.2 rule 1). It reads nothing from the database or request.
--}}
@props([
    'tone' => 'neutral',
    'glyph' => null,
])

@php
    $tones = [
        'neutral' => 'text-text-muted',
        'info' => 'text-accent',
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
        'live' => 'text-live-text',
    ];

    $glyphColour = [
        'neutral' => 'text-text-muted',
        'info' => 'text-accent',
        'success' => 'text-success-glyph',
        'warning' => 'text-warning-glyph',
        'danger' => 'text-danger',
        'live' => 'text-live',
    ];

    $defaultGlyph = [
        'neutral' => 'circle',
        'info' => 'half',
        'success' => 'check',
        'warning' => 'triangle',
        'danger' => 'square',
        'live' => 'dot',
    ];

    // The shared Status vocabulary, identical to status.tsx's `Glyph` (viewBox 0 0 12 12).
    $shapes = [
        'circle' => '<circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" stroke-width="1.5"/>',
        'dashed' => '<circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="2.2 1.6"/>',
        'half' => '<circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M6 1.75a4.25 4.25 0 0 1 0 8.5z" fill="currentColor"/>',
        'dot' => '<circle cx="6" cy="6" r="4.5" fill="currentColor"/>',
        'check' => '<circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3.9 6.2 5.4 7.7 8.2 4.6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>',
        'triangle' => '<path d="M6 1.5 11 10.5H1z" fill="currentColor" stroke-linejoin="round"/>',
        'square' => '<rect x="2" y="2" width="8" height="8" rx="1" fill="currentColor"/>',
    ];

    if (! isset($tones[$tone])) {
        throw new InvalidArgumentException("Unknown x-ui.status tone \"{$tone}\".");
    }

    $glyph ??= $defaultGlyph[$tone];

    if (! isset($shapes[$glyph])) {
        throw new InvalidArgumentException("Unknown x-ui.status glyph \"{$glyph}\".");
    }
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-sm font-medium '.$tones[$tone]]) }}>
    <svg viewBox="0 0 12 12" class="h-3 w-3 shrink-0 {{ $glyphColour[$tone] }}" data-glyph="{{ $glyph }}" aria-hidden="true" focusable="false">{!! $shapes[$glyph] !!}</svg>
    <span>{{ $slot }}</span>
</span>
