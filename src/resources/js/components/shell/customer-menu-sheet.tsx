import * as Dialog from '@radix-ui/react-dialog';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { Workspace } from '@/types';

import { ShellLink } from './shell-link';

/**
 * The customer menu sheet (Direction D §5.2, §7): below 900px the top bar's links fold into this
 * sheet, from the same server payload. It holds the Focused links only — the account trigger stays in
 * the bar — and carries no contextual section, because the Focused shell has no drawer.
 *
 * Radix `Dialog` supplies the focus trap, `Esc`, outside-click and focus return, exactly as the
 * operator `NavSheet` uses it. The width is a one-off geometry rule in `app.css`
 * (`[data-shell-sheet='focused']`), not a utility here: Radix portals the content to `<body>`, so the
 * rule is keyed on the attribute rather than nested under the shell root.
 */
export function CustomerMenuSheet({ workspaces }: { workspaces: Workspace[] }) {
    const [open, setOpen] = useState(false);

    /**
     * Navigating closes the sheet so it never covers the page it just opened. A handler rather than an
     * effect on the current workspace: activating a link is the actual cause, and on a `document`
     * destination the page unloads regardless.
     */
    function onNavigate(event: React.MouseEvent<HTMLElement>) {
        if ((event.target as Element | null)?.closest('a')) {
            setOpen(false);
        }
    }

    return (
        <Dialog.Root open={open} onOpenChange={setOpen}>
            <Dialog.Trigger
                data-shell-sheet-trigger
                className={cn(
                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-control text-text-secondary transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text',
                    focusRing,
                )}
                aria-label="Open navigation"
            >
                <Menu className="h-5 w-5" aria-hidden="true" />
            </Dialog.Trigger>

            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-40 bg-scrim data-[state=closed]:animate-overlay-out data-[state=open]:animate-overlay-in" />
                <Dialog.Content
                    data-shell-sheet="focused"
                    className="fixed inset-y-0 right-0 z-50 flex flex-col overflow-y-auto border-l border-rule bg-surface data-[state=closed]:animate-overlay-out data-[state=open]:animate-overlay-in"
                >
                    <div className="flex h-14 shrink-0 items-center justify-between border-b border-rule px-3">
                        <Dialog.Title className="text-sm font-semibold text-text">
                            Navigation
                        </Dialog.Title>
                        <Dialog.Close
                            className={cn(
                                'flex h-11 w-11 items-center justify-center rounded-control text-text-muted hover:bg-surface-hover hover:text-text',
                                focusRing,
                            )}
                            aria-label="Close navigation"
                        >
                            <X className="h-5 w-5" aria-hidden="true" />
                        </Dialog.Close>
                    </div>

                    <nav
                        aria-label="Main"
                        className="flex flex-col gap-0.5 px-2 py-3"
                        onClick={onNavigate}
                    >
                        {workspaces.map((workspace) => (
                            <ShellLink
                                key={workspace.key}
                                href={workspace.href}
                                visit={workspace.visit}
                                aria-current={workspace.isActive ? 'page' : undefined}
                                data-shell-nav-row
                                className={cn(
                                    'flex min-h-11 items-center rounded-control px-2.5 text-sm',
                                    workspace.isActive
                                        ? 'bg-surface-selected font-medium text-text ring-1 ring-rule'
                                        : 'text-text-secondary hover:bg-surface-hover hover:text-text',
                                    focusRing,
                                )}
                            >
                                {workspace.label}
                            </ShellLink>
                        ))}
                    </nav>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
