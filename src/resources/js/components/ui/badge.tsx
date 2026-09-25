import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/*
 * Badge is the tag / category / filter-chip primitive (Direction D: pills are for tags, never for
 * status). Anything that states a STATE, such as a ticket, task, project or entry status, belongs on
 * `Status`, which carries a glyph as well as a label. The success / warning / danger / info
 * variants remain, bound to the legacy raw status variables, only for the consumers that have not
 * moved to `Status` yet (EPIC-013 §20: "its status variants stay working until each consumer adopts").
 */
const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            variant: {
                neutral: 'border-rule-control bg-surface-sunken text-text-secondary',
                success: 'border-[var(--border-success)] bg-[var(--surface-success)] text-[var(--text-success)]',
                warning: 'border-[var(--border-warning)] bg-[var(--surface-warning)] text-[var(--text-warning)]',
                danger: 'border-[var(--border-danger)] bg-[var(--surface-danger)] text-[var(--text-danger)]',
                info: 'border-[var(--border-info)] bg-[var(--surface-info)] text-[var(--text-info)]',
            },
        },
        defaultVariants: { variant: 'neutral' },
    },
);

type BadgeProps = HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>;

export function Badge({ className, variant, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
