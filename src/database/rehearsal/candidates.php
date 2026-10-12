<?php

/*
|--------------------------------------------------------------------------
| Release 0.9 migration rehearsal: the reviewed candidate migrations
|--------------------------------------------------------------------------
| The rehearsal harness (App\Support\Rehearsal, `php artisan rehearsal:run`) builds its baseline from the
| migrations pinned in baseline.php, seeds records, snapshots them, applies the candidates the operator selects
| with --candidate=<name>, snapshots again, and reports any difference it was not told to expect. It runs ONLY
| migrations that are listed here, are present in database/migrations, and whose file still has the recorded
| SHA-256, so what is rehearsed is exactly what was reviewed. Nothing else (no path, no other PHP file) is ever
| executed by it. A migration file named in neither baseline.php nor this file is refused before anything is
| changed; it can never silently join the baseline.
|
| An entry is added in the same change that adds the migration, and must sort after every baseline migration.
| Shape:
|
|   '2026_10_12_000001_example_migration' => [
|       'summary' => 'One sentence a reviewer can check the change against.',
|       'sha256'  => hash('sha256', file contents of database/migrations/<name>.php),
|       // Every structural change the migration is MEANT to make, in the harness's notation. The rehearsal
|       // fails if the observed schema difference is not exactly this list.
|       'schema'  => ['column+:users.example', 'fk~:tickets.user_id->users.id:CASCADE>RESTRICT'],
|       // Tables whose existing rows the migration is MEANT to change (for example a backfill). Empty for
|       // an additive or tightening migration.
|       'data'    => [],
|   ],
|
| Notation: table+:t . table-:t . column+:t.c . column-:t.c . column~:t.c (type or nullability changed) .
| collation~:t.c . tablecollation~:t . primary~:t . unique+:t(a,b) . unique-:t(a,b) . index+:t(a,b) .
| index-:t(a,b) . fk+:t.c->p.c:DELETE/UPDATE . fk-:t.c->p.c:DELETE/UPDATE . fk~:t.c->p.c:OLD>NEW (ON DELETE rule) .
| fku~:t.c->p.c:OLD>NEW (ON UPDATE rule).
|
| R0.9-1B: the three Release 0.9 schema-protection migrations (H1, H2 and the schema portion of H3). Once a
| candidate has shipped and a later package needs its columns in the fixtures, move its entry to the END of
| baseline.php as `name => sha256` in the same change that updates the fixtures.
*/

return [
    '2026_10_12_000001_add_disabled_at_to_users_table' => [
        'summary' => 'H3 (schema only): add the nullable users.disabled_at timestamp. No backfill, index or enforcement.',
        'sha256' => 'a67d04a080a0d729f47118adf1c4eb55f0c97b4d1f95d343644970c67a1778cb',
        'schema' => [
            'column+:users.disabled_at',
        ],
        'data' => [],
    ],

    '2026_10_12_000002_restrict_user_business_record_deletes' => [
        'summary' => 'H1: change the 13 user-referencing business foreign keys from ON DELETE CASCADE to RESTRICT.',
        'sha256' => '2d7a7ee13d408a41bcb0d7f220454757450e292c5826e4dbfe37b8c951d89892',
        'schema' => [
            'fk~:tickets.user_id->users.id:CASCADE>RESTRICT',
            'fk~:ticket_replies.user_id->users.id:CASCADE>RESTRICT',
            'fk~:ticket_attachments.user_id->users.id:CASCADE>RESTRICT',
            'fk~:time_entries.user_id->users.id:CASCADE>RESTRICT',
            'fk~:task_comments.user_id->users.id:CASCADE>RESTRICT',
            'fk~:tasks.created_by->users.id:CASCADE>RESTRICT',
            'fk~:projects.created_by->users.id:CASCADE>RESTRICT',
            'fk~:organizations.owner_id->users.id:CASCADE>RESTRICT',
            'fk~:crm_companies.created_by->users.id:CASCADE>RESTRICT',
            'fk~:crm_contacts.created_by->users.id:CASCADE>RESTRICT',
            'fk~:cms_pages.created_by->users.id:CASCADE>RESTRICT',
            'fk~:invoices.client_id->users.id:CASCADE>RESTRICT',
            'fk~:invoices.created_by->users.id:CASCADE>RESTRICT',
        ],
        'data' => [],
    ],

    '2026_10_12_000003_restrict_company_project_association_deletes' => [
        'summary' => 'H2: change project_company.crm_company_id from ON DELETE CASCADE to RESTRICT.',
        'sha256' => 'b21de2b29872e2a828fadadb9072527554ea04677f373fbf375c0601a0479865',
        'schema' => [
            'fk~:project_company.crm_company_id->crm_companies.id:CASCADE>RESTRICT',
        ],
        'data' => [],
    ],
];
