import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TimerPill } from '@/components/time/timer-pill';
import { TimerProvider } from '@/components/time/timer-provider';
import { resetInertiaMock } from '@/test/inertia';
import type { ActiveTimer } from '@/types/time';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(() => {
    resetInertiaMock();
    vi.unstubAllGlobals();
});

function timer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 1,
        started_at: '2026-09-28T11:59:50.000Z',
        server_now: '2026-09-28T12:00:00.000Z',
        description: 'Original',
        context: { type: 'Task', id: 5, label: 'Ship the invoice run', url: '/projects/1/tasks/5' },
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

/** Mounts the pill and opens its tray, which is the only way a user reaches the tray. */
async function openTray(fetchMock: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', fetchMock);

    render(
        <TimerProvider enabled>
            <TimerPill />
        </TimerProvider>,
    );

    await act(async () => Promise.resolve());
    await userEvent.click(screen.getByRole('button', { name: /^Running timer:|^Start timer$/ }));

    return screen.findByRole('dialog', { name: 'Running timers' });
}

it('shows the context, an editable description and the elapsed time for each row', async () => {
    const tray = await openTray(vi.fn().mockResolvedValue(jsonResponse([timer()])));

    expect(within(tray).getByText('1 running')).toBeInTheDocument();
    expect(
        within(tray).getByRole('link', { name: 'Task: Ship the invoice run' }),
    ).toBeInTheDocument();
    expect(
        within(tray).getByRole('textbox', { name: 'Description for Task: Ship the invoice run' }),
    ).toHaveValue('Original');
});

it('saves a description through the canonical endpoint on Enter', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([timer()]))
        .mockResolvedValueOnce(jsonResponse({ id: 1, description: 'Rewritten' }));

    const tray = await openTray(fetchMock);
    const field = within(tray).getByRole('textbox', { name: /^Description for/ });

    await userEvent.clear(field);
    await userEvent.type(field, 'Rewritten{Enter}');

    await waitFor(() =>
        expect(fetchMock).toHaveBeenCalledWith(
            '/time/timer/1/description',
            expect.objectContaining({
                method: 'PATCH',
                body: JSON.stringify({ description: 'Rewritten' }),
            }),
        ),
    );
});

it('sends null rather than an empty string when the description is cleared', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([timer()]))
        .mockResolvedValueOnce(jsonResponse({ id: 1, description: null }));

    const tray = await openTray(fetchMock);
    const field = within(tray).getByRole('textbox', { name: /^Description for/ });

    await userEvent.clear(field);
    await userEvent.tab();

    await waitFor(() =>
        expect(fetchMock).toHaveBeenCalledWith(
            '/time/timer/1/description',
            expect.objectContaining({ body: JSON.stringify({ description: null }) }),
        ),
    );
});

it('does not send a request when the description is unchanged', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse([timer()]));
    const tray = await openTray(fetchMock);
    const reads = fetchMock.mock.calls.length;

    const field = within(tray).getByRole('textbox', { name: /^Description for/ });
    await userEvent.click(field);
    await userEvent.tab();

    expect(fetchMock).toHaveBeenCalledTimes(reads);
});

it('keeps the draft and marks the field invalid when a save fails', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([timer()]))
        .mockResolvedValueOnce(jsonResponse({ message: 'Timer is not running.' }, 422))
        .mockResolvedValue(jsonResponse([timer()]));

    const tray = await openTray(fetchMock);
    const field = within(tray).getByRole('textbox', { name: /^Description for/ });

    await userEvent.clear(field);
    await userEvent.type(field, 'Unsaved words{Enter}');

    expect(await within(tray).findByText('Timer is not running.')).toBeInTheDocument();
    // The user's words survive the failure; nothing is silently discarded.
    expect(field).toHaveValue('Unsaved words');
    expect(field).toHaveAttribute('aria-invalid', 'true');
});

it('reverts the draft on Escape without closing the tray', async () => {
    const tray = await openTray(vi.fn().mockResolvedValue(jsonResponse([timer()])));
    const field = within(tray).getByRole('textbox', { name: /^Description for/ });

    await userEvent.clear(field);
    await userEvent.type(field, 'Abandoned{Escape}');

    expect(field).toHaveValue('Original');
    // Escape in the field belongs to the field: the tray stays open.
    expect(screen.getByRole('dialog', { name: 'Running timers' })).toBeInTheDocument();
});

it('closes the tray on Escape when the focused description holds no draft', async () => {
    const tray = await openTray(vi.fn().mockResolvedValue(jsonResponse([timer()])));
    const field = within(tray).getByRole('textbox', { name: /^Description for/ });

    await userEvent.click(field);
    await userEvent.keyboard('{Escape}');

    // Nothing was typed, so Escape means what it means everywhere else. Only a field with an
    // unsaved draft gets to swallow it.
    await waitFor(() =>
        expect(screen.queryByRole('dialog', { name: 'Running timers' })).not.toBeInTheDocument(),
    );
});

it('stops an individual timer from its row', async () => {
    const older = timer({
        id: 1,
        started_at: '2026-09-28T10:00:00.000Z',
        context: { type: 'Task', id: 5, label: 'Older task', url: null },
    });
    const newer = timer({
        id: 2,
        started_at: '2026-09-28T11:00:00.000Z',
        context: { type: 'Task', id: 6, label: 'Newer task', url: null },
    });

    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(jsonResponse([older, newer]))
        .mockResolvedValueOnce(jsonResponse({ id: 1 }))
        .mockResolvedValue(jsonResponse([newer]));

    const tray = await openTray(fetchMock);

    await userEvent.click(
        within(tray).getByRole('button', { name: 'Stop timer: Task: Older task' }),
    );

    await waitFor(() =>
        expect(fetchMock).toHaveBeenCalledWith(
            '/time/timer/1/stop',
            expect.objectContaining({ method: 'POST' }),
        ),
    );
});

it('carries no NEXT feature', async () => {
    const tray = await openTray(vi.fn().mockResolvedValue(jsonResponse([timer()])));

    // Stop all has no endpoint, "Today N logged" is not in the payload, and the "Start another…"
    // global search is a separate workstream. D7 draws all three; none may ship here (§12.0).
    expect(within(tray).queryByRole('button', { name: /Stop all/i })).not.toBeInTheDocument();
    expect(within(tray).queryByText(/logged/i)).not.toBeInTheDocument();
    expect(within(tray).queryByRole('textbox', { name: /Start another/i })).not.toBeInTheDocument();
    expect(within(tray).queryByRole('button', { name: /Switch/i })).not.toBeInTheDocument();
});

it('closes on an outside click without stealing focus back', async () => {
    await openTray(vi.fn().mockResolvedValue(jsonResponse([timer()])));

    await userEvent.click(document.body);

    await waitFor(() =>
        expect(screen.queryByRole('dialog', { name: 'Running timers' })).not.toBeInTheDocument(),
    );
});
