import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            variant: {
                neutral: 'border-border bg-muted text-muted-foreground',
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
