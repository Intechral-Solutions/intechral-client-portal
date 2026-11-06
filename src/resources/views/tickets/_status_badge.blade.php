{{--
    EPIC-016 WP2: the ticket lifecycle mapping (§9.1, Direction D §10.4). Domain knowledge lives here; the
    x-ui.status primitive only draws a tone, a glyph and a label. Labels and status values are unchanged.

    `pending_user` uses the hourglass (EPIC-017 §13, WP2), ending the EPIC-016 P6 `dashed` substitute. The
    operator label is "Waiting on customer" (HD-P1, Ticket::OPERATOR_STATUS_LABELS). An unknown status falls back to neutral.

    @param string $status
--}}
@php
$map = [
    'open'         => ['tone' => 'info',    'glyph' => 'circle', 'label' => \App\Models\Ticket::operatorStatusLabel('open')],
    'in_progress'  => ['tone' => 'info',    'glyph' => 'half',   'label' => \App\Models\Ticket::operatorStatusLabel('in_progress')],
    'pending_user' => ['tone' => 'neutral', 'glyph' => 'hourglass', 'label' => \App\Models\Ticket::operatorStatusLabel('pending_user')],
    'resolved'     => ['tone' => 'success', 'glyph' => 'check',  'label' => \App\Models\Ticket::operatorStatusLabel('resolved')],
    'closed'       => ['tone' => 'neutral', 'glyph' => 'check',  'label' => \App\Models\Ticket::operatorStatusLabel('closed')],
];
$entry = $map[$status] ?? ['tone' => 'neutral', 'glyph' => 'circle', 'label' => \App\Models\Ticket::operatorStatusLabel($status)];
@endphp
<x-ui.status :tone="$entry['tone']" :glyph="$entry['glyph']" data-ticket-status="{{ $status }}">{{ $entry['label'] }}</x-ui.status>
