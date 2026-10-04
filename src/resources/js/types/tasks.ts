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
 * viewer's own policy would deny that destination (D2/§16), or that the context has none (a
 * standalone task's context is a plain label) — never a placeholder.
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
 * visit mode from a URL shape. A standalone row's `url` is its own page, `tasks.show` (EPIC-014
 * WP5); its `context.url` stays `null` because "Standalone" is a label, not a destination.
 */
export type TaskRow = {
    id: number;
    title: string;
    kind: TaskRowKind;
    /** A board row's project, the key into `TaskAssigneeOptions.projects`; `null` for a standalone row. */
    projectId: number | null;
    priority: TaskPriority;
    status: TaskStatusDto;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    assignee: UserRef | null;
    context: TaskContext;
    /** The task's own detail page: `projects.tasks.show` for a board task, `tasks.show` for a standalone one. */
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

/**
 * The per-task outcome of `POST /tasks/bulk` (EPIC-014 §15.2, `flash.bulk`): every submitted id lands in
 * exactly one bucket. An id the viewer may not act on, or that does not exist, is `notPermitted`.
 */
export type TaskBulkResult = {
    action: 'complete' | 'reopen';
    succeeded: number[];
    notPermitted: number[];
    configurationError: number[];
    failed: number[];
};

/** Labelled vocabulary for the standalone-task create form (§15: the server names the options). */
export type TaskCreateOptions = {
    priorities: { value: TaskPriority; label: string }[];
    statuses: { value: 'todo' | 'in_progress' | 'done'; label: string }[];
};

/** The labelled vocabulary the standalone create and edit forms share (INV-19). */
export type TaskFormOptions = TaskCreateOptions;

/**
 * Who a Tasks-list row may be assigned to (EPIC-014 §7.3, R6), from `TaskAssigneeOptions`. `self` is
 * the only person a standalone task may be handed to; `projects` lists, for each project the viewer may
 * assign a row in on this page, that project's current members. The server sends nothing for a project
 * whose rows the viewer may not assign, and never a departed member, so this is the whole candidate
 * pool: the browser derives no membership of its own. It is a convenience: the assign endpoint
 * authorizes and validates every request again.
 */
export type TaskAssigneeOptions = {
    self: UserRef;
    projects: { projectId: number; members: UserRef[] }[];
};

/**
 * The standalone task detail DTO (StandaloneTaskPresenter). It has no project, milestone, column,
 * checklist or comments, because a standalone task has none. `status` is the display state;
 * `statusValue` is the editable field, for the edit form.
 */
export type StandaloneTaskDetail = {
    id: number;
    title: string;
    description: string | null;
    priority: TaskPriority;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    status: TaskStatusDto;
    statusValue: 'todo' | 'in_progress' | 'done';
    assignee: UserRef | null;
};

/** What the viewer may do to a standalone task, from `TaskPolicy`; `logTime` also needs time eligibility (P5). */
export type StandaloneTaskAbilities = {
    update: boolean;
    complete: boolean;
    reopen: boolean;
    delete: boolean;
    assign: boolean;
    logTime: boolean;
};

/** Present only when the viewer may update. `assignees.self` is the one person a standalone task may be handed to. */
export type StandaloneTaskOptions = TaskFormOptions & {
    assignees: { self: UserRef };
};

// ── Project Tasks tab (EPIC-015 §13) ───────────────────────────────────────

/** A milestone as a project task row names it: id and name only (EPIC-015 §13.3). */
export type TaskMilestoneRef = { id: number; name: string };

/**
 * One row of a project's Tasks tab: the canonical `TaskRow` plus the task's milestone, which only
 * project scope carries (EPIC-015 P4), so the global list's DTO is unchanged.
 */
export type ProjectTaskRow = TaskRow & { milestone: TaskMilestoneRef | null };

/**
 * The project tab's URL state as the server normalized it (EPIC-015 §13.2): the global vocabulary
 * without `kind`, `project` and `organization` (the project is fixed). `milestone` is always one of
 * the project's own; `assignee` is any well-formed id or `none`, applied only as a narrowing filter.
 */
export type ProjectTaskFilters = Pick<
    TaskListFilters,
    'completion' | 'priority' | 'due' | 'milestone' | 'assignee' | 'q'
>;

/** The global sorts plus the project-only board order (column, then position). */
export type ProjectTaskSort = {
    by: TaskListSort['by'] | 'board';
    dir: TaskListSort['dir'];
};

/** Server-named vocabulary and options for the project tab, each drawn from the project. */
export type ProjectTaskFilterOptions = Pick<
    TaskFilterOptions,
    'completion' | 'priorities' | 'due'
> & {
    sorts: Labelled<ProjectTaskSort['by']>[];
    milestones: NamedOption[];
    assignees: NamedOption[];
};
