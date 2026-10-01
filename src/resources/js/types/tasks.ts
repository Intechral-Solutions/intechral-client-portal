import type { TaskPriority, TaskStatusDto, UserRef } from '@/types/projects';

/**
 * The context a Tasks-workspace row belongs to. The workspace surfaces exactly two kinds
 * (EPIC-014 Q6): ticket-kind tasks never reach this list, so no `ticket` context exists here.
 */
export type TaskKind = 'project' | 'standalone';

/**
 * A row's own kind, as `Task::kind()` names it (`board` for a project-board task). This is NOT the
 * filter's vocabulary: the `kind` filter and `context.kind` say `project`, a row says `board`, so
 * never compare `row.kind` with `filters.kind` directly.
 */
export type TaskRowKind = 'board' | 'standalone';

/**
 * Where a row's context (project name, or "Standalone") points, if anywhere. `null` means the
 * viewer's own policy would deny that destination (D2/§16) or, for a standalone task, that no
 * such destination exists yet (its detail page ships with EPIC-014 WP5) — never a placeholder.
 */
export type TaskContext = {
    kind: TaskKind;
    label: string;
    url: string | null;
};

/**
 * What the viewer may do to a row, computed by the server in one batch per page (EPIC-014
 * §13.3). React renders controls from these and never derives authority from roles; the routes
 * still authorize every request themselves.
 */
export type TaskRowAbilities = {
    complete: boolean;
    reopen: boolean;
    assign: boolean;
};

/**
 * One row of the Tasks workspace list (TaskListPresenter; EPIC-011E §5, EPIC-014 §13.3). The
 * fields here are genuinely shared by both surfaced kinds (id, title, priority, status, due
 * date, assignee); `context` and `url` are the kind-scoped parts, and both are computed
 * server-side from the same checks their destination route enforces — React never guesses a
 * visit mode from a URL shape. A standalone row's `context.url` and `url` are `null` until its
 * detail page exists (EPIC-014 WP5).
 */
export type TaskRow = {
    id: number;
    title: string;
    kind: TaskRowKind;
    priority: TaskPriority;
    status: TaskStatusDto;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    assignee: UserRef | null;
    context: TaskContext;
    /** The task's own detail destination — only ever set for a project-board task (for now). */
    url: string | null;
    abilities: TaskRowAbilities;
};

/** The two Tasks views (EPIC-014 §9.4); the server decides which one is being served. */
export type TaskView = 'mine' | 'all';

/**
 * The list's URL state as the server normalized it (EPIC-014 §9.5): unknown values are already
 * dropped, and every id is one the viewer was offered in `TaskFilterOptions`.
 */
export type TaskListFilters = {
    completion: 'open' | 'done' | 'any';
    priority: TaskPriority[];
    due: 'overdue' | 'today' | 'next7' | 'none' | null;
    kind: TaskKind | null;
    project: number | null;
    milestone: number | null;
    assignee: number | 'none' | null;
    organization: number | null;
    q: string;
};

export type TaskListSort = {
    by: 'due' | 'priority' | 'title' | 'updated' | 'created';
    dir: 'asc' | 'desc';
};

type Labelled<T extends string> = { value: T; label: string };
type NamedOption = { id: number; name: string };

/**
 * Server-named filter vocabulary and options, each drawn from what the viewer may already see
 * (INV-17, INV-19). `milestones` is non-empty only while exactly one project is selected;
 * `assignees` only in All Tasks.
 */
export type TaskFilterOptions = {
    completion: Labelled<TaskListFilters['completion']>[];
    priorities: Labelled<TaskPriority>[];
    due: Labelled<NonNullable<TaskListFilters['due']>>[];
    kinds: Labelled<TaskKind>[];
    sorts: Labelled<TaskListSort['by']>[];
    projects: NamedOption[];
    milestones: NamedOption[];
    assignees: NamedOption[];
    organizations: NamedOption[];
};

/** Labelled vocabulary for the standalone-task create form (§15: the server names the options). */
export type TaskCreateOptions = {
    priorities: { value: TaskPriority; label: string }[];
    statuses: { value: 'todo' | 'in_progress' | 'done'; label: string }[];
};
