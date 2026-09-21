import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

import type { AllocationEntry } from '@/types/time';

type ReloadOptions = { only: string[]; onSuccess?: () => void };

const inertia = vi.hoisted(() => ({
    reload: vi.fn(),
    chart: {
        current: null as null | {
            entries: AllocationEntry[];
            onAdjust: (...args: number[]) => void;
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: React.ComponentProps<'a'>) => <a href={href}>{children}</a>,
    router: { get: vi.fn(), reload: inertia.reload },
}));
vi.mock('@/components/time/allocation-chart', () => ({
    AllocationChart: (props: {
        entries: AllocationEntry[];
        onAdjust: (...args: number[]) => void;
    }) => {
        inertia.chart.current = props;

        return <div>Chart</div>;
    },
}));

import { AllocationPage } from '@/pages/time/allocation';

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((res) => {
        resolve = res;
    });

    return { promise, resolve };
}

function slotResponse(
    blockNumber: number,
    blocks: Array<[id: number, entryId: number, pct: number]>,
    locked = false,
) {
    return {
        ok: true,
        json: vi.fn().mockResolvedValue({
            slot: {
                block_date: '2026-09-20',
                block_number: blockNumber,
                allocation_pct: 100,
                blocks: blocks.map(([id, entryId, pct]) => ({
                    id,
                    time_entry_id: entryId,
                    allocation_pct: pct,
                    is_overridden: true,
                    locked,
                })),
            },
        }),
    } as unknown as Response;
}

function entriesFor(slot36: [number, number], slot40: [number, number] = [50, 50]) {
    return [
        {
            id: 1,
            description: 'Implementation',
            locked: false,
            context: null,
            blocks: [
                { id: 10, blockNumber: 36, allocationPct: slot36[0], isOverridden: false },
                { id: 20, blockNumber: 40, allocationPct: slot40[0], isOverridden: false },
            ],
        },
        {
            id: 2,
            description: 'Review',
            locked: false,
            context: null,
            blocks: [
                { id: 11, blockNumber: 36, allocationPct: slot36[1], isOverridden: false },
                { id: 21, blockNumber: 40, allocationPct: slot40[1], isOverridden: false },
            ],
        },
    ] satisfies AllocationEntry[];
}

const date = '2026-09-20';

afterEach(() => {
    vi.unstubAllGlobals();
    inertia.reload.mockReset();
    inertia.chart.current = null;
});

it('sends one request and replaces the entire slot from the canonical response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        slotResponse(36, [
            [10, 1, 75],
            [11, 2, 25],
        ]),
    );
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    const input = screen.getAllByLabelText('Implementation percentage')[0]!;
    await user.clear(input);
    await user.type(input, '75');
    await user.click(screen.getAllByRole('button', { name: 'Save' })[0]!);

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('09:00 UTC'));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
        '/time/blocks/10/allocation',
        expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ allocation_pct: 75 }) }),
    );
    expect(screen.getAllByLabelText('Review percentage')[0]).toHaveValue(25);
});

it('announces the updated slot with the adjusted and redistributed values', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            slotResponse(36, [
                [10, 1, 72.5],
                [11, 2, 27.5],
            ]),
        ),
    );
    const user = userEvent.setup();
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    const input = screen.getAllByLabelText('Implementation percentage')[0]!;
    await user.clear(input);
    await user.type(input, '72.5{Enter}');

    expect(await screen.findByRole('status')).toHaveTextContent(
        'Slot 09:00 UTC updated. Implementation is now 72.5%. Other entries: Review 27.5%.',
    );
});

it('keeps keyboard focus on the adjusted control after the server reconciles the slot', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            slotResponse(36, [
                [10, 1, 75],
                [11, 2, 25],
            ]),
        ),
    );
    const user = userEvent.setup();
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    const input = screen.getAllByLabelText('Implementation percentage')[0]!;
    await user.clear(input);
    await user.type(input, '75{Enter}');

    await screen.findByRole('status');
    expect(screen.getAllByLabelText('Implementation percentage')[0]).toBe(input);
    expect(input).toHaveFocus();
    expect(input).toHaveValue(75);
    expect(screen.getAllByLabelText('Review percentage')[0]).toHaveValue(25);
});

it('keeps a locked slot read-only', () => {
    render(
        <AllocationPage
            date={date}
            entries={entriesFor([60, 40]).map((entry, index) => ({
                ...entry,
                locked: index === 1,
            }))}
        />,
    );

    expect(screen.getAllByLabelText('Implementation percentage')[0]).toBeDisabled();
    expect(screen.getAllByRole('button', { name: 'Save' })[0]).toBeDisabled();
});

