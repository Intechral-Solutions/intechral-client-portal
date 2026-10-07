{{--
    EPIC-016 WP2: the ticket priority mapping (§9.2, Direction D §10.3): bars + visible label. low 1 bar,
    medium 2, high 3, all neutral; critical is 3 bars in danger. Values, ordering and sorting are unchanged.
    An unknown priority falls back to one neutral bar.

    @param string $priority
--}}
@php
$map = [
    'low'      => ['bars' => 1, 'tone' => 'neutral'],
    'medium'   => ['bars' => 2, 'tone' => 'neutral'],
    'high'     => ['bars' => 3, 'tone' => 'neutral'],
    'critical' => ['bars' => 3, 'tone' => 'danger'],
];
$entry = $map[$priority] ?? ['bars' => 1, 'tone' => 'neutral'];
@endphp
<x-ui.priority :bars="$entry['bars']" :tone="$entry['tone']" data-ticket-priority="{{ $priority }}">{{ ucfirst($priority) }}</x-ui.priority>
