<?php

namespace App\Shared\Navigation;

/**
 * The semantic projection hint on a contextual navigation section (EPIC-013 §12.2).
 *
 * `kind` is what lets a non-drawer presentation project a section sensibly instead of guessing.
 * It describes what the section MEANS, never how it is drawn: the Operational presentation renders
 * `Views` as drawer rows and `Actions` as a drawer action, while a later Focused presentation
 * renders the same data as a view selector and a top-bar button. An unknown value must render as a
 * plain section rather than throwing.
 */
enum ContextKind: string
{
    /** Mutually exclusive ways of looking at the same workspace. */
    case Views = 'views';

    /**
     * Filtered work lists, countable.
     *
     * Declared, with no emitter in this epic: `count` is reserved and always null (§12.3 rule 8),
     * so a queue section would render a count slot nothing can fill. Its named first consumer is
     * the Helpdesk operator queue, once an endpoint provides counts.
     */
    case Queues = 'queues';

    /** Specific records (recent / watched). Declared; no emitter exists in the application yet. */
    case Entities = 'entities';

    /** User-saved views. Declared; no emitter exists in the application yet. */
    case Saved = 'saved';

    /** Workspace-level actions rather than destinations. Never carries active state (A1.10). */
    case Actions = 'actions';
}
