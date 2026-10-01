import { taskListClearedQuery, taskListQuery } from '@/components/tasks/task-list-query';
import type { TaskListFilters, TaskListSort, TaskView } from '@/types/tasks';

/**
 * EPIC-014 WP4 — how a control change becomes the next `/tasks` query. The server is the only filter
 * authority (WP3): this builds a *request* from the canonical state the server last returned plus one
 * change, omitting defaults, and the server's normalized echo decides what actually applied.
 */
const none: TaskListFilters = {
    completion: 'open',
    priority: [],
    due: null,
    kind: null,
    project: null,
    milestone: null,
    assignee: null,
    organization: null,
    q: '',
};
const dueAsc: TaskListSort = { by: 'due', dir: 'asc' };

const q = (
    patch = {},
    over: { view?: TaskView; filters?: Partial<TaskListFilters>; sort?: TaskListSort } = {},
) =>
    taskListQuery(
        {
            view: over.view ?? 'mine',
            filters: { ...none, ...over.filters },
            sort: over.sort ?? dueAsc,
        },
        patch,
    );

describe('taskListQuery', () => {
    it('is empty for the default state: My Tasks, open, due ascending', () => {
        expect(q()).toEqual({});
    });

    it('keeps the served view, since the view is part of the URL', () => {
        expect(q({}, { view: 'all' })).toEqual({ view: 'all' });
    });

    it('omits a value that equals its default and sends everything else', () => {
        expect(q({ completion: 'any' })).toEqual({ completion: 'any' });
        expect(q({ completion: 'open' }, { filters: { completion: 'done' } })).toEqual({});
    });

    it('carries the canonical state it was given, plus the one change', () => {
        expect(
            q(
                { due: 'overdue' },
                { view: 'all', filters: { priority: ['high'], project: 7, q: 'report' } },
            ),
        ).toEqual({ view: 'all', priority: ['high'], project: 7, q: 'report', due: 'overdue' });
    });

    it('clears a filter with null, an empty string or an empty list', () => {
        const filters = {
            due: 'today' as const,
            q: 'x',
            priority: ['low' as const],
            kind: 'project' as const,
        };

        expect(q({ due: null }, { filters })).toEqual({
            q: 'x',
            priority: ['low'],
            kind: 'project',
        });
        expect(q({ q: '' }, { filters })).toEqual({
            due: 'today',
            priority: ['low'],
            kind: 'project',
        });
        expect(q({ priority: [] }, { filters })).toEqual({ due: 'today', q: 'x', kind: 'project' });
    });

    it('never sends a page: a changed query starts from page one', () => {
        expect(q({ due: 'today' })).not.toHaveProperty('page');
    });

    it('drops the milestone when the project changes or is cleared, since a milestone belongs to one project', () => {
        const filters = { project: 4, milestone: 9 };

        expect(q({ due: 'today' }, { filters })).toEqual({
            due: 'today',
            project: 4,
            milestone: 9,
        });
        expect(q({ project: 5 }, { filters })).toEqual({ project: 5 });
        expect(q({ project: null }, { filters })).toEqual({});
        expect(q({ project: 5, milestone: 11 }, { filters })).toEqual({
            project: 5,
            milestone: 11,
        });
    });

    it('sends an assignee id, or the literal none for unassigned', () => {
        expect(q({ assignee: 3 }, { view: 'all' })).toEqual({ view: 'all', assignee: 3 });
        expect(q({ assignee: 'none' }, { view: 'all' })).toEqual({ view: 'all', assignee: 'none' });
    });

    it('carries a non-default sort and direction, and lets the server pick the natural direction for a new field', () => {
        expect(q({}, { sort: { by: 'title', dir: 'desc' } })).toEqual({
            sort: 'title',
            dir: 'desc',
        });
        expect(q({ sort: 'priority' }, { sort: { by: 'title', dir: 'desc' } })).toEqual({
            sort: 'priority',
        });
        expect(q({ sort: 'due' }, { sort: { by: 'title', dir: 'desc' } })).toEqual({});
    });

    it('sets an explicit direction for the current field', () => {
        expect(q({ dir: 'desc' })).toEqual({ sort: 'due', dir: 'desc' });
        expect(q({ dir: 'asc' }, { sort: { by: 'title', dir: 'desc' } })).toEqual({
            sort: 'title',
            dir: 'asc',
        });
    });

    it('resets every filter and the search but keeps the view and the sort', () => {
        expect(
            taskListClearedQuery({
                view: 'all',
                filters: {
                    ...none,
                    due: 'today',
                    q: 'x',
                    priority: ['low'],
                    project: 2,
                    assignee: 'none',
                },
                sort: { by: 'title', dir: 'desc' },
            }),
        ).toEqual({ view: 'all', sort: 'title', dir: 'desc' });
    });
});
