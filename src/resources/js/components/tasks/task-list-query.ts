import type {
    ProjectTaskFilters,
    ProjectTaskSort,
    TaskListFilters,
    TaskListSort,
    TaskView,
} from '@/types/tasks';

/**
 * EPIC-014 WP4 — how a control change becomes the next `/tasks` request.
 *
 * The server owns filter state (WP3): it normalizes every parameter and echoes the canonical result.
 * This builds a *request* from that echo plus one change, omitting defaults so the URL stays short, and
 * never a page, so any change starts again from page one. Nothing here decides what applies: an unknown
 * or malformed value is dropped by the server, and the page re-renders from what the server returned.
 */
export type TaskListState = {
    view: TaskView;
    filters: TaskListFilters;
    sort: TaskListSort;
};

/** One change. `null`, `''` and `[]` clear a filter; `completion` takes `'open'` to return to the default. */
export type TaskListPatch = {
    completion?: TaskListFilters['completion'];
    priority?: TaskListFilters['priority'];
    due?: TaskListFilters['due'];
    kind?: TaskListFilters['kind'];
    project?: number | null;
    milestone?: number | null;
    assignee?: number | 'none' | null;
    organization?: number | null;
    q?: string;
    /** A new sort field: the server picks its natural direction. */
    sort?: TaskListSort['by'];
    dir?: TaskListSort['dir'];
};

export type TaskListQuery = Record<string, string | number | string[]>;

export function taskListQuery(state: TaskListState, patch: TaskListPatch): TaskListQuery {
    const filters: TaskListFilters = {
        ...state.filters,
        ...(patch.completion !== undefined && { completion: patch.completion }),
        ...(patch.priority !== undefined && { priority: patch.priority }),
        ...(patch.due !== undefined && { due: patch.due }),
        ...(patch.kind !== undefined && { kind: patch.kind }),
        ...(patch.project !== undefined && { project: patch.project }),
        ...(patch.milestone !== undefined && { milestone: patch.milestone }),
        ...(patch.assignee !== undefined && { assignee: patch.assignee }),
        ...(patch.organization !== undefined && { organization: patch.organization }),
        ...(patch.q !== undefined && { q: patch.q }),
    };

    // A milestone belongs to one project, so changing or clearing the project drops it unless the
    // caller names the new one in the same change.
    if (
        patch.project !== undefined &&
        patch.project !== state.filters.project &&
        patch.milestone === undefined
    ) {
        filters.milestone = null;
    }

    const by = patch.sort ?? state.sort.by;
    const dir = patch.dir ?? (by === state.sort.by ? state.sort.dir : undefined);

    return compact(state.view, filters, by, dir);
}

/** Every filter and the search off; the view and the sort stay (they are not filters). */
export function taskListClearedQuery(state: TaskListState): TaskListQuery {
    return compact(
        state.view,
        {
            completion: 'open',
            priority: [],
            due: null,
            kind: null,
            project: null,
            milestone: null,
            assignee: null,
            organization: null,
            q: '',
        },
        state.sort.by,
        state.sort.dir,
    );
}

function compact(
    view: TaskView,
    filters: TaskListFilters,
    by: TaskListSort['by'],
    dir: TaskListSort['dir'] | undefined,
): TaskListQuery {
    const query: TaskListQuery = {};

    if (view === 'all') query.view = 'all';
    if (filters.completion !== 'open') query.completion = filters.completion;
    if (filters.priority.length > 0) query.priority = filters.priority;
    if (filters.due) query.due = filters.due;
    if (filters.kind) query.kind = filters.kind;
    if (filters.project !== null) query.project = filters.project;
    // A milestone only ever applies together with its project.
    if (filters.project !== null && filters.milestone !== null) query.milestone = filters.milestone;
    if (filters.assignee !== null) query.assignee = filters.assignee;
    if (filters.organization !== null) query.organization = filters.organization;
    if (filters.q !== '') query.q = filters.q;

    return withSort(query, by, dir);
}

/** The default sort (due date, ascending) is left out of the URL; anything else names both parts. */
function withSort(
    query: TaskListQuery,
    by: ProjectTaskSort['by'],
    dir: TaskListSort['dir'] | undefined,
): TaskListQuery {
    if (by !== 'due' || (dir !== undefined && dir !== 'asc')) {
        query.sort = by;
        if (dir !== undefined) query.dir = dir;
    }

    return query;
}

// ── Project Tasks tab (EPIC-015 §13.2) ─────────────────────────────────────

/**
 * The same request grammar for a project's Tasks tab. The project is the route's, so no `project`,
 * `kind`, `organization` or `view` parameter is ever written; a milestone is one of the project's own
 * and is sent on its own.
 */
export type ProjectTaskListState = {
    filters: ProjectTaskFilters;
    sort: ProjectTaskSort;
};

export type ProjectTaskListPatch = Pick<
    TaskListPatch,
    'completion' | 'priority' | 'due' | 'milestone' | 'assignee' | 'q' | 'dir'
> & {
    sort?: ProjectTaskSort['by'];
};

export function projectTaskListQuery(
    state: ProjectTaskListState,
    patch: ProjectTaskListPatch,
): TaskListQuery {
    const filters: ProjectTaskFilters = {
        ...state.filters,
        ...(patch.completion !== undefined && { completion: patch.completion }),
        ...(patch.priority !== undefined && { priority: patch.priority }),
        ...(patch.due !== undefined && { due: patch.due }),
        ...(patch.milestone !== undefined && { milestone: patch.milestone }),
        ...(patch.assignee !== undefined && { assignee: patch.assignee }),
        ...(patch.q !== undefined && { q: patch.q }),
    };

    const by = patch.sort ?? state.sort.by;
    const dir = patch.dir ?? (by === state.sort.by ? state.sort.dir : undefined);

    return compactProject(filters, by, dir);
}

/** Every filter and the search off; the sort stays (it is not a filter). */
export function projectTaskListClearedQuery(state: ProjectTaskListState): TaskListQuery {
    return compactProject(
        { completion: 'open', priority: [], due: null, milestone: null, assignee: null, q: '' },
        state.sort.by,
        state.sort.dir,
    );
}

function compactProject(
    filters: ProjectTaskFilters,
    by: ProjectTaskSort['by'],
    dir: TaskListSort['dir'] | undefined,
): TaskListQuery {
    const query: TaskListQuery = {};

    if (filters.completion !== 'open') query.completion = filters.completion;
    if (filters.priority.length > 0) query.priority = filters.priority;
    if (filters.due) query.due = filters.due;
    if (filters.milestone !== null) query.milestone = filters.milestone;
    if (filters.assignee !== null) query.assignee = filters.assignee;
    if (filters.q !== '') query.q = filters.q;

    return withSort(query, by, dir);
}
