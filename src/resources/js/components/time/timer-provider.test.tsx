import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

import { TimerPill } from '@/components/time/timer-pill';
import { TimerProvider, useTimers } from '@/components/time/timer-provider';

function jsonResponse(body: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: vi.fn().mockResolvedValue(body),
    } as unknown as Response;
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const timerA = {
    id: 1,
    started_at: '2026-09-20T11:00:00.000Z',
    server_now: '2026-09-20T12:00:00.000Z',
    description: 'A',
    context: null,
};
const timerB = { ...timerA, id: 2, description: 'B' };

function TimerActions() {
    const { refreshTimers, startTimer, status, error, stopTimer, timers, updateDescription } =
        useTimers();
    const firstTimer = timers[0];

    return (
        <>
            <span data-testid="timer-ids">{timers.map((timer) => timer.id).join(',')}</span>
            <span data-testid="status">{status}</span>
            <span data-testid="error">{error}</span>
            <button type="button" onClick={() => void refreshTimers()}>
                Refresh timers
            </button>
            <span data-testid="timer-count">{timers.length}</span>
            <span data-testid="first-description">{firstTimer?.description}</span>
            <button
                type="button"
                onClick={() => void startTimer({ description: 'Started from React' })}
            >
                Start test timer
            </button>
            <button
                type="button"
                onClick={() => {
                    if (firstTimer) void updateDescription(firstTimer.id, 'Canonical description');
                }}
                disabled={!firstTimer}
            >
                Update test timer
            </button>
            <button
                type="button"
                onClick={() => {
                    if (firstTimer) void stopTimer(firstTimer.id);
                }}
                disabled={!firstTimer}
            >
                Stop test timer
            </button>
        </>
    );
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('hydrates and renders multiple timers using one derived ticking clock', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-20T12:00:00.000Z'));
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            jsonResponse([
                {
                    id: 1,
                    started_at: '2026-09-20T11:59:50.000Z',
                    server_now: '2026-09-20T12:00:00.000Z',
                    description: 'First timer',
                    context: null,
                },
                {
                    id: 2,
                    started_at: '2026-09-20T11:59:55.000Z',
                    server_now: '2026-09-20T12:00:00.000Z',
                    description: 'Second timer',
                    context: null,
                },
            ]),
        ),
    );

    render(
        <TimerProvider enabled>
            <TimerPill />
        </TimerProvider>,
    );

    await act(async () => Promise.resolve());

    // WP6: the pill shows the most recently started timer (id 2, 5s old) and counts the other,
    // rather than the retired strip's row-per-timer. One derived clock still drives it.
    expect(screen.getByText('0:00:05')).toBeInTheDocument();
    expect(screen.getByText('+1')).toBeInTheDocument();
    expect(screen.queryByText('0:00:10')).not.toBeInTheDocument();

    act(() => vi.advanceTimersByTime(2000));
    expect(screen.getByText('0:00:07')).toBeInTheDocument();
});

it('reconciles a started timer from the canonical server response', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([]))
        .mockResolvedValueOnce(
            jsonResponse({
                id: 9,
                started_at: '2026-09-20T12:00:00.000Z',
                server_now: '2026-09-20T12:00:00.000Z',
                description: 'Started from React',
                context: null,
            }),
        );
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(
        <TimerProvider enabled>
            <TimerActions />
        </TimerProvider>,
    );

    await waitFor(() => expect(screen.getByTestId('timer-count')).toHaveTextContent('0'));
    await user.click(screen.getByRole('button', { name: 'Start test timer' }));
    await waitFor(() => expect(screen.getByTestId('timer-count')).toHaveTextContent('1'));

    expect(fetchMock).toHaveBeenLastCalledWith(
        '/time/timer/start',
        expect.objectContaining({ method: 'POST' }),
    );
});

it('keeps the application usable and offers retry after hydration failure', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(jsonResponse({ message: 'Unavailable' }, 503)),
    );

    render(
        <TimerProvider enabled>
            <TimerPill />
            <p>Application content</p>
        </TimerProvider>,
    );

    // The page stays usable and the pill stays mounted; the failure and its retry are in the tray.
    expect(screen.getByText('Application content')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Start timer/ }));
    expect(await screen.findByText('Unavailable')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
});

