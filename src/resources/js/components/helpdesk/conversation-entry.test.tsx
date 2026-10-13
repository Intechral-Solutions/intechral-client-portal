import { render, screen } from '@testing-library/react';

import { ConversationEntry } from '@/components/helpdesk/conversation-entry';
import type { ConversationEntryData } from '@/types/helpdesk';

const entry: ConversationEntryData = {
    key: 'reply-4',
    kind: 'reply',
    author: { name: 'Handler Hana' },
    body: 'Line one\nLine two',
    createdAt: '2026-10-08T10:00:00Z',
    createdAtHuman: '2 hours ago',
    attachments: [
        { id: 9, filename: 'diagnostics.txt', size: '1.2 KB', href: '/attachments/9/download' },
    ],
};

function renderEntry(data: ConversationEntryData) {
    return render(
        <ol>
            <ConversationEntry entry={data} />
        </ol>,
    );
}

it('shows the author, a machine-readable time and the body with its line breaks kept', () => {
    renderEntry(entry);

    expect(screen.getByText('Handler Hana')).toBeInTheDocument();
    expect(screen.getByText('2 hours ago')).toHaveAttribute('datetime', '2026-10-08T10:00:00Z');
    expect(screen.getByText(/Line one/)).toHaveClass('whitespace-pre-wrap');
});

it('renders a hostile body and file name as text, never as markup', () => {
    const hostile = '<img src=x onerror="window.__pwned=1"><script>alert(1)</script>';
    const { container } = renderEntry({
        ...entry,
        body: hostile,
        attachments: [{ ...entry.attachments[0]!, filename: hostile }],
    });

    expect(container.querySelector('img, script')).not.toBeInTheDocument();
    expect(screen.getAllByText(hostile).length).toBeGreaterThan(0);
});

it('links each attachment to its download as a document link, with the size', () => {
    renderEntry(entry);

    const link = screen.getByRole('link', { name: /diagnostics\.txt/ });

    expect(link).toHaveAttribute('href', '/attachments/9/download');
    expect(link).not.toHaveAttribute('data-inertia-link');
    expect(link).toHaveTextContent('(1.2 KB)');
});

it('shows no attachment list for an entry without files', () => {
    renderEntry({ ...entry, attachments: [] });

    expect(screen.queryByRole('list', { name: 'Attached files' })).not.toBeInTheDocument();
});

it('can take programmatic focus without becoming a tab stop', () => {
    renderEntry(entry);

    expect(document.querySelector('li')).toHaveAttribute('tabindex', '-1');
});

it('shows exactly what it was given and filters nothing', () => {
    // The server removes internal notes before this component ever sees a list; it holds no visibility
    // rule of its own, so there is nothing here to bypass.
    renderEntry({ ...entry, body: 'A note the server chose to send' });

    expect(screen.getByText('A note the server chose to send')).toBeInTheDocument();
});
