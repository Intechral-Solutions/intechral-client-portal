import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { ReactElement } from 'react';

import { StandaloneEditDialog } from '@/components/tasks/standalone-edit-dialog';
import { standaloneChoices } from '@/components/tasks/task-assignee-choices';
import { TaskAssigneeMenu } from '@/components/tasks/task-assignee-menu';
import { TaskCompleteAction } from '@/components/tasks/task-complete-action';
import { TaskDeleteButton } from '@/components/tasks/task-delete-button';
import { TaskDetailFrame, TaskDetailsList } from '@/components/tasks/task-detail-frame';
import { TaskStatusBadge } from '@/components/projects/task-status-badge';
import { TaskTimePanel } from '@/components/projects/task-time-panel';
import { AppShell } from '@/components/shell/app-shell';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Section } from '@/components/section';
import { layoutPageProps } from '@/lib/inertia-layout';
import { destroy } from '@/routes/tasks';
import { update as assigneeUpdate } from '@/routes/tasks/assignee';
import type { TaskTimeSummary } from '@/types/time';
import type {
    StandaloneTaskAbilities,
    StandaloneTaskDetail,
    StandaloneTaskOptions,
} from '@/types/tasks';

/**
 * The standalone task detail (EPIC-014 §10, §14.2, WP5): the shared Direction D detail grammar
 * (`TaskDetailFrame`) with only the sections a standalone task has: a Description, the Details (assignee,
 * due date, priority) and the contextual Time. It shows no project, milestone, column, checklist or
 * comments, because a standalone task has none.
 *
 * The server is the only authority. Every control is rendered from `abilities`, which `TaskPolicy`
 * computed: Complete/Reopen, Edit, Delete and the assign control exist only when allowed, and each calls
 * the route that authorizes it again (`tasks.complete`/`reopen`, `tasks.update`, `tasks.assignee.update`,
 * `tasks.destroy`). Nothing is invented before the server answers. Assignment is Me or Unassigned and
 * nobody else (§7.3); a refusal of Complete/Reopen or of assignment is shown in a live alert, and a delete
 * the recorded-time guard refuses is shown in the confirmation dialog. The page draws no breadcrumb: the
 * shell does, from the trail the layout supplies (A13.12).
 */
export type TasksShowProps = {
    task: StandaloneTaskDetail;
    abilities: StandaloneTaskAbilities;
    /** Present only when the viewer may update. */
    options: StandaloneTaskOptions | null;
    timeSummary: TaskTimeSummary | null;
};

export function TasksShowPage({ task, abilities, options, timeSummary }: TasksShowProps) {
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

    // The same choices the list offers a standalone row: Me, and for a legacy holder their current value.
    const choices = options ? standaloneChoices(options.assignees.self, task.assignee) : [];

    return (
        <>
            <Head title={task.title} />
            <TaskDetailFrame
                overline="Task · Standalone"
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
                        {abilities.update && options ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setEditing(true)}
                            >
                                Edit task
                            </Button>
                        ) : null}
                        {abilities.delete ? (
                            <TaskDeleteButton url={destroy.url(task.id)} taskTitle={task.title} />
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
                                    abilities.assign && options ? (
                                        <TaskAssigneeMenu
                                            taskTitle={task.title}
                                            assignee={task.assignee}
                                            choices={choices}
                                            pending={assigning}
                                            onAssign={assign}
                                        />
                                    ) : (
                                        (task.assignee?.name ?? '—')
                                    )
                                }
                            />
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
            </TaskDetailFrame>

            {editing && options ? (
                <StandaloneEditDialog
                    open
                    onOpenChange={setEditing}
                    task={task}
                    options={options}
                />
            ) : null}
        </>
    );
}

/**
 * The task reaches the shell's one breadcrumb as the last segment of the trail (§13.2, A13.12), after
 * Tasks and the active view. The layout line still names `AppShell` alone (the shell seam, EPIC-013
 * §23.2); only its `trail` prop is new, read from the page element (`layoutPageProps`).
 */
TasksShowPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<TasksShowProps>(page);

    return <AppShell trail={props && [{ label: props.task.title }]}>{page}</AppShell>;
};

export default TasksShowPage;
