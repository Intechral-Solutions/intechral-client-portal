import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskTimePanel } from '@/components/projects/task-time-panel';
import { TimerProvider } from '@/components/time/timer-provider';
import { inertiaSpies, resetInertiaMock } from '@/test/inertia';
import type { TaskTimeSummary } from '@/types/time';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

function jsonResponse(body: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: vi.fn().mockResolvedValue(body),
    } as unknown as Response;
}

const runningForTask = (taskId: number) => ({
    id: 9,
    started_at: '2026-09-20T12:00:00.000Z',
    server_now: '2026-09-20T12:01:00.000Z',
    description: null,
    context: { type: 'Task' as const, id: taskId, label: 'Ship it', url: '/x' },
});

afterEach(() => {
    vi.unstubAllGlobals();
    resetInertiaMock();
});

function renderPanel(props: Partial<React.ComponentProps<typeof TaskTimePanel>> = {}) {
    return render(
        <TimerProvider enabled>
            <TaskTimePanel taskId={1} canLog timeSummary={null} {...props} />
        </TimerProvider>,
    );
}

it('shows a Start timer form when logTime is allowed and nothing is running for this task', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
    renderPanel();

    await screen.findByRole('button', { name: 'Start timer' });
});

it('shows the running indicator and a Stop control when a timer runs for this task', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([runningForTask(1)])));
    renderPanel();

    // WP6: the shared `TimerControl` draws this state, so the Stop action names its context rather
    // than repeating it as prose beside the button.
    expect(
        await screen.findByRole('button', { name: 'Stop timer: this task' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Running')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();
});

it('does not treat a timer running for a different task as running here', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([runningForTask(2)])));
    renderPanel({ taskId: 1 });

    await screen.findByRole('button', { name: 'Start timer' });
    expect(screen.queryByRole('button', { name: /^Stop timer/ })).not.toBeInTheDocument();
});

it('shows no controls without logTime, only the read-only summary', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
    const summary: TaskTimeSummary = { scope: 'all', totalMinutes: 90, entries: [] };
    renderPanel({ canLog: false, timeSummary: summary });

    await waitFor(() => expect(screen.getByText('All time logged')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();
});

it('renders an own-scope entry without a user name', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
    const own: TaskTimeSummary = {
        scope: 'own',
        totalMinutes: 90,
        entries: [{ id: 1, date: '2026-06-01', durationMinutes: 90 }],
    };
    renderPanel({ timeSummary: own });

    await screen.findByText('Your time logged');
    expect(screen.getAllByText('1h 30m')).toHaveLength(2); // total and the one entry
    expect(screen.queryByText(/·/)).not.toBeInTheDocument();
});

it('renders an all-scope entry with its user name', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
    const all: TaskTimeSummary = {
        scope: 'all',
        totalMinutes: 45,
        entries: [{ id: 2, date: '2026-06-01', durationMinutes: 45, userName: 'Zed Otherperson' }],
    };
    renderPanel({ timeSummary: all });

    await screen.findByText('All time logged');
    expect(screen.getByText(/Zed Otherperson/)).toBeInTheDocument();
});

it('shows nothing when the viewer has neither logTime nor a summary', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
    renderPanel({ canLog: false, timeSummary: null });

    expect(await screen.findByText('No time tracked.')).toBeInTheDocument();
});

it('starts a timer scoped to this task through the persistent provider', async () => {
    const user = userEvent.setup();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([]))
        .mockResolvedValueOnce(jsonResponse(runningForTask(1)));
    vi.stubGlobal('fetch', fetchMock);
    renderPanel();

    await user.type(await screen.findByLabelText('Timer description'), 'Reviewing PR');
    await user.click(screen.getByRole('button', { name: 'Start timer' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    const [, init] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(JSON.parse(init.body as string)).toMatchObject({
        task_id: 1,
        description: 'Reviewing PR',
    });
});

it('reloads only timeSummary when Stop removes the running timer for this task', async () => {
    const user = userEvent.setup();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([runningForTask(1)]))
        .mockResolvedValueOnce(jsonResponse({ id: 9 }));
    vi.stubGlobal('fetch', fetchMock);
    renderPanel();

    await user.click(await screen.findByRole('button', { name: /Stop timer/ }));

    await waitFor(() =>
        expect(inertiaSpies.router.reload).toHaveBeenCalledWith({ only: ['timeSummary'] }),
    );
});
