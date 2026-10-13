import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: React.ComponentProps<'a'>) => <a href={href}>{children}</a>,
    router: { get: inertia.get, reload: inertia.reload, delete: vi.fn() },
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
import type { ActiveTimer, TimeEntryData } from '@/types/time';

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
    inertia.reload.mockReset();
    vi.unstubAllGlobals();
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

// ── The page-local Stop (EPIC-017 WP1, F1) ────────────────────────────────────────────────────
//
// WP1 gives a Focused customer no global timer pill or tray, yet this page can still start a timer.
// These cases pin the Start → Stop completeness without a shell: the provider is the real one, and
// the timer API is a URL-routed stand-in.

function runningTimer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 7,
        started_at: '2026-09-20T11:00:00.000Z',
        server_now: '2026-09-20T12:00:00.000Z',
        description: null,
        context: null,
        ...overrides,
    };
}

function json(body: unknown, status = 200) {
    return { ok: status < 400, status, json: async () => body } as unknown as Response;
}

/** A timer API that answers by URL; `active` is the server's current set and changes as it is stopped. */
function stubTimerApi(initial: ActiveTimer[], stop: (id: number) => Response | null = () => null) {
    let active = initial;
    const calls: string[] = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            calls.push(`${method} ${url}`);

            if (url.endsWith('/time/timers/active')) {
                return json(active);
            }

            if (url.endsWith('/time/timer/start') && method === 'POST') {
                const started = runningTimer({ id: 8, description: 'Second one' });
                active = [...active, started];

                return json(started);
            }

            const stopping = /\/time\/timer\/(\d+)\/stop$/.exec(url);

            if (stopping && method === 'POST') {
                const id = Number(stopping[1]);
                const override = stop(id);

                if (override) {
                    return override;
                }

                active = active.filter((timer) => timer.id !== id);

                return json({ id });
            }

            return json([]);
        }),
    );

    return calls;
}

function renderTimePage() {
    return render(
        <TimerProvider enabled>
            <TimePage {...emptyPage} />
        </TimerProvider>,
    );
}

it('draws no running-timers list before the first read is confirmed', async () => {
    let release: (value: Response) => void = () => {};
    vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise<Response>((resolve) => (release = resolve))),
    );

    renderTimePage();

    expect(screen.queryByText('Running timers')).not.toBeInTheDocument();

    await act(async () => release(json([runningTimer()])));

    expect(await screen.findByText('Running timers')).toBeInTheDocument();
});

it('draws nothing while no timer runs', async () => {
    stubTimerApi([]);
    renderTimePage();

    await act(async () => Promise.resolve());

    expect(screen.queryByText('Running timers')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Stop timer/ })).not.toBeInTheDocument();
    // The rest of the page is untouched.
    expect(screen.getByRole('heading', { name: 'Start a timer' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Start timer' })).toBeInTheDocument();
});

it('names each running timer by its record, else its description, else a plain fallback', async () => {
    stubTimerApi([
        runningTimer({
            id: 1,
            description: 'ignored when there is a record',
            context: { type: 'Task', id: 4, label: 'Fix the invoice run', url: null },
        }),
        runningTimer({ id: 2, description: 'Call with the client' }),
        runningTimer({ id: 3 }),
    ]);
    renderTimePage();

    expect(
        await screen.findByRole('button', { name: 'Stop timer: Fix the invoice run' }),
    ).toBeVisible();
    expect(screen.getByRole('button', { name: 'Stop timer: Call with the client' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Stop timer: Unassigned timer' })).toBeVisible();
    expect(
        screen.getAllByRole('listitem').filter((item) => item.querySelector('button')),
    ).toHaveLength(3);
});

it('stops the timer from the page, removes its row, and reloads only the entries', async () => {
    const user = userEvent.setup();
    const calls = stubTimerApi([runningTimer({ id: 7, description: 'Weekly sync' })]);
    renderTimePage();

    // The first confirmed read is not a disappearance, so it reloads nothing.
    await user.click(await screen.findByRole('button', { name: 'Stop timer: Weekly sync' }));

    await waitFor(() => expect(screen.queryByText('Running timers')).not.toBeInTheDocument());
    expect(calls).toContain('POST /time/timer/7/stop');
    expect(inertia.reload).toHaveBeenCalledTimes(1);
    expect(inertia.reload).toHaveBeenCalledWith({ only: ['entries', 'totalMinutes'] });
});

it('does not reload merely because a timer is present or appears', async () => {
    const user = userEvent.setup();
    stubTimerApi([runningTimer({ id: 7 })]);
    renderTimePage();

    await screen.findByText('Running timers');

    expect(inertia.reload).not.toHaveBeenCalled();

    // Starting another timer from this page adds a row and still reloads nothing.
    await user.type(
        screen.getByLabelText('Description', { selector: '#timer-description' }),
        'Second one',
    );
    await user.click(screen.getByRole('button', { name: 'Start timer' }));
    expect(await screen.findByRole('button', { name: 'Stop timer: Second one' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Stop timer: Unassigned timer' })).toBeVisible();

    expect(inertia.reload).not.toHaveBeenCalled();
});

it('keeps the row, shows the server message and reloads nothing when a stop fails', async () => {
    const user = userEvent.setup();
    stubTimerApi([runningTimer({ id: 7, description: 'Weekly sync' })], () =>
        json({ message: 'This timer cannot be stopped.' }, 422),
    );
    renderTimePage();

    await user.click(await screen.findByRole('button', { name: 'Stop timer: Weekly sync' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('This timer cannot be stopped.');
    expect(screen.getByRole('button', { name: 'Stop timer: Weekly sync' })).toBeVisible();
    expect(inertia.reload).not.toHaveBeenCalled();
});

it('shows the same Stop whatever shell hosts the page: the page reads no presentation', async () => {
    stubTimerApi([runningTimer({ id: 7, description: 'Weekly sync' })]);
    renderTimePage();

    expect(await screen.findByRole('button', { name: 'Stop timer: Weekly sync' })).toBeVisible();
    expect(document.querySelector('[data-shell-timer]')).toBeNull();
});
