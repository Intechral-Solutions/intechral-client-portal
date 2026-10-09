import { forwardRef } from 'react';

import { AttachmentList } from '@/components/helpdesk/attachment-list';
import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import { formatTimestamp } from '@/lib/dates';
import type { ConversationEntryData } from '@/types/helpdesk';

/**
 * One entry of a ticket conversation (EPIC-017 §15). Presentational: it receives an entry list the
 * server has ALREADY filtered and never filters, hides or reorders anything itself, so there is no
 * client-side visibility rule to get wrong. The body is plain text; `whitespace-pre-wrap` keeps line
 * breaks without giving the text any markup meaning, and React's escaping is the XSS boundary.
 *
 * The entry takes programmatic focus (`tabIndex={-1}`) so a sent reply can be announced by landing
 * on it, without adding a tab stop.
 */
export const ConversationEntry = forwardRef<HTMLLIElement, { entry: ConversationEntryData }>(
    ({ entry }, ref) => (
        <li
            ref={ref}
            id={`entry-${entry.key}`}
            tabIndex={-1}
            className={cn('rounded-control border border-rule bg-surface p-5', focusRing)}
        >
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span className="text-sm font-medium text-text">{entry.author.name}</span>
                <time
                    dateTime={entry.createdAt}
                    title={formatTimestamp(entry.createdAt)}
                    className="text-xs text-text-secondary"
                >
                    {entry.createdAtHuman}
                </time>
            </div>
            <p className="text-sm break-words whitespace-pre-wrap text-text">{entry.body}</p>
            <AttachmentList attachments={entry.attachments} />
        </li>
    ),
);

ConversationEntry.displayName = 'ConversationEntry';
