import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

export function Alert({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn('rounded-md border border-border bg-card px-4 py-3 text-sm', className)}
            {...props}
        />
    );
}
