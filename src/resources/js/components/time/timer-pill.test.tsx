import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useRef } from 'react';

import { TimerPill } from '@/components/time/timer-pill';
import { TimerProvider, useTimers } from '@/components/time/timer-provider';
import { resetInertiaMock } from '@/test/inertia';
import type { ActiveTimer } from '@/types/time';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(() => {
    resetInertiaMock();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const NOW = '2026-09-28T12:00:00.000Z';

function timer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 1,
        started_at: '2026-09-28T11:59:50.000Z',
        server_now: NOW,
        description: null,
        context: null,
        ...overrides,
    };
}

function jsonResponse(body: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: vi.fn().mockResolvedValue(body),
    } as unknown as Response;
}

/** Hydrates the provider from one canonical read, the way a real page load does. */
function mount(timers: ActiveTimer[], fetchImpl?: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', fetchImpl ?? vi.fn().mockResolvedValue(jsonResponse(timers)));

    return render(
        <TimerProvider enabled>
            <TimerPill />
        </TimerProvider>,
    );
}

async function settle() {
    await act(async () => Promise.resolve());
}

describe('zero state', () => {
    it('offers a ghost Start timer affordance and opens the tray to the empty state', async () => {
        mount([]);
        await settle();

        const trigger = screen.getByRole('button', { name: 'Start timer' });
        expect(trigger).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Stop timer/ })).not.toBeInTheDocument();

        await userEvent.click(trigger);

        expect(await screen.findByText('No timers running.')).toBeInTheDocument();
        // The zero state links to the existing start flow; it never embeds a second one (§12.0).
        expect(screen.getByRole('link', { name: 'Open Time →' })).toHaveAttribute('href', '/time');
    });
});

describe('one running timer', () => {
    it('shows a live dot, the mono elapsed time, the context label and a context-named Stop', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));

        mount([timer({ context: { type: 'Ticket', id: 7, label: 'TKT-42', url: '/tickets/7' } })]);
        await settle();

        expect(screen.getByText('0:00:10')).toBeInTheDocument();
        expect(screen.getByText('Ticket: TKT-42')).toBeInTheDocument();
        // The Stop action names what it will stop, so it is unambiguous out of context.
        expect(
            screen.getByRole('button', { name: 'Stop timer: Ticket: TKT-42' }),
        ).toBeInTheDocument();
        // One timer needs no disclosure chevron and no count badge.
        expect(screen.queryByText(/^\+\d/)).not.toBeInTheDocument();
    });

    it('advances the elapsed time once a second without a server request', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));

        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([timer()]));
        mount([], fetchMock);
        await settle();

        expect(screen.getByText('0:00:10')).toBeInTheDocument();
        const readsAfterHydration = fetchMock.mock.calls.length;

        act(() => vi.advanceTimersByTime(3000));

        expect(screen.getByText('0:00:13')).toBeInTheDocument();
        // Rendering a clock must never poll: elapsed time is derived from the start instant.
        expect(fetchMock).toHaveBeenCalledTimes(readsAfterHydration);
    });

    it('crosses the minute and hour boundaries correctly', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));

        mount([timer({ started_at: '2026-09-28T11:00:00.000Z' })]);
        await settle();

        expect(screen.getByText('1:00:00')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(60_000));
        expect(screen.getByText('1:01:00')).toBeInTheDocument();
    });
});

