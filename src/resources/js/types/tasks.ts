import type { TaskPriority, TaskStatusDto, UserRef } from '@/types/projects';

/** Which of the three shapes sharing the `tasks` table a row is (`Task::kind()`, EPIC-011E §15). */
export type TaskKind = 'project' | 'ticket' | 'standalone';

/**
 * Where a row's context (project/ticket name, or "Standalone") points, if anywhere. `null`
 * means the viewer's own policy would deny that destination (D2/§16) or, for a standalone task,
 * that no such destination exists at all (D3) — never a placeholder link.
 */
export type TaskContext = {
    kind: TaskKind;
    label: string;
    url: string | null;
};

/**
 * One row of the unified `/tasks` list (TaskListPresenter, EPIC-011E §5, WP8). The fields here
 * are genuinely shared by every kind (id, title, priority, status, due date, assignee); `context`
 * and `url` are the only kind-scoped parts, and both are computed server-side from the same
 * checks their destination route enforces — React never guesses a visit mode from a URL shape.
 * A standalone row's `context.url` and `url` are always `null` (no detail page exists, D3); a
 * ticket row's `url` is always `null` (its own "page" is `context.url`, the ticket itself).
 */
export type TaskRow = {
    id: number;
    title: string;
    priority: TaskPriority;
    status: TaskStatusDto;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    assignee: UserRef | null;
    context: TaskContext;
    /** The task's own detail destination — only ever set for a project-board task. */
    url: string | null;
};

/** Labelled vocabulary for the standalone-task create form (§15: the server names the options). */
export type TaskCreateOptions = {
    priorities: { value: TaskPriority; label: string }[];
    statuses: { value: 'todo' | 'in_progress' | 'done'; label: string }[];
};
