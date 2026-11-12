<?php

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RehearsalSandbox;

/*
 * Release 1.0 (R1-3C): the integrity snapshot's invitation checks. The rehearsal baseline is the Release 0.9 shape and never runs a 1.0
 * migration (R1-0), so the table is absent there and the checks must not exist; they appear only on a database that has the table. The
 * test adds the real R1-3A migration to the rehearsal primary, breaks one rule at a time, and puts the baseline back.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    $this->baseline = RehearsalSandbox::baseline();
    $this->primary = RehearsalTarget::primary();
    $this->db = fn (): Connection => DB::connection($this->primary->connectionName());
});

/** The real migration, run against the rehearsal primary; dropped again by the caller. */
function invitationTableOn(RehearsalTarget $primary): void
{
    RehearsalDatabase::within($primary, function () use ($primary) {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_11_11_090000_create_organization_invitations_table.php');
        $migration->up();
        expect(Schema::connection($primary->connectionName())->hasTable('organization_invitations'))->toBeTrue();
    });
}

function dropInvitationTable(Connection $db): void
{
    $db->statement('drop table if exists organization_invitations');
}

/** A row the service could have written, then whatever the test changes. */
function integrityInvitation(Connection $db, array $state = []): int
{
    $user = (int) $db->table('users')->orderBy('id')->value('id');
    $organization = (int) $db->table('organizations')->orderBy('id')->value('id');
    $email = 'invitee'.random_int(1, 999999).'@example.test';

    return (int) $db->table('organization_invitations')->insertGetId(array_merge([
        'organization_id' => $organization, 'email' => $email, 'live_email' => $email, 'token_hash' => hash('sha256', $email),
        'invited_by' => $user, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
    ], $state));
}

const INVITATION_CHECKS = [
    'organization_invitations:open_without_slot', 'organization_invitations:slot_is_not_the_address', 'organization_invitations:accepted_still_holding_slot',
    'organization_invitations:accepted_and_revoked', 'organization_invitations:acceptance_half_recorded', 'organization_invitations:revocation_detail_without_revocation',
];

it('has no invitation checks on the Release 0.9 baseline, where the table does not exist', function () {
    expect(array_intersect(INVITATION_CHECKS, array_keys($this->baseline['checks'])))->toBe([]);
});

it('adds the invitation checks once the table exists, all zero on rows the service could have written', function () {
    invitationTableOn($this->primary);

    try {
        $user = (int) ($this->db)()->table('users')->orderBy('id')->value('id');
        integrityInvitation(($this->db)());                                                                       // open
        integrityInvitation(($this->db)(), ['expires_at' => now()->subDay()]);                                    // expired, slot kept
        integrityInvitation(($this->db)(), ['revoked_at' => now(), 'revoked_reason' => 'issuer_suspended']);      // hook-revoked, slot kept
        integrityInvitation(($this->db)(), ['live_email' => null, 'revoked_at' => now(), 'revoked_by' => $user, 'revoked_reason' => 'operator']);
        integrityInvitation(($this->db)(), ['live_email' => null, 'expires_at' => now()->subDay()]);              // superseded after it expired: slot released
        integrityInvitation(($this->db)(), ['live_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $user]);

        $snapshot = IntegritySnapshot::capture($this->primary);

        foreach (INVITATION_CHECKS as $check) {
            expect($snapshot['checks'])->toHaveKey($check)->and($snapshot['checks'][$check])->toBe(0);
        }

        expect(IntegritySnapshot::failures($snapshot))->toBe([]);
    } finally {
        dropInvitationTable(($this->db)());
    }

    expect(IntegritySnapshot::hash(IntegritySnapshot::capture($this->primary)))->toBe(IntegritySnapshot::hash($this->baseline));
});

it('counts each rule it can break, once, and says nothing else', function (string $check, array $state) {
    invitationTableOn($this->primary);

    try {
        $user = (int) ($this->db)()->table('users')->orderBy('id')->value('id');
        integrityInvitation(($this->db)(), array_map(fn ($value) => $value === '@user' ? $user : ($value === '@now' ? now() : $value), $state));

        $failures = IntegritySnapshot::failures(IntegritySnapshot::capture($this->primary));

        expect($failures)->toBe([$check.': 1']);
    } finally {
        dropInvitationTable(($this->db)());
    }
})->with([
    'an open row without its slot' => ['organization_invitations:open_without_slot', ['live_email' => null]],
    'a slot that is not the address' => ['organization_invitations:slot_is_not_the_address', ['live_email' => 'other@example.test']],
    'an accepted row still holding the slot' => ['organization_invitations:accepted_still_holding_slot', ['accepted_at' => '@now', 'accepted_user_id' => '@user']],
    'accepted and revoked' => ['organization_invitations:accepted_and_revoked', ['live_email' => null, 'accepted_at' => '@now', 'accepted_user_id' => '@user', 'revoked_at' => '@now', 'revoked_reason' => 'operator']],
    'an acceptance without its account' => ['organization_invitations:acceptance_half_recorded', ['live_email' => null, 'accepted_at' => '@now']],
    'a revocation reason without a revocation' => ['organization_invitations:revocation_detail_without_revocation', ['revoked_reason' => 'operator']],
]);

it('reads the expiry in the application clock, not the database server\'s', function () {
    invitationTableOn($this->primary);

    try {
        // Without a slot and expiring one minute from now: open by the application's clock, so the row is broken. Were the check to use the
        // database server's own NOW() in a different zone, this row would read as expired and pass.
        integrityInvitation(($this->db)(), ['live_email' => null, 'expires_at' => now()->addMinute()]);

        expect(IntegritySnapshot::failures(IntegritySnapshot::capture($this->primary)))->toBe(['organization_invitations:open_without_slot: 1']);
    } finally {
        dropInvitationTable(($this->db)());
    }
});
