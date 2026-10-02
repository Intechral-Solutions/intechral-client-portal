import { assigneeChoices } from '@/components/tasks/task-assignee-choices';
import type { TaskAssigneeOptions, TaskRow } from '@/types/tasks';

const options: TaskAssigneeOptions = {
    self: { id: 1, name: 'Dana Webb' },
    projects: [
        {
            projectId: 7,
            members: [
                { id: 1, name: 'Dana Webb' },
                { id: 2, name: 'Max Member' },
            ],
        },
    ],
};

function row(overrides: Partial<TaskRow>): TaskRow {
    return {
        id: 10,
        title: 'A task',
        kind: 'standalone',
        projectId: null,
        priority: 'medium',
        status: { label: 'To Do', done: false, source: 'status' },
        dueDate: null,
        overdue: false,
        assignee: null,
        context: { kind: 'standalone', label: 'Standalone', url: null },
        url: '/tasks/10',
        abilities: { complete: true, reopen: true, assign: true },
        ...overrides,
    };
}

it('offers a standalone task only Me', () => {
    expect(assigneeChoices(row({}), options)).toEqual([{ id: 1, label: 'Me' }]);
});

it('keeps a standalone task held by someone else visible as its current value, never as a new candidate', () => {
    const choices = assigneeChoices(row({ assignee: { id: 9, name: 'Legacy Holder' } }), options);

    expect(choices).toEqual([
        { id: 1, label: 'Me' },
        { id: 9, label: 'Legacy Holder', current: true },
    ]);
});

it('does not duplicate Me when the actor already holds the standalone task', () => {
    expect(assigneeChoices(row({ assignee: { id: 1, name: 'Dana Webb' } }), options)).toEqual([
        { id: 1, label: 'Me' },
    ]);
});

it("offers a board task the project's members the server listed, nobody else", () => {
    const board = row({
        kind: 'board',
        projectId: 7,
        context: { kind: 'project', label: 'Alpha', url: '/projects/7/board' },
        url: '/projects/7/tasks/10',
    });

    expect(assigneeChoices(board, options)).toEqual([
        { id: 1, label: 'Dana Webb' },
        { id: 2, label: 'Max Member' },
    ]);
});

it('labels a departed board assignee as current and no longer a member, without making them a candidate', () => {
    const board = row({ kind: 'board', projectId: 7, assignee: { id: 99, name: 'Xi Departed' } });

    expect(assigneeChoices(board, options)).toEqual([
        { id: 1, label: 'Dana Webb' },
        { id: 2, label: 'Max Member' },
        { id: 99, label: 'Xi Departed (no longer a project member)', current: true },
    ]);
});

it('offers nothing for a board row whose project the server listed no members for', () => {
    const board = row({ kind: 'board', projectId: 123 });

    expect(assigneeChoices(board, options)).toEqual([]);
});

it('still shows a departed holder as the checked current value when the project has no current members', () => {
    const board = row({ kind: 'board', projectId: 123, assignee: { id: 99, name: 'Xi Departed' } });

    // Displayed, flagged `current` (the stored value), and the only entry: no new candidate exists.
    expect(assigneeChoices(board, options)).toEqual([
        { id: 99, label: 'Xi Departed (no longer a project member)', current: true },
    ]);
    expect(assigneeChoices(board, options).filter((choice) => !choice.current)).toEqual([]);
});

it('drops the departed holder from the choices once the task is no longer theirs', () => {
    const board = row({ kind: 'board', projectId: 123, assignee: null });

    expect(assigneeChoices(board, options)).toEqual([]);
});
