import { Head, Link } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { CreateTaskForm } from '@/components/tasks/create-task-form';
import { TaskListRow } from '@/components/tasks/task-list-row';
import { AppShell } from '@/components/shell/app-shell';
import { cn } from '@/lib/utils';
import { index } from '@/routes/tasks';
import type { Paginated } from '@/types/pagination';
import type { TaskCreateOptions, TaskRow } from '@/types/tasks';

export type TasksIndexProps = {
    tasks: Paginated<TaskRow>;
    view: 'mine' | 'org';
    /** The org tab only ever widens the list through a company link (D2); hidden when that can
     * never surface a row, exactly like the Blade page it replaces. */
    canViewOrg: boolean;
    createOptions: TaskCreateOptions;
};

function ViewTab({ href, active, children }: { href: string; active: boolean; children: string }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                active
                    ? 'border-ink text-text'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </Link>
    );
}

export function TasksIndexPage({ tasks, view, canViewOrg, createOptions }: TasksIndexProps) {
    return (
        <>
            <Head title="Tasks" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Tasks"
                    description="Tasks assigned to you or your organization."
                />

                <CreateTaskForm options={createOptions} />

                <div className="flex gap-1 border-b border-border">
                    <ViewTab href={index.url({ query: { view: 'mine' } })} active={view === 'mine'}>
                        Assigned to Me
                    </ViewTab>
                    {canViewOrg ? (
                        <ViewTab
                            href={index.url({ query: { view: 'org' } })}
                            active={view === 'org'}
                        >
                            My Organization
                        </ViewTab>
                    ) : null}
                </div>

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
