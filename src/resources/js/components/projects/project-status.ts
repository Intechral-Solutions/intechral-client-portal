import type { ProjectStatus } from '@/types/projects';

export const PROJECT_STATUSES: { value: ProjectStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
];

export function projectStatusLabel(status: ProjectStatus): string {
    return PROJECT_STATUSES.find((option) => option.value === status)?.label ?? status;
}