describe('several running timers', () => {
    const older = timer({ id: 1, started_at: '2026-09-28T11:00:00.000Z', description: 'Older' });
    const newer = timer({ id: 2, started_at: '2026-09-28T11:59:00.000Z', description: 'Newer' });

    it('shows the most recently started timer and counts the rest', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));

        mount([older, newer]);
        await settle();

        expect(screen.getByText('0:01:00')).toBeInTheDocument();
        expect(screen.getByText('Newer')).toBeInTheDocument();
        expect(screen.getByText('+1')).toBeInTheDocument();
        // Never more than one timer inline (Direction D §12.1).
        expect(screen.queryByText('Older')).not.toBeInTheDocument();
        expect(screen.queryByText('1:00:00')).not.toBeInTheDocument();
    });

    it('stops only the timer it shows', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([older, newer]))
            .mockResolvedValueOnce(jsonResponse({ id: 2 }));

        mount([], fetchMock);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: 'Stop timer: Newer' }));

        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/time/timer/2/stop',
                expect.objectContaining({ method: 'POST' }),
            ),
        );
        // The older timer is untouched: there is no stop-all and no fan-out (§12.0).
        expect(fetchMock).not.toHaveBeenCalledWith(
            '/time/timer/1/stop',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('lists every running timer newest-first in the tray', async () => {
        mount([older, newer]);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: /^Running timer:/ }));

        const tray = await screen.findByRole('dialog', { name: 'Running timers' });
        expect(within(tray).getByText('2 running')).toBeInTheDocument();

        const rows = within(tray).getAllByRole('listitem');
        expect(rows).toHaveLength(2);
        expect(rows[0]).toHaveTextContent('Newer');
        expect(rows[1]).toHaveTextContent('Older');
    });
});

