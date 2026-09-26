import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import type { ReactElement } from 'react';

import { AllocationChart } from '@/components/time/allocation-chart';
import { AllocationEditor } from '@/components/time/allocation-editor';
import { entryLabel, slotLabel } from '@/components/time/allocation-labels';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/components/shell/app-shell';
import { allocation, index as timeIndex } from '@/routes/time';
import { allocation as updateAllocation } from '@/routes/time/blocks';
import type { AllocationEntry, AllocationSlotResponse } from '@/types/time';

type Props = {
    date: string;
    entries: AllocationEntry[];
};

function formatPercentage(value: number) {
    return `${Number(value.toFixed(1))}%`;
}

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function slotStatus(
    blockNumber: number,
    adjustedBlockId: number,
    blocks: AllocationSlotResponse['slot']['blocks'],
    entries: AllocationEntry[],
) {
    const describe = (block: AllocationSlotResponse['slot']['blocks'][number]) => {
        const entry = entries.find((candidate) => candidate.id === block.time_entry_id);

        return entry ? entryLabel(entry) : `Entry #${block.time_entry_id}`;
    };
    const adjusted = blocks.find((block) => block.id === adjustedBlockId);
    const others = blocks.filter((block) => block.id !== adjustedBlockId);
    const parts = [`Slot ${slotLabel(blockNumber)} UTC updated.`];

    if (adjusted) {
        parts.push(`${describe(adjusted)} is now ${formatPercentage(adjusted.allocation_pct)}.`);
    }

    if (others.length > 0) {
        parts.push(
            `Other entries: ${others
                .map((block) => `${describe(block)} ${formatPercentage(block.allocation_pct)}`)
                .join(', ')}.`,
        );
    }

    return parts.join(' ');
}

