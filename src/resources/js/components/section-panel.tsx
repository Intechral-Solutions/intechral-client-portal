import type { ReactNode } from 'react';

import { Section } from '@/components/section';

type SectionPanelProps = {
    /** An in-page anchor target; passed to `Section`, which adds the scroll margin. */
    id?: string;
    title: string;
    description?: string;
    children: ReactNode;
};

/**
 * The existing two-column form section (title left, content right), kept as-is for its 26
 * consumers and now a thin wrapper over `Section layout="split"`. New code uses `Section`
 * directly; pages move over as they are adopted (EPIC-013 WP2 does not migrate them).
 */
export function SectionPanel({ id, title, description, children }: SectionPanelProps) {
    return (
        <Section layout="split" id={id} title={title} description={description}>
            {children}
        </Section>
    );
}
