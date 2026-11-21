<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            ['organization_invitations', 'accepted_user_id'], // organization_invitations.* (R1-3A, RESTRICT): provenance rows that name a user go first
            ['organization_invitations', 'revoked_by'],
            ['organization_invitations', 'invited_by'],
            ['organization_members', 'support_granted_by'], // organization_members.support_granted_by (R1-4, RESTRICT): the designation rows an operator gave go first
            ['invoices', 'client_id'],        // invoices.client_id (items and payments cascade; time entries are detached)
            ['invoices', 'created_by'],       // invoices.created_by
            ['time_entries', 'user_id'],      // before the tasks and projects they reference (RESTRICT)
            ['task_comments', 'user_id'],
            ['tasks', 'created_by'],          // checklists, dependencies and comments cascade
            ['ticket_attachments', 'user_id'],
            ['ticket_replies', 'user_id'],
            ['tickets', 'submitted_by_id'],   // tickets.submitted_by_id (R1-2, RESTRICT); replies, attachments and status history cascade
            ['tickets', 'user_id'],           // replies, attachments and status history cascade
            ['projects', 'created_by'],       // columns, members, milestones, remaining tasks and company links cascade
            ['crm_contacts', 'created_by'],
            ['crm_companies', 'created_by'],           // the historical schema only (ORG-2 retired the table): skipped below when it does not exist
            ['cms_pages', 'created_by'],
            ['organizations', 'created_by'],           // ORG-2, canonical schema only: replaces crm_companies.created_by (skipped below on the historical one)
            ['organizations', 'portal_enabled_by'],    // members are RESTRICT since ORG-1: they are deleted first, below
        ];

        foreach ($owned as [$table, $column]) {
            // The same helper serves the current schema and the historical one the tests under tests/Historical build: a key whose table or column the schema
            // in force does not have is not there to satisfy.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // ORG-1: `organization_members.organization_id` is RESTRICT, so the members of an Organization go before the Organization does.
            if ($table === 'organizations') {
                DB::table('organization_members')->whereIn('organization_id', DB::table('organizations')->whereIn($column, $ids)->select('id'))->delete();
            }

            DB::table($table)->whereIn($column, $ids)->delete();
        }

        DB::table('users')->whereIn('id', $ids)->delete();
    }
}