export function AllocationPage({ date, entries: pageEntries }: Props) {
    const [selectedDate, setSelectedDate] = useState(date);
    const [processingSlots, setProcessingSlots] = useState<Set<number>>(() => new Set());
    const [slotOverrides, setSlotOverrides] = useState<
        Map<number, AllocationSlotResponse['slot']['blocks']>
    >(() => new Map());
    const [error, setError] = useState<string | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    // A rejected drag leaves the chart showing an unsaved value; bumping this hands it a
    // fresh entries array so it repaints from server data even if the reload cannot land.
    const [resyncCount, setResyncCount] = useState(0);

    const entries = useMemo(
        () =>
            pageEntries.map((entry) => ({
                ...entry,
                locked:
                    entry.locked ||
                    entry.blocks.some((block) =>
                        slotOverrides
                            .get(block.blockNumber)
                            ?.some((canonical) => canonical.id === block.id && canonical.locked),
                    ),
                blocks: entry.blocks.map((block) => {
                    const canonical = slotOverrides
                        .get(block.blockNumber)
                        ?.find((candidate) => candidate.id === block.id);

                    return canonical
                        ? {
                              ...block,
                              allocationPct: canonical.allocation_pct,
                              isOverridden: canonical.is_overridden,
                          }
                        : block;
                }),
            })),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- resyncCount only forces a new array identity
        [pageEntries, slotOverrides, resyncCount],
    );

    // In-flight slots are claimed synchronously in a ref: render-captured state can still
    // read "idle" for a second action in the same tick. A claimed slot admits no second
    // mutation, so its single response can never be overtaken by an older one. The version
    // counter (bumped when a slot's mutation starts and finishes) lets a reload that began
    // earlier recognise slots that changed since and leave their newer state alone.
    const inFlightSlots = useRef(new Set<number>());
    const slotVersions = useRef(new Map<number, number>());

    const bumpSlotVersion = useCallback((blockNumber: number) => {
        slotVersions.current.set(blockNumber, (slotVersions.current.get(blockNumber) ?? 0) + 1);
    }, []);

    const reloadAuthoritativeEntries = useCallback(() => {
        const versionsAtStart = new Map(slotVersions.current);

        router.reload({
            only: ['entries'],
            onSuccess: () => {
                const reloaded = [...slotVersions.current.keys()].filter(
                    (slot) =>
                        (slotVersions.current.get(slot) ?? 0) === (versionsAtStart.get(slot) ?? 0),
                );

                setSlotOverrides((current) => {
                    const next = new Map(current);
                    reloaded.forEach((slot) => next.delete(slot));

                    return next;
                });
            },
        });
    }, []);

    const adjust = useCallback(
        async (blockId: number, percentage: number, blockNumber: number) => {
            if (inFlightSlots.current.has(blockNumber)) return;
            inFlightSlots.current.add(blockNumber);
            bumpSlotVersion(blockNumber);
            setError(null);
            setStatus(null);
            setProcessingSlots((current) => new Set(current).add(blockNumber));
            let failed = false;

            try {
                const response = await fetch(updateAllocation.url(blockId), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ allocation_pct: percentage }),
                });

                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                        errors?: Record<string, string[]>;
                    } | null;
                    throw new Error(
                        body?.message ??
                            Object.values(body?.errors ?? {})[0]?.[0] ??
                            'Unable to update allocation.',
                    );
                }

                const body = (await response.json()) as AllocationSlotResponse;
                setSlotOverrides((current) => {
                    const next = new Map(current);
                    next.set(body.slot.block_number, body.slot.blocks);
                    return next;
                });
                setStatus(slotStatus(blockNumber, blockId, body.slot.blocks, pageEntries));
            } catch (reason) {
                failed = true;
                setResyncCount((count) => count + 1);
                setError(reason instanceof Error ? reason.message : 'Unable to update allocation.');
            } finally {
                inFlightSlots.current.delete(blockNumber);
                bumpSlotVersion(blockNumber);
                setProcessingSlots((current) => {
                    const next = new Set(current);
                    next.delete(blockNumber);
                    return next;
                });
            }

            if (failed) reloadAuthoritativeEntries();
        },
        [bumpSlotVersion, pageEntries, reloadAuthoritativeEntries],
    );

    // Stable identity: changing unrelated page state must not hand the chart a new callback.
    const handleAdjust = useCallback(
        (blockId: number, percentage: number, blockNumber: number) =>
            void adjust(blockId, percentage, blockNumber),
        [adjust],
    );

    return (
        <>
            <Head title="Time Allocation" />
            <div className="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="My Time"
                    description="Review and adjust timer allocation across 15-minute UTC blocks."
                />
                <nav className="flex gap-1 border-b border-border" aria-label="Time views">
                    <Link
                        href={timeIndex.url()}
                        className="border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground"
                    >
                        Entries
                    </Link>
                    <Link
                        href={allocation.url()}
                        className="border-b-2 border-ink px-3 py-2 text-sm font-medium text-text"
                    >
                        Allocation
                    </Link>
                </nav>

                <form
                    className="flex items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            allocation.url(),
                            { date: selectedDate },
                            { preserveState: false, replace: true },
                        );
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="allocation-date">Date</Label>
                        <Input
                            id="allocation-date"
                            type="date"
                            value={selectedDate}
                            onChange={(event) => setSelectedDate(event.target.value)}
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        View date
                    </Button>
                </form>

                {error ? <Alert variant="danger">{error}</Alert> : null}
                {status ? (
                    <p className="text-sm text-muted-foreground" role="status">
                        {status}
                    </p>
                ) : null}

                {entries.length === 0 ? (
                    <Alert>No time blocks were recorded for this date.</Alert>
                ) : (
                    <>
                        <section className="space-y-3">
                            <h2 className="text-base font-semibold">Allocation chart</h2>
                            <div className="overflow-x-auto border-y border-border py-4">
                                <AllocationChart
                                    entries={entries}
                                    processingSlots={processingSlots}
                                    onAdjust={handleAdjust}
                                />
                            </div>
                        </section>
                        <section className="space-y-3">
                            <h2 className="text-base font-semibold">Allocation editor</h2>
                            <p className="text-sm text-muted-foreground">
                                Adjust the same server-owned allocation without dragging the chart.
                            </p>
                            <AllocationEditor
                                entries={entries}
                                processingSlots={processingSlots}
                                onAdjust={handleAdjust}
                            />
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

AllocationPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default AllocationPage;