it('reconciles description and stop mutations from canonical responses', async () => {
    const timer = {
        id: 4,
        started_at: '2026-09-20T12:00:00.000Z',
        server_now: '2026-09-20T12:01:00.000Z',
        description: 'Original',
        context: null,
    };
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([timer]))
        .mockResolvedValueOnce(jsonResponse({ id: 4, description: 'Canonical description' }))
        .mockResolvedValueOnce(jsonResponse({ id: 4, duration_minutes: 1, duration_human: '1m' }));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(
        <TimerProvider enabled>
            <TimerActions />
        </TimerProvider>,
    );

    expect(await screen.findByTestId('first-description')).toHaveTextContent('Original');
    await user.click(screen.getByRole('button', { name: 'Update test timer' }));
    await waitFor(() =>
        expect(screen.getByTestId('first-description')).toHaveTextContent('Canonical description'),
    );
    await user.click(screen.getByRole('button', { name: 'Stop test timer' }));
    await waitFor(() => expect(screen.getByTestId('timer-count')).toHaveTextContent('0'));

    expect(fetchMock).toHaveBeenNthCalledWith(
        2,
        '/time/timer/4/description',
        expect.objectContaining({ method: 'PATCH' }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
        3,
        '/time/timer/4/stop',
        expect.objectContaining({ method: 'POST' }),
    );
});

describe('refresh sequencing', () => {
    it('ignores a stale refresh that resolves after a newer one', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        const fetchMock = vi
            .fn()
            .mockReturnValueOnce(first.promise)
            .mockReturnValueOnce(second.promise);
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
        expect(fetchMock).toHaveBeenCalledTimes(2);

        await act(async () => second.resolve(jsonResponse([timerB])));
        expect(screen.getByTestId('timer-ids')).toHaveTextContent('2');
        expect(screen.getByTestId('status')).toHaveTextContent('ready');

        await act(async () => first.resolve(jsonResponse([timerA])));
        expect(screen.getByTestId('timer-ids')).toHaveTextContent('2');
        expect(screen.getByTestId('status')).toHaveTextContent('ready');
    });

    it('applies the latest refresh even when an older one is still pending', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise),
        );
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Refresh timers' }));

        await act(async () => second.resolve(jsonResponse([timerB])));
        await act(async () => first.resolve(jsonResponse([timerA, timerB])));

        expect(screen.getByTestId('timer-ids')).toHaveTextContent(/^2$/);
    });

    it('does not let hydration that began before a successful start remove the new timer', async () => {
        const hydration = deferred<Response>();
        const fetchMock = vi
            .fn()
            .mockReturnValueOnce(hydration.promise)
            .mockResolvedValueOnce(jsonResponse(timerB))
            // The follow-up authoritative read taken after the start committed.
            .mockResolvedValueOnce(jsonResponse([timerB]));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Start test timer' }));
        await waitFor(() => expect(screen.getByTestId('timer-ids')).toHaveTextContent('2'));

        // The older read predates the start and does not contain the running timer.
        await act(async () => hydration.resolve(jsonResponse([])));

        expect(screen.getByTestId('timer-ids')).toHaveTextContent('2');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock).toHaveBeenLastCalledWith('/time/timers/active', expect.anything());
        await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('ready'));
        expect(screen.getByTestId('timer-ids')).toHaveTextContent('2');
    });

    it('keeps timers that only the stale read knew about by re-reading after a mutation', async () => {
        const hydration = deferred<Response>();
        const fetchMock = vi
            .fn()
            .mockReturnValueOnce(hydration.promise)
            .mockResolvedValueOnce(jsonResponse(timerB))
            .mockResolvedValueOnce(jsonResponse([timerA, timerB]));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Start test timer' }));
        await act(async () => hydration.resolve(jsonResponse([timerA])));

        await waitFor(() => expect(screen.getByTestId('timer-ids')).toHaveTextContent('1,2'));
    });

    it('does not let a refresh that began before a successful stop resurrect the timer', async () => {
        const staleRead = deferred<Response>();
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timerA]))
            .mockReturnValueOnce(staleRead.promise)
            .mockResolvedValueOnce(
                jsonResponse({ id: 1, duration_minutes: 60, duration_human: '1h' }),
            )
            .mockResolvedValueOnce(jsonResponse([]));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await waitFor(() => expect(screen.getByTestId('timer-ids')).toHaveTextContent('1'));
        await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
        await user.click(screen.getByRole('button', { name: 'Stop test timer' }));
        await waitFor(() => expect(screen.getByTestId('timer-ids')).toBeEmptyDOMElement());

        await act(async () => staleRead.resolve(jsonResponse([timerA])));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
        await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('ready'));
        expect(screen.getByTestId('timer-ids')).toBeEmptyDOMElement();
    });

    it('hydrates normally with a single request and leaves the ready state', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([timerA, timerB]));
        vi.stubGlobal('fetch', fetchMock);

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );

        await waitFor(() => expect(screen.getByTestId('timer-ids')).toHaveTextContent('1,2'));
        expect(screen.getByTestId('status')).toHaveTextContent('ready');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not report an error from a stale failed refresh', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise),
        );
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
        await act(async () => second.resolve(jsonResponse([timerB])));
        await act(async () => first.reject(new Error('Network down')));

        expect(screen.getByTestId('status')).toHaveTextContent('ready');
        expect(screen.getByTestId('error')).toBeEmptyDOMElement();
        expect(screen.getByTestId('timer-ids')).toHaveTextContent('2');
    });

    it('reports the latest failure and recovers on the next successful refresh', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(jsonResponse({ message: 'Unavailable' }, 503))
                .mockResolvedValueOnce(jsonResponse([timerA])),
        );
        const user = userEvent.setup();

        render(
            <TimerProvider enabled>
                <TimerActions />
            </TimerProvider>,
        );
        await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('error'));
        expect(screen.getByTestId('error')).toHaveTextContent('Unavailable');

        await user.click(screen.getByRole('button', { name: 'Refresh timers' }));

        await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('ready'));
        expect(screen.getByTestId('error')).toBeEmptyDOMElement();
        expect(screen.getByTestId('timer-ids')).toHaveTextContent('1');
    });
});

