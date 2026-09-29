import { cva, type VariantProps } from 'class-variance-authority';
import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * Direction D banner (EPIC-013 WP2, §15.3). Four semantic variants plus a plain `neutral` panel
 * (the pre-existing use: an empty-state or explanatory block, no glyph).
 *
 * Meaning never rests on colour alone: each semantic variant carries its own glyph and an
 * `sr-only` kind label, the body stays `text` ink on a soft tint, and the glyph takes the
 * semantic text colour (which is AA on its own tint). `success` has no soft token, so it sits on
 * `surface`.
 *
 * Roles: `danger` is `alert` (assertive; genuine errors only); `info`, `success` and `warning` are
 * `status` (polite). `neutral` has no live-region role. Pass `role` to override.
 */
const alertVariants = cva('rounded-control border px-4 py-3 text-sm text-text', {
    variants: {
        variant: {
            neutral: 'border-rule-control bg-surface',
            info: 'border-accent-line/40 bg-accent-soft',
            success: 'border-rule-control bg-surface',
            warning: 'border-warning-glyph/50 bg-warning-soft',
            danger: 'border-danger/50 bg-danger-soft',
        },
    },
    defaultVariants: { variant: 'neutral' },
});

const glyphs = {
    info: { Icon: Info, className: 'text-accent', label: 'Notice', role: 'status' },
    success: { Icon: CircleCheck, className: 'text-success', label: 'Success', role: 'status' },
    warning: { Icon: TriangleAlert, className: 'text-warning', label: 'Warning', role: 'status' },
    danger: { Icon: CircleAlert, className: 'text-danger', label: 'Error', role: 'alert' },
} as const;

type AlertProps = HTMLAttributes<HTMLDivElement> &
    VariantProps<typeof alertVariants> & {
        /** Trailing control, e.g. a dismiss button. Rendered outside the message. */
        action?: ReactNode;
    };

export function Alert({ className, variant, role, action, children, ...props }: AlertProps) {
    const kind = variant && variant !== 'neutral' ? glyphs[variant] : null;

    return (
        <div
            role={role ?? kind?.role}
            className={cn(alertVariants({ variant }), kind && 'flex items-start gap-3', className)}
            {...props}
        >
            {kind ? (
                <>
                    <kind.Icon
                        className={cn('mt-0.5 h-4 w-4 shrink-0', kind.className)}
                        aria-hidden="true"
                    />
                    <span className="sr-only">{kind.label}: </span>
                    <div className="min-w-0 flex-1">{children}</div>
                </>
            ) : (
                children
            )}
            {action}
        </div>
    );
}
