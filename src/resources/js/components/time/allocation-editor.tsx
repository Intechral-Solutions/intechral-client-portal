import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import { entryLabel, slotLabel } from '@/components/time/allocation-labels';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AllocationEntry } from '@/types/time';

type SlotRow = {
    entry: AllocationEntry;
    block: AllocationEntry['blocks'][number];
};

export function AllocationEditor({
    entries,
    processingSlots,
    onAdjust,
}: {
    entries: AllocationEntry[];
    processingSlots: Set<number>;
    onAdjust: (blockId: number, percentage: number, blockNumber: number) => void;
}) {
    const slots = useMemo(() => {
        const grouped = new Map<number, SlotRow[]>();

        for (const entry of entries) {
            for (const block of entry.blocks) {
                grouped.set(block.blockNumber, [
                    ...(grouped.get(block.blockNumber) ?? []),
                    { entry, block },
                ]);
            }
        }

        return [...grouped.entries()].sort(([left], [right]) => left - right);
    }, [entries]);

    if (slots.length === 0) return null;

    return (
        <div className="space-y-5">
            {slots.map(([blockNumber, rows]) => {
                const locked = rows.some(({ entry }) => entry.locked);
                const total = rows.reduce((sum, { block }) => sum + block.allocationPct, 0);

                return (
                    <fieldset
                        key={blockNumber}
                        disabled={locked}
                        aria-busy={processingSlots.has(blockNumber)}
                        className="border-t border-border pt-4"
                    >
                        <legend className="flex w-full items-center justify-between gap-3 text-sm font-semibold">
                            <span>{slotLabel(blockNumber)} UTC</span>
                            <span className="flex items-center gap-2">
                                Total {total.toFixed(1)}%{locked ? <Badge>Locked</Badge> : null}
                            </span>
                        </legend>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            {rows.map(({ entry, block }) => (
                                <AllocationInput
                                    key={block.id}
                                    label={entryLabel(entry)}
                                    blockId={block.id}
                                    blockNumber={blockNumber}
                                    value={block.allocationPct}
                                    busy={processingSlots.has(blockNumber)}
                                    onAdjust={onAdjust}
                                />
                            ))}
                        </div>
                        {locked ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                This slot cannot be adjusted because it contains billed or
                                invoice-linked time.
                            </p>
                        ) : (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Saving one value redistributes the remainder across this slot.
                            </p>
                        )}
                    </fieldset>
                );
            })}
        </div>
    );
}

function AllocationInput({
    label,
    blockId,
    blockNumber,
    value,
    busy,
    onAdjust,
}: {
    label: string;
    blockId: number;
    blockNumber: number;
    value: number;
    busy: boolean;
    onAdjust: (blockId: number, percentage: number, blockNumber: number) => void;
}) {
    const [draft, setDraft] = useState(String(value));
    const [seenValue, setSeenValue] = useState(value);
    const [invalid, setInvalid] = useState(false);

    // The block keeps a stable identity across server redistribution, so a changed
    // authoritative value is adopted here instead of by remounting (which would drop
    // keyboard focus). An unchanged value, e.g. after a failed save, keeps the draft.
    if (value !== seenValue) {
        setSeenValue(value);
        setDraft(String(value));
        setInvalid(false);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        // Read-only/aria-disabled rather than disabled while saving: a disabled focused
        // control loses keyboard focus in browsers.
        if (busy) return;

        const percentage = Number(draft);
        if (
            draft.trim() === '' ||
            !Number.isFinite(percentage) ||
            percentage < 0 ||
            percentage > 100
        ) {
            setInvalid(true);
            return;
        }

        setInvalid(false);
        onAdjust(blockId, percentage, blockNumber);
    }

    const inputId = `allocation-${blockId}`;

    return (
        <form onSubmit={submit} noValidate className="flex items-start gap-2">
            <div className="min-w-0 flex-1 space-y-1">
                <Label htmlFor={inputId}>{label} percentage</Label>
                <Input
                    id={inputId}
                    type="number"
                    min="0"
                    max="100"
                    step="0.1"
                    value={draft}
                    readOnly={busy}
                    aria-invalid={invalid}
                    aria-describedby={invalid ? `${inputId}-error` : undefined}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setInvalid(false);
                    }}
                />
                {invalid ? (
                    <p id={`${inputId}-error`} role="alert" className="text-xs text-destructive">
                        Enter a percentage between 0 and 100.
                    </p>
                ) : null}
            </div>
            <Button
                type="submit"
                variant="outline"
                className="mt-6"
                aria-disabled={busy || undefined}
            >
                Save
            </Button>
        </form>
    );
}
