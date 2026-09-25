import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * Person identity mark (EPIC-013 WP2, docs/design/direction-d-design-system.md §13.1): a circle at
 * every size, initials in Plex Sans 600 on a neutral surface. The shape never encodes a role, and
 * there is no per-person hue. Photos are not supported yet: nothing in the app has one.
 *
 * The visible label is initials only, so the person's name is exposed as the accessible name
 * (`role="img"`), never the email. Where the name is already printed next to the avatar, pass
 * `decorative` so it is not announced twice.
 */
const avatarVariants = cva(
    'inline-flex shrink-0 select-none items-center justify-center rounded-full border border-rule-control bg-surface-sunken font-semibold text-text',
    {
        variants: {
            size: {
                sm: 'h-6 w-6 text-[10px]',
                md: 'h-7 w-7 text-xs',
                lg: 'h-9 w-9 text-sm',
            },
        },
        defaultVariants: { size: 'md' },
    },
);

/** First letter of the first and last word, upper-cased; `?` when the name has no letters. */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const first = words[0];
    if (!first) return '?';

    const last = words.length > 1 ? words[words.length - 1] : undefined;
    const initial = (word: string | undefined) => (word ? (Array.from(word)[0] ?? '') : '');

    return (initial(first) + initial(last)).toUpperCase();
}

type AvatarProps = Omit<HTMLAttributes<HTMLSpanElement>, 'children'> &
    VariantProps<typeof avatarVariants> & {
        name: string;
        /** Hide from assistive technology when the name is already shown beside the avatar. */
        decorative?: boolean;
    };

export function Avatar({ name, size, decorative = false, className, ...props }: AvatarProps) {
    return (
        <span
            className={cn(avatarVariants({ size }), className)}
            {...(decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': name })}
            {...props}
        >
            {initialsOf(name)}
        </span>
    );
}
