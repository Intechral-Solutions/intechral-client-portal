import type { AllocationEntry } from '@/types/time';

export function slotLabel(blockNumber: number) {
    const hour = String(Math.floor(blockNumber / 4)).padStart(2, '0');
    const minute = String((blockNumber % 4) * 15).padStart(2, '0');

    return `${hour}:${minute}`;
}

export function entryLabel(entry: AllocationEntry) {
    return entry.context?.label ?? entry.description ?? `Entry #${entry.id}`;
}
