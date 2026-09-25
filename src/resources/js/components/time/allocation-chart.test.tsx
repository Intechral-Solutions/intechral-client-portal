import { render } from '@testing-library/react';
import { vi } from 'vitest';

type ChartConfig = {
    data: { datasets: Array<{ data: number[]; dragData: boolean }> };
    options: {
        plugins: {
            dragData: {
                onDragStart: (event: unknown, datasetIndex: number, blockNumber: number) => boolean;
                onDragEnd: (
                    event: unknown,
                    datasetIndex: number,
                    blockNumber: number,
                    value: number,
                ) => void;
            };
        };
    };
};

const chart = vi.hoisted(() => ({
    instances: [] as Array<{ config: unknown; data: unknown }>,
    destroy: vi.fn(),
    update: vi.fn(),
    register: vi.fn(),
}));

vi.mock('chart.js/auto', () => ({
    default: class ChartMock {
        static register = chart.register;
        destroy = chart.destroy;
        update = chart.update;
        data: unknown;

        constructor(
            _canvas: HTMLCanvasElement,
            public config: { data: unknown },
        ) {
            this.data = config.data;
            chart.instances.push(this);
        }
    },
}));
vi.mock('chartjs-plugin-dragdata', () => ({ default: {} }));

import { AllocationChart } from '@/components/time/allocation-chart';
import type { AllocationEntry } from '@/types/time';

function entry(overrides: Partial<AllocationEntry> = {}, pct = 100): AllocationEntry {
    return {
        id: 1,
        description: 'Implementation',
        locked: false,
        context: null,
        blocks: [{ id: 10, blockNumber: 36, allocationPct: pct, isOverridden: false }],
        ...overrides,
    };
}

function latestChart() {
    return chart.instances[chart.instances.length - 1] as unknown as {
        config: ChartConfig;
        data: ChartConfig['data'];
    };
}

const config = () => latestChart().config;
const instanceData = () => latestChart().data;

afterEach(() => {
    chart.instances.length = 0;
    chart.destroy.mockReset();
    chart.update.mockReset();
});

it('emits one adjustment when dragging completes and destroys the chart on unmount', () => {
    const onAdjust = vi.fn();
    const { unmount } = render(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={onAdjust} />,
    );

    config().options.plugins.dragData.onDragEnd({}, 0, 36, 72.5);

    expect(onAdjust).toHaveBeenCalledTimes(1);
    expect(onAdjust).toHaveBeenCalledWith(10, 72.5, 36);
    expect(chart.destroy).not.toHaveBeenCalled();
    unmount();
    expect(chart.destroy).toHaveBeenCalledOnce();
});

it('does not rebuild the chart for unrelated parent renders', () => {
    const entries = [entry()];
    const { rerender } = render(
        <AllocationChart entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );

    // Fresh inline callback and Set identities, as a parent produces on every render.
    rerender(<AllocationChart entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />);
    rerender(<AllocationChart entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />);
    // Same content, new array identity.
    rerender(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );

    expect(chart.instances).toHaveLength(1);
    expect(chart.destroy).not.toHaveBeenCalled();
});

it('updates the existing chart in place when the allocation data changes', () => {
    const { rerender } = render(
        <AllocationChart
            entries={[
                entry({}, 60),
                entry({
                    id: 2,
                    blocks: [{ id: 11, blockNumber: 36, allocationPct: 40, isOverridden: false }],
                }),
            ]}
            processingSlots={new Set()}
            onAdjust={vi.fn()}
        />,
    );
    expect(instanceData().datasets[0]!.data[36]).toBe(60);

    rerender(
        <AllocationChart
            entries={[
                entry({}, 75),
                entry({
                    id: 2,
                    locked: true,
                    blocks: [{ id: 11, blockNumber: 36, allocationPct: 25, isOverridden: true }],
                }),
            ]}
            processingSlots={new Set()}
            onAdjust={vi.fn()}
        />,
    );

    expect(chart.instances).toHaveLength(1);
    expect(chart.destroy).not.toHaveBeenCalled();
    expect(chart.update).toHaveBeenCalled();
    expect(instanceData().datasets[0]!.data[36]).toBe(75);
    expect(instanceData().datasets[1]!.data[36]).toBe(25);
    expect(instanceData().datasets[1]!.dragData).toBe(false);
});

it('gives drag handlers the latest processing state and callback without rebuilding', () => {
    const first = vi.fn();
    const second = vi.fn();
    const { rerender } = render(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={first} />,
    );
    const { onDragStart } = config().options.plugins.dragData;
    expect(onDragStart({}, 0, 36)).toBe(true);

    rerender(
        <AllocationChart entries={[entry()]} processingSlots={new Set([36])} onAdjust={second} />,
    );

    expect(chart.instances).toHaveLength(1);
    expect(config().options.plugins.dragData.onDragStart({}, 0, 36)).toBe(false);
    expect(onDragStart({}, 0, 36)).toBe(false);

    rerender(<AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={second} />);
    config().options.plugins.dragData.onDragEnd({}, 0, 36, 50);
    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledWith(10, 50, 36);
});

it('reads locked slots and block ids from the latest entries', () => {
    const { rerender } = render(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );
    const { onDragStart } = config().options.plugins.dragData;
    expect(onDragStart({}, 0, 36)).toBe(true);

    rerender(
        <AllocationChart
            entries={[entry({ locked: true })]}
            processingSlots={new Set()}
            onAdjust={vi.fn()}
        />,
    );

    expect(onDragStart({}, 0, 36)).toBe(false);
});

it('destroys the chart when the last entry disappears and rebuilds when entries return', () => {
    const { rerender } = render(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );

    rerender(<AllocationChart entries={[]} processingSlots={new Set()} onAdjust={vi.fn()} />);
    expect(chart.destroy).toHaveBeenCalledOnce();

    rerender(
        <AllocationChart entries={[entry()]} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );
    expect(chart.instances).toHaveLength(2);
});
