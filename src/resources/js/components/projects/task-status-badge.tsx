import { Status } from '@/components/ui/status';
import type { TaskStatusDto } from '@/types/projects';

/** The shared status DTO, rendered the same way wherever a task shows its status (EPIC-011E §15). */
export function TaskStatusBadge({ status }: { status: TaskStatusDto }) {
    return <Status tone={status.done ? 'success' : 'neutral'}>{status.label}</Status>;
}
