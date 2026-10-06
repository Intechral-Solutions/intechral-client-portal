{{--
    EPIC-016 WP1: the semantic link seam (Direction D §2.2 "Link / info", EPIC-016 §6 row-link grammar, P2).

    <x-ui.link href="...">View</x-ui.link>                       standalone links and row actions: text-accent
    <x-ui.link href="..." variant="quiet">Back to tickets</x-ui.link>   quieter, underlined
    <x-ui.link href="..." variant="row">{{ $title }}</x-ui.link>         a row's primary identifier

    Carries the exact focusRing string with `rounded-control`. Callers add layout classes only; `href`,
    `target`, `rel` and every other attribute pass through unchanged.
--}}
@props([
    'variant' => 'accent',
])

@php
    $variants = [
        'accent' => 'text-accent hover:text-accent-hover hover:underline',
        'quiet' => 'text-text-secondary underline underline-offset-2 hover:text-text',
        'row' => 'text-text font-medium hover:underline',
    ];

    if (! isset($variants[$variant])) {
        throw new InvalidArgumentException("Unknown x-ui.link variant \"{$variant}\".");
    }

    // Not Tailwind's colour-transition shorthand: it includes `outline-color`, so the focus ring would fade
    // in from currentColor instead of appearing at full strength (control-metrics.ts `focusRing`, §7.2 rule 2).
    $classes = 'rounded-control transition-[color,background-color,border-color] duration-motion-fast ease-motion focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus '.$variants[$variant];
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
