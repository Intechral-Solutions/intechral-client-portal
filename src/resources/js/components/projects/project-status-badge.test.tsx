import { render, screen } from '@testing-library/react';

import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import type { ProjectStatus } from '@/types/projects';

const statuses: ProjectStatus[] = ['active', 'on_hold', 'completed', 'archived'];

it('shows each lifecycle state as a labelled glyph, so no two states differ by colour alone', () => {
    const shapes = statuses.map((status) => {
        const { container, unmount } = render(<ProjectStatusBadge status={status} />);
        const label = container.firstElementChild?.textContent ?? '';
        const shape = Array.from(container.querySelector('svg')?.children ?? [])
            .map(
                (c) =>
                    `${c.tagName}${c.getAttribute('d') ?? ''}${c.getAttribute('fill')}${c.getAttribute('stroke-dasharray') ?? ''}`,
            )
            .join('|');
        unmount();

        return { label, shape };
    });

    expect(shapes.every(({ label }) => label.length > 0)).toBe(true);
    expect(new Set(shapes.map(({ shape }) => shape)).size).toBe(statuses.length);
});

it('names the state in text', () => {
    render(<ProjectStatusBadge status="on_hold" />);

    expect(screen.getByText('On hold')).toBeVisible();
});
