import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

type ProgressProps = Omit<HTMLAttributes<HTMLDivElement>, 'children' | 'role'> & {
    value: number;
    /** Defaults to 100 (a percentage). Pass a count, e.g. `total`, to show "3 of 5" progress. */
    max?: number;
    /** Accessible name. Required: a progress bar with no name is announced as an anonymous bar. */
    label: string;
    /** Spoken instead of the raw number, e.g. "3 of 5 items". */
    valueText?: string;
};

export function Progress({ value, max = 100, label, valueText, className, ...props }: ProgressProps) {
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
            className={cn('h-2 w-full overflow-hidden rounded-full bg-muted', className)}
            {...props}
        >
            <div
                className="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}
