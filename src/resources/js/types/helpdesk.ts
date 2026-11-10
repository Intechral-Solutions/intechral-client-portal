import type { Paginated } from '@/types/pagination';

/**
 * The requester Helpdesk page props (EPIC-017 §19). Each shape mirrors, key for key, what
 * `App\Http\Presenters\RequesterTicketPresenter` writes out and `RequesterTicketPropsTest` pins. A key
 * that is not here is never sent to a requester: there is no time data, internal note, SLA, history
 * actor, company, or organization id in these types (the organization NAME is here, owner decision E4) because there is none on the wire.
 */
export type TicketStatusValue = 'open' | 'in_progress' | 'pending_user' | 'resolved' | 'closed';

export type TicketPriorityValue = 'low' | 'medium' | 'high' | 'critical';

/**
 * One organization the signed-in user could raise a request for right now: their OWN current membership, id and name, nothing else
 * (R1-2B). The server decides again when the request arrives; this only fills the page.
 */
export type OrganizationOption = { id: number; name: string };

export type RequesterTicketRow = {
    /** The public reference (`TK-XXXXX-XXXXX`): the ticket's only public identity. The numeric key is never sent. */
    number: string;
    title: string;
    /** The name of the organization the request was raised for (R1-2B); never its id. */
    organization: string;
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
    /** The public reference (`TK-XXXXX-XXXXX`): the ticket's only public identity. The numeric key is never sent. */
    number: string;
    title: string;
    /** The name of the organization the request was raised for (R1-2B); never its id. */
    organization: string;
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
