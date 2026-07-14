/**
 * Allocation Chart — stacked area chart with draggable boundaries.
 *
 * Relies on window.AllocationData (set by time/allocation.blade.php):
 *   Array<{
 *     entryId:    number,
 *     label:      string,
 *     contextUrl: string|null,
 *     description:string|null,
 *     locked:     boolean,
 *     blocks:     { [blockNumber: string]: { id: number, pct: number } }
 *   }>
 *
 * window.AllocationRoutes.blockAllocation  — base URL for PATCH requests
 */

import Chart from 'chart.js/auto';
import dragData from 'chartjs-plugin-dragdata';

Chart.register(dragData);

// ── Constants ─────────────────────────────────────────────────────────────────

const BLOCKS_PER_DAY = 96; // 24h × 4 blocks/h

const COLORS = [
    '#6366f1', // accent-indigo
    '#10b981', // emerald
    '#f59e0b', // amber
    '#ef4444', // red
    '#3b82f6', // blue
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#14b8a6', // teal
    '#f97316', // orange
    '#84cc16', // lime
];

/** X-axis labels: "00:00", "00:15", … "23:45" */
const X_LABELS = Array.from({ length: BLOCKS_PER_DAY }, (_, i) => {
    const h = String(Math.floor(i / 4)).padStart(2, '0');
    const m = String((i % 4) * 15).padStart(2, '0');
    return `${h}:${m}`;
});

// ── State ─────────────────────────────────────────────────────────────────────

/** Live allocation matrix: alloc[entryIndex][blockNumber] = pct */
let alloc = [];

/** Map from blockNumber → { entryIndex → blockId } — needed for PATCH calls */
let blockIdMap = [];

/** Pending PATCH calls — debounced per (entryIndex, blockNumber) */
const pendingPatches = new Map();

// ── Helpers ───────────────────────────────────────────────────────────────────

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Build the per-series data arrays from `alloc`.
 * Chart.js stacked: series 0 at bottom, last series at top.
 */
function buildDatasets(entries) {
    return entries.map((entry, idx) => {
        const color = COLORS[idx % COLORS.length];
        return {
            label:           entry.label,
            data:            Array.from({ length: BLOCKS_PER_DAY }, (_, b) => alloc[idx][b]),
            backgroundColor: hexToRgba(color, 0.55),
            borderColor:     color,
            borderWidth:     1.5,
            fill:            true,
            tension:         0,
            pointRadius:     0,
            pointHitRadius:  8,
            // dragData plugin uses this to know which points are draggable
            dragData:        !entry.locked,
        };
    });
}

/**
 * Redistribute the remaining (100 - newPct) across all other entries
 * for the given block, proportional to their current allocation.
 * Mutates `alloc` in-place.
 *
 * Returns an array of { entryIndex, blockNumber, newPct } for batching.
 */
function redistributeBlock(draggedEntryIdx, blockNumber, newPct) {
    const n = alloc.length;
    const remainder = 100 - newPct;
    const others = [];

    // Sum other allocations
    let otherTotal = 0;
    for (let i = 0; i < n; i++) {
        if (i !== draggedEntryIdx) {
            otherTotal += alloc[i][blockNumber];
            others.push(i);
        }
    }

    // Set dragged entry
    alloc[draggedEntryIdx][blockNumber] = newPct;

    // Distribute remainder proportionally
    let assigned = 0;
    others.forEach((i, j) => {
        const share = otherTotal > 0
            ? Math.round((alloc[i][blockNumber] / otherTotal) * remainder * 100) / 100
            : Math.round((remainder / others.length) * 100) / 100;

        if (j === others.length - 1) {
            // Last one gets the rounding remainder
            alloc[i][blockNumber] = Math.round((remainder - assigned) * 100) / 100;
        } else {
            alloc[i][blockNumber] = share;
            assigned += share;
        }
    });

    return [draggedEntryIdx, ...others].map(i => ({
        entryIndex:  i,
        blockNumber,
        newPct:      alloc[i][blockNumber],
    }));
}

/**
 * Debounced PATCH to /time/blocks/{id}/allocation
 */
function schedulePatch(entryIndex, blockNumber, pct) {
    const id = blockIdMap[blockNumber]?.[entryIndex];
    if (!id) return; // block not yet persisted — skip

    const key = `${entryIndex}-${blockNumber}`;
    if (pendingPatches.has(key)) clearTimeout(pendingPatches.get(key));

    pendingPatches.set(key, setTimeout(async () => {
        pendingPatches.delete(key);
        try {
            const response = await fetch(`${window.AllocationRoutes.blockAllocation}/${id}/allocation`, {
                method:  'PATCH',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]').content,
                    'Accept':        'application/json',
                },
                body: JSON.stringify({ allocation_pct: pct }),
            });

            if (!response.ok) {
                throw new Error(`Unable to update allocation (${response.status})`);
            }
        } catch (error) {
            window.alert(error.message || 'Unable to update allocation.');
            window.location.reload();
        }
    }, 400));
}

