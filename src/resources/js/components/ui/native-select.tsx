import type { SelectHTMLAttributes } from 'react';
import { forwardRef } from 'react';

import { cn } from '@/lib/utils';

/**
 * A styled native `<select>`. Native on purpose: it is fully keyboard, touch, and screen-reader
 * accessible for free. Pass `className="w-full"` inside a stacked form; in a filter row it sizes to
 * its content.
 */
export const NativeSelect = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, ...props }, ref) => (
        <select
            ref={ref}
            className={cn(
                'flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    ),
);

NativeSelect.displayName = 'NativeSelect';