it('claims a slot synchronously so two same-tick mutations send one request', async () => {
    const pending = deferred<Response>();
    const fetchMock = vi.fn().mockReturnValue(pending.promise);
    vi.stubGlobal('fetch', fetchMock);
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    act(() => {
        inertia.chart.current!.onAdjust(10, 70, 36);
        inertia.chart.current!.onAdjust(11, 30, 36);
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    await act(async () =>
        pending.resolve(
            slotResponse(36, [
                [10, 1, 70],
                [11, 2, 30],
            ]),
        ),
    );

    // The slot is released afterwards and accepts a fresh mutation.
    fetchMock.mockResolvedValueOnce(
        slotResponse(36, [
            [10, 1, 55],
            [11, 2, 45],
        ]),
    );
    await act(async () => inertia.chart.current!.onAdjust(10, 55, 36));
    expect(fetchMock).toHaveBeenCalledTimes(2);
});

it('lets different slots mutate independently while one is still in flight', async () => {
    const slot36 = deferred<Response>();
    const slot40 = deferred<Response>();
    const fetchMock = vi
        .fn()
        .mockReturnValueOnce(slot36.promise)
        .mockReturnValueOnce(slot40.promise);
    vi.stubGlobal('fetch', fetchMock);
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    act(() => {
        inertia.chart.current!.onAdjust(10, 70, 36);
        inertia.chart.current!.onAdjust(20, 30, 40);
    });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
        '/time/blocks/10/allocation',
        '/time/blocks/20/allocation',
    ]);

    await act(async () =>
        slot40.resolve(
            slotResponse(40, [
                [20, 1, 30],
                [21, 2, 70],
            ]),
        ),
    );
    await act(async () =>
        slot36.resolve(
            slotResponse(36, [
                [10, 1, 70],
                [11, 2, 30],
            ]),
        ),
    );

    expect(screen.getAllByLabelText('Implementation percentage')[0]).toHaveValue(70);
    expect(screen.getAllByLabelText('Implementation percentage')[1]).toHaveValue(30);
});

it('reloads authoritative entries after an uncertain allocation failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network unavailable')));
    const user = userEvent.setup();

    render(<AllocationPage date={date} entries={entriesFor([100, 0])} />);
    await user.click(screen.getAllByRole('button', { name: 'Save' })[0]!);

    expect(await screen.findByRole('alert')).toHaveTextContent('Network unavailable');
    expect(inertia.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['entries'] }));
});

it('drops stale client overrides once authoritative entries are reloaded', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            slotResponse(36, [
                [10, 1, 75],
                [11, 2, 25],
            ]),
        )
        .mockRejectedValueOnce(new Error('Network unavailable'));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();
    const { rerender } = render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    await act(async () => inertia.chart.current!.onAdjust(10, 75, 36));
    expect(screen.getAllByLabelText('Implementation percentage')[0]).toHaveValue(75);

    // A later slot fails, triggering the authoritative reload.
    await user.click(screen.getAllByRole('button', { name: 'Save' })[1]!);
    await screen.findByRole('alert');
    const options = inertia.reload.mock.calls[0]![0] as ReloadOptions;

    // Another tab changed slot 36 on the server; the reloaded page reflects it.
    rerender(<AllocationPage date={date} entries={entriesFor([50, 50])} />);
    act(() => options.onSuccess?.());

    expect(screen.getAllByLabelText('Implementation percentage')[0]).toHaveValue(50);
    expect(screen.getAllByLabelText('Review percentage')[0]).toHaveValue(50);
});

it('does not let a late reload clobber a slot mutated after the reload began', async () => {
    const fetchMock = vi
        .fn()
        .mockRejectedValueOnce(new Error('Network unavailable'))
        .mockResolvedValueOnce(
            slotResponse(36, [
                [10, 1, 80],
                [11, 2, 20],
            ]),
        );
    vi.stubGlobal('fetch', fetchMock);
    const { rerender } = render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);

    await act(async () => inertia.chart.current!.onAdjust(10, 90, 36));
    await screen.findByRole('alert');
    const options = inertia.reload.mock.calls[0]![0] as ReloadOptions;

    // Reload is still in flight when the user succeeds on the same slot.
    await act(async () => inertia.chart.current!.onAdjust(10, 80, 36));
    expect(screen.getAllByLabelText('Implementation percentage')[0]).toHaveValue(80);

    // The older reload lands with pre-mutation data.
    rerender(<AllocationPage date={date} entries={entriesFor([60, 40])} />);
    act(() => options.onSuccess?.());

    expect(screen.getAllByLabelText('Implementation percentage')[0]).toHaveValue(80);
    expect(screen.getAllByLabelText('Review percentage')[0]).toHaveValue(20);
});

it('hands the chart fresh entries after a rejected drag so it repaints server data', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network unavailable')));
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);
    const before = inertia.chart.current!.entries;

    await act(async () => inertia.chart.current!.onAdjust(10, 90, 36));
    await screen.findByRole('alert');

    // The reload never lands, yet the chart is still told to repaint from server values.
    expect(inertia.chart.current!.entries).not.toBe(before);
    expect(inertia.chart.current!.entries).toEqual(before);
});

it('keeps the chart entries stable across unrelated page state changes', async () => {
    const user = userEvent.setup();
    render(<AllocationPage date={date} entries={entriesFor([60, 40])} />);
    const before = inertia.chart.current!.entries;

    await user.type(screen.getByLabelText('Date'), '2026-09-21');

    expect(inertia.chart.current!.entries).toBe(before);
});
