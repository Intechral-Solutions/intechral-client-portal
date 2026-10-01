import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';

import { EntityHeader } from '@/components/entity-header';
import { PageFrame } from '@/components/page-frame';
import { TaskPriorityMark } from '@/components/tasks/task-priority';
import { formatDate } from '@/lib/dates';
import type { TaskPriority } from '@/types/projects';

/**
 * EPIC-014 §14.2 — the one Direction D grammar both task detail pages share: a `grid` `PageFrame`
 * whose `EntityHeader` (overline, title, status, actions, then the strata) spans both columns, a main
 * column, and a supporting column that is a labelled `<aside>` ("Task details").
 *
 * It composes the existing primitives and adds no layout of its own. It also adds no domain: the board
 * and standalone pages hand it already-authorized `actions`, their own sections as `children` and their
 * own aside, so neither kind pretends to have the other's sections (a standalone task has no project,
 * milestone, column, checklist or comments), and there is no `isStandalone`-style flag to hide a
 * difference. It draws no breadcrumb: the shell's utility bar owns the one trail (A13.12), which a page
 * supplies through the shell's `trail`.
 */
export function TaskDetailFrame({
    overline,
    title,
    status,
    actions,
    aside,
    children,
}: {
    overline: string;
    title: string;
    status?: ReactNode;
    actions?: ReactNode;
    aside: ReactNode;
    children: ReactNode;
}) {
    return (
        <PageFrame
            width="grid"
            header={
                <EntityHeader overline={overline} title={title} status={status} actions={actions} />
            }
            aside={aside}
            asideLabel="Task details"
        >
            <div className="space-y-6">{children}</div>
        </PageFrame>
    );
}

/**
 * The "Details" facts both kinds have: assignee, due date, priority. A kind adds its own rows as
 * `children` (`TaskDetailsList.Row`), so a standalone task shows no milestone or column and a board
 * task adds both, without either being a flag. `assignee` is a node because it is a plain name for a
 * viewer who may not assign and a menu for one who may.
 */
export function TaskDetailsList({
    assignee,
    dueDate,
    overdue,
    priority,
    children,
}: {
    assignee: ReactNode;
    dueDate: string | null;
    overdue: boolean;
    priority: { value: TaskPriority; label?: string };
    children?: ReactNode;
}) {
    return (
        <dl className="space-y-3 text-sm">
            <TaskDetailsRow label="Assignee">{assignee}</TaskDetailsRow>
            <TaskDetailsRow label="Due date">
                {dueDate ? (
                    // Overdue is the server's call, shown by a glyph and a cue as well as colour.
                    <span
                        className={
                            overdue
                                ? 'inline-flex items-center gap-1 font-medium text-danger'
                                : undefined
                        }
                    >
                        {overdue ? (
                            <>
                                <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                                <span className="sr-only">Overdue:</span>
                            </>
                        ) : null}
                        <time dateTime={dueDate}>{formatDate(dueDate)}</time>
                    </span>
                ) : (
                    '—'
                )}
            </TaskDetailsRow>
            <TaskDetailsRow label="Priority">
                <TaskPriorityMark priority={priority.value} label={priority.label} />
            </TaskDetailsRow>
            {children}
        </dl>
    );
}

function TaskDetailsRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="font-medium text-text-muted">{label}</dt>
            <dd className="mt-0.5 text-text">{children}</dd>
        </div>
    );
}

TaskDetailsList.Row = TaskDetailsRow;
