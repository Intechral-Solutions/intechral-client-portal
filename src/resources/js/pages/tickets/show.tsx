import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ReactElement } from 'react';

import { ConversationEntry } from '@/components/helpdesk/conversation-entry';
import { ReplySection } from '@/components/helpdesk/reply-section';
import { TicketPriority, TicketStatus } from '@/components/helpdesk/ticket-status';
import { PageFrame } from '@/components/page-frame';
import { AppShell } from '@/components/shell/app-shell';
import { Section } from '@/components/section';
import { buttonVariants } from '@/components/ui/button';
import { layoutPageProps } from '@/lib/inertia-layout';
import { cn } from '@/lib/utils';
import { index } from '@/routes/tickets';
import type {
    AttachmentPolicy,
    ConversationEntryData,
    ReplyState,
    RequesterHistoryEntry,
    RequesterTicketDetail,
} from '@/types/helpdesk';

/**
 * One request, as its requester sees it (EPIC-017 §19.3): the opening description, the PUBLIC replies,
 * the status trail with no actors, and the reply area. Whatever is not in these props (internal
 * notes, SLA, time, the assignee's contact details) cannot be shown, because it was never sent.
 */
export type TicketsShowProps = {
    ticket: RequesterTicketDetail;
    conversation: ConversationEntryData[];
    history: RequesterHistoryEntry[];
    reply: ReplyState;
    attachmentPolicy: AttachmentPolicy;
    abilities: { create: boolean };
};

export function TicketsShowPage({
    ticket,
    conversation,
    history,
    reply,
    attachmentPolicy,
    abilities,
}: TicketsShowProps) {
    // A sent reply is announced and the new entry takes focus (§17.9). Inertia may call the form's
    // `onSuccess` before or after the page re-renders with the server's updated conversation, so the
    // page records how long the conversation was when the reply was sent and acts once it has grown past
    // that, whichever happens first. Nothing is announced unless the server accepted the reply. The
    // region is written directly, cleared first so a second reply is announced again, as the route
    // announcer does (use-page-announcement.ts).
    const sentFrom = useRef<number | null>(null);
    const latestLength = useRef(conversation.length);
    const lastEntry = useRef<HTMLLIElement>(null);
    const status = useRef<HTMLParagraphElement>(null);

    function settle() {
        if (sentFrom.current === null || latestLength.current <= sentFrom.current) return;

        sentFrom.current = null;
        lastEntry.current?.focus();

        const region = status.current;

        if (region) {
            region.textContent = '';
            window.setTimeout(() => {
                region.textContent = 'Reply sent.';
            }, 50);
        }
    }

    useEffect(() => {
        latestLength.current = conversation.length;
        settle();
    });

    return (
        <>
            <Head title={`${ticket.number} — ${ticket.title}`} />
            <PageFrame width="reading" measure="conversation" className="flex flex-col gap-6">
                <Link
                    href={index.url()}
                    className={cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'w-fit')}
                >
                    <ArrowLeft aria-hidden="true" />
                    All requests
                </Link>

                <header className="flex flex-col gap-2 border-b border-rule pb-5">
                    <p className="font-mono text-xs tracking-wide text-text-muted uppercase">
                        {ticket.number}
                    </p>
                    <h1 className="text-2xl font-semibold break-words text-text">{ticket.title}</h1>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <TicketStatus status={ticket.status} label={ticket.statusLabel} />
                        <TicketPriority priority={ticket.priority} />
                    </div>
                    <p className="text-sm text-text-secondary">
                        Opened <time dateTime={ticket.createdAt}>{ticket.createdAtHuman}</time>
                        {ticket.handledBy ? (
                            <>
                                <span aria-hidden="true"> · </span>
                                Handled by {ticket.handledBy}
                            </>
                        ) : null}
                    </p>
                </header>

                <section aria-labelledby="conversation-heading">
                    <h2 id="conversation-heading" className="sr-only">
                        Conversation
                    </h2>
                    <ol className="flex flex-col gap-4">
                        {conversation.map((entry, position) => (
                            <ConversationEntry
                                key={entry.key}
                                entry={entry}
                                ref={position === conversation.length - 1 ? lastEntry : undefined}
                            />
                        ))}
                    </ol>
                </section>

                <ReplySection
                    ticketId={ticket.id}
                    reply={reply}
                    policy={attachmentPolicy}
                    canCreate={abilities.create}
                    onSent={() => {
                        sentFrom.current = conversation.length;
                        settle();
                    }}
                />

                <p ref={status} role="status" aria-live="polite" className="sr-only" />

                <Section title="Details">
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-text-secondary">Category</dt>
                            <dd className="text-text">{ticket.category}</dd>
                        </div>
                    </dl>
                </Section>

                {history.length > 0 ? (
                    <Section title="History">
                        <ol className="flex flex-col gap-2">
                            {history.map((entry, position) => (
                                <li key={position} className="text-sm text-text-secondary">
                                    <span className="text-text">
                                        Status changed to {entry.statusLabel}
                                    </span>
                                    <span aria-hidden="true"> · </span>
                                    <time dateTime={entry.at}>{entry.atHuman}</time>
                                </li>
                            ))}
                        </ol>
                    </Section>
                ) : null}
            </PageFrame>
        </>
    );
}

TicketsShowPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<TicketsShowProps>(page);

    return <AppShell trail={props && [{ label: props.ticket.number }]}>{page}</AppShell>;
};

export default TicketsShowPage;
