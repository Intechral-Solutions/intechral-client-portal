<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationPreflight;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedUsers;

/*
 * Release 1.0 (MO-2A1): the administrator-approval migration itself. Kept in its own file for the reason ManagedOrganizationMigrationTest
 * is: DDL commits implicitly in MariaDB, so a test that runs a migration cannot rely on RefreshDatabase's rollback. Tests that need rows
 * around the DDL create them AFTER it (committed) and remove them by id in a finally block, and every test puts the schema back
 * exactly as the suite expects it.
 *
 * What the populated-data behaviour is (rows survive, nothing else moves) is also proven by the migration rehearsal harness.
 */

const OA_MIGRATION = '2026_11_07_090000_add_organization_admin_approval';
const OA_FOREIGN_KEY = 'organization_members_admin_approved_by_foreign';

function oaMigration(): object
{
    return require database_path('migrations/'.OA_MIGRATION.'.php');
}

/** Whatever a test did, leave the schema migrated forward. */
function oaRestoreForward(): void
{
    oaMigration()->up();
}

/** @return object{name: string, delete_rule: string, target: string, target_column: string}|null */
function oaForeignKey(): ?object
{
    return DB::selectOne(
        'select k.constraint_name as name, r.delete_rule, k.referenced_table_name as target, k.referenced_column_name as target_column
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         where k.table_schema = database() and k.table_name = ? and k.column_name = ?',
        ['organization_members', 'admin_approved_by']
    );
}

/** Committed fixtures: an owner, an organization, two members. Returns what the cleanup needs. */
function oaCommittedMembers(): array
{
    $owner = User::factory()->create();
    $legacyAdmin = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $owner->id]);
    DB::table('organization_members')->insert([
        ['organization_id' => $organization->id, 'user_id' => $legacyAdmin->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
    ]);

    return ['owner' => $owner, 'admin' => $legacyAdmin, 'member' => $member, 'organization' => $organization];
}

function oaCleanup(array $fixture): void
{
    DB::table('organization_members')->where('organization_id', $fixture['organization']->id)->delete();
    DB::table('organizations')->where('id', $fixture['organization']->id)->delete();
    CommittedUsers::delete([$fixture['owner']->id, $fixture['admin']->id, $fixture['member']->id]);
}

