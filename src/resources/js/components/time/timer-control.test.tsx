import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TimerControl } from '@/components/time/timer-control';
import { TimerProvider } from '@/components/time/timer-provider';
import type { ActiveTimer } from '@/types/time';

afterEach(() => vi.unstubAllGlobals());

function timer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 1,
        started_at: '2026-09-28T11:59:50.000Z',
        server_now: '2026-09-28T12:00:00.000Z',
        description: null,
        context: { type: 'Task', id: 5, label: 'Ship it', url: null },
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

async function mount(running: ActiveTimer | null, fetchMock: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', fetchMock);

    render(
        <TimerProvider enabled>
            <TimerControl label="Ship it" payload={{ task_id: 5 }} running={running} />
        </TimerProvider>,
    );

    await act(async () => Promise.resolve());
}

it('offers a context-named start control when idle', async () => {
    await mount(null, vi.fn().mockResolvedValue(jsonResponse([])));

    expect(screen.getByRole('button', { name: 'Start timer: Ship it' })).toBeInTheDocument();
    expect(screen.queryByText('Running')).not.toBeInTheDocument();
});

it('starts against the caller-supplied context through the canonical endpoint', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([]))
        .mockResolvedValueOnce(jsonResponse(timer()));

    await mount(null, fetchMock);
    await userEvent.click(screen.getByRole('button', { name: 'Start timer: Ship it' }));

    await waitFor(() =>
        expect(fetchMock).toHaveBeenCalledWith(
            '/time/timer/start',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ task_id: 5 }),
            }),
        ),
    );
});

it('shows the running state and a stop control when a timer runs here', async () => {
    await mount(timer(), vi.fn().mockResolvedValue(jsonResponse([timer()])));

    expect(screen.getByRole('button', { name: 'Stop timer: Ship it' })).toBeInTheDocument();
    expect(screen.getByText('Running')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Start timer/ })).not.toBeInTheDocument();
});

it('stops only its own timer, never any other', async () => {
    const other = timer({ id: 99 });
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([timer(), other]))
        .mockResolvedValueOnce(jsonResponse({ id: 1 }))
        .mockResolvedValue(jsonResponse([other]));

    await mount(timer(), fetchMock);
    await userEvent.click(screen.getByRole('button', { name: 'Stop timer: Ship it' }));

    await waitFor(() =>
        expect(fetchMock).toHaveBeenCalledWith(
            '/time/timer/1/stop',
            expect.objectContaining({ method: 'POST' }),
        ),
    );
    // Starting or stopping from a context never touches another timer (Direction D §12.3).
    expect(fetchMock).not.toHaveBeenCalledWith(
        '/time/timer/99/stop',
        expect.objectContaining({ method: 'POST' }),
    );
});

it('reports a failed start without retrying it', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([]))
        .mockResolvedValueOnce(jsonResponse({ message: 'Project is archived.' }, 422))
        .mockResolvedValue(jsonResponse([]));

    await mount(null, fetchMock);
    await userEvent.click(screen.getByRole('button', { name: 'Start timer: Ship it' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Project is archived.');

    // Exactly one start attempt: a start is never retried automatically (Direction D §12.1).
    const starts = fetchMock.mock.calls.filter(([url]) => url === '/time/timer/start');
    expect(starts).toHaveLength(1);
});

it('disables the control and says so while a mutation is in flight', async () => {
    let release!: () => void;
    const blocked = new Promise<void>((resolve) => {
        release = resolve;
    });

    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([]))
        .mockImplementationOnce(async () => {
            await blocked;

            return jsonResponse(timer());
        });

    await mount(null, fetchMock);
    await userEvent.click(screen.getByRole('button', { name: 'Start timer: Ship it' }));

    const control = await screen.findByRole('button', { name: 'Start timer: Ship it' });
    expect(control).toBeDisabled();
    expect(control).toHaveTextContent('Starting…');

    release();
});