describe('first paint: the last confirmed state, or nothing (Direction D §15.1)', () => {
    /** A `fetch` whose responses the test releases one at a time, in call order. */
    function controlledFetch() {
        const pending: ((response: Response) => void)[] = [];
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => pending.push(resolve)));

        return {
            fetchMock,
            async respond(body: unknown, status = 200) {
                await waitFor(() => expect(pending.length).toBeGreaterThan(0));
                await act(async () => pending.shift()!(jsonResponse(body, status)));
            },
        };
    }

    function Reload() {
        const { refreshTimers } = useTimers();

        return (
            <button type="button" onClick={() => void refreshTimers()}>
                Reload timers
            </button>
        );
    }

    function mountControlled() {
        const controlled = controlledFetch();
        vi.stubGlobal('fetch', controlled.fetchMock);
        const view = render(
            <TimerProvider enabled>
                <TimerPill />
                <Reload />
            </TimerProvider>,
        );

        return { ...controlled, pill: () => view.container.querySelector('[data-shell-timer]') };
    }

    it('renders no timer control at all until the first read resolves', async () => {
        const { pill } = mountControlled();
        await settle();

        // Not a ghost, not a skeleton: nothing is known yet, so nothing is claimed.
        expect(pill()).toBeNull();
        expect(screen.queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();
    });

    it('shows the idle affordance once an empty set is confirmed', async () => {
        const { pill, respond } = mountControlled();

        await respond([]);

        expect(pill()).toHaveAttribute('data-timer-running', 'false');
        expect(screen.getByRole('button', { name: 'Start timer' })).toBeInTheDocument();
    });

    it('goes straight from nothing to the running pill, never through Start timer', async () => {
        const { pill, respond } = mountControlled();
        await settle();
        expect(screen.queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();

        await respond([timer({ description: 'Long running work' })]);

        expect(pill()).toHaveAttribute('data-timer-running', 'true');
        expect(
            screen.getByRole('button', { name: 'Running timer: Long running work. Show timers' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();
    });

    it('keeps a confirmed running pill while a refresh is in flight, then shows the newer result', async () => {
        const { pill, respond } = mountControlled();
        await respond([timer({ description: 'Long running work' })]);

        await userEvent.click(screen.getByRole('button', { name: 'Reload timers' }));

        // Revalidating is not "unknown": the last confirmed state stays exactly as it was.
        expect(pill()).toHaveAttribute('data-timer-running', 'true');
        expect(
            screen.getByRole('button', { name: 'Running timer: Long running work. Show timers' }),
        ).toBeInTheDocument();

        await respond([]);

        expect(pill()).toHaveAttribute('data-timer-running', 'false');
        expect(screen.getByRole('button', { name: 'Start timer' })).toBeInTheDocument();
    });

    it('keeps a confirmed idle pill while a refresh is in flight, then shows the newer result', async () => {
        const { pill, respond } = mountControlled();
        await respond([]);

        await userEvent.click(screen.getByRole('button', { name: 'Reload timers' }));

        expect(pill()).toHaveAttribute('data-timer-running', 'false');
        expect(screen.getByRole('button', { name: 'Start timer' })).toBeInTheDocument();

        await respond([timer({ description: 'Started elsewhere' })]);

        expect(pill()).toHaveAttribute('data-timer-running', 'true');
        expect(
            screen.getByRole('button', { name: 'Running timer: Started elsewhere. Show timers' }),
        ).toBeInTheDocument();
    });

    it('shows a failed first read as an error, and does not dress a retry up as idle', async () => {
        const { respond } = mountControlled();
        await respond({ message: 'Unavailable' }, 503);

        // A failed read is a result: the pill mounts so the tray can offer Retry (A11.6) …
        const trigger = screen.getByRole('button', { name: 'Start timer' });
        expect(trigger).toHaveClass('border-danger');

        // … and while that retry is in flight nothing has been confirmed, so it stays an error
        // rather than turning into a plain "Start timer" that claims nothing is running.
        await userEvent.click(screen.getByRole('button', { name: 'Reload timers' }));
        expect(screen.getByRole('button', { name: 'Start timer' })).toHaveClass('border-danger');

        await respond([]);
        expect(screen.getByRole('button', { name: 'Start timer' })).not.toHaveClass(
            'border-danger',
        );
    });

    it('does not announce a start when the first confirmed set already has a timer running', async () => {
        const { respond } = mountControlled();

        await respond([timer()]);

        // Hydrating is not starting: the first confirmed count is only the baseline.
        expect(document.querySelector('[data-shell-timer-announce]')).toHaveTextContent('');
    });
});

describe('pending and failed states', () => {
    it('shows Stopping… with no elapsed time and no Stop control while a stop is in flight', async () => {
        let release!: () => void;
        const blocked = new Promise<void>((resolve) => {
            release = resolve;
        });

        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer({ description: 'In flight' })]))
            .mockImplementationOnce(async () => {
                await blocked;

                return jsonResponse({ id: 1 });
            });

        mount([], fetchMock);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: 'Stop timer: In flight' }));

        expect(await screen.findByText('Stopping…')).toBeInTheDocument();
        // No elapsed value is shown until the server confirms (Direction D §12.1).
        expect(screen.queryByText(/^\d+:\d\d:\d\d$/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Stop timer/ })).not.toBeInTheDocument();

        release();
    });

    it('keeps the last confirmed state and offers a retry when a stop fails', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer({ description: 'Stubborn' })]))
            .mockResolvedValueOnce(jsonResponse({ message: 'Nope' }, 500))
            .mockResolvedValue(jsonResponse([timer({ description: 'Stubborn' })]));

        mount([], fetchMock);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: 'Stop timer: Stubborn' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Couldn’t stop');
        // The timer is still running, so its last confirmed state stays on screen.
        expect(screen.getByText('Stubborn')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop timer: Stubborn' })).toHaveTextContent(
            'Retry stop',
        );
    });

    it('never offers a retry for a start: the zero state links to the start flow instead', async () => {
        mount([]);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: 'Start timer' }));
        const tray = await screen.findByRole('dialog', { name: 'Running timers' });

        // A start is never retried automatically (Direction D §12.1).
        expect(within(tray).queryByRole('button', { name: /Retry/ })).not.toBeInTheDocument();
        expect(within(tray).getByRole('link', { name: 'Open Time →' })).toBeInTheDocument();
    });

    it('surfaces a failed hydration in the tray with a retry, keeping the shell usable', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ message: 'Unavailable' }, 503));
        mount([], fetchMock);
        await settle();

        await userEvent.click(screen.getByRole('button', { name: 'Start timer' }));

        expect(await screen.findByText('Unavailable')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    });
});

