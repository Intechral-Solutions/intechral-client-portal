import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { FlashRegion } from '@/components/feedback/flash-region';
import { usePageAnnouncement } from '@/hooks/use-page-announcement';
import type { SharedPageProps } from '@/types';

import { AccountMenu } from './account-menu';
import type { BreadcrumbSegment } from './breadcrumb';
import { BrandMark } from './brand-mark';
import { CustomerMenuSheet } from './customer-menu-sheet';
import { SkipLink } from './skip-link';
import { TopNav } from './top-nav';

/**
 * The Direction D **Focused** presentation for customers (Direction D §7, EPIC-017 §5): a 60px top bar
 * (brand, navigation, account), then one centred column of at most 1080px. No rail, drawer, utility
 * bar, breadcrumb or global timer — ever. `AppShell` is the only component that chooses this over the
 * Operational shell, so this file does not look at the presentation and neither does any page.
 *
 * Its navigation input is the server's Focused projection of the `navigation` prop: it renders what it
 * is given, derives no visibility or active state, and so cannot show a destination the actor is not
 * already offered. Hiding timer chrome here is presentation, never a security boundary — which
 * contexts may receive time is decided on the server.
 *
 * `trail` is accepted so a page that supplies one works in both shells, and ignored: the customer shell
 * owns no breadcrumb (a record page renders its own back link as content).
 *
 * Geometry is CSS off `[data-shell='focused']` in `app.css`, shared with the Blade twin, so no
 * JavaScript decides layout.
 */
export function CustomerShell({ children }: PropsWithChildren<{ trail?: BreadcrumbSegment[] }>) {
    const { auth, navigation, flash } = usePage<SharedPageProps>().props;
    const announcement = usePageAnnouncement();

    return (
        <div data-shell="focused">
            <SkipLink />

            <header data-shell-topbar>
                <span data-shell-brand className="flex shrink-0 items-center gap-2">
                    {/* The mark carries the name; the visible wordmark repeats it, so it is hidden from
                        assistive technology rather than announced twice. */}
                    <BrandMark
                        variant="compact"
                        label="Intechral"
                        className="block [&>svg]:h-7 [&>svg]:w-7"
                    />
                    <span aria-hidden="true" className="text-sm font-semibold text-text">
                        Intechral
                    </span>
                </span>

                <TopNav workspaces={navigation.workspaces} />

                <div data-shell-topbar-end>
                    <CustomerMenuSheet workspaces={navigation.workspaces} />
                    {auth.user ? <AccountMenu user={auth.user} placement="topbar" /> : null}
                </div>
            </header>

            <div data-shell-column>
                <FlashRegion flash={flash} />

                {/* `tabIndex={-1}` makes this both the skip link's destination and the repair target for
                    a visit that destroyed focus (A1.8). */}
                <main id="main-content" tabIndex={-1} className="min-w-0 flex-1 outline-none">
                    {children}
                </main>

                {/* `data-shell-announcer` is the shell's own live region, as in the Operational shell. */}
                <p ref={announcement} data-shell-announcer aria-live="polite" className="sr-only" />
            </div>
        </div>
    );
}
