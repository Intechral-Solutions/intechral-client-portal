import { PanelLeft } from 'lucide-react';
import type { ReactNode } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { Workspace } from '@/types';

import { BrandMark } from './brand-mark';
import { ShellLink } from './shell-link';
import { WorkspaceIcon } from './workspace-icon';

/**
 * The 64px workspace rail (Direction D §6, §8).
 *
 * It renders the ordered, already-authorized workspace list exactly as the server sent it: it does
 * not filter, sort, infer permissions or compute active state. A workspace key it has never seen
 * still renders, with the neutral fallback icon, because the server decides what exists.
 *
 * Plain `<a href>` links in normal Tab order — no roving tabindex and no arrow-key handlers (L11,
 * Direction D §14.3). Selected treatment is `surface-selected` + a 1px `rule` ring and **no strip**:
 * the 2px accent strip belongs to the drawer alone, so at most one appears per screen (§8).
 */
export function Rail({
    workspaces,
    showToggle,
    onToggle,
    children,
}: {
    workspaces: Workspace[];
    /** The drawer toggle appears on the rail only while the panel is collapsed (§6). */
    showToggle: boolean;
    onToggle: () => void;
    /** The account trigger and the narrow-width sheet trigger, pinned to the ends. */
    children?: ReactNode;
}) {
    return (
        <div data-shell-rail>
            <span className="flex h-9 w-9 shrink-0 items-center justify-center" data-shell-brand>
                {/* Decorative: the account trigger carries identity and the rail is already
                    labelled, so a second announcement of the brand adds nothing. */}
                <BrandMark
                    variant="compact"
                    label="Intechral"
                    className="block [&>svg]:h-7 [&>svg]:w-7"
                />
            </span>

            {showToggle ? (
                <button
                    type="button"
                    data-shell-toggle
                    onClick={onToggle}
                    aria-expanded={false}
                    aria-controls="shell-drawer"
                    className={cn(
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text',
                        focusRing,
                    )}
                >
                    <PanelLeft className="h-4 w-4" aria-hidden="true" />
                    <span className="sr-only">Show workspace views</span>
                </button>
            ) : null}

            <nav
                aria-label="Workspaces"
                data-shell-workspaces
                className="flex w-full min-w-0 flex-col items-center gap-1 px-1.5"
            >
                {workspaces.map((workspace) => (
                    <RailItem key={workspace.key} workspace={workspace} />
                ))}
            </nav>

            <div className="flex shrink-0 items-center gap-1 md:mt-auto md:flex-col">
                {children}
            </div>
        </div>
    );
}

export function RailItem({ workspace }: { workspace: Workspace }) {
    return (
        <ShellLink
            href={workspace.href}
            visit={workspace.visit}
            // Server-computed (§12.3 rule 3). Never derived from the URL here.
            aria-current={workspace.isActive ? 'page' : undefined}
            data-shell-nav-row
            className={cn(
                'flex h-[46px] w-[52px] flex-col items-center justify-center gap-1 rounded-control text-[10px] leading-none transition-colors duration-motion-fast',
                workspace.isActive
                    ? 'bg-surface-selected font-semibold text-text ring-1 ring-rule'
                    : 'text-text-muted hover:bg-surface-hover hover:text-text',
                focusRing,
            )}
        >
            <WorkspaceIcon icon={workspace.icon} className="h-[18px] w-[18px]" />
            <span className="max-w-full truncate px-0.5">{workspace.label}</span>
        </ShellLink>
    );
}
