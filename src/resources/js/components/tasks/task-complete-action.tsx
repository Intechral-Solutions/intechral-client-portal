import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { complete, reopen } from '@/routes/tasks';

/**
 * EPIC-014 §8, §14.2 — the semantic Complete / Reopen action on a task detail's header, for board and
 * standalone tasks alike. It is the WP2 endpoints' second consumer (the list's ring is the first) and
 * shares their rules: the server's `abilities` decide whether it exists at all (nothing is rendered that
 * could only answer 403), it calls `tasks.complete` / `tasks.reopen` and invents no state (the page
 * re-renders from the redirect), a configuration error comes back on the operation's key and is handed
 * to the page to show in a live alert, and no timer is touched.
 *
 * Unlike the row's ring, a detail header has room for a named text button, so it is one: "Complete
 * task" / "Reopen task". While a request is in flight it is `aria-disabled`, not `disabled`, so a
 * keyboard user keeps their place, and a second press is ignored. The button is the same element before
 * and after, so focus stays on it when its name flips.
 */
export function TaskCompleteAction({
    taskId,
    done,
    canComplete,
    canReopen,
    onError,
}: {
    taskId: number;
    done: boolean;
    canComplete: boolean;
    canReopen: boolean;
    /** The refusal to show, or `null` to clear it. */
    onError: (message: string | null) => void;
}) {
    const [pending, setPending] = useState(false);
    const inFlight = useRef(false);

    if (!(done ? canReopen : canComplete)) return null;

    function toggle() {
        if (inFlight.current) return;

        inFlight.current = true;
        setPending(true);
        onError(null);

        router.put(
            done ? reopen.url(taskId) : complete.url(taskId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onError(null),
                onError: (errors) =>
                    onError(
                        errors.complete ??
                            errors.reopen ??
                            Object.values(errors)[0] ??
                            'The task could not be changed.',
                    ),
                onFinish: () => {
                    inFlight.current = false;
                    setPending(false);
                },
            },
        );
    }

    return (
        <Button
            type="button"
            aria-disabled={pending || undefined}
            aria-busy={pending || undefined}
            onClick={toggle}
        >
            {done ? 'Reopen task' : 'Complete task'}
        </Button>
    );
}
