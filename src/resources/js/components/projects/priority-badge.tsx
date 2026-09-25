import { Badge } from '@/components/ui/badge';
import type { TaskPriority } from '@/types/projects';

const variants = {
    low: 'neutral',
    medium: 'info',
    high: 'warning',
    critical: 'danger',
} as const satisfies Record<TaskPriority, 'neutral' | 'info' | 'warning' | 'danger'>;

const labels: Record<TaskPriority, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export function PriorityBadge({ priority }: { priority: TaskPriority }) {
    return <Badge variant={variants[priority] ?? 'neutral'}>{labels[priority] ?? priority}</Badge>;
}
