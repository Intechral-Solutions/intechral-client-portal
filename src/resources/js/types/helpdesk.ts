import type { Paginated } from '@/types/pagination';

/**
 * The requester Helpdesk page props (EPIC-017 §19). Each shape mirrors, key for key, what
 * `App\Http\Presenters\RequesterTicketPresenter` writes out and `RequesterTicketPropsTest` pins. A key
 * that is not here is never sent to a requester: there is no time data, internal note, SLA, history
 * actor or company in these types because there is none on the wire.
 */
export type TicketStatusValue = 'open' | 'in_progress' | 'pending_user' | 'resolved' | 'closed';

export type TicketPriorityValue = 'low' | 'medium' | 'high' | 'critical';

export type RequesterTicketRow = {
    id: number;
    number: string;
    title: string;
    status: TicketStatusValue;
    /** The customer vocabulary, chosen by the server (EPIC-017 §13). */
    statusLabel: string;
    priority: TicketPriorityValue;
    createdAt: string;
    createdAtHuman: string;
    href: string;
};

export type RequesterTicketFilters = {
    search: string | null;
    status: TicketStatusValue | null;
};

export type SelectOption<T extends string = string> = { value: T; label: string };

/** A Laravel paginator as the list page reads it: the base shape plus the first-page link. */
export type RequesterTicketPage = Paginated<RequesterTicketRow> & { first_page_url: string };

export type AttachmentPolicy = {
    maxFiles: number;
    maxSizeMb: number;
    extensions: string[];
};

export type ConversationAttachment = {
    id: number;
    filename: string;
    /** Already formatted by the server ("1.2 MB"). */
    size: string;
    href: string;
};

export type ConversationEntryData = {
    key: string;
    kind: 'description' | 'reply';
    author: { name: string };
    body: string;
    createdAt: string;
    createdAtHuman: string;
    attachments: ConversationAttachment[];
};

export type RequesterTicketDetail = {
    id: number;
    number: string;
    title: string;
    status: TicketStatusValue;
    statusLabel: string;
    priority: TicketPriorityValue;
    category: string;
    createdAt: string;
    createdAtHuman: string;
    /** The assignee's name only, never their id or email (P6). */
    handledBy: string | null;
};

export type RequesterHistoryEntry = {
    statusLabel: string;
    at: string;
    atHuman: string;
};

export type ReplyState = {
    allowed: boolean;
    notice: 'returns' | 'reopens' | null;
    closedMessage: string | null;
};
