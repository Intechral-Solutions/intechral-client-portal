import { Link } from '@inertiajs/react';

import { focusRing } from '@/components/ui/control-metrics';
import { Status } from '@/components/ui/status';
import { Tag } from '@/components/ui/tag';
import { cn } from '@/lib/utils';
import type { TaskRow } from '@/types/tasks';

/** The mono source tag's text. A row's own kind (`board`), not the filter's `project` (EPIC-014 A3.7). */
const sourceTag: Record<TaskRow['kind'], string> = { board: 'Board', standalone: 'Standalone' };

/**
 * A row's title, source tag and, while the viewer's own timer runs on it, the running state
 * (EPIC-014 §14.1, §15.3). The title is a link exactly when the row has a page to open (`url`: a board
 * task's `projects.tasks.show`, a standalone task's `tasks.show`) and plain text otherwise — never a
 * dead anchor. A present `url` is always a migrated React task page, so it is always an Inertia
 * `Link`, never a document navigation guessed from the URL shape.
 *
 * At S (D9) the title band is one visual line: the title truncates visually (`text-overflow`, the DOM
 * text is the full title, so assistive technology reads all of it) while the tag and the running
 * state keep their size. From M up the title wraps as before.
 *
 * Done-ness is the row's job (muted text); this cell inherits the colour and adds no strike-through.
 */
export function TaskTitleCell({
    task,
    running = false,
    showSource = true,
}: {
    task: TaskRow;
    running?: boolean;
    /** Off in a project's Tasks tab, where every row is a board task and the tag says nothing. */
    showSource?: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 max-md:flex-nowrap">
            {task.url ? (
                <Link
                    href={task.url}
                    title={task.title}
                    className={cn(
                        'font-medium break-words text-inherit hover:underline max-md:min-w-0 max-md:truncate',
                        focusRing,
                    )}
                >
                    {task.title}
                </Link>
            ) : (
                <span
                    title={task.title}
                    className="font-medium break-words max-md:min-w-0 max-md:truncate"
                >
                    {task.title}
                </span>
            )}
            {showSource ? <Tag className="shrink-0">{sourceTag[task.kind]}</Tag> : null}
            {running ? (
                <Status tone="live" className="shrink-0 whitespace-nowrap">
                    Timer running
                </Status>
            ) : null}
        </div>
    );
}
