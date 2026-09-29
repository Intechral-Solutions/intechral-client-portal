import * as Dialog from '@radix-ui/react-dialog';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { Workspace } from '@/types';

import { ShellLink } from './shell-link';
import { WorkspaceIcon } from './workspace-icon';

/**
 * The narrow-width (S, < 768) navigation sheet (Direction D §5.2, EPIC-013 §16).
 *
 * At S the rail becomes a 56px top bar and its workspace list folds in here, together with the
 * current workspace's contextual views — the same two sets the rail and drawer show at wider
 * widths, from the same server payload. CSS removes the rail's own list and the drawer from the
 * accessibility tree at this width (`display: none`), so these links are never duplicated for
 * assistive technology.
 *
 * Radix `Dialog` supplies the focus trap, `Esc`, outside-click and focus return that a sheet needs.
 */
export function NavSheet({
    workspaces,
    current,
}: {
    workspaces: Workspace[];
    current: Workspace | null;
}) {
    const [open, setOpen] = useState(false);

    /**
     * Navigating closes the sheet, so it never covers the page it just opened. This is an event
     * handler rather than an effect on the current workspace: activating a link is the actual cause,
     * and a view within the same workspace would not change any prop an effect could watch. On a
     * `document` destination the page unloads regardless.
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
                    data-shell-sheet
                    className="fixed inset-y-0 left-0 z-50 flex w-[min(20rem,88vw)] flex-col overflow-y-auto border-r border-rule bg-drawer data-[state=closed]:animate-overlay-out data-[state=open]:animate-overlay-in"
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
                        aria-label="Workspaces"
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
                                    'flex items-center gap-2.5 rounded-control px-2.5 text-sm',
                                    workspace.isActive
                                        ? 'bg-surface-selected font-medium text-text ring-1 ring-rule'
                                        : 'text-text-secondary hover:bg-surface-hover hover:text-text',
                                    focusRing,
                                )}
                            >
                                <WorkspaceIcon
                                    icon={workspace.icon}
                                    className="h-[18px] w-[18px]"
                                />
                                {workspace.label}
                            </ShellLink>
                        ))}
                    </nav>

                    {current && current.context.length > 0 ? (
                        <nav
                            aria-label={`${current.label} views`}
                            className="flex flex-col gap-3 border-t border-rule px-2 py-3"
                            onClick={onNavigate}
                        >
                            {current.context.map((section) => (
                                <div
                                    key={section.key}
                                    role="group"
                                    aria-labelledby={
                                        section.label ? `shell-sheet-${section.key}` : undefined
                                    }
                                >
                                    {section.label ? (
                                        <p
                                            id={`shell-sheet-${section.key}`}
                                            className="px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-text-muted uppercase"
                                        >
                                            {section.label}
                                        </p>
                                    ) : null}
                                    <div className="flex flex-col gap-0.5">
                                        {section.items.map((item) => {
                                            const action = section.kind === 'actions';

                                            return (
                                                <ShellLink
                                                    key={item.key}
                                                    href={item.href}
                                                    visit={item.visit}
                                                    aria-current={
                                                        !action && item.isActive
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    data-shell-nav-row
                                                    className={cn(
                                                        'flex items-center rounded-control px-2.5 text-sm',
                                                        !action && item.isActive
                                                            ? 'bg-surface-selected font-medium text-text ring-1 ring-rule'
                                                            : 'text-text-secondary hover:bg-surface-hover hover:text-text',
                                                        action && 'text-accent',
                                                        focusRing,
                                                    )}
                                                >
                                                    {item.label}
                                                </ShellLink>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </nav>
                    ) : null}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
