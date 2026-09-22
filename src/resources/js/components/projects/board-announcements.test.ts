import {
    DEFAULT_MOVE_FAILURE_MESSAGE,
    moveFailureMessage,
    moveSuccessMessage,
} from '@/components/projects/board-announcements';
import type { BoardColumn } from '@/types/projects';

function columns(): BoardColumn[] {
    return [
        {
            id: 1,
            name: 'To Do',
            isDone: false,
            tasks: [
                {
                    id: 1,
                    title: 'A',
                    priority: 'low',
                    dueDate: null,
                    overdue: false,
                    assignee: null,
                    milestone: null,
                    checklist: { done: 0, total: 0 },
                },
            ],
        },
        {
            id: 2,
            name: 'In Progress',
            isDone: false,
            tasks: [
                {
                    id: 2,
                    title: 'B',
                    priority: 'low',
                    dueDate: null,
                    overdue: false,
                    assignee: null,
                    milestone: null,
                    checklist: { done: 0, total: 0 },
                },
                {
                    id: 3,
                    title: 'Fix login',
                    priority: 'low',
                    dueDate: null,
                    overdue: false,
                    assignee: null,
                    milestone: null,
                    checklist: { done: 0, total: 0 },
                },
            ],
        },
        { id: 4, name: 'Done', isDone: true, tasks: [] },
    ];
}

describe('moveSuccessMessage', () => {
    it('names the column and a 1-based position', () => {
        expect(moveSuccessMessage(columns(), 3, 'Fix login')).toBe(
            'Moved "Fix login" to In Progress, position 2 of 2.',
        );
    });

    it('marks a done column in the message', () => {
        const board = columns();
        const done = board.find((c) => c.id === 4);
        if (!done) throw new Error('Expected a Done column');
        done.tasks = [
            {
                id: 9,
                title: 'Ship it',
                priority: 'low',
                dueDate: null,
                overdue: false,
                assignee: null,
                milestone: null,
                checklist: { done: 0, total: 0 },
            },
        ];

        expect(moveSuccessMessage(board, 9, 'Ship it')).toBe(
            'Moved "Ship it" to Done (done), position 1 of 1.',
        );
    });

    it('returns null when the task cannot be found (never fabricates a placement)', () => {
        expect(moveSuccessMessage(columns(), 999, 'Ghost')).toBeNull();
    });
});

describe('moveFailureMessage', () => {
    it('uses the given reason when present', () => {
        expect(moveFailureMessage('The selected column is invalid.')).toBe(
            'The selected column is invalid.',
        );
    });

    it('falls back to the default message when no reason is given', () => {
        expect(moveFailureMessage()).toBe(DEFAULT_MOVE_FAILURE_MESSAGE);
        expect(moveFailureMessage(null)).toBe(DEFAULT_MOVE_FAILURE_MESSAGE);
        expect(moveFailureMessage('')).toBe(DEFAULT_MOVE_FAILURE_MESSAGE);
        expect(moveFailureMessage('   ')).toBe(DEFAULT_MOVE_FAILURE_MESSAGE);
    });
});
