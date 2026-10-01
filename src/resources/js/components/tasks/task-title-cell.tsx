import { Link } from '@inertiajs/react';

import type { TaskRow } from '@/types/tasks';

/**
 * A row's title, kind-aware (EPIC-011E §11, WP8). Only a project-board task has a detail page
 * from this list so far (`url` is `null` for standalone rows until EPIC-014 WP5), so this is the
 * one cell whose markup genuinely differs by kind; every other cell is identical across both. Its
 * own destination is always the migrated React task page, so it is always an Inertia `Link` when
 * present — never a document navigation guessed from the URL shape.
 */
export function TaskTitleCell({ task }: { task: TaskRow }) {
    const done = task.status.done;
    const className = done ? 'font-medium line-through opacity-60' : 'font-medium';

    if (task.url) {
        return (
            <Link href={task.url} className={`${className} legacy-text-primary hover:underline`}>
                {task.title}
            </Link>
        );
    }

    return <span className={`${className} text-foreground`}>{task.title}</span>;
}
