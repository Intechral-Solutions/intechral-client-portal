{{--
    EPIC-016 WP2: the ONE invoice lifecycle mapping (§9.3, Direction D §10.5), shared by the operator index
    and detail and the client index and detail. It replaces four duplicated colour maps. `Invoice::STATUSES`
    is unchanged.

    `sent` uses `half` and `overdue` uses `square`: the recorded temporary substitutes (P11) for Direction
    D's accent arrow and danger clock, which the shared Status glyph vocabulary lacks. A cancelled invoice's
    amount is struck through by the row that shows it (P7). An unknown status falls back to the draft look.

    @param string $status
--}}
@php
$map = [
    'draft'     => ['tone' => 'neutral', 'glyph' => 'dashed', 'label' => 'Draft'],
    'sent'      => ['tone' => 'info',    'glyph' => 'half',   'label' => 'Sent'],
    'paid'      => ['tone' => 'success', 'glyph' => 'check',  'label' => 'Paid'],
    'overdue'   => ['tone' => 'danger',  'glyph' => 'square', 'label' => 'Overdue'],
    'cancelled' => ['tone' => 'neutral', 'glyph' => 'circle', 'label' => 'Cancelled'],
];
$entry = $map[$status] ?? ['tone' => 'neutral', 'glyph' => 'dashed', 'label' => ucfirst($status)];
@endphp
<x-ui.status :tone="$entry['tone']" :glyph="$entry['glyph']" data-invoice-status="{{ $status }}">{{ $entry['label'] }}</x-ui.status>
