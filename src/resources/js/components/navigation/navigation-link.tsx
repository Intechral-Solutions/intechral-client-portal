import { Link } from '@inertiajs/react';
import { forwardRef } from 'react';
import type { AnchorHTMLAttributes, ComponentProps, MouseEvent } from 'react';

import { cn } from '@/lib/utils';
import type { NavigationItem } from '@/types';

type NavigationLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    item: NavigationItem;
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
