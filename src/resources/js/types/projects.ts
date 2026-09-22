export type ProjectStatus = 'active' | 'on_hold' | 'completed' | 'archived';

export type MemberRole = 'member' | 'manager';

export type TaskPriority = 'low' | 'medium' | 'high' | 'critical';

/** One card on the projects index (ProjectPresenter::card). */
export type ProjectCardData = {
    id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    targetDate: string | null;
    /** 0 to 100, tasks in done columns over all tasks. */
    completion: number;
    overdueCount: number;
    memberCount: number;
};

/** The project's own editable fields (ProjectPresenter::detail). */
export type ProjectDetail = {
    id: number;
    name: string;
    description: string | null;
    startDate: string | null;
    targetDate: string | null;
    status: ProjectStatus;
    /** A decimal string such as `"1200.50"`; it is never parsed into a JS number. */
    budget: string | null;
};

export type CompanyOption = { id: number; name: string };

/** Current membership as every manager sees it: no email. */
export type ProjectMemberRef = {
    id: number;
    name: string;
    role: MemberRole;
    isOwner: boolean;
};

/** The administrator-only user directory for the membership editor. */
export type MemberCandidate = { id: number; name: string; email: string };

/** One row on the milestones page (ProjectMilestonePresenter::item). */
export type MilestoneItem = {
    id: number;
    name: string;
    description: string | null;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string;
    taskCount: number;
    doneCount: number;
    /** 0 to 100, tasks in done columns/status over all tasks on this milestone. */
    completion: number;
    overdue: boolean;
};

/** One card on the board (ProjectBoardPresenter::task). No description, comments, or emails. */
export type BoardTask = {
    id: number;
    title: string;
    priority: TaskPriority;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    assignee: { id: number; name: string } | null;
    milestone: { id: number; name: string } | null;
    checklist: { done: number; total: number };
};

/** One column of the board, tasks already in board order (ProjectBoardPresenter::column). */
export type BoardColumn = {
    id: number;
    name: string;
    isDone: boolean;
    tasks: BoardTask[];
};

/** A minimal user reference: id and display name only, never an email (D2/A3 minimisation). */
export type UserRef = { id: number; name: string };

/** A minimal milestone reference, as shown on a task. */
export type MilestoneRef = { id: number; name: string };

/**
 * The status shared by the board, task detail, and `/tasks` (TaskStatusPresenter, EPIC-011E
 * §15). `label`/`done` mirror `Task::effectiveStatus()`/`Task::isDone()` exactly; `source` says
 * which field is authoritative for this task's kind. Never compare a raw `status` string.
 */
export type TaskStatusDto = {
    label: string;
    done: boolean;
    source: 'column' | 'status';
};

/** The task detail page's own task DTO (ProjectTaskPresenter::detail). */
export type TaskDetail = {
    id: number;
    title: string;
    description: string | null;
    priority: TaskPriority;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    dueDate: string | null;
    overdue: boolean;
    status: TaskStatusDto;
    column: { id: number; name: string; isDone: boolean } | null;
    assignee: UserRef | null;
    /** False when the current assignee has since left the project (I8); they still show up
     * here so a manager's unrelated save cannot silently unassign them. */
    assigneeIsMember: boolean;
    milestone: MilestoneRef | null;
};

/** One checklist item (ProjectTaskPresenter::checklistItem). */
export type TaskChecklistItemData = {
    id: number;
    title: string;
    completed: boolean;
};

/** One comment (ProjectTaskPresenter::comment). Plain text; never rendered as HTML. */
export type TaskCommentData = {
    id: number;
    body: string;
    createdAt: string;
    author: UserRef | null;
};

/** Options for the manager-only edit form; present only when `abilities.manage` is true. */
export type TaskEditOptions = {
    /** Project members only (A3); the current assignee is added separately if departed. */
    members: UserRef[];
    milestones: MilestoneRef[];
    priorities: { value: TaskPriority; label: string }[];
};
