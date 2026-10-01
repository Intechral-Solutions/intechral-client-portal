import { cn } from '@/lib/utils';
import { focusRing } from '@/components/ui/control-metrics';
import type { TaskRow } from '@/types/tasks';

/**
 * EPIC-014 WP4 — the Complete ring (§14.1; Direction D §10.2). A hollow ring is an open task *and* the
 * Complete control; a ring with a check is a done task and the Reopen control. The shapes differ, so
 * state is never colour alone, and the status text sits in the Status column beside it.
 *
 * It renders what the server's per-row `abilities` allow and never infers authority from a role: with
 * the ability it is a real `button` ("Complete <title>" / "Reopen <title>"), without it it is a
 * decorative mark and no control at all, so nothing is offered that can only answer 403. (The routes
 * still authorize every request; hiding is presentation.)
 *
 * While a request is in flight the control is `aria-disabled`, not `disabled`: a disabled button drops
 * focus, and a keyboard user who just pressed Complete must not lose their place. The press is ignored
 * and the control says it is busy.
 */
function Ring({ done, className }: { done: boolean; className?: string }) {
    return (
        <svg
            viewBox="0 0 20 20"
            className={cn('size-5', className)}
            aria-hidden="true"
            focusable="false"
            data-state={done ? 'done' : 'open'}
        >
            <circle
                cx="10"
                cy="10"
                r="8"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                className={done ? 'text-success-glyph' : 'text-text-muted'}
            />
            {done ? (
                <path
                    d="M6.2 10.3 8.9 13l5-5.6"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="text-success-glyph"
                />
            ) : null}
        </svg>
    );
}

export function TaskCompleteControl({
    task,
    pending,
    onToggle,
}: {
    task: TaskRow;
    pending: boolean;
    onToggle: (task: TaskRow) => void;
}) {
    const done = task.status.done;
    const allowed = done ? task.abilities.reopen : task.abilities.complete;

    if (!allowed) {
        return (
            <span className="inline-flex size-9 items-center justify-center">
                <Ring done={done} />
            </span>
        );
    }

    return (
        <button
            type="button"
            aria-label={`${done ? 'Reopen' : 'Complete'} ${task.title}`}
            aria-disabled={pending || undefined}
            aria-busy={pending || undefined}
            onClick={() => {
                if (!pending) onToggle(task);
            }}
            className={cn(
                'inline-flex size-9 items-center justify-center rounded-control transition-colors duration-motion-fast hover:bg-surface-sunken pointer-coarse:size-11',
                pending && 'opacity-60',
                focusRing,
            )}
        >
            <Ring done={done} />
        </button>
    );
}
