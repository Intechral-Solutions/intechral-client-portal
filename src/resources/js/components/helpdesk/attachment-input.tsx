import { useEffect, useRef } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AttachmentPolicy } from '@/types/helpdesk';

/** The messages for the attachment field: the list-level one, then one per file, named by the file. */
export function attachmentErrors(
    errors: Record<string, string | undefined>,
    files: File[],
): string[] {
    const messages: string[] = [];

    if (errors.attachments) messages.push(errors.attachments);

    Object.entries(errors).forEach(([key, message]) => {
        const match = /^attachments\.(\d+)$/.exec(key);

        if (match && message) {
            const file = files[Number(match[1])];
            messages.push(file ? `${file.name}: ${message}` : message);
        }
    });

    return messages;
}

/**
 * The named file control (EPIC-017 §17.8): a visible label, the limits as a hint tied to the field
 * with `aria-describedby`, and the server's messages verbatim under it. The type allowlist is the
 * server's (`AllowedTicketAttachment`); the `accept` list here only narrows the picker and is not a
 * check. The hint is built from the policy the server sent, so the page never states a limit the
 * rule does not enforce.
 */
export function AttachmentInput({
    id,
    files,
    onChange,
    policy,
    errors,
    label = 'Attachments',
}: {
    id: string;
    files: File[];
    onChange: (files: File[]) => void;
    policy: AttachmentPolicy;
    errors: Record<string, string | undefined>;
    label?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const messages = attachmentErrors(errors, files);

    // A cleared selection (after a successful send) must also clear the native control.
    useEffect(() => {
        if (files.length === 0 && input.current) input.current.value = '';
    }, [files]);

    const describedBy = [`${id}-hint`, messages.length > 0 ? `${id}-error` : null]
        .filter(Boolean)
        .join(' ');

    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                ref={input}
                id={id}
                type="file"
                multiple
                accept={policy.extensions.map((extension) => `.${extension}`).join(',')}
                onChange={(event) => onChange(Array.from(event.target.files ?? []))}
                aria-invalid={messages.length > 0}
                aria-describedby={describedBy}
            />
            <p id={`${id}-hint`} className="text-xs text-text-secondary">
                Up to {policy.maxFiles} files, {policy.maxSizeMb} MB each. Allowed types:{' '}
                {policy.extensions.join(', ')}.
            </p>
            {messages.length > 0 ? (
                <ul id={`${id}-error`} role="alert" className="space-y-1 text-sm text-danger">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