describe('malformed state', () => {
    it('shows no elapsed value rather than a plausible zero for an unparseable start', async () => {
        mount([timer({ started_at: 'not-a-date', description: 'Broken' })]);
        await settle();

        // The pill still exists and is still stoppable — it just refuses to invent a duration.
        // Both the wide and the narrow form render (CSS shows one), so both say so.
        expect(screen.getAllByText('—')).toHaveLength(2);
        expect(screen.queryByText('0:00:00')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop timer: Broken' })).toBeInTheDocument();
    });
});

describe('accessibility', () => {
    it('names the pill without the elapsed value, so a running clock never announces per second', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));

        mount([timer({ description: 'Quiet' })]);
        await settle();

        const trigger = screen.getByRole('button', { name: /^Running timer:/ });
        expect(trigger).toHaveAccessibleName('Running timer: Quiet. Show timers');

        // The digits are visible text, outside any live region.
        const elapsed = screen.getByText('0:00:10');
        expect(elapsed.closest('[aria-live]')).toBeNull();
        expect(trigger).toHaveAccessibleName(expect.not.stringMatching(/\d:\d\d/));
    });

    it('announces a start and a stop, driven by the count rather than the clock', async () => {
        // A start can originate anywhere (the Time form, a task panel, another tab), so this drives
        // the provider directly rather than pretending the pill starts timers.
        function Reload() {
            const { refreshTimers } = useTimers();

            return (
                <button type="button" onClick={() => void refreshTimers()}>
                    Reload timers
                </button>
            );
        }

        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([]))
            .mockResolvedValueOnce(jsonResponse([timer()]))
            .mockResolvedValue(jsonResponse([]));

        vi.stubGlobal('fetch', fetchMock);
        render(
            <TimerProvider enabled>
                <TimerPill />
                <Reload />
            </TimerProvider>,
        );
        await settle();

        // Hydrating with nothing running announces nothing.
        const region = document.querySelector('[aria-live="polite"]');
        expect(region).toHaveTextContent('');

        await userEvent.click(screen.getByRole('button', { name: 'Reload timers' }));
        await waitFor(() => expect(region).toHaveTextContent('Timer started.'));

        await userEvent.click(screen.getByRole('button', { name: 'Reload timers' }));
        await waitFor(() => expect(region).toHaveTextContent('Timer stopped.'));
    });

    it('closes the tray on Escape and returns focus to the pill', async () => {
        mount([timer({ description: 'Focus me' })]);
        await settle();

        const trigger = screen.getByRole('button', { name: /^Running timer:/ });
        await userEvent.click(trigger);
        expect(await screen.findByRole('dialog', { name: 'Running timers' })).toBeInTheDocument();

        await userEvent.keyboard('{Escape}');

        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Running timers' }),
            ).not.toBeInTheDocument(),
        );
        expect(trigger).toHaveFocus();
    });

    it('opens the tray from the keyboard', async () => {
        mount([]);
        await settle();

        await userEvent.tab();
        expect(screen.getByRole('button', { name: 'Start timer' })).toHaveFocus();

        await userEvent.keyboard('{Enter}');
        expect(await screen.findByRole('dialog', { name: 'Running timers' })).toBeInTheDocument();
    });
});

describe('tick localisation (the EPIC-011D regression guard)', () => {
    it('re-renders the pill on a tick without re-rendering a sibling mounted beside it', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([timer()])));

        const renders = { count: 0 };

        function RailSentinel() {
            const own = useRef(0);
            own.current += 1;
            renders.count = own.current;

            return <span data-testid="rail-sentinel" />;
        }

        render(
            <TimerProvider enabled>
                <RailSentinel />
                <TimerPill />
            </TimerProvider>,
        );
        await settle();

        expect(screen.getByText('0:00:10')).toBeInTheDocument();
        const before = renders.count;

        act(() => vi.advanceTimersByTime(5000));

        // The clock advanced, so the pill definitely re-rendered...
        expect(screen.getByText('0:00:15')).toBeInTheDocument();
        // ...and nothing outside it did. The one-second interval lives inside the pill, so a tick
        // must never re-render the rail, the drawer, the breadcrumb or the page (§18.3, §26).
        expect(renders.count).toBe(before);
    });
});
