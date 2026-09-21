import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: React.ComponentProps<'a'>) => <a href={href}>{children}</a>,
    router: { get: inertia.get, delete: vi.fn() },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setData] = useState(initial);

        return {
            data,
            setData: (key: keyof T, value: T[keyof T]) =>
                setData((current) => ({ ...current, [key]: value })),
            errors: {},
            processing: false,
            reset: vi.fn(),
            post: inertia.post,
            put: inertia.put,
        };
    },
}));

import { TimerProvider } from '@/components/time/timer-provider';
import { EntryActions, TimePage } from '@/pages/time';
import type { TimeEntryData } from '@/types/time';

const entry: TimeEntryData = {
    id: 1,
    date: '2026-09-20',
    durationMinutes: 60,
    durationHuman: '1h',
    hours: 1,
    description: 'Entry',
    billable: true,
    billed: false,
    invoiceLinked: false,
    locked: false,
    running: false,
    context: null,
};

const emptyPage = {
    entries: {
        data: [entry],
        current_page: 1,
        last_page: 1,
        from: 1,
        to: 1,
        total: 1,
        links: [],
        prev_page_url: null,
        next_page_url: null,
    },
    projects: [],
    filters: { project_id: null, ticket_id: null, from: null, to: null },
    totalMinutes: 60,
};

afterEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.get.mockReset();
});

it('suppresses mutation controls for locked and running entries', () => {
    const { rerender } = render(<EntryActions entry={{ ...entry, locked: true }} />);

    expect(screen.getByText('Locked')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();

    rerender(<EntryActions entry={{ ...entry, running: true }} />);
    expect(screen.getByText('Running')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('lets a timer-derived non-quarter-hour entry pass native validation and submit', async () => {
    const user = userEvent.setup();
    // 50 minutes, as the server serializes it: round(50 / 60, 2).
    render(<EntryActions entry={{ ...entry, durationMinutes: 50, hours: 0.83 }} />);

    await user.click(screen.getByRole('button', { name: 'Edit entry from 2026-09-20' }));

    const hours = screen.getByLabelText('Hours');
    expect(hours).toHaveValue(0.83);
    expect(hours).toHaveAttribute('min', '0.01');
    expect(hours).toHaveAttribute('max', '24');
    expect(hours).toHaveAttribute('step', 'any');
    expect((hours as HTMLInputElement).validity.valid).toBe(true);

    await user.click(screen.getByRole('button', { name: 'Save entry' }));

    expect(inertia.put).toHaveBeenCalledTimes(1);
    expect(inertia.put).toHaveBeenCalledWith(
        '/time/1',
        expect.objectContaining({ errorBag: 'updateTimeEntry1' }),
    );
});

it('lets a short timer-derived entry be re-saved without raising it to 15 minutes', async () => {
    const user = userEvent.setup();
    // 7 minutes, as the server serializes it: round(7 / 60, 2).
    render(<EntryActions entry={{ ...entry, durationMinutes: 7, hours: 0.12 }} />);

    await user.click(screen.getByRole('button', { name: 'Edit entry from 2026-09-20' }));

    const hours = screen.getByLabelText('Hours') as HTMLInputElement;
    expect(hours).toHaveValue(0.12);
    expect(hours.validity.valid).toBe(true);

    await user.click(screen.getByRole('button', { name: 'Save entry' }));

    expect(inertia.put).toHaveBeenCalledTimes(1);
});

it('still rejects zero, sub-minute, and oversized hours when editing', async () => {
    const user = userEvent.setup();
    render(<EntryActions entry={{ ...entry, durationMinutes: 50, hours: 0.83 }} />);

    await user.click(screen.getByRole('button', { name: 'Edit entry from 2026-09-20' }));
    const hours = screen.getByLabelText('Hours') as HTMLInputElement;

    for (const [value, reason] of [
        ['0', 'rangeUnderflow'],
        ['0.005', 'rangeUnderflow'],
        ['24.5', 'rangeOverflow'],
    ] as const) {
        await user.clear(hours);
        await user.type(hours, value);
        expect(hours.validity[reason]).toBe(true);
    }

    await user.click(screen.getByRole('button', { name: 'Save entry' }));
    expect(inertia.put).not.toHaveBeenCalled();
});

it('keeps the manual create form on the quarter-hour contract', async () => {
    const user = userEvent.setup();
    render(
        <TimerProvider enabled={false}>
            <TimePage {...emptyPage} />
        </TimerProvider>,
    );

    const hours = screen.getAllByLabelText('Hours')[0]! as HTMLInputElement;
    expect(hours).toHaveAttribute('step', '0.25');
    expect(hours).toHaveAttribute('min', '0.25');

    // A 7-minute value is not a valid new manual entry, though it is a valid edit.
    await user.type(hours, '0.12');
    expect(hours.validity.rangeUnderflow).toBe(true);
    await user.clear(hours);

    await user.type(hours, '0.83');
    expect(hours.validity.stepMismatch).toBe(true);

    await user.clear(hours);
    await user.type(hours, '1.25');
    expect(hours.validity.valid).toBe(true);
});

it('keeps an active backend-only ticket filter when applying other filters', async () => {
    const user = userEvent.setup();
    render(
        <TimerProvider enabled={false}>
            <TimePage
                {...emptyPage}
                filters={{ project_id: null, ticket_id: '7', from: null, to: null }}
            />
        </TimerProvider>,
    );

    await user.type(screen.getByLabelText('From'), '2026-09-01');
    await user.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(inertia.get).toHaveBeenCalledWith(
        '/time',
        { ticket_id: '7', from: '2026-09-01' },
        { preserveState: true, replace: true },
    );
});
