{{--
    EPIC-016 WP2: the ticket lifecycle mapping (§9.1, Direction D §10.4). Domain knowledge lives here; the
    x-ui.status primitive only draws a tone, a glyph and a label. Labels and status values are unchanged.

    `pending_user` uses `dashed` as the recorded temporary substitute for Direction D's hourglass (P6): the
    shared Status glyph vocabulary has no hourglass. An unknown status falls back to neutral.

    @param string $status
--}}
@php
$map = [
    'open'         => ['tone' => 'info',    'glyph' => 'circle', 'label' => 'Open'],
    'in_progress'  => ['tone' => 'info',    'glyph' => 'half',   'label' => 'In Progress'],
    'pending_user' => ['tone' => 'neutral', 'glyph' => 'dashed', 'label' => 'Pending'],
    'resolved'     => ['tone' => 'success', 'glyph' => 'check',  'label' => 'Resolved'],
    'closed'       => ['tone' => 'neutral', 'glyph' => 'check',  'label' => 'Closed'],
];
$entry = $map[$status] ?? ['tone' => 'neutral', 'glyph' => 'circle', 'label' => ucfirst($status)];
@endphp
<x-ui.status :tone="$entry['tone']" :glyph="$entry['glyph']" data-ticket-status="{{ $status }}">{{ $entry['label'] }}</x-ui.status>
