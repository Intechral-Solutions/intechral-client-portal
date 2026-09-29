import type { SelectHTMLAttributes } from 'react';
import { forwardRef } from 'react';

import { controlHeight, focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/**
 * A styled native `<select>`. Native on purpose: it is fully keyboard, touch, and screen-reader
 * accessible for free. Pass `className="w-full"` inside a stacked form; in a filter row it sizes to
 * its content.
 *
 * The height is the shared control height, set explicitly with no vertical padding: a native
 * select's content-driven height moved with the font (WP1d measured 37 -> 38 px), so it is pinned
 * to the same value as `Input` and `Button`.
 */
export const NativeSelect = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, ...props }, ref) => (
        <select
            ref={ref}
            className={cn(
                'flex rounded-control border border-control-edge bg-surface px-3 py-0 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control',
                controlHeight.md,
                focusRing,
                className,
            )}
            {...props}
        />
    ),
);

NativeSelect.displayName = 'NativeSelect';
