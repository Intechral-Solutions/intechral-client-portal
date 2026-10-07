import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';

import { controlHeight, focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/**
 * Direction D button contract (EPIC-013 WP2, docs/design/direction-d-design-system.md §2.3.5):
 *
 * - `primary` (alias `default`): the region's one primary action, filled with `ink`.
 * - `secondary` (alias `outline`): `surface` fill with a `control-edge` border (its
 *   only non-text affordance, so it must reach 3:1; `rule-control` is too faint for it).
 * - `ghost`: toolbar and icon actions, no resting chrome.
 * - `danger-secondary`: a destructive TRIGGER (P4): the `secondary` surface with `danger` text and edge. It never
 *   fills, so opening a confirmation does not look like confirming.
 * - `destructive`: `danger`; fills solid, so it is used for the confirming action only.
 *
 * `default` and `outline` are the pre-Direction D names; they stay so the roughly 100 existing
 * call sites need no churn. The border is always present (transparent when the variant has no
 * visible one) so every variant is exactly the same height and width for the same content. No
 * variant repeats a class another one sets (`buttonVariants` is also used directly as a class
 * string on links, where nothing merges duplicates).
 * Disabled buttons drop the fill and keep their label at `text-muted` (4.5:1+, since the label still
 * carries information), so they read as unavailable without a global opacity.
 */
const primary =
    'border-transparent bg-ink text-on-ink hover:bg-ink/90 active:bg-ink/80 disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted';
const secondary =
    'border-control-edge bg-surface text-text hover:bg-surface-hover active:bg-surface-sunken disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted';

const dangerSecondary =
    'border-danger bg-surface text-danger hover:bg-surface-hover active:bg-surface-sunken disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted';

const buttonVariants = cva(
    [
        'inline-flex items-center justify-center gap-2 border font-medium transition-[color,background-color,border-color] duration-motion-fast ease-motion disabled:pointer-events-none',
        focusRing,
    ],
    {
        variants: {
            variant: {
                primary,
                default: primary,
                secondary,
                outline: secondary,
                'danger-secondary': dangerSecondary,
                ghost: 'border-transparent text-text hover:bg-surface-hover active:bg-surface-sunken disabled:text-text-muted',
                destructive:
                    'border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/90 active:bg-destructive/80 disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted',
            },
            size: {
                sm: `${controlHeight.sm} rounded-control px-2.5 text-xs`,
                md: `${controlHeight.md} rounded-control px-3 text-sm`,
                default: `${controlHeight.md} rounded-control px-3 text-sm`,
                lg: `${controlHeight.lg} rounded-control-lg px-4 text-sm`,
                icon: 'size-9 pointer-coarse:size-11 rounded-control p-0 text-sm',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
);

// ComponentProps (not ButtonHTMLAttributes) so `ref` is accepted: React 19 passes it as a prop.
type ButtonProps = ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    };

export function Button({ className, variant, size, asChild, ...props }: ButtonProps) {
    const Component = asChild ? Slot : 'button';

    return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

export { buttonVariants };
