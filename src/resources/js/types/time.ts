export type TimerContext = {
    type: 'Project' | 'Task' | 'Ticket';
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

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
    prev_page_url: string | null;
    next_page_url: string | null;
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
