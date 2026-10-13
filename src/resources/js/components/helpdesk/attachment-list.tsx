import { Paperclip } from 'lucide-react';

import type { ConversationAttachment } from '@/types/helpdesk';

/**
 * The files on one conversation entry. Each is a plain document link to the policy-gated download
 * route (`Content-Disposition: attachment`), never an Inertia visit: it returns a file, not a page.
 * The file name is text, so a hostile name is escaped, never parsed.
 */
export function AttachmentList({ attachments }: { attachments: ConversationAttachment[] }) {
    if (attachments.length === 0) return null;

    return (
        <ul
            aria-label="Attached files"
            className="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-rule pt-3"
        >
            {attachments.map((attachment) => (
                <li key={attachment.id}>
                    <a
                        href={attachment.href}
                        className="inline-flex items-center gap-1.5 text-sm text-accent underline-offset-2 hover:underline"
                    >
                        <Paperclip className="size-3.5 shrink-0" aria-hidden="true" />
                        <span className="break-all">{attachment.filename}</span>
                        <span className="text-text-secondary">({attachment.size})</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}
