import { Badge } from '@/components/ui/badge';
import { projectStatusLabel } from '@/components/projects/project-status';
import type { ProjectStatus } from '@/types/projects';

const variants = {
    active: 'success',
    on_hold: 'warning',
    completed: 'info',
    archived: 'neutral',
} as const satisfies Record<ProjectStatus, 'success' | 'warning' | 'info' | 'neutral'>;

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
    return <Badge variant={variants[status] ?? 'neutral'}>{projectStatusLabel(status)}</Badge>;
}
