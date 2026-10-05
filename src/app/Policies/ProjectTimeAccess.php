<?php

namespace App\Policies;

use App\Models\User;

/**
 * Whose project time a viewer may see (EPIC-015 §7, §12.1; WP5 S3). The caller must already have
 * authorized `ProjectPolicy::view` on the project: this class answers only the time half.
 *
 *   `all`   `time.view_all`: every user's attributed time on the project;
 *   `own`   `time.log` without `time.view_all`: the viewer's own attributed time only;
 *   null    neither: no project time at all (no Overview `time` key, no Time tab, 403 on its route).
 *
 * The one statement of the rule. The Overview `time` key, the Project Time page and the workspace's
 * Time tab all read it, so the tab is offered exactly when the page answers 200 and the Overview's
 * summary and the Time page never disagree about scope. No permission is added or widened: these
 * are the two existing time permissions, read exactly as the Overview read them in WP1.
 */
final class ProjectTimeAccess
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_OWN = 'own';

    /** @return 'all'|'own'|null */
    public static function scope(User $viewer): ?string
    {
        if ($viewer->can('time.view_all')) {
            return self::SCOPE_ALL;
        }

        return $viewer->can('time.log') ? self::SCOPE_OWN : null;
    }
}