describe('server clock offset', () => {
    // Browser and server disagree by `skewMs`; the request takes 2s, so the server
    // stamped server_now at the request midpoint (browser start + 1s).
    it.each([
        ['browser clock is behind the server', 5 * 60_000],
        ['browser clock is ahead of the server', -5 * 60_000],
    ])('derives elapsed time from the corrected server clock when the %s', async (_name, skew) => {
        vi.useFakeTimers();
        const browserStart = Date.parse('2026-09-20T12:00:00.000Z');
        vi.setSystemTime(browserStart);
        const serverNow = new Date(browserStart + 1000 + skew).toISOString();
        const startedAt = new Date(browserStart + 1000 + skew - 10_000).toISOString();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(async () => {
                vi.advanceTimersByTime(2000);

                return jsonResponse([
                    {
                        id: 1,
                        started_at: startedAt,
                        server_now: serverNow,
                        description: 'Skewed',
                        context: null,
                    },
                ]);
            }),
        );

        render(
            <TimerProvider enabled>
                <TimerPill />
            </TimerProvider>,
        );
        await act(async () => Promise.resolve());

        // Browser time is 12:00:02 at receipt; the corrected server clock is server_now + 1s
        // of half-latency, i.e. 10s + 1s after the timer started. One tick later it is 12s.
        act(() => vi.advanceTimersByTime(1000));
        expect(screen.getByText('0:00:12')).toBeInTheDocument();
    });

    it('clamps a timer that starts after the corrected server clock to zero', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-20T12:00:00.000Z'));
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse([
                    {
                        id: 1,
                        started_at: '2026-09-20T12:00:30.000Z',
                        server_now: '2026-09-20T12:00:00.000Z',
                        description: 'Ahead of clock',
                        context: null,
                    },
                ]),
            ),
        );

        render(
            <TimerProvider enabled>
                <TimerPill />
            </TimerProvider>,
        );
        await act(async () => Promise.resolve());

        expect(screen.getByText('0:00:00')).toBeInTheDocument();
        expect(screen.queryByText(/^-/)).not.toBeInTheDocument();
    });
});
