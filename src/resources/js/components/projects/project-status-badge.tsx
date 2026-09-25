import { projectStatusLabel } from '@/components/projects/project-status';
import { Status, type StatusGlyph, type StatusTone } from '@/components/ui/status';
import type { ProjectStatus } from '@/types/projects';

/** Project lifecycle, not health: a state mark, so it uses `Status` (glyph + label). */
const marks = {
    active: { tone: 'success', glyph: 'dot' },
    on_hold: { tone: 'warning', glyph: 'dashed' },
    completed: { tone: 'success', glyph: 'check' },
    archived: { tone: 'neutral', glyph: 'circle' },
} as const satisfies Record<ProjectStatus, { tone: StatusTone; glyph: StatusGlyph }>;

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
    const mark = marks[status] ?? marks.archived;

    return (
        <Status tone={mark.tone} glyph={mark.glyph}>
            {projectStatusLabel(status)}
        </Status>
    );
}
