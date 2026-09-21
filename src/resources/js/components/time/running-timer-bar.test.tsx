import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

import { RunningTimerBar } from '@/components/time/running-timer-bar';
import { TimerProvider, useTimers } from '@/components/time/timer-provider';

function jsonResponse(body: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: vi.fn().mockResolvedValue(body),
    } as unknown as Response;
}

const timer = (description: string | null) => ({
    id: 4,
    started_at: '2026-09-20T12:00:00.000Z',
    server_now: '2026-09-20T12:01:00.000Z',
    description,
    context: null,
});

function RefreshButton() {
    const { refreshTimers } = useTimers();

    return (
        <button type="button" onClick={() => void refreshTimers()}>
            Refresh timers
        </button>
    );
}

function renderBar() {
    return render(
        <TimerProvider enabled>
            <RunningTimerBar />
            <RefreshButton />
        </TimerProvider>,
    );
}

afterEach(() => vi.unstubAllGlobals());

it('seeds the description draft from the current server value when editing starts', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer('Original')]))
            // Another tab renamed the timer; the component instance (keyed by id) survives.
            .mockResolvedValueOnce(jsonResponse([timer('Renamed elsewhere')])),
    );
    const user = userEvent.setup();
    renderBar();

    await screen.findByRole('button', { name: 'Edit timer description: Original' });
    await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
    await user.click(
        await screen.findByRole('button', { name: 'Edit timer description: Renamed elsewhere' }),
    );

    expect(screen.getByLabelText('Timer description')).toHaveValue('Renamed elsewhere');
});

it('does not overwrite an in-progress draft when the authoritative value changes while editing', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer('Original')]))
            .mockResolvedValueOnce(jsonResponse([timer('Renamed elsewhere')])),
    );
    const user = userEvent.setup();
    renderBar();

    await user.click(await screen.findByRole('button', { name: /Edit timer description/ }));
    const input = screen.getByLabelText('Timer description');
    await user.clear(input);
    await user.type(input, 'My draft');

    await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    await act(async () => Promise.resolve());

    expect(screen.getByLabelText('Timer description')).toHaveValue('My draft');
});

it('re-seeds from the authoritative value after cancelling an edit', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer('Original')]))
            .mockResolvedValueOnce(jsonResponse([timer('Renamed elsewhere')])),
    );
    const user = userEvent.setup();
    renderBar();

    await user.click(await screen.findByRole('button', { name: /Edit timer description/ }));
    await user.type(screen.getByLabelText('Timer description'), ' typed then abandoned');
    await user.click(screen.getByRole('button', { name: 'Cancel description editing' }));
    await user.click(screen.getByRole('button', { name: 'Refresh timers' }));
    await user.click(
        await screen.findByRole('button', { name: 'Edit timer description: Renamed elsewhere' }),
    );

    expect(screen.getByLabelText('Timer description')).toHaveValue('Renamed elsewhere');
});

it('retains the draft and surfaces the error after a failed save', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([timer('Original')]))
            .mockResolvedValueOnce(jsonResponse({ message: 'Billing locked.' }, 422))
            // Reconciliation read after the rejected write.
            .mockResolvedValueOnce(jsonResponse([timer('Original')])),
    );
    const user = userEvent.setup();
    renderBar();

    await user.click(await screen.findByRole('button', { name: /Edit timer description/ }));
    const input = screen.getByLabelText('Timer description');
    await user.clear(input);
    await user.type(input, 'Precious draft');
    await user.click(screen.getByRole('button', { name: 'Save description' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Billing locked.');
    expect(screen.getByLabelText('Timer description')).toHaveValue('Precious draft');
});
