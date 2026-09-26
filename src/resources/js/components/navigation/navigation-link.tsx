/**
 * TEMPORARY: the pre-WP4 shell's link. Deleted in WP4 with `app-layout.tsx`'s sticky header, which
 * `RailItem` and `DrawerItem` replace. It renders the flat compatibility shape
 * (`LegacyNavigationItem`), never the canonical `Workspace`/`ContextItem` contract.
 */
import { Link } from '@inertiajs/react';
import { forwardRef } from 'react';
import type { AnchorHTMLAttributes, ComponentProps, MouseEvent } from 'react';

import { cn } from '@/lib/utils';
import type { LegacyNavigationItem } from '@/types';

type NavigationLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    item: LegacyNavigationItem;
    onNavigate?: () => void;
};

export const NavigationLink = forwardRef<HTMLAnchorElement, NavigationLinkProps>(
    ({ item, className, onNavigate, onClick, ...props }, ref) => {
        const classes = cn(
            'rounded-md px-3 py-2 text-sm font-medium transition-colors',
            item.isActive
                ? 'bg-muted text-foreground'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            className,
        );
        const handleClick = (event: MouseEvent<Element>) => {
            onClick?.(event as MouseEvent<HTMLAnchorElement>);
            onNavigate?.();
        };

        if (item.visit === 'inertia') {
            const inertiaProps = props as unknown as ComponentProps<typeof Link>;

            return (
                <Link
                    {...inertiaProps}
                    href={item.href}
                    className={classes}
                    onClick={handleClick}
                    ref={ref}
                >
                    {item.label}
                </Link>
            );
        }

        return (
            <a href={item.href} className={classes} onClick={handleClick} ref={ref} {...props}>
                {item.label}
            </a>
        );
    },
);

NavigationLink.displayName = 'NavigationLink';
