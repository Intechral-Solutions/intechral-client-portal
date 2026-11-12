<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-3B cleanup): the migration that removes the retired organization-less invitation's persistence. DDL commits implicitly in
 * MariaDB, so the round-trip tests cannot lean on RefreshDatabase's rollback: each puts the schema back in a finally block.
 */

const RLI_MIGRATION = '2026_11_12_090000_retire_legacy_invitations';

function rliMigration(): object
{
    return require database_path('migrations/'.RLI_MIGRATION.'.php');
}

it('leaves a fresh install without the legacy table and column, and with the new entity and users.invited_by', function () {
    expect(Schema::hasTable('invitations'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'invitation_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'invited_by'))->toBeTrue()
        ->and(Schema::hasTable('organization_invitations'))->toBeTrue();

    // Both legacy keys are out of the committed manifest that database:verify enforces, and users.invited_by stays in it.
    $manifest = json_decode(file_get_contents(database_path('profile/foreign-keys.json')), true)['foreign_keys'];
    expect($manifest)->not->toHaveKey('users.invitation_id->invitations.id')
        ->and($manifest)->not->toHaveKey('invitations.invited_by->users.id')
        ->and($manifest)->toHaveKey('users.invited_by->users.id');
});

it('is a no-op on a schema that already lacks both objects', function () {
    rliMigration()->up();

    expect(Schema::hasTable('invitations'))->toBeFalse()->and(Schema::hasColumn('users', 'invitation_id'))->toBeFalse();
});

it('removes a POPULATED legacy structure, keeps every user row and users.invited_by, and down() restores an empty one', function () {
    try {
        rliMigration()->down();
        expect(Schema::hasTable('invitations'))->toBeTrue()->and(Schema::hasColumn('users', 'invitation_id'))->toBeTrue();

        $inviter = DB::table('users')->insertGetId(['name' => 'Inviter', 'email' => 'inviter@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $legacy = DB::table('invitations')->insertGetId(['email' => 'legacy@example.test', 'token' => str_repeat('t', 64), 'invited_by' => $inviter, 'status' => 'pending', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $invitee = DB::table('users')->insertGetId(['name' => 'Invitee', 'email' => 'invitee@example.test', 'password' => 'x', 'invitation_id' => $legacy, 'invited_by' => $inviter, 'created_at' => now(), 'updated_at' => now()]);
    } finally {
        rliMigration()->up();
    }

    expect(Schema::hasTable('invitations'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'invitation_id'))->toBeFalse()
        ->and((int) DB::table('users')->where('id', $invitee)->value('invited_by'))->toBe($inviter)
        ->and(DB::table('users')->whereIn('id', [$inviter, $invitee])->count())->toBe(2);

    $foreign = collect(Schema::getForeignKeys('users'))->map(fn ($key) => implode(',', $key['columns']))->all();
    expect($foreign)->toContain('invited_by')->not->toContain('invitation_id');

    DB::table('users')->whereIn('id', [$invitee, $inviter])->delete();
});
