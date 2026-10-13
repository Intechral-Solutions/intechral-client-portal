import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TicketsShowPage, type TicketsShowProps } from '@/pages/tickets/show';
import { emitFormErrors, resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';
import type { ConversationEntryData, ReplyState, TicketStatusValue } from '@/types/helpdesk';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const CLOSED = 'This request is closed. Raise a new request if you need more help.';

/** The page's polite announcement region (the closed notice is also a status alert, so it is told apart). */
function announcer() {
    return screen.getAllByRole('status').find((element) => element.classList.contains('sr-only'))!;
}

function entry(key: string, overrides: Partial<ConversationEntryData> = {}): ConversationEntryData {
    return {
        key,
        kind: 'reply',
        author: { name: 'Handler Hana' },
        body: 'We are looking into it.',
        createdAt: '2026-10-08T10:00:00Z',
        createdAtHuman: '1 hour ago',
        attachments: [],
        ...overrides,
    };
}

function replyFor(status: TicketStatusValue): ReplyState {
    return {
        allowed: status !== 'closed',
        notice: status === 'pending_user' ? 'returns' : status === 'resolved' ? 'reopens' : null,
        closedMessage: status === 'closed' ? CLOSED : null,
    };
}

function props(
    status: TicketStatusValue = 'open',
    overrides: Partial<TicketsShowProps> = {},
): TicketsShowProps {
    return {
        ticket: {
            id: 12,
            number: 'TKT-12',
            title: 'Printer offline',
            status,
            statusLabel:
                status === 'pending_user'
                    ? 'Waiting on you'
                    : status === 'in_progress'
                      ? 'In progress'
                      : status[0]!.toUpperCase() + status.slice(1),
            priority: 'high',
            category: 'Technical',
            createdAt: '2026-10-08T08:00:00Z',
            createdAtHuman: '3 hours ago',
            handledBy: 'Handler Hana',
        },
        conversation: [
            entry('description', {
                kind: 'description',
                author: { name: 'Casey Customer' },
                body: 'It prints nothing.\nNot even a test page.',
            }),
            entry('reply-4'),
        ],
        history: [
            { statusLabel: 'Waiting on you', at: '2026-10-08T09:00:00Z', atHuman: '2 hours ago' },
        ],
        reply: replyFor(status),
        attachmentPolicy: { maxFiles: 10, maxSizeMb: 20, extensions: ['pdf', 'png'] },
        abilities: { create: true },
        ...overrides,
    };
}

it('shows the request: number, title, customer status, priority, opened time and who handles it', () => {
    render(<TicketsShowPage {...props('pending_user')} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Printer offline' })).toBeInTheDocument();
    expect(screen.getByText('TKT-12')).toBeInTheDocument();
    expect(screen.getAllByText('Waiting on you').length).toBeGreaterThan(0);
    expect(screen.getByText('High')).toBeVisible();
    expect(screen.getByText('3 hours ago')).toHaveAttribute('datetime', '2026-10-08T08:00:00Z');
    expect(screen.getByText(/Handled by Handler Hana/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /All requests/ })).toHaveAttribute('href', '/tickets');
});

it('omits "Handled by" while nobody is assigned', () => {
    const base = props();
    render(<TicketsShowPage {...base} ticket={{ ...base.ticket, handledBy: null }} />);

    expect(screen.queryByText(/Handled by/)).not.toBeInTheDocument();
});

it('lists the description first and then the replies, in the order the server sent', () => {
    render(<TicketsShowPage {...props()} />);

    const entries = within(screen.getByRole('region', { name: 'Conversation' })).getAllByRole(
        'listitem',
    );

    expect(entries).toHaveLength(2);
    expect(entries[0]).toHaveTextContent('Casey Customer');
    expect(entries[0]).toHaveTextContent('It prints nothing.');
    expect(entries[1]).toHaveTextContent('Handler Hana');
});

it('shows the status trail with the customer label and no actor', () => {
    render(<TicketsShowPage {...props()} />);

    const history = within(screen.getByRole('region', { name: 'History' }));

    expect(history.getByText('Status changed to Waiting on you')).toBeInTheDocument();
    expect(history.getByText('2 hours ago')).toHaveAttribute('datetime', '2026-10-08T09:00:00Z');
    expect(history.queryByText(/ by /)).not.toBeInTheDocument();
});

it('shows the category in the details and no operator-only section', () => {
    render(<TicketsShowPage {...props()} />);

    expect(screen.getByText('Category').nextElementSibling).toHaveTextContent('Technical');
    for (const operatorOnly of [
        /SLA/i,
        /overdue/i,
        /time tracked/i,
        /start timer/i,
        /internal/i,
        /assignee/i,
    ]) {
        expect(screen.queryByText(operatorOnly)).not.toBeInTheDocument();
    }
});

describe('the reply area, for every status', () => {
    it.each<TicketStatusValue>(['open', 'in_progress'])(
        'offers the composer on %s with no notice',
        (status) => {
            render(<TicketsShowPage {...props(status)} />);

            expect(screen.getByLabelText(/Your reply/)).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Send reply' })).toBeInTheDocument();
            expect(screen.queryByText(/Replying/)).not.toBeInTheDocument();
        },
    );

    it('offers the composer on a request waiting for the customer and says a reply returns it to the team', () => {
        render(<TicketsShowPage {...props('pending_user')} />);

        expect(screen.getByLabelText(/Your reply/)).toBeInTheDocument();
        expect(
            screen.getByText('Replying sends this request back to the support team.'),
        ).toBeInTheDocument();
    });

    it('offers the composer on a resolved request and says a reply reopens it', () => {
        render(<TicketsShowPage {...props('resolved')} />);

        expect(screen.getByLabelText(/Your reply/)).toBeInTheDocument();
        expect(screen.getByText('Replying reopens this request.')).toBeInTheDocument();
    });

    it('offers no composer on a closed request, only the approved notice and a New request link', () => {
        render(<TicketsShowPage {...props('closed')} />);

        expect(screen.queryByLabelText(/Your reply/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send reply' })).not.toBeInTheDocument();
        expect(screen.getByText(CLOSED)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'New request' })).toHaveAttribute(
            'href',
            '/tickets/create',
        );
    });

    it('leaves the New request link out of the closed notice without the ability', () => {
        render(<TicketsShowPage {...props('closed', { abilities: { create: false } })} />);

        expect(screen.getByText(CLOSED)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'New request' })).not.toBeInTheDocument();
    });
});

