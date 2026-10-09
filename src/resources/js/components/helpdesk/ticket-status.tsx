import { Priority } from '@/components/ui/priority';
import { Status, type StatusGlyph, type StatusTone } from '@/components/ui/status';
import type { TicketPriorityValue, TicketStatusValue } from '@/types/helpdesk';

/**
 * The ticket lifecycle mapping (EPIC-017 §13, Direction D §10): the Blade `tickets/_status_badge`
 * twin. The domain decides the tone and the glyph; the shared `Status` only draws. The LABEL is
 * the server's: the customer vocabulary differs from the operator one ("Waiting on you" against
 * "Waiting on customer", P4), and a client-side label map would be a second copy of it.
 */
const marks: Record<TicketStatusValue, { tone: StatusTone; glyph: StatusGlyph }> = {
    open: { tone: 'info', glyph: 'circle' },
    in_progress: { tone: 'info', glyph: 'half' },
    pending_user: { tone: 'neutral', glyph: 'hourglass' },
    resolved: { tone: 'success', glyph: 'check' },
    closed: { tone: 'neutral', glyph: 'check' },
};

export function TicketStatus({ status, label }: { status: TicketStatusValue; label: string }) {
    const mark = marks[status] ?? { tone: 'neutral' as const, glyph: 'circle' as const };

    return (
        <Status tone={mark.tone} glyph={mark.glyph} data-ticket-status={status}>
            {label}
        </Status>
    );
}

/** Direction D §10.3: low 1 bar, medium 2, high 3, critical 3 in danger. */
const bars: Record<TicketPriorityValue, 1 | 2 | 3> = { low: 1, medium: 2, high: 3, critical: 3 };

const labels: Record<TicketPriorityValue, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export function TicketPriority({ priority }: { priority: TicketPriorityValue }) {
    return (
        <Priority bars={bars[priority] ?? 1} tone={priority === 'critical' ? 'danger' : 'neutral'}>
            {labels[priority] ?? priority}
        </Priority>
    );
}
