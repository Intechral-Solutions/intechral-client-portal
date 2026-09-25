import { Badge } from '@/components/ui/badge';
import type { TaskStatusDto } from '@/types/projects';

/** The shared status DTO, rendered the same way wherever a task shows its status (EPIC-011E §15). */
export function TaskStatusBadge({ status }: { status: TaskStatusDto }) {
    return <Badge variant={status.done ? 'success' : 'neutral'}>{status.label}</Badge>;
}
