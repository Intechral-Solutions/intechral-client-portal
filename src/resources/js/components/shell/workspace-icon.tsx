import {
    Clock,
    Contact,
    FolderKanban,
    Handshake,
    House,
    Library,
    LifeBuoy,
    ListChecks,
    Receipt,
    Settings,
} from 'lucide-react';
import type { ComponentType } from 'react';

import type { NavigationIcon } from '@/types';

/**
 * Icon keys → components (EPIC-013 §12.3 rule 2).
 *
 * The server sends a stable key, never markup, and the mapping is client presentation metadata
 * keyed by that key — never derived from the label, which is display text and may be relabelled.
 * The map is explicit and exhaustive for every key `NavigationBuilder` emits today; an unknown key
 * renders the documented neutral fallback rather than throwing.
 */
const icons: Record<string, ComponentType<{ className?: string; 'aria-hidden'?: boolean }>> = {
    house: House,
    'folder-kanban': FolderKanban,
    'list-checks': ListChecks,
    'life-buoy': LifeBuoy,
    clock: Clock,
    contact: Contact,
    receipt: Receipt,
    settings: Settings,
    library: Library,
};

/** The neutral fallback for a key this client does not know. */
const fallback = Handshake;

export function WorkspaceIcon({ icon, className }: { icon: NavigationIcon; className?: string }) {
    const Icon = icons[icon] ?? fallback;

    return <Icon className={className} aria-hidden={true} />;
}

/** Exposed for the test that keeps the map in step with the server's key set. */
export const workspaceIconKeys = Object.keys(icons);
