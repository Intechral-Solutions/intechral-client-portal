<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-3A): the organization_invitations migration. The schema is the first line of the invariants (an invitation names exactly
 * one organization, carries no role and no status, cannot be accepted by two accounts, and no (organization, address) has two slot holders),
 * so it is read back from information_schema. DDL commits implicitly in MariaDB, so the round-trip test cannot lean on RefreshDatabase's
 * rollback: it puts the schema back in a finally block (the table is empty inside this suite).
 */

const OIM_MIGRATION = '2026_11_11_090000_create_organization_invitations_table';

function oimMigration(): object
{
    return require database_path('migrations/'.OIM_MIGRATION.'.php');
}

it('has exactly the designed columns: no status, no role, no plaintext token', function () {
    $columns = collect(Schema::getColumns('organization_invitations'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'organization_id', 'email', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id',
        'revoked_at', 'revoked_by', 'revoked_reason', 'live_email', 'created_at', 'updated_at',
    ]);

    foreach (['organization_id', 'email', 'token_hash', 'invited_by', 'expires_at'] as $required) {
        expect($columns[$required]['nullable'])->toBeFalse("{$required} must be NOT NULL");
    }
    foreach (['accepted_at', 'accepted_user_id', 'revoked_at', 'revoked_by', 'revoked_reason', 'live_email'] as $optional) {
        expect($columns[$optional]['nullable'])->toBeTrue("{$optional} must be nullable");
    }

    expect($columns['token_hash']['type'])->toBe('char(64)')
        ->and($columns['revoked_reason']['type'])->toBe('varchar(32)')
        ->and($columns['expires_at']['type'])->toStartWith('datetime')
        ->and($columns['organization_id']['type'])->toContain('bigint')->toContain('unsigned');
});

it('has the unique keys that carry the invariants, and only those', function () {
    $unique = DB::select("select index_name as name, group_concat(column_name order by seq_in_index) as cols from information_schema.statistics
        where table_schema = database() and table_name = 'organization_invitations' and non_unique = 0 group by index_name order by index_name");

    $actual = collect($unique)->mapWithKeys(fn ($r) => [$r->name => $r->cols])->all();
    ksort($actual);

    expect($actual)->toBe([
        'PRIMARY' => 'id',
        'organization_invitations_accepted_user_id_unique' => 'accepted_user_id',
        'organization_invitations_org_live_email_unique' => 'organization_id,live_email',
        'organization_invitations_token_hash_unique' => 'token_hash',
    ]);
});

it('restricts delete and update on all four foreign keys, so an invitation is provenance and never cascades away', function () {
    $keys = collect(DB::select(
        'select k.column_name as col, k.referenced_table_name as parent, r.delete_rule as d, r.update_rule as u
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         where k.table_schema = database() and k.table_name = ? and k.referenced_table_name is not null order by k.column_name',
        ['organization_invitations']
    ))->mapWithKeys(fn ($r) => [$r->col => "{$r->parent} {$r->d}/{$r->u}"])->all();

    expect($keys)->toBe([
        'accepted_user_id' => 'users RESTRICT/RESTRICT',
        'invited_by' => 'users RESTRICT/RESTRICT',
        'organization_id' => 'organizations RESTRICT/RESTRICT',
        'revoked_by' => 'users RESTRICT/RESTRICT',
    ]);
});

it('is listed in the committed foreign-key manifest that database:verify checks', function () {
    $manifest = json_decode(file_get_contents(database_path('profile/foreign-keys.json')), true)['foreign_keys'];

    expect(collect($manifest)->filter(fn ($rule, $key) => str_starts_with($key, 'organization_invitations.'))->all())->toBe([
        'organization_invitations.accepted_user_id->users.id' => 'RESTRICT/RESTRICT',
        'organization_invitations.invited_by->users.id' => 'RESTRICT/RESTRICT',
        'organization_invitations.organization_id->organizations.id' => 'RESTRICT/RESTRICT',
        'organization_invitations.revoked_by->users.id' => 'RESTRICT/RESTRICT',
    ]);
});

it('creates the table on a fresh migration and drops it on down, and the round trip leaves the schema as it was', function () {
    try {
        oimMigration()->down();
        expect(Schema::hasTable('organization_invitations'))->toBeFalse();
    } finally {
        oimMigration()->up();
    }

    expect(Schema::hasTable('organization_invitations'))->toBeTrue();
    expect(collect(Schema::getColumns('organization_invitations'))->pluck('name')->all())->toContain('token_hash', 'live_email');
});
