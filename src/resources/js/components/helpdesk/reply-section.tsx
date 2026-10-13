import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';

import { AttachmentInput } from '@/components/helpdesk/attachment-input';
import { FormFieldError } from '@/components/forms/form-field-error';
import { Alert } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { focusRing } from '@/components/ui/control-metrics';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { create } from '@/routes/tickets';
import { store } from '@/routes/tickets/replies';
import type { AttachmentPolicy, ReplyState } from '@/types/helpdesk';

const NOTICES: Record<NonNullable<ReplyState['notice']>, string> = {
    returns: 'Replying sends this request back to the support team.',
    reopens: 'Replying reopens this request.',
};

/**
 * The requester's reply area (EPIC-017 §19.3, O2). The server decides what is offered
 * (`reply.allowed`); the lifecycle itself (pending_user → in_progress, resolved → open, closed
 * refused) is G1's `TicketService::addReply`, so this component states it and never applies it.
 *
 * A reply is server-confirmed, never optimistic. If the ticket closed while the customer was
 * writing, the server answers 422 on `body`; the page then reloads as closed, so the area becomes
 * the closed notice and shows that refusal as an error together with the unsent text. Nothing says
 * "sent" unless the request succeeded.
 *
 * Inertia swaps the page's props BEFORE it reports the 422, so by then the textarea (and the Send button
 * that had the focus) are gone and the focus has fallen to the body; `onError` has nothing left to focus.
 * The refusal is therefore its own focus target: it takes the focus when the error arrives, and only
 * then, so a request that was simply closed already never steals it.
 */
export function ReplySection({
    ticketId,
    reply,
    policy,
    canCreate,
    onSent,
}: {
    ticketId: number;
    reply: ReplyState;
    policy: AttachmentPolicy;
    canCreate: boolean;
    /** Called once the server has accepted the reply, so the page can announce it and focus it. */
    onSent: () => void;
}) {
    const form = useForm<{ body: string; attachments: File[] }>({ body: '', attachments: [] });
    const body = useRef<HTMLTextAreaElement>(null);
    const refusal = useRef<HTMLDivElement>(null);
    const errors: Record<string, string | undefined> = form.errors;
    const refused = !reply.allowed ? errors.body : undefined;
    const showRefusal = Boolean(refused);

    useEffect(() => {
        if (showRefusal) refusal.current?.focus();
    }, [showRefusal]);

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(store.url(ticketId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                onSent();
            },
            onError: (submitted) => {
                if (submitted.body) {
                    body.current?.focus();
                } else {
                    document.getElementById('reply-attachments')?.focus();
                }
            },
        });
    }

    if (!reply.allowed) {
        return (
            <section aria-labelledby="reply-closed-heading" className="flex flex-col gap-3">
                <h2 id="reply-closed-heading" className="sr-only">
                    Reply
                </h2>
                {refused ? (
                    <div
                        ref={refusal}
                        role="group"
                        aria-label="Reply refused"
                        tabIndex={-1}
                        className={cn('rounded-control', focusRing)}
                    >
                        <Alert variant="danger">{refused}</Alert>
                    </div>
                ) : null}
                <Alert variant="info">
                    <p>{reply.closedMessage}</p>
                    {canCreate ? (
                        <Link
                            href={create.url()}
                            className={cn(buttonVariants({ variant: 'secondary' }), 'mt-3')}
                        >
                            New request
                        </Link>
                    ) : null}
                </Alert>
                {refused && form.data.body ? (
                    <div className="space-y-2">
                        <Label htmlFor="reply-unsent">Your message was not sent</Label>
                        <Textarea id="reply-unsent" rows={4} readOnly value={form.data.body} />
                    </div>
                ) : null}
            </section>
        );
    }

    return (
        <section aria-labelledby="reply-heading">
            <h2 id="reply-heading" className="mb-3 text-base font-semibold text-text">
                Reply
            </h2>
            {reply.notice ? (
                <p className="mb-3 text-sm text-text-secondary">{NOTICES[reply.notice]}</p>
            ) : null}
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="space-y-2">
                    <Label htmlFor="reply-body">
                        Your reply <span aria-hidden="true">*</span>
                    </Label>
                    <Textarea
                        id="reply-body"
                        ref={body}
                        rows={5}
                        required
                        value={form.data.body}
                        onChange={(event) => form.setData('body', event.target.value)}
                        aria-invalid={Boolean(errors.body)}
                        aria-describedby={errors.body ? 'reply-body-error' : undefined}
                    />
                    <FormFieldError id="reply-body-error" message={errors.body} />
                </div>

                <AttachmentInput
                    id="reply-attachments"
                    files={form.data.attachments}
                    onChange={(files) => form.setData('attachments', files)}
                    policy={policy}
                    errors={errors}
                />

                <div className="flex justify-end">
                    <Button
                        type="submit"
                        className="min-w-28"
                        disabled={form.processing || form.data.body.trim() === ''}
                    >
                        {form.processing ? 'Sending…' : 'Send reply'}
                    </Button>
                </div>
            </form>
        </section>
    );
}
