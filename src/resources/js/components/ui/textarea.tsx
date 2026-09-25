import type { TextareaHTMLAttributes } from 'react';
import { forwardRef } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/** Multi-line field: the `Input` treatment with a minimum height instead of a fixed one. */
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
    ({ className, ...props }, ref) => (
        <textarea
            ref={ref}
            className={cn(
                'flex min-h-24 w-full resize-y rounded-control border border-control-edge bg-surface px-3 py-2 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion placeholder:text-text-muted hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control',
                focusRing,
                className,
            )}
            {...props}
        />
    ),
);

Textarea.displayName = 'Textarea';
