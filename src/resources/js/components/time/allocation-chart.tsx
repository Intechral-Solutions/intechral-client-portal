import Chart from 'chart.js/auto';
import dragData from 'chartjs-plugin-dragdata';
import { useEffect, useRef } from 'react';

import { entryLabel, slotLabel } from '@/components/time/allocation-labels';
import type { AllocationEntry } from '@/types/time';

Chart.register(dragData);

const labels = Array.from({ length: 96 }, (_, blockNumber) => slotLabel(blockNumber));

const colors = [
    '#4f46e5',
    '#059669',
    '#d97706',
    '#dc2626',
    '#2563eb',
    '#7c3aed',
    '#db2777',
    '#0d9488',
];

type AdjustHandler = (blockId: number, percentage: number, blockNumber: number) => void;

function buildDatasets(entries: AllocationEntry[]) {
    return entries.map((entry, entryIndex) => ({
        label: entryLabel(entry),
        data: Array.from({ length: 96 }, (_, blockNumber) => {
            return (
                entry.blocks.find((block) => block.blockNumber === blockNumber)?.allocationPct ?? 0
            );
        }),
        backgroundColor: `${colors[entryIndex % colors.length]}88`,
        borderColor: colors[entryIndex % colors.length],
        borderWidth: 1.5,
        fill: true,
        tension: 0,
        pointRadius: 0,
        pointHitRadius: 8,
        dragData: !entry.locked,
    }));
}

export function AllocationChart({
    entries,
    processingSlots,
    onAdjust,
}: {
    entries: AllocationEntry[];
    processingSlots: Set<number>;
    onAdjust: AdjustHandler;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const chartRef = useRef<Chart<'line'> | null>(null);
    const renderedEntries = useRef<AllocationEntry[] | null>(null);
    // Drag callbacks are created once with the chart, so they read current values here
    // instead of closing over render-time props (which would force a rebuild per render).
    const latest = useRef({ entries, processingSlots, onAdjust });
    const hasEntries = entries.length > 0;

    useEffect(() => {
        latest.current = { entries, processingSlots, onAdjust };
    });

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas || !hasEntries) return;

        const blockIdFor = (datasetIndex: number, blockNumber: number) => {
            const entry = latest.current.entries[datasetIndex];

            return entry?.blocks.find((block) => block.blockNumber === blockNumber)?.id;
        };

        const chart = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets: buildDatasets(latest.current.entries) },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    dragData: {
                        round: 1,
                        showTooltip: true,
                        dragX: false,
                        dragY: true,
                        magnet: {
                            to: (value) =>
                                typeof value === 'number'
                                    ? Math.max(0, Math.min(100, Math.round(value * 10) / 10))
                                    : value,
                        },
                        onDragStart: (_event, datasetIndex, blockNumber) => {
                            const { entries: current, processingSlots: processing } =
                                latest.current;
                            const entry = current[datasetIndex];

                            return Boolean(
                                entry &&
                                !entry.locked &&
                                !current.some(
                                    (candidate) =>
                                        candidate.locked &&
                                        candidate.blocks.some(
                                            (block) => block.blockNumber === blockNumber,
                                        ),
                                ) &&
                                !processing.has(blockNumber) &&
                                blockIdFor(datasetIndex, blockNumber) !== undefined,
                            );
                        },
                        onDrag: () => undefined,
                        onDragEnd: (_event, datasetIndex, blockNumber, value) => {
                            const blockId = blockIdFor(datasetIndex, blockNumber);

                            if (blockId !== undefined && typeof value === 'number') {
                                latest.current.onAdjust(blockId, value, blockNumber);
                            }
                        },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        ticks: { maxTicksLimit: 25, maxRotation: 0 },
                    },
                    y: {
                        stacked: true,
                        min: 0,
                        max: 100,
                        ticks: { stepSize: 25, callback: (value) => `${value}%` },
                    },
                },
            },
        });

        chartRef.current = chart;
        renderedEntries.current = latest.current.entries;

        return () => {
            chart.destroy();
            chartRef.current = null;
            renderedEntries.current = null;
        };
    }, [hasEntries]);

    // Server-confirmed allocation changes update the live chart instead of rebuilding it.
    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || renderedEntries.current === entries) return;

        chart.data.datasets = buildDatasets(entries);
        chart.update('none');
        renderedEntries.current = entries;
    }, [entries]);

    if (!hasEntries) return null;

    return (
        <div className="h-96 min-w-[44rem]">
            <canvas ref={canvasRef} aria-label="Time allocation chart" role="img" />
        </div>
    );
}