describe('sending a reply', () => {
    it('posts multipart to the reply route with the body and files, and only when there is text', async () => {
        const user = userEvent.setup();
        render(<TicketsShowPage {...props('open')} />);

        expect(screen.getByRole('button', { name: 'Send reply' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/Your reply/), {
            target: { value: 'Here is a photo.' },
        });
        const file = new File(['png'], 'photo.png', { type: 'image/png' });
        await user.upload(screen.getByLabelText('Attachments'), file);
        await user.click(screen.getByRole('button', { name: 'Send reply' }));

        expect(submitted()).toHaveLength(1);
        expect(submitted()[0]).toMatchObject({
            method: 'post',
            url: '/tickets/12/replies',
            options: { forceFormData: true, preserveScroll: true },
            data: { body: 'Here is a photo.', attachments: [file] },
        });
        // The requester never chooses an internal note: the field does not exist.
        expect(Object.keys(submitted()[0]!.data).sort()).toEqual(['attachments', 'body']);
    });

    it('clears the form, announces "Reply sent." and moves focus to the new entry once the server has accepted it', async () => {
        const page = props('open');
        const { rerender } = render(<TicketsShowPage {...page} />);

        fireEvent.change(screen.getByLabelText(/Your reply/), { target: { value: 'Thanks' } });
        fireEvent.submit(screen.getByLabelText(/Your reply/).closest('form')!);
        act(() => submitted()[0]!.options.onSuccess!());

        // Nothing is announced before the server's conversation arrives.
        expect(announcer()).toHaveTextContent('');

        rerender(
            <TicketsShowPage
                {...page}
                conversation={[
                    ...page.conversation,
                    entry('reply-5', { author: { name: 'Casey Customer' }, body: 'Thanks' }),
                ]}
            />,
        );

        await waitFor(() => expect(announcer()).toHaveTextContent('Reply sent.'));
        expect(document.getElementById('entry-reply-5')).toHaveFocus();
        expect(screen.getByLabelText(/Your reply/)).toHaveValue('');
    });

    it('does the same when the updated conversation renders before the form reports success', async () => {
        const page = props('open');
        const { rerender } = render(<TicketsShowPage {...page} />);

        fireEvent.change(screen.getByLabelText(/Your reply/), { target: { value: 'Thanks' } });
        fireEvent.submit(screen.getByLabelText(/Your reply/).closest('form')!);

        // Inertia swapped the props first...
        rerender(
            <TicketsShowPage
                {...page}
                conversation={[...page.conversation, entry('reply-5', { body: 'Thanks' })]}
            />,
        );
        expect(announcer()).toHaveTextContent('');

        // ...and only then called onSuccess (the closure it holds is the submit-time render's).
        act(() => submitted()[0]!.options.onSuccess!());

        await waitFor(() => expect(announcer()).toHaveTextContent('Reply sent.'));
        expect(document.getElementById('entry-reply-5')).toHaveFocus();
    });

    it('announces a second reply again, clearing the region first', async () => {
        const page = props('open');
        const { rerender } = render(<TicketsShowPage {...page} />);
        const send = (key: string, conversation: ConversationEntryData[]) => {
            fireEvent.change(screen.getByLabelText(/Your reply/), { target: { value: key } });
            fireEvent.submit(screen.getByLabelText(/Your reply/).closest('form')!);
            act(() => submitted().at(-1)!.options.onSuccess!());
            rerender(<TicketsShowPage {...page} conversation={conversation} />);
        };
        const first = [...page.conversation, entry('reply-5')];

        send('one', first);
        await waitFor(() => expect(announcer()).toHaveTextContent('Reply sent.'));

        send('two', [...first, entry('reply-6')]);
        // Cleared synchronously, so assistive technology sees a change before the text returns.
        expect(announcer()).toHaveTextContent('');
        await waitFor(() => expect(announcer()).toHaveTextContent('Reply sent.'));
        expect(document.getElementById('entry-reply-6')).toHaveFocus();
    });

    it("shows a rejected reply's message under the field, keeps the text and says nothing was sent", () => {
        setFormErrors({ body: 'The body field is required.' });
        render(<TicketsShowPage {...props('open')} />);

        const body = screen.getByLabelText(/Your reply/);

        expect(body).toHaveAttribute('aria-invalid', 'true');
        expect(body).toHaveAttribute('aria-describedby', 'reply-body-error');
        expect(screen.getByText('The body field is required.')).toHaveAttribute(
            'id',
            'reply-body-error',
        );
        expect(announcer()).toHaveTextContent('');
        expect(screen.queryByText('Reply sent.')).not.toBeInTheDocument();
    });

    it('names the offending file when an attachment is refused', async () => {
        setFormErrors({
            'attachments.0': 'This file type cannot be attached. Allowed types: pdf.',
        });
        const user = userEvent.setup({ applyAccept: false });
        render(<TicketsShowPage {...props('open')} />);
        await user.upload(screen.getByLabelText('Attachments'), new File(['MZ'], 'setup.exe'));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'setup.exe: This file type cannot be attached. Allowed types: pdf.',
        );
    });

    it('moves focus to the reply field, or to the files field, on a refused submit', async () => {
        const user = userEvent.setup();
        render(<TicketsShowPage {...props('open')} />);
        fireEvent.change(screen.getByLabelText(/Your reply/), { target: { value: 'x' } });
        await user.click(screen.getByRole('button', { name: 'Send reply' }));

        act(() => submitted()[0]!.options.onError!({ body: 'The body field is required.' }));
        expect(screen.getByLabelText(/Your reply/)).toHaveFocus();

        act(() => submitted()[0]!.options.onError!({ 'attachments.0': 'Failed.' }));
        expect(screen.getByLabelText('Attachments')).toHaveFocus();
    });
});

