import type { InputHTMLAttributes } from 'react';
import { forwardRef } from 'react';

import { controlHeight, focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/**
 * Text field. Height is explicit (`controlHeight.md`). The resting edge is `control-edge`, the
 * dedicated interactive-boundary token: at least 3:1 against every surface a field sits on (canvas,
 * surface, drawer, sunken and the legacy page/card backgrounds), which is what WCAG 1.4.11 asks of a
 * control's visible boundary. `rule-control` is a structural hairline and is deliberately not used
 * here. Hover strengthens the edge to `text-muted`; `aria-invalid="true"` turns it `danger`; focus is
 * the 2px outline, so all three read differently from rest. Disabled fields drop to `surface-sunken`
 * but keep their value at `text-muted`: it is still information.
 */
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
    ({ className, ...props }, ref) => (
        <input
            ref={ref}
            className={cn(
                'flex w-full min-w-0 rounded-control border border-control-edge bg-surface px-3 text-sm text-text transition-[color,background-color,border-color] duration-motion-fast ease-motion placeholder:text-text-muted hover:border-text-muted aria-invalid:border-danger disabled:cursor-not-allowed disabled:border-rule-control disabled:bg-surface-sunken disabled:text-text-muted disabled:hover:border-rule-control',
                controlHeight.md,
                focusRing,
                className,
            )}
            {...props}
        />
    ),
);

Input.displayName = 'Input';
