import { Link } from '@inertiajs/react';
import { forwardRef } from 'react';
import type { AnchorHTMLAttributes, ComponentProps } from 'react';

import type { VisitMode } from '@/types';

type ShellLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string;
    /**
     * The server's per-destination navigation mode (EPIC-013 §11.4). `inertia` destinations are
     * client visits into the persistent shell; `document` destinations are still Blade and need a
     * full page load, which unmounts React and lets the Blade shell render. The two are not
     * interchangeable, so this component never guesses — it renders what the contract says.
     */
    visit: VisitMode;
};

/**
 * The one place the shell turns a server destination into a link.
 *
 * It replaces the pre-WP4 `NavigationLink`, which carried its own active styling; here styling is
 * the caller's (a rail tile and a drawer row look nothing alike) and this component owns only the
 * visit-mode decision.
 */
export const ShellLink = forwardRef<HTMLAnchorElement, ShellLinkProps>(
    ({ href, visit, children, ...props }, ref) => {
        if (visit === 'inertia') {
            const inertiaProps = props as unknown as ComponentProps<typeof Link>;

            return (
                <Link {...inertiaProps} href={href} ref={ref}>
                    {children}
                </Link>
            );
        }

        return (
            <a href={href} ref={ref} {...props}>
                {children}
            </a>
        );
    },
);

ShellLink.displayName = 'ShellLink';
