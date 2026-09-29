import { PanelLeftClose, Pin, Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { ContextItem, ContextSection, Workspace } from '@/types';

import { ShellLink } from './shell-link';

/**
 * The 248px contextual drawer (Direction D §5.4, §6, §8).
 *
 * **It projects the workspace's `context`; it does not own it** (EPIC-013 §23.2). Sections arrive
 * ordered, labelled, already capability-filtered and already pruned of empty groups, so the drawer
 * renders what it is given in the order given. It reads `presentation` for nothing except its
 * open/collapsed default — the caller resolves that — so dropping the whole `presentation` key would
 * still render every section and item.
 *
 * This is the only place the 2px `accent-line` strip appears (§8).
 */
export function Drawer({
    workspace,
    pinned,
    autoFocus,
    onClose,
    onPin,
}: {
    workspace: Workspace;
    pinned: boolean;
    /**
     * True when the user just opened the panel, false when it is simply the default state.
     *
     * Whether the panel is docked or floating is a width question that CSS answers (§16), so this
     * component never asks how wide the viewport is. Focus moves in on a deliberate open, which is
     * what Direction D §14.2 wants for the overlay and is harmless when it docks.
     */
    autoFocus: boolean;
    onClose: () => void;
    onPin: () => void;
}) {
    const panel = useRef<HTMLDivElement | null>(null);

    // Nothing is trapped: the drawer is navigation, not a dialog, so §25.3 flow 4 asks for focus
    // return rather than a trap. Focus return to the toggle is the caller's job, because the caller
    // owns the toggle.
    useEffect(() => {
        if (!autoFocus) {
            return;
        }

        const current = panel.current?.querySelector<HTMLElement>('[aria-current="page"]');
        (current ?? panel.current?.querySelector<HTMLElement>('a, button'))?.focus();
    }, [autoFocus, workspace.key]);

    return (
        <div id="shell-drawer" data-shell-drawer ref={panel}>
            <div className="flex h-12 shrink-0 items-center gap-1 border-b border-rule px-3">
                {/* Not a heading: the workspace name is already the rail's current item, the
                    breadcrumb's first segment and this panel's own `nav` label. Making it an `<h2>`
                    put a second "Projects" in the document's heading outline, directly above the
                    page's own `<h1>`, which is noise for anyone navigating by heading. */}
                <p className="min-w-0 flex-1 truncate text-xs font-semibold tracking-wide text-text-muted uppercase">
                    {workspace.label}
                </p>

                {!pinned ? (
                    <button
                        type="button"
                        data-shell-pin
                        onClick={onPin}
                        className={cn(
                            'flex h-7 w-7 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text',
                            focusRing,
                        )}
                    >
                        <Pin className="h-3.5 w-3.5" aria-hidden="true" />
                        <span className="sr-only">Pin workspace views</span>
                    </button>
                ) : null}

                <button
                    type="button"
                    data-shell-collapse
                    onClick={onClose}
                    aria-expanded={true}
                    aria-controls="shell-drawer"
                    className={cn(
                        'flex h-7 w-7 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text',
                        focusRing,
                    )}
                >
                    <PanelLeftClose className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="sr-only">Collapse workspace views</span>
                </button>
            </div>

            <nav aria-label={`${workspace.label} views`} className="flex flex-col gap-4 px-2 py-3">
                {workspace.context.map((section) => (
                    <DrawerSection key={section.key} section={section} />
                ))}
            </nav>
        </div>
    );
}

export function DrawerSection({ section }: { section: ContextSection }) {
    const labelId = `shell-drawer-${section.key}`;

    return (
        // A labelled group rather than a heading: these are link-group labels inside a navigation
        // landmark, so `role="group"` conveys the grouping without adding to the heading outline.
        <div role="group" aria-labelledby={section.label ? labelId : undefined}>
            {section.label ? (
                <p
                    id={labelId}
                    className="px-2 pb-1.5 text-[11px] font-semibold tracking-wide text-text-muted uppercase"
                >
                    {section.label}
                </p>
            ) : null}

            <div className="flex flex-col gap-0.5">
                {section.items.map((item) => (
                    // An unknown `kind` falls through to the plain row rather than throwing
                    // (§12.2), and `actions` are rendered as actions rather than destinations.
                    <DrawerItem key={item.key} item={item} action={section.kind === 'actions'} />
                ))}
            </div>
        </div>
    );
}

export function DrawerItem({ item, action }: { item: ContextItem; action: boolean }) {
    return (
        <ShellLink
            href={item.href}
            visit={item.visit}
            // WP3 guarantees an action is never active; honouring `isActive` unconditionally would
            // put the strip on "New project" the moment that ever regressed, so the presentation
            // refuses it too (A1.10 requirement 2).
            aria-current={!action && item.isActive ? 'page' : undefined}
            data-shell-nav-row
            className={cn(
                'relative flex items-center gap-2 rounded-control py-1.5 pr-2 pl-3 text-sm transition-colors duration-motion-fast',
                !action && item.isActive
                    ? 'bg-surface-selected font-medium text-text ring-1 ring-rule before:absolute before:inset-y-1 before:left-0 before:w-0.5 before:rounded-full before:bg-accent-line before:content-[""]'
                    : 'text-text-secondary hover:bg-surface-hover hover:text-text',
                action && 'text-accent',
                focusRing,
            )}
        >
            {/* The affordance is an icon, not text: prefixing the label would rewrite the server's
                own words and change the control's accessible name. */}
            {action ? <Plus className="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> : null}
            <span className="min-w-0 flex-1 truncate">{item.label}</span>

            {/* Reserved and always null in this epic (§12.3 rule 8): the slot exists so a later
                epic adds data, not a field. Operational metadata never becomes an item. */}
            {item.count === null ? null : (
                <span className="shrink-0 font-mono text-xs text-text-muted">{item.count}</span>
            )}
        </ShellLink>
    );
}