// ── Legend ────────────────────────────────────────────────────────────────────

function renderLegend(entries) {
    const el = document.getElementById('block-legend');
    if (!el) return;

    el.innerHTML = entries.map((entry, idx) => {
        const color = COLORS[idx % COLORS.length];
        const link  = entry.contextUrl
            ? `<a href="${entry.contextUrl}" class="hover:underline" style="color:${color};">${entry.label}</a>`
            : `<span style="color:${color};">${entry.label}</span>`;

        return `<div class="flex items-center gap-1.5 text-sm">
            <span class="inline-block h-3 w-3 rounded-sm flex-shrink-0" style="background-color:${color};"></span>
            ${link}
        </div>`;
    }).join('');
}

// ── Main ──────────────────────────────────────────────────────────────────────

export function init() {
    const canvas = document.getElementById('allocation-chart');
    if (!canvas) return;

    const entries = window.AllocationData || [];
    if (entries.length === 0) return;

    // Initialise alloc matrix and blockIdMap
    alloc     = entries.map(() => new Array(BLOCKS_PER_DAY).fill(0));
    blockIdMap = Array.from({ length: BLOCKS_PER_DAY }, () => ({}));

    entries.forEach((entry, idx) => {
        Object.entries(entry.blocks).forEach(([blockNumStr, { id, pct }]) => {
            const b = parseInt(blockNumStr, 10);
            alloc[idx][b]         = pct;
            blockIdMap[b][idx]    = id;
        });
    });

    renderLegend(entries);

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels:   X_LABELS,
            datasets: buildDatasets(entries),
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            animation:           false,
            interaction: {
                mode:      'index',
                intersect: false,
            },
            plugins: {
                legend: { display: false },   // custom legend above
                tooltip: {
                    callbacks: {
                        label(ctx) {
                            return ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%`;
                        },
                    },
                },
                dragData: {
                    round:            1,
                    showTooltip:      true,
                    magnet: {
                        to: val => Math.max(0, Math.min(100, Math.round(val * 10) / 10)),
                    },
                    onDragStart(_evt, datasetIndex, index, _value) {
                        if (entries[datasetIndex]?.locked) return false;

                        // Redistribution affects every persisted sibling in the
                        // slot, so any locked sibling makes the whole slot read-only.
                        return !entries.some((entry, entryIndex) => (
                            entry.locked && blockIdMap[index]?.[entryIndex]
                        ));
                    },
                    onDrag(_evt, datasetIndex, index, value) {
                        // Live visual update — redistribute in alloc matrix
                        const changed = redistributeBlock(datasetIndex, index, value);

                        // Update all affected datasets immediately so the stacked
                        // chart re-renders each frame during drag
                        changed.forEach(({ entryIndex, blockNumber, newPct }) => {
                            chart.data.datasets[entryIndex].data[blockNumber] = newPct;
                        });
                        // Chart.js will call update() itself after onDrag returns
                    },
                    onDragEnd(_evt, datasetIndex, index, value) {
                        const changed = redistributeBlock(datasetIndex, index, value);

                        // Sync chart data and schedule persists
                        changed.forEach(({ entryIndex, blockNumber, newPct }) => {
                            chart.data.datasets[entryIndex].data[blockNumber] = newPct;
                            schedulePatch(entryIndex, blockNumber, newPct);
                        });

                        chart.update('none');
                    },
                },
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: {
                        maxTicksLimit: 25,       // show ~every 4th label (6 hours)
                        maxRotation:   0,
                        color:         'var(--text-muted, #9ca3af)',
                        font:          { size: 10, family: 'ui-monospace, monospace' },
                    },
                    grid: {
                        color: 'var(--border-subtle, rgba(0,0,0,.06))',
                    },
                },
                y: {
                    stacked: true,
                    min:     0,
                    max:     100,
                    ticks: {
                        stepSize: 25,
                        color:    'var(--text-muted, #9ca3af)',
                        callback: v => `${v}%`,
                        font:     { size: 11 },
                    },
                    grid: {
                        color: 'var(--border-subtle, rgba(0,0,0,.06))',
                    },
                },
            },
        },
    });
}
