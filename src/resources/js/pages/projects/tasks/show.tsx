import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { ReactElement } from 'react';

import { TaskChecklist } from '@/components/projects/task-checklist';
import { TaskComments } from '@/components/projects/task-comments';
import { TaskEditDialog } from '@/components/projects/task-edit-dialog';
import { TaskStatusBadge } from '@/components/projects/task-status-badge';
import { TaskTimePanel } from '@/components/projects/task-time-panel';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { boardChoices } from '@/components/tasks/task-assignee-choices';
import { TaskAssigneeMenu } from '@/components/tasks/task-assignee-menu';
import { TaskCompleteAction } from '@/components/tasks/task-complete-action';
import { TaskDeleteButton } from '@/components/tasks/task-delete-button';
import { TaskDetailFrame, TaskDetailsList } from '@/components/tasks/task-detail-frame';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { layoutPageProps } from '@/lib/inertia-layout';
import { board } from '@/routes/projects';
import { destroy } from '@/routes/projects/tasks';
import { update as assigneeUpdate } from '@/routes/tasks/assignee';
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
        /** Complete / Reopen, from `TaskPolicy` (Q1): a manager, or the current assignee who is still a member. */
        complete: boolean;
        reopen: boolean;
    };
    timeSummary: TaskTimeSummary | null;
};

/**
 * The board (project) task detail (EPIC-011E §11, EPIC-014 §14.2, WP5), on the shared Direction D
 * detail grammar (`TaskDetailFrame`). It is a presentation migration: the route, the domain behaviour,
 * the authorization and every mutation endpoint are unchanged. The edit form became an Edit dialog over
 * the same `projects.tasks.update`; the page-owned breadcrumb is gone (A13.12), so the project and task
 * trail comes through the shell via the layout's `trail`.
 *
 * Board-specific stays explicit: the project in the overline, the milestone and column in the Details,
 * the checklist and comments sections, and **no raw status editor**: completion is the column's
 * (INV-1), moved only by the semantic Complete/Reopen action, which the server's abilities offer to a
 * manager or the member-assignee (Q1). Assignment is the project-member rule: the control offers the
 * members the server sent a manager and keeps a departed holder as the current value (I8).
 */
export function ProjectTaskShowPage({
    project,
    task,
    checklist,
    comments,
    options,
    abilities,
    timeSummary,
}: ProjectTaskShowProps) {
    const [editing, setEditing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [assigning, setAssigning] = useState(false);
    const assignInFlight = useRef(false);

    function assign(userId: number | null) {
        if (assignInFlight.current) return;

        assignInFlight.current = true;
        setAssigning(true);
        setActionError(null);

        router.put(
            assigneeUpdate.url(task.id),
            { assignee_id: userId },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setActionError(
                        errors.assignee_id ??
                            Object.values(errors)[0] ??
                            'The assignee could not be changed.',
                    ),
                onFinish: () => {
                    assignInFlight.current = false;
                    setAssigning(false);
                },
            },
        );
    }

    const canAssign = abilities.manage && options !== null;
    // The members the server sent a manager; a departed holder stays as the current value (I8).
    const choices = options ? boardChoices(options.members, task.assignee) : [];

    return (
        <>
            <Head title={`${task.title} — ${project.name}`} />
            <TaskDetailFrame
                overline={`Task · ${project.name}`}
                title={task.title}
                status={<TaskStatusBadge status={task.status} />}
                actions={
                    <>
                        <TaskCompleteAction
                            taskId={task.id}
                            done={task.status.done}
                            canComplete={abilities.complete}
                            canReopen={abilities.reopen}
                            onError={setActionError}
                        />
                        {abilities.manage && options ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setEditing(true)}
                            >
                                Edit task
                            </Button>
                        ) : null}
                        {abilities.manage ? (
                            <TaskDeleteButton
                                url={destroy.url({ project: project.id, task: task.id })}
                                taskTitle={task.title}
                            />
                        ) : null}
                    </>
                }
                aside={
                    <div className="space-y-6">
                        <Section title="Details">
                            <TaskDetailsList
                                dueDate={task.dueDate}
                                overdue={task.overdue}
                                priority={{
                                    value: task.priority,
                                    // Only a label the server supplied; otherwise the mark names it.
                                    label: options?.priorities.find(
                                        (priority) => priority.value === task.priority,
                                    )?.label,
                                }}
                                assignee={
                                    canAssign ? (
                                        <TaskAssigneeMenu
                                            taskTitle={task.title}
                                            assignee={task.assignee}
                                            choices={choices}
                                            pending={assigning}
                                            onAssign={assign}
                                        />
                                    ) : (
                                        <>
                                            {task.assignee?.name ?? '—'}
                                            {task.assignee && !task.assigneeIsMember ? (
                                                <span className="ml-1 text-xs text-text-muted">
                                                    (no longer a project member)
                                                </span>
                                            ) : null}
                                        </>
                                    )
                                }
                            >
                                <TaskDetailsList.Row label="Milestone">
                                    {task.milestone?.name ?? '—'}
                                </TaskDetailsList.Row>
                                <TaskDetailsList.Row label="Column">
                                    {task.column?.name ?? '—'}
                                </TaskDetailsList.Row>
                            </TaskDetailsList>
                        </Section>

                        <Section title="Time">
                            <TaskTimePanel
                                taskId={task.id}
                                canLog={abilities.logTime}
                                timeSummary={timeSummary}
                            />
                        </Section>
                    </div>
                }
            >
                {actionError ? <Alert variant="danger">{actionError}</Alert> : null}

                <Section title="Description">
                    {task.description ? (
                        <p className="text-sm whitespace-pre-wrap text-text-secondary">
                            {task.description}
                        </p>
                    ) : (
                        <p className="text-sm text-text-muted italic">No description.</p>
                    )}
                </Section>

                <Section title="Checklist">
                    <TaskChecklist
                        projectId={project.id}
                        taskId={task.id}
                        items={checklist}
                        canAuthor={abilities.manage}
                    />
                </Section>

                <Section title="Comments">
                    <TaskComments
                        projectId={project.id}
                        taskId={task.id}
                        comments={comments}
                        canComment={abilities.comment}
                    />
                </Section>
            </TaskDetailFrame>

            {editing && options ? (
                <TaskEditDialog
                    open
                    onOpenChange={setEditing}
                    projectId={project.id}
                    task={task}
                    options={options}
                />
            ) : null}
        </>
    );
}

/**
 * The project and the task reach the shell's one breadcrumb as the page's trail (§13.2, A13.12). The
 * layout line still names `AppShell` alone (the shell seam, EPIC-013 §23.2); only its `trail` prop is
 * new, read from the page element (`layoutPageProps`).
 */
ProjectTaskShowPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<ProjectTaskShowProps>(page);

    return (
        <AppShell
            trail={
                props && [
                    { label: props.project.name, href: board.url(props.project.id) },
                    { label: props.task.title },
                ]
            }
        >
            {page}
        </AppShell>
    );
};

export default ProjectTaskShowPage;
