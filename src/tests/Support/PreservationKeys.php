<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Release 0.9 (R0.9-1B): the approved foreign-key inventory, shared by the schema, migration and deletion tests so
 * each file can run on its own.
 */
final class PreservationKeys
{
    /** The 13 H1 foreign keys to `users` (table, column, constraint). The two renamed tables keep their original constraint names. */
    public const USER_KEYS = [
        ['tickets', 'user_id', 'tickets_user_id_foreign'],
        ['ticket_replies', 'user_id', 'ticket_replies_user_id_foreign'],
        ['ticket_attachments', 'user_id', 'ticket_attachments_user_id_foreign'],
        ['time_entries', 'user_id', 'time_entries_user_id_foreign'],
        ['task_comments', 'user_id', 'project_task_comments_user_id_foreign'],
        ['tasks', 'created_by', 'project_tasks_created_by_foreign'],
        ['projects', 'created_by', 'projects_created_by_foreign'],
        ['organizations', 'owner_id', 'organizations_owner_id_foreign'],
        ['crm_companies', 'created_by', 'crm_companies_created_by_foreign'],
        ['crm_contacts', 'created_by', 'crm_contacts_created_by_foreign'],
        ['cms_pages', 'created_by', 'cms_pages_created_by_foreign'],
        ['invoices', 'client_id', 'invoices_client_id_foreign'],
        ['invoices', 'created_by', 'invoices_created_by_foreign'],
    ];

    /**
     * The same count of keys as they stand in the LIVE (canonical) schema. Two things changed since the historical H1 inventory above:
     *   - Release 1.0 (ORG-1): `organizations.owner_id` was renamed `portal_enabled_by` and the foreign key kept its original constraint name;
     *   - Release 1.0 (ORG-2): `crm_companies` was retired, so its `created_by` key is gone, and `organizations.created_by` (NOT NULL, RESTRICT to users,
     *     constraint `organizations_created_by_foreign`) takes its place: the creator of a durable Organization record is protected exactly as the creator
     *     of a Company profile was.
     * {@see USER_KEYS} stays the historical H1 inventory (the pinned M2 migration and the rehearsal harness, whose databases never run those migrations, still
     * know the schema that way).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function liveUserKeys(): array
    {
        return array_map(fn (array $key) => match (true) {
            $key[0] === 'organizations' && $key[1] === 'owner_id' => ['organizations', 'portal_enabled_by', $key[2]],
            $key[0] === 'crm_companies' => ['organizations', 'created_by', 'organizations_created_by_foreign'],
            default => $key,
        }, self::USER_KEYS);
    }

    /**
     * Every foreign key that points at `users` or `organizations`, as "table.column -> parent" => "DELETE/UPDATE (constraint)". The canonical counterpart of
     * the historical "users and crm_companies" listing: `crm_companies` is gone, and the Organization is now the parent of the Person affiliation and the
     * project link, so those references are part of the same contract.
     *
     * @return array<string, string>
     */
    public static function toUsersAndOrganizations(): array
    {
        return collect(DB::select(
            "select k.table_name as t, k.column_name as c, k.referenced_table_name as p, k.constraint_name as n, r.delete_rule as d, r.update_rule as u
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = database() and k.referenced_table_name in ('users', 'organizations')
             order by k.table_name, k.column_name"
        ))->mapWithKeys(fn (object $row) => ["{$row->t}.{$row->c} -> {$row->p}" => "{$row->d}/{$row->u} ({$row->n})"])->all();
    }
}
