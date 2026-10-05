import type { Stage } from '@/components/ui/stage-path';
import { formatDate } from '@/lib/dates';
import type { MilestoneItem } from '@/types/projects';

/**
 * EPIC-015 §14.2 — milestones as `StagePath` stages, shared by the Overview (compact) and the
 * Milestones page (full), so the two never map them differently.
 *
 * Stages keep the server's order (`due_date`, `id`). **Explicit completion** (`completedAt`) is `done`;
 * the server's `currentId` (the first incomplete milestone) is `current`; every other incomplete one is
 * `planned`. Task progress never sets a state: a milestone whose linked tasks are all done but which
 * nobody completed is still `current` or `planned`. Overdue is the server's flag, shown in the meta.
 */
export function milestoneStages(items: MilestoneItem[], currentId: number | null): Stage[] {
    return items.map((item) => ({
        key: String(item.id),
        label: item.name,
        state: item.completedAt ? 'done' : item.id === currentId ? 'current' : 'planned',
        meta: item.overdue
            ? `Overdue · due ${formatDate(item.dueDate)}`
            : `Due ${formatDate(item.dueDate)}`,
    }));
}

/**
 * A milestone's linked-task progress, worded as task progress (EPIC-015 Q2, carried WP1 finding). A
 * milestone is complete only when someone completed it, so "4 of 4 linked tasks done" never reads as
 * "100% complete" while the milestone is still open or overdue.
 */
export function milestoneTaskText(item: Pick<MilestoneItem, 'taskCount' | 'doneCount'>): string {
    if (item.taskCount === 0) return 'No linked tasks';

    return `${item.doneCount} of ${item.taskCount} linked ${item.taskCount === 1 ? 'task' : 'tasks'} done`;
}
