import type { UserRef } from '@/types/projects';
import type { TaskAssigneeOptions, TaskRow } from '@/types/tasks';

/** One entry of an assignee menu. `current` marks the stored holder who is not a new candidate. */
export type AssigneeChoice = { id: number; label: string; current?: true };

/**
 * The people an assign menu names (EPIC-014 §7.3, R6), from what the server offered and nothing else.
 * Nothing here derives membership or invents a candidate. A **current holder who is not in the offered
 * set** (a legacy standalone holder, a departed project member) is kept as a labelled, checked `current`
 * entry, so the control never silently drops them and an unrelated change cannot unassign them (I8). It
 * is the stored value, never a new choice: the menu sends nothing when it is chosen again.
 */

/** A standalone task: `self` ("Me") and nobody else. */
export function standaloneChoices(self: UserRef, holder: UserRef | null): AssigneeChoice[] {
    const choices: AssigneeChoice[] = [{ id: self.id, label: 'Me' }];

    if (holder && holder.id !== self.id) {
        choices.push({ id: holder.id, label: holder.name, current: true });
    }

    return choices;
}

/**
 * A board task: the members the server listed for its project. A departed current holder is shown as the
 * checked current value even when the server listed no members at all (a project with none left); they
 * are the stored value only, never a new candidate.
 */
export function boardChoices(
    members: readonly UserRef[],
    holder: UserRef | null,
): AssigneeChoice[] {
    const choices: AssigneeChoice[] = members.map((member) => ({
        id: member.id,
        label: member.name,
    }));

    if (holder && !choices.some((choice) => choice.id === holder.id)) {
        choices.push({
            id: holder.id,
            label: `${holder.name} (no longer a project member)`,
            current: true,
        });
    }

    return choices;
}

/** A Tasks-list row's choices, from the page's `assigneeOptions`. */
export function assigneeChoices(
    task: Pick<TaskRow, 'kind' | 'projectId' | 'assignee'>,
    options: TaskAssigneeOptions,
): AssigneeChoice[] {
    if (task.kind === 'standalone') return standaloneChoices(options.self, task.assignee);

    const members =
        options.projects.find((entry) => entry.projectId === task.projectId)?.members ?? [];

    return boardChoices(members, task.assignee);
}
