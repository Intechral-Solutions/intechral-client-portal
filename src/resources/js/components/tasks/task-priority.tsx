import { Priority } from '@/components/ui/priority';
import type { TaskPriority } from '@/types/projects';

/** Direction D §10.3: how many bars a priority earns. The domain decides; `Priority` only draws. */
const bars: Record<TaskPriority, 1 | 2 | 3> = { low: 1, medium: 2, high: 3, critical: 3 };

const fallbackLabels: Record<TaskPriority, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

/**
 * A task's priority mark, the same on the list and on the detail pages. The list passes the server's
 * label (INV-19); a detail page may not have the form options (a viewer who cannot edit gets none), so
 * the label falls back to the plain name of the value, as `PriorityBadge` did before it.
 */
export function TaskPriorityMark({ priority, label }: { priority: TaskPriority; label?: string }) {
    return (
        <Priority bars={bars[priority]} tone={priority === 'critical' ? 'danger' : 'neutral'}>
            {label ?? fallbackLabels[priority] ?? priority}
        </Priority>
    );
}
