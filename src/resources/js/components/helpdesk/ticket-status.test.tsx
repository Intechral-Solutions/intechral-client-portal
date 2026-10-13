import { render, screen } from '@testing-library/react';

import { TicketPriority, TicketStatus } from '@/components/helpdesk/ticket-status';
import type { TicketPriorityValue, TicketStatusValue } from '@/types/helpdesk';

function glyph(container: HTMLElement) {
    return Array.from(container.querySelector('svg')!.children)
        .map(
            (child) =>
                `${child.tagName}:${child.getAttribute('stroke-dasharray') ?? ''}:${child.getAttribute('d')?.slice(0, 12) ?? ''}`,
        )
        .join('|');
}

const statuses: [TicketStatusValue, string][] = [
    ['open', 'Open'],
    ['in_progress', 'In progress'],
    ['pending_user', 'Waiting on you'],
    ['resolved', 'Resolved'],
    ['closed', 'Closed'],
];

it.each(statuses)('shows the server label as visible text for %s', (status, label) => {
    const { container } = render(<TicketStatus status={status} label={label} />);

    expect(screen.getByText(label)).toBeVisible();
    expect(container.firstElementChild).toHaveAttribute('data-ticket-status', status);
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
});

it('uses the label the server sent, never one of its own', () => {
    render(<TicketStatus status="pending_user" label="Waiting on customer" />);

    expect(screen.getByText('Waiting on customer')).toBeVisible();
    expect(screen.queryByText('Waiting on you')).not.toBeInTheDocument();
});

it('gives the waiting state the hourglass, not the dashed circle it used to borrow', () => {
    const waiting = render(<TicketStatus status="pending_user" label="Waiting on you" />);
    const dashed = render(<TicketStatus status="open" label="Open" />);

    expect(glyph(waiting.container)).toContain('path');
    expect(glyph(waiting.container)).not.toContain('2.2 1.6');
    expect(glyph(waiting.container)).not.toEqual(glyph(dashed.container));
});

it('never lets two different lifecycle states share a shape and a colour', () => {
    const marks = statuses.map(([status, label]) => {
        const { container } = render(<TicketStatus status={status} label={label} />);

        return `${container.firstElementChild!.className}::${glyph(container)}`;
    });

    // resolved and closed share a shape, but not a tone.
    expect(new Set(marks).size).toBe(statuses.length);
});

const priorities: [TicketPriorityValue, string, number][] = [
    ['low', 'Low', 1],
    ['medium', 'Medium', 2],
    ['high', 'High', 3],
    ['critical', 'Critical', 3],
];

it.each(priorities)('draws %s with its label and %i filled bars', (priority, label, bars) => {
    const { container } = render(<TicketPriority priority={priority} />);

    expect(screen.getByText(label)).toBeVisible();
    expect(container.querySelectorAll('[data-bar="filled"]')).toHaveLength(bars);
});

it('marks critical as danger', () => {
    const { container } = render(<TicketPriority priority="critical" />);

    expect(container.firstElementChild).toHaveClass('text-danger');
});
