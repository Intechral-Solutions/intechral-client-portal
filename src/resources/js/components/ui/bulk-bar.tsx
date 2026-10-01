import type { KeyboardEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';

/**
 * EPIC-014 WP4 — the floating bulk-action bar (Direction D §4.4 level 2, §18). It exists only while
 * something is selected, states how many, hosts the caller's actions and can drop the selection (the
 * button, or `Esc` from inside the bar — §14.3's "clear selection"). It is a named toolbar and the
 * count is a polite live region, so a screen reader hears the selection change without the bar moving
 * focus. It knows nothing about what is selected or what the actions do.
 */
export function BulkBar({
    count,
    noun = 'item',
    label,
    onClear,
    children,
}: {
    count: number;
    /** Singular; the plural adds `s`. */
    noun?: string;
    label: string;
    onClear: () => void;
    children: ReactNode;
}) {
    if (count < 1) return null;

    function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            onClear();
        }
    }

    return (
        <div
            role="toolbar"
            aria-label={label}
            onKeyDown={onKeyDown}
            className="fixed inset-x-4 bottom-4 z-40 mx-auto flex max-w-fit flex-wrap items-center gap-2 rounded-overlay bg-surface px-3 py-2 text-sm text-text shadow-overlay"
        >
            <span aria-live="polite" className="px-1 font-medium">
                {count} {count === 1 ? noun : `${noun}s`} selected
            </span>
            {children}
            <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                Clear selection
            </Button>
        </div>
    );
}