describe('MO-2A1 approval columns', function () {
    it('appends a nullable datetime and a nullable reference to users that restricts deletes, with no CHECK', function () {
        $columns = collect(Schema::getColumns('organization_members'))->keyBy('name');

        expect($columns['admin_approved_at']['nullable'])->toBeTrue()
            ->and($columns['admin_approved_at']['type'])->toStartWith('datetime')
            ->and($columns['admin_approved_by']['nullable'])->toBeTrue()
            ->and($columns['admin_approved_by']['type'])->toContain('bigint')->toContain('unsigned');

        $key = oaForeignKey();
        expect($key->name)->toBe(OA_FOREIGN_KEY)->and($key->delete_rule)->toBe('RESTRICT')->and($key->target)->toBe('users')->and($key->target_column)->toBe('id');

        // No CHECK: the previous release writes a role without knowing the approval, so approval must not be tied to role = admin.
        expect(DB::selectOne("select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = 'organization_members' and constraint_type = 'CHECK'")->n)->toBe(0);
    });

    it('lets the database hold an approval on a non-administrator row, as a demotion by older code leaves it', function () {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $approver = User::factory()->create();

        DB::table('organization_members')->insert([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'member',
            'admin_approved_at' => now(), 'admin_approved_by' => $approver->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(DB::table('organization_members')->where('user_id', $user->id)->whereNotNull('admin_approved_at')->count())->toBe(1);
    });

    it('restricts deleting an operator who approved a membership', function () {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        $approver = User::factory()->create();
        DB::table('organization_members')->insert([
            'organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'admin',
            'admin_approved_at' => now(), 'admin_approved_by' => $approver->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(fn () => DB::table('users')->where('id', $approver->id)->delete())->toThrow(QueryException::class);
        expect(DB::table('users')->where('id', $approver->id)->exists())->toBeTrue();
    });

    it('keeps every historical row unapproved and unchanged when the migration runs over existing data', function () {
        $migration = oaMigration();
        $fixture = null;

        try {
            $migration->down();   // DDL: the test transaction ends here. Everything below is committed and removed by hand.
            expect(Schema::hasColumn('organization_members', 'admin_approved_at'))->toBeFalse();

            $fixture = oaCommittedMembers();
            $before = DB::table('organization_members')->where('organization_id', $fixture['organization']->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

            $migration->up();

            $after = DB::table('organization_members')->where('organization_id', $fixture['organization']->id)->orderBy('id')->get();
            expect($after->pluck('admin_approved_at')->unique()->all())->toBe([null])
                ->and($after->pluck('admin_approved_by')->unique()->all())->toBe([null])
                ->and($after->map(fn ($row) => ['id' => $row->id, 'organization_id' => $row->organization_id, 'user_id' => $row->user_id, 'role' => $row->role])->all())
                ->toEqual(array_map(fn ($row) => ['id' => $row['id'], 'organization_id' => $row['organization_id'], 'user_id' => $row['user_id'], 'role' => $row['role']], $before));
        } finally {
            oaRestoreForward();
            $fixture && oaCleanup($fixture);
        }
    });

    it('rolls back and forward, and a repeat is harmless', function () {
        $migration = oaMigration();

        try {
            $migration->down();
            $migration->down();
            expect(Schema::hasColumn('organization_members', 'admin_approved_at'))->toBeFalse()
                ->and(Schema::hasColumn('organization_members', 'admin_approved_by'))->toBeFalse()
                ->and(oaForeignKey())->toBeNull();

            $migration->up();
            $migration->up();
            expect(Schema::hasColumn('organization_members', 'admin_approved_by'))->toBeTrue()
                ->and(oaForeignKey()?->name)->toBe(OA_FOREIGN_KEY);

            $migration->down();
            expect(Schema::hasColumn('organization_members', 'admin_approved_at'))->toBeFalse();
        } finally {
            oaRestoreForward();
        }
    });

    it('completes a run that stopped after the foreign key was lost: the named key comes back, ON DELETE RESTRICT', function () {
        try {
            DB::statement('alter table organization_members drop foreign key '.OA_FOREIGN_KEY);
            expect(oaForeignKey())->toBeNull();

            oaMigration()->up();

            expect(oaForeignKey()?->name)->toBe(OA_FOREIGN_KEY)->and(oaForeignKey()->delete_rule)->toBe('RESTRICT')->and(oaForeignKey()->target)->toBe('users');
        } finally {
            oaRestoreForward();
        }
    });

    it('completes an interrupted first run (only the first column exists), keeping the data already in it', function () {
        $migration = oaMigration();
        $fixture = null;

        try {
            $migration->down();
            // What the first run left if it stopped right after creating the first column (DDL commits as it goes).
            DB::statement('alter table organization_members add column admin_approved_at datetime null');

            $fixture = oaCommittedMembers();
            $stamp = '2026-11-01 09:30:00';
            DB::table('organization_members')->where('user_id', $fixture['admin']->id)->update(['admin_approved_at' => $stamp]);

            $migration->up();

            expect(Schema::hasColumn('organization_members', 'admin_approved_by'))->toBeTrue()
                ->and(oaForeignKey()?->name)->toBe(OA_FOREIGN_KEY)
                ->and(oaForeignKey()->delete_rule)->toBe('RESTRICT')
                ->and(DB::table('organization_members')->where('user_id', $fixture['admin']->id)->value('admin_approved_at'))->toBe($stamp, 'existing data kept');
        } finally {
            if (Schema::hasColumn('organization_members', 'admin_approved_at') && $fixture) {
                DB::table('organization_members')->where('organization_id', $fixture['organization']->id)->update(['admin_approved_at' => null, 'admin_approved_by' => null]);
            }
            oaRestoreForward();
            $fixture && oaCleanup($fixture);
        }
    });

    it('stops rather than overwrite a differently-shaped column or foreign key of the same name', function () {
        $migration = oaMigration();

        try {
            $migration->down();
            DB::statement('alter table organization_members add column admin_approved_at varchar(30) null');
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'not a nullable datetime');

            DB::statement('alter table organization_members drop column admin_approved_at');
            $migration->up();

            DB::statement('alter table organization_members drop foreign key '.OA_FOREIGN_KEY);
            DB::statement('alter table organization_members add constraint '.OA_FOREIGN_KEY.' foreign key (admin_approved_by) references users (id) on delete cascade');
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'ON DELETE RESTRICT');

            DB::statement('alter table organization_members drop foreign key '.OA_FOREIGN_KEY);
        } finally {
            $type = collect(Schema::getColumns('organization_members'))->firstWhere('name', 'admin_approved_at')['type'] ?? null;
            if ($type !== null && ! str_starts_with($type, 'datetime')) {
                DB::statement('alter table organization_members drop column admin_approved_at');
            }
            oaRestoreForward();
        }

        expect(oaForeignKey()->delete_rule)->toBe('RESTRICT')
            ->and(collect(Schema::getColumns('organization_members'))->firstWhere('name', 'admin_approved_at')['type'])->toStartWith('datetime');
    });
});

describe('MO-2A1 preflight', function () {
    it('reports the approval inventory as "not migrated yet" before the migration, and as a section after it', function () {
        $migration = oaMigration();

        try {
            $migration->down();

            expect(app(OrganizationPreflight::class)->report()['adminApproval'])->toBeNull();
            $this->artisan('organizations:preflight')->expectsOutputToContain('Administrator approval: not migrated yet')->assertSuccessful();

            $migration->up();

            $report = app(OrganizationPreflight::class)->report();
            expect($report['adminApproval'])->toBeArray()->and($report['adminApproval']['unapprovedAdministrators'])->toBe([]);
        } finally {
            oaRestoreForward();
        }
    });
});