describe('a reply refused because the request closed meanwhile (422 on body)', () => {
    it('shows the refusal as an error with the closed notice, keeps the unsent text, and never says it was sent', () => {
        // The form held an error from the refused POST when the page reloaded as closed.
        setFormErrors({ body: CLOSED });
        const open = props('open');
        const { rerender } = render(<TicketsShowPage {...open} />);
        fireEvent.change(screen.getByLabelText(/Your reply/), {
            target: { value: 'My long unsent answer' },
        });

        rerender(<TicketsShowPage {...props('closed')} />);

        expect(screen.queryByLabelText(/Your reply/)).not.toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent(CLOSED);
        expect(screen.getByLabelText('Your message was not sent')).toHaveValue(
            'My long unsent answer',
        );
        expect(screen.getByLabelText('Your message was not sent')).toHaveAttribute('readonly');
        expect(screen.getByRole('link', { name: 'New request' })).toBeInTheDocument();
        expect(screen.queryByText('Reply sent.')).not.toBeInTheDocument();
        expect(announcer()).toHaveTextContent('');
    });

    it('moves keyboard focus to the refusal when the page closes under the focused composer, then the 422 arrives', async () => {
        const user = userEvent.setup();
        const open = props('open');
        const { rerender } = render(<TicketsShowPage {...open} />);

        await user.click(screen.getByLabelText(/Your reply/));
        await user.keyboard('My long unsent answer');
        await user.click(screen.getByRole('button', { name: 'Send reply' }));
        expect(screen.getByRole('button', { name: 'Send reply' })).toHaveFocus();

        // The request closed meanwhile. Inertia swaps the page's props first: the composer unmounts with
        // the focus in it (the Send button was just pressed), and focus falls to the body...
        rerender(<TicketsShowPage {...props('closed')} />);
        expect(screen.queryByLabelText(/Your reply/)).not.toBeInTheDocument();
        expect(document.body).toHaveFocus();

        // ...and only then the form learns of the 422 (onError finds no textarea left to focus).
        act(() => {
            emitFormErrors({ body: CLOSED });
            submitted()[0]!.options.onError!({ body: CLOSED });
        });

        const refusal = screen.getByRole('group', { name: 'Reply refused' });

        expect(refusal).toHaveFocus();
        expect(refusal).toHaveAttribute('tabindex', '-1');
        // Still announced as an error, and the closed notice and the unsent text are kept.
        expect(within(refusal).getByRole('alert')).toHaveTextContent(CLOSED);
        expect(screen.getByLabelText('Your message was not sent')).toHaveValue(
            'My long unsent answer',
        );
        expect(screen.getByRole('link', { name: 'New request' })).toBeInTheDocument();
        expect(announcer()).toHaveTextContent('');
    });

    it('does not take focus on an ordinary closed request, nor on a re-render after the refusal', () => {
        const { rerender } = render(<TicketsShowPage {...props('closed')} />);

        expect(document.body).toHaveFocus();
        expect(screen.queryByRole('group', { name: 'Reply refused' })).not.toBeInTheDocument();

        act(() => emitFormErrors({ body: CLOSED }));
        const refusal = screen.getByRole('group', { name: 'Reply refused' });
        expect(refusal).toHaveFocus();

        // The reader moves on; a later render with the same refusal must not pull focus back.
        const link = screen.getByRole('link', { name: 'All requests' });
        act(() => link.focus());
        rerender(<TicketsShowPage {...props('closed')} />);

        expect(link).toHaveFocus();
    });

    it('does not show an error or an unsent message on a request that was simply closed already', () => {
        render(<TicketsShowPage {...props('closed')} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Your message was not sent')).not.toBeInTheDocument();
    });
});

it('renders a hostile reply and attachment name as text', () => {
    const hostile = '<img src=x onerror="window.__pwned=1">';
    const base = props();
    const { container } = render(
        <TicketsShowPage
            {...base}
            conversation={[
                ...base.conversation,
                entry('reply-9', {
                    body: hostile,
                    attachments: [
                        { id: 3, filename: hostile, size: '1 B', href: '/attachments/3/download' },
                    ],
                }),
            ]}
        />,
    );

    expect(container.querySelector('img')).not.toBeInTheDocument();
    expect(screen.getAllByText(hostile).length).toBeGreaterThan(0);
});
