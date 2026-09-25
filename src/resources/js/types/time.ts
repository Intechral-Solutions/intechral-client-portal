export type TimerContext = {
    type: 'Project' | 'Task' | 'Ticket';
    id: number;
    label: string;
    url: string | null;
};

export type ActiveTimer = {
    id: number;
    started_at: string;
    server_now: string;
    description: string | null;
    context: TimerContext | null;
};

export type TimerStartPayload = {
    project_id?: number | null;
    task_id?: number | null;
    ticket_id?: number | null;
    description?: string | null;
    billable?: boolean;
};

export type ContextKind = 'project' | 'task' | 'ticket';

/** One row in the task time panel (D6). `userName` is present only when `scope` is `'all'`. */
export type TaskTimeSummaryEntry = {
    id: number;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    date: string;
    durationMinutes: number;
    userName?: string;
};

/**
 * The task detail page's time panel DTO (TaskTimeSummaryPresenter, EPIC-011E §11, D6). `'own'`
 * when the viewer has only `time.log`; `'all'` when they also hold `time.view_all`. No
 * description, billing/invoice flag, email, or unrelated user id — ever.
 */
export type TaskTimeSummary = {
    scope: 'own' | 'all';
    totalMinutes: number;
    entries: TaskTimeSummaryEntry[];
};

export type ContextOption = {
    id: number;
    label: string;
};

export type EntryContext = {
    kind: ContextKind;
    id: number;
    label: string;
    url: string | null;
};

export type TimeEntryData = {
    id: number;
    date: string;
    durationMinutes: number;
    durationHuman: string;
    hours: number;
    description: string | null;
    billable: boolean;
    billed: boolean;
    invoiceLinked: boolean;
    locked: boolean;
    running: boolean;
    context: EntryContext | null;
};

export type AllocationBlock = {
    id: number;
    blockNumber: number;
    allocationPct: number;
    isOverridden: boolean;
};

export type AllocationEntry = {
    id: number;
    description: string | null;
    locked: boolean;
    context: TimerContext | null;
    blocks: AllocationBlock[];
};

export type AllocationSlotResponse = {
    slot: {
        block_date: string;
        block_number: number;
        allocation_pct: number;
        blocks: Array<{
            id: number;
            time_entry_id: number;
            allocation_pct: number;
            is_overridden: boolean;
            locked: boolean;
        }>;
    };
};
