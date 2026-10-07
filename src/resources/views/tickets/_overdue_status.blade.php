{{--
    EPIC-016 WP2: the ticket SLA "Overdue" mark (§9.4): danger status, glyph + the existing label. The glyph
    is `square`, the recorded temporary substitute for Direction D's clock (P11). The caller decides
    whether a ticket is overdue (Ticket::isOverdue(), existing server truth) and adds layout classes.

    @param string $class  layout classes only
--}}
<x-ui.status tone="danger" glyph="square" data-overdue-status class="{{ $class ?? '' }}">Overdue</x-ui.status>
