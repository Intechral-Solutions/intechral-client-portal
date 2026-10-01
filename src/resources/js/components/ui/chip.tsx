import { X } from 'lucide-react';
import type { ReactNode } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/**
 * EPIC-014 WP4 — `Chip` and `FilterChip` (Direction D §10, §18). Pills are reserved for tags and filter
 * chips (§10): a 5px radius, a structural `rule-control` edge, no fill of its own. Presentation only —
 * a chip names a value and a filter chip can be removed; neither knows what it is a chip of.
 */
const chipClass =
    'inline-flex min-h-7 items-center gap-1 rounded-control border border-rule-control bg-surface px-2 text-xs font-medium text-text';

export function Chip({ children, className }: { children: ReactNode; className?: string }) {
    return <span className={cn(chipClass, className)}>{children}</span>;
}

/**
 * A removable chip. The text is a plain label; the one control is the remove button, named after the
 * filter it removes ("Remove filter: Priority: High") so it is unambiguous out of context, in a
 * screen reader's button list and on a touch target (44px under `pointer-coarse`).
 */
export function FilterChip({
    label,
    onRemove,
    className,
}: {
    label: string;
    onRemove: () => void;
    className?: string;
}) {
    return (
        <span className={cn(chipClass, 'pr-0.5', className)}>
            <span>{label}</span>
            <button
                type="button"
                onClick={onRemove}
                aria-label={`Remove filter: ${label}`}
                className={cn(
                    'inline-flex size-6 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text pointer-coarse:size-9',
                    focusRing,
                )}
            >
                <X className="size-3.5" aria-hidden="true" />
            </button>
        </span>
    );
}
