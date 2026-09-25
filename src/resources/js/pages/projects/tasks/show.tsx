import { Head, Link } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import { PageHeader } from '@/components/page-header';
import { PriorityBadge } from '@/components/projects/priority-badge';
import { TaskChecklist } from '@/components/projects/task-checklist';
import { TaskComments } from '@/components/projects/task-comments';
import { TaskDeleteButton } from '@/components/projects/task-delete-button';
import { TaskEditForm } from '@/components/projects/task-edit-form';
import { TaskStatusBadge } from '@/components/projects/task-status-badge';
import { TaskTimePanel } from '@/components/projects/task-time-panel';
import { formatDate } from '@/lib/dates';
import { board } from '@/routes/projects';
import { AppLayout } from '@/layouts/app-layout';
import type {
    TaskChecklistItemData,
    TaskCommentData,
    TaskDetail,
    TaskEditOptions,
} from '@/types/projects';
import type { TaskTimeSummary } from '@/types/time';

export type ProjectTaskShowProps = {
    project: { id: number; name: string };
    task: TaskDetail;
    checklist: TaskChecklistItemData[];
    comments: TaskCommentData[];
    /** Present only when `abilities.manage` is true. */
    options: TaskEditOptions | null;
    abilities: {
        /** Edit fields, assign, milestone, delete, checklist add/remove (D1, D5). */
        manage: boolean;
        comment: boolean;
        toggleChecklist: boolean;
        logTime: boolean;
    };
    timeSummary: TaskTimeSummary | null;
};

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3 rounded-md border border-border bg-card p-5 text-card-foreground">
            <h2 className="text-sm font-semibold text-foreground">{title}</h2>
            {children}
        </section>
    );
}

export function ProjectTaskShowPage({
    project,
    task,
    checklist,
    comments,
    options,
    abilities,
    timeSummary,
}: ProjectTaskShowProps) {
    return (
        <>
            <Head title={`${task.title} — ${project.name}`} />
            <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <nav
                    aria-label="Breadcrumb"
                    className="mb-4 flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Link href={board.url(project.id)} className="hover:underline">
                        {project.name}
                    </Link>
                    <span aria-hidden="true">/</span>
                    <span className="truncate text-foreground">{task.title}</span>
                </nav>

                <PageHeader
                    title={task.title}
                    description={project.name}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <TaskStatusBadge status={task.status} />
                            <PriorityBadge priority={task.priority} />
                        </div>
                    }
                />

                <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Main column */}
                    <div className="space-y-6 lg:col-span-2">
                        <Panel title="Description">
                            {task.description ? (
                                <p className="text-sm whitespace-pre-wrap text-secondary-foreground">
                                    {task.description}
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground italic">
                                    No description.
                                </p>
                            )}
                        </Panel>

                        <Panel title="Checklist">
                            <TaskChecklist
                                projectId={project.id}
                                taskId={task.id}
                                items={checklist}
                                canAuthor={abilities.manage}
                            />
                        </Panel>

                        <Panel title="Comments">
                            <TaskComments
                                projectId={project.id}
                                taskId={task.id}
                                comments={comments}
                                canComment={abilities.comment}
                            />
                        </Panel>
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-6">
                        <Panel title="Details">
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="font-medium text-muted-foreground">Assignee</dt>
                                    <dd className="mt-0.5 text-foreground">
                                        {task.assignee?.name ?? '—'}
                                        {task.assignee && !task.assigneeIsMember ? (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                (no longer a project member)
                                            </span>
                                        ) : null}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-medium text-muted-foreground">Due date</dt>
                                    <dd
                                        className={
                                            task.overdue
                                                ? 'mt-0.5 font-medium text-[var(--text-danger)]'
                                                : 'mt-0.5 text-foreground'
                                        }
                                    >
                                        {task.dueDate ? formatDate(task.dueDate) : '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-medium text-muted-foreground">Milestone</dt>
                                    <dd className="mt-0.5 text-foreground">
                                        {task.milestone?.name ?? '—'}
                                    </dd>
                                </div>
                            </dl>
                        </Panel>

                        <Panel title="Time">
                            <TaskTimePanel
                                taskId={task.id}
                                canLog={abilities.logTime}
                                timeSummary={timeSummary}
                            />
                        </Panel>

                        {abilities.manage && options ? (
                            <Panel title="Edit task">
                                <TaskEditForm
                                    projectId={project.id}
                                    task={task}
                                    options={options}
                                />
                                <div className="border-t border-border pt-4">
                                    <TaskDeleteButton
                                        projectId={project.id}
                                        taskId={task.id}
                                        taskTitle={task.title}
                                    />
                                </div>
                            </Panel>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}

ProjectTaskShowPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default ProjectTaskShowPage;
