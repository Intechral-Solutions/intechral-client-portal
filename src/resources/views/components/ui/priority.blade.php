{{--
    EPIC-016 WP2: the Blade twin of resources/js/components/ui/priority.tsx (Direction D §10.3).

    <x-ui.priority :bars="3" tone="danger">Critical</x-ui.priority>

    Three ascending bars plus a visible text label. The bars are decoration (`aria-hidden`) and the label is
    the signal, so priority is never colour or shape alone. `critical` is the same three bars in `danger`
    with the label in `danger`; unfilled bars use `rule-control`.

    The domain decides how many bars a value earns and which tone it takes (tickets/_priority_badge); this
    primitive only draws. `bars` is 1-3, `tone` is `neutral` (default) or `danger`.
--}}
@props([
    'bars',
    'tone' => 'neutral',
])

@php
    $bars = (int) $bars;

    if ($bars < 1 || $bars > 3) {
        throw new InvalidArgumentException("x-ui.priority bars must be 1, 2 or 3, got \"{$bars}\".");
    }
    if (! in_array($tone, ['neutral', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown x-ui.priority tone \"{$tone}\".");
    }

    $toneClasses = $tone === 'danger' ? 'font-medium text-danger' : 'text-text-secondary';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-sm '.$toneClasses]) }}>
    <svg viewBox="0 0 12 12" class="h-3 w-3 shrink-0" data-bars="{{ $bars }}" aria-hidden="true" focusable="false">
        @foreach ([1, 2, 3] as $bar)
        <rect data-bar="{{ $bar <= $bars ? 'filled' : 'empty' }}" x="{{ ($bar - 1) * 4.2 }}" y="{{ 12 - $bar * 3.6 }}" width="2.6" height="{{ $bar * 3.6 }}" rx="0.6" class="{{ $bar <= $bars ? 'fill-current' : 'fill-rule-control' }}"/>
        @endforeach
    </svg>
    <span>{{ $slot }}</span>
</span>
