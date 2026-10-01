import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * Direction D §10.3: three ascending bars plus a text label. The bars are decoration (`aria-hidden`)
 * and the label is the signal, so priority is never colour or shape alone. Critical is the same
 * three bars in `danger` with the label in `danger`; unfilled bars use `rule-control`.
 *
 * The domain decides how many bars a value earns and which tone it takes; this primitive only draws.
 */
export function Priority({
    bars,
    tone = 'neutral',
    children,
    className,
}: {
    bars: 1 | 2 | 3;
    tone?: 'neutral' | 'danger';
    /** The visible label. */
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-sm',
                tone === 'danger' ? 'font-medium text-danger' : 'text-text-secondary',
                className,
            )}
        >
            <svg viewBox="0 0 12 12" className="h-3 w-3 shrink-0" aria-hidden="true" focusable="false">
                {[1, 2, 3].map((bar) => {
                    const filled = bar <= bars;

                    return (
                        <rect
                            key={bar}
                            data-bar={filled ? 'filled' : 'empty'}
                            x={(bar - 1) * 4.2}
                            y={12 - bar * 3.6}
                            width="2.6"
                            height={bar * 3.6}
                            rx="0.6"
                            className={filled ? 'fill-current' : 'fill-rule-control'}
                        />
                    );
                })}
            </svg>
            <span>{children}</span>
        </span>
    );
}
