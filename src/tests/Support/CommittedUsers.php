<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Removes users a test COMMITTED to the shared testing database, together with the durable records they own,
 * in dependency order.
 *
 * Release 0.9 (R0.9-1B, decision H1) made the user's business records `ON DELETE RESTRICT`: deleting a user that
 * owns tickets, projects, time and the like is now refused by the database instead of silently cascading
 * through them. Tests that commit real rows (so that worker processes can see them) and used to clean up with
 * "delete the user, the cascade removes the rest" must delete what they created first. This does that, and only
 * that:
 *
 *  - it is scoped by the user ids it is given, never by a pattern or a table sweep, so it cannot remove rows
 *    that belong to anyone else (another test's data, seeded fixtures, the E2E owner);
 *  - it deletes in an order that satisfies every remaining foreign key (time entries before the tasks and
 *    projects they reference, children before parents), so a leftover it did not expect makes it FAIL loudly
 *    rather than be skipped;
 *  - it deletes rows, not files: a test that wrote attachment files removes them itself.
 *
 * The same ordering is the model for a future, deliberate offboarding or erasure process, which is a separate
 * design (decision H7).
 */
final class CommittedUsers
{
    /**
     * Delete the given users and everything they own.
     *
     * @param  int|list<int>  $userIds
     */
    public static function delete(int|array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) $userIds)));

        if ($ids === []) {
            return;
        }

        // Children before parents. The comment names the foreign key each step satisfies.
        $owned = [
            ['invoices', 'client_id'],        // invoices.client_id (items and payments cascade; time entries are detached)
            ['invoices', 'created_by'],       // invoices.created_by
            ['time_entries', 'user_id'],      // before the tasks and projects they reference (RESTRICT)
            ['task_comments', 'user_id'],
            ['tasks', 'created_by'],          // checklists, dependencies and comments cascade
            ['ticket_attachments', 'user_id'],
            ['ticket_replies', 'user_id'],
            ['tickets', 'user_id'],           // replies, attachments and status history cascade
            ['projects', 'created_by'],       // columns, members, milestones, remaining tasks and company links cascade
            ['crm_contacts', 'created_by'],
            ['crm_companies', 'created_by'],
            ['cms_pages', 'created_by'],
            ['organizations', 'owner_id'],    // members cascade
        ];

        foreach ($owned as [$table, $column]) {
            DB::table($table)->whereIn($column, $ids)->delete();
        }

        DB::table('users')->whereIn('id', $ids)->delete();
    }
}
