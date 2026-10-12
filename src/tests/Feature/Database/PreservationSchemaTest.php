<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PreservationKeys;

/*
 * Release 0.9 (R0.9-1B): the schema the migrations leave behind, read from information_schema. Metadata only, no
 * rows, so nothing here depends on the RefreshDatabase transaction. The migrations themselves (down, up, the
 * preflight, a resume after interruption) are tested in PreservationMigrationsTest; what happens to DATA when a
 * parent is deleted is tested in PreservationDeletionTest.
 */

it('restricts delete on each of the 13 business-record foreign keys to users, under its original name, and keeps ON UPDATE', function (string $table, string $column, string $constraint) {
    $key = DB::selectOne(
        'select k.constraint_name as name, k.referenced_table_name as parent, k.referenced_column_name as parent_column, r.delete_rule as d, r.update_rule as u, c.is_nullable as nullable
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r
           on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         join information_schema.columns c on c.table_schema = k.table_schema and c.table_name = k.table_name and c.column_name = k.column_name
         where k.table_schema = database() and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null',
        [$table, $column]
    );

    expect($key)->not->toBeNull("{$table}.{$column} has no foreign key")
        ->and($key->name)->toBe($constraint)
        ->and($key->parent)->toBe('users')
        ->and($key->parent_column)->toBe('id')
        ->and($key->d)->toBe('RESTRICT')
        ->and($key->u)->toBe('RESTRICT')
        ->and($key->nullable)->toBe('NO');
})->with(PreservationKeys::USER_KEYS);

it('restricts delete on project_company.crm_company_id (H2), under its original name, and keeps ON UPDATE', function () {
    $keys = PreservationKeys::toUsersAndCompanies();

    expect($keys['project_company.crm_company_id -> crm_companies'])->toBe('RESTRICT/RESTRICT (project_company_crm_company_id_foreign)')
        ->and(DB::selectOne("select is_nullable as n from information_schema.columns where table_schema = database() and table_name = 'project_company' and column_name = 'crm_company_id'")->n)->toBe('NO');
});

it('changes exactly those 14 foreign keys and no other reference to users or companies', function () {
    $restricted = [];
    foreach (PreservationKeys::USER_KEYS as [$table, $column, $constraint]) {
        $restricted["{$table}.{$column} -> users"] = "RESTRICT/RESTRICT ({$constraint})";
    }
    $restricted['project_company.crm_company_id -> crm_companies'] = 'RESTRICT/RESTRICT (project_company_crm_company_id_foreign)';

    $unchanged = [
        // Credential and membership children and derived rows: still removed with their user (H1, "Unchanged").
        'organization_members.user_id -> users' => 'CASCADE/RESTRICT (organization_members_user_id_foreign)',
        'password_histories.user_id -> users' => 'CASCADE/RESTRICT (password_histories_user_id_foreign)',
        'project_members.user_id -> users' => 'CASCADE/RESTRICT (project_members_user_id_foreign)',
        'social_accounts.user_id -> users' => 'CASCADE/RESTRICT (social_accounts_user_id_foreign)',
        'time_entry_blocks.user_id -> users' => 'CASCADE/RESTRICT (time_entry_blocks_user_id_foreign)',
        // Nullable pointers: the record stays and the pointer is cleared.
        'cms_pages.updated_by -> users' => 'SET NULL/RESTRICT (cms_pages_updated_by_foreign)',
        'invitations.invited_by -> users' => 'SET NULL/RESTRICT (invitations_invited_by_foreign)',
        'project_milestones.completed_by -> users' => 'SET NULL/RESTRICT (project_milestones_completed_by_foreign)',
        'tasks.assignee_id -> users' => 'SET NULL/RESTRICT (project_tasks_assignee_id_foreign)',
        'ticket_status_histories.user_id -> users' => 'SET NULL/RESTRICT (ticket_status_histories_user_id_foreign)',
        'tickets.assignee_id -> users' => 'SET NULL/RESTRICT (tickets_assignee_id_foreign)',
        'users.invited_by -> users' => 'SET NULL/RESTRICT (users_invited_by_foreign)',
        // The other company references.
        'crm_contacts.crm_company_id -> crm_companies' => 'SET NULL/RESTRICT (crm_contacts_crm_company_id_foreign)',
        'tickets.company_id -> crm_companies' => 'SET NULL/RESTRICT (tickets_company_id_foreign)',
    ];

    $expected = $restricted + $unchanged;
    ksort($expected);
    $actual = PreservationKeys::toUsersAndCompanies();
    ksort($actual);

    expect($actual)->toBe($expected);
});

it('leaves no temporary constraint behind from the two-step replacement', function () {
    $left = DB::select(
        "select table_name as t, constraint_name as n from information_schema.referential_constraints
         where constraint_schema = database() and constraint_name like '%\\_restrict'"
    );

    expect($left)->toBe([]);
});

it('adds users.disabled_at as a nullable timestamp with no default, and nothing else about users changes shape', function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'disabled_at');
    $default = $column['default'] ?? null;

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and($column['type_name'])->toBe('timestamp')
        ->and($default === null || strtoupper((string) $default) === 'NULL')->toBeTrue()
        // No index was added for it.
        ->and(DB::select("select index_name from information_schema.statistics where table_schema = database() and table_name = 'users' and column_name = 'disabled_at'"))->toBe([]);
});

it('adds users.disabled_at last, so the existing columns keep their order', function () {
    $columns = array_column(Schema::getColumns('users'), 'name');

    expect(end($columns))->toBe('disabled_at')
        ->and(array_slice($columns, 0, 3))->toBe(['id', 'name', 'email']);
});
