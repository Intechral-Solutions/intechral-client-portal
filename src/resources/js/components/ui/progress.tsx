import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * Direction D continuous bar (EPIC-013 WP2, docs/design/direction-d-design-system.md §11.3): a
 * quantity (percent complete, hours, counts), never a staged path. 4px inline / 6px summary, radius
 * half the height, `progress-track` under `progress-fill`. The fill is never brand cyan.
 *
 * Semantics: `role="progressbar"` with `aria-valuemin`, `aria-valuemax`, `aria-valuenow` and an
 * accessible name. Every current consumer measures completion (milestones, checklists, tasks), which
 * is what §11.3 assigns to `progressbar`; `meter` is reserved for a quantity read against a plan
 * (budget vs expected) and has no consumer yet, so it is not built. Pair the bar with its number in
 * a visible label; `valueText` is the spoken form when that is richer than the raw value.
 *
 * The expected-marker and over-100% treatments in §11.3 are not implemented: no consumer needs them.
 */
const progressVariants = cva('w-full overflow-hidden rounded-full bg-progress-track', {
    variants: {
        size: { sm: 'h-1', md: 'h-1.5' },
    },
    defaultVariants: { size: 'md' },
});

type ProgressProps = Omit<HTMLAttributes<HTMLDivElement>, 'children' | 'role'> &
    VariantProps<typeof progressVariants> & {
        value: number;
        /** Defaults to 100 (a percentage). Pass a count, e.g. `total`, to show "3 of 5" progress. */
        max?: number;
        /** Accessible name. Required: a progress bar with no name is announced as an anonymous bar. */
        label: string;
        /** Spoken instead of the raw number, e.g. "3 of 5 items". */
        valueText?: string;
    };

export function Progress({ value, max = 100, label, valueText, size, className, ...props }: ProgressProps) {
    const upper = Number.isFinite(max) && max > 0 ? max : 0;
    const current = Number.isFinite(value) ? Math.min(Math.max(value, 0), upper) : 0;
    const percent = upper > 0 ? (current / upper) * 100 : 0;

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={upper}
            aria-valuenow={current}
            aria-valuetext={valueText}
            className={cn(progressVariants({ size }), className)}
            {...props}
        >
            <div
                className="h-full rounded-full bg-progress-fill transition-[width] duration-motion-base ease-motion motion-reduce:transition-none"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}
