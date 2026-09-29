import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatTimestamp } from '@/lib/dates';
import { store } from '@/routes/projects/tasks/comments';
import type { TaskCommentData } from '@/types/projects';

const BODY_LIMIT = 5000;

type TaskCommentsProps = {
    projectId: number;
    taskId: number;
    comments: TaskCommentData[];
    /** Any member or admin who may view the page; unchanged from the page it replaces. */
    canComment: boolean;
};

/**
 * The task comment thread (EPIC-011E §13). Server-confirmed, never optimistic: a comment needs
 * the server's id, author, and timestamp. Body is rendered as plain text — React's own escaping
 * is the XSS boundary, and no HTML or Markdown is ever introduced.
 */
export function TaskComments({ projectId, taskId, comments, canComment }: TaskCommentsProps) {
    const form = useForm({ body: '' });
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const remaining = BODY_LIMIT - form.data.body.length;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url({ project: projectId, task: taskId }), {
            preserveScroll: true,
            only: ['comments', 'flash'],
            onSuccess: () => {
                form.reset('body');
                textareaRef.current?.focus();
            },
        });
    }

    return (
        <div>
            {comments.length === 0 ? (
                <p className="text-sm text-muted-foreground italic">No comments yet.</p>
            ) : (
                <ul className="space-y-4">
                    {comments.map((comment) => (
                        <li key={comment.id} className="flex gap-3">
                            <Avatar
                                className="mt-0.5"
                                name={comment.author?.name ?? 'Unknown'}
                                decorative
                            />
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-foreground">
                                    {comment.author?.name ?? 'Unknown'}{' '}
                                    <time
                                        dateTime={comment.createdAt}
                                        className="ml-1 font-normal text-muted-foreground"
                                    >
                                        {formatTimestamp(comment.createdAt)}
                                    </time>
                                </p>
                                {/* Plain text, never HTML or Markdown: whitespace-pre-wrap keeps
                                    line breaks without giving the content any markup meaning. */}
                                <p className="mt-1 text-sm break-words whitespace-pre-wrap text-secondary-foreground">
                                    {comment.body}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canComment ? (
                <form onSubmit={submit} className="mt-4 space-y-2">
                    <label htmlFor="task-comment-body" className="sr-only">
                        Add a comment
                    </label>
                    <Textarea
                        id="task-comment-body"
                        ref={textareaRef}
                        rows={3}
                        placeholder="Add a comment…"
                        maxLength={BODY_LIMIT}
                        value={form.data.body}
                        onChange={(event) => form.setData('body', event.target.value)}
                        aria-invalid={Boolean(form.errors.body)}
                        aria-describedby="task-comment-body-error task-comment-remaining"
                    />
                    <div className="flex items-center justify-between">
                        <FormFieldError id="task-comment-body-error" message={form.errors.body} />
                        {remaining <= 200 ? (
                            <span
                                id="task-comment-remaining"
                                className="text-xs text-muted-foreground"
                            >
                                {remaining} characters left
                            </span>
                        ) : null}
                    </div>
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing || form.data.body.trim() === ''}
                        >
                            {form.processing ? 'Posting...' : 'Post comment'}
                        </Button>
                    </div>
                </form>
            ) : null}

            <p aria-live="polite" className="sr-only">
                {form.recentlySuccessful ? 'Comment added.' : ''}
            </p>
        </div>
    );
}
