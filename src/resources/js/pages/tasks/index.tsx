import { Head } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { CreateTaskForm } from '@/components/tasks/create-task-form';
import { TaskListRow } from '@/components/tasks/task-list-row';
import { AppShell } from '@/components/shell/app-shell';
import type { Paginated } from '@/types/pagination';
import type {
    TaskCreateOptions,
    TaskFilterOptions,
    TaskListFilters,
    TaskListSort,
    TaskRow,
    TaskView,
} from '@/types/tasks';

/**
 * The Tasks list (EPIC-014 WP3 contract). The view, filters and sort are server-resolved URL
 * state; `filters`, `filterOptions`, `sort` and each row's `abilities` are received but not yet
 * rendered — the Direction D list that consumes them is WP4. My tasks / All tasks are shell
 * drawer views, so the page carries no view tabs of its own.
 */
export type TasksIndexProps = {
    tasks: Paginated<TaskRow>;
    view: TaskView;
    filters: TaskListFilters;
    filterOptions: TaskFilterOptions;
    sort: TaskListSort;
    canViewAll: boolean;
    createOptions: TaskCreateOptions;
};

const viewDescriptions: Record<TaskView, string> = {
    mine: 'Tasks assigned to you, and unassigned tasks you created.',
    all: 'Every task you can see: tasks in your projects, and your own tasks.',
};

export function TasksIndexPage({ tasks, view, createOptions }: TasksIndexProps) {
    return (
        <>
            <Head title="Tasks" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader title="Tasks" description={viewDescriptions[view]} />

                <CreateTaskForm options={createOptions} />

                {tasks.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-md border border-dashed border-border px-4 py-14 text-center">
                        <ListChecks
                            className="h-10 w-10 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium text-muted-foreground">No tasks found.</p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-md border border-border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border bg-muted/50 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <th className="px-4 py-3">Task</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Priority</th>
                                    <th className="px-4 py-3">Context</th>
                                    <th className="px-4 py-3">Assignee</th>
                                    <th className="px-4 py-3">Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tasks.data.map((task) => (
                                    <TaskListRow key={task.id} task={task} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {tasks.last_page > 1 ? <Pagination paginator={tasks} /> : null}
            </div>
        </>
    );
}

TasksIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default TasksIndexPage;
