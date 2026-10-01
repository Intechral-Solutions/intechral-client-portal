import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * Direction D §18's `Tag`: the small mono, uppercase marker for a source or kind (§10.2's `BOARD`,
 * `STANDALONE`). Plain text with a structural edge, never a control and never the only signal.
 */
export function Tag({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <span
            className={cn(
                'inline-block rounded-tag whitespace-nowrap border border-rule-control px-1.5 font-mono text-[10px] leading-4 tracking-wide text-text-muted uppercase',
                className,
            )}
        >
            {children}
        </span>
    );
}
