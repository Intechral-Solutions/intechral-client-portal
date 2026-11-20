<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedUsers;

/*
 * Release 1.0 (ORG-1): the portal-state migration itself. Kept in its own file for the reason ManagedOrganizationMigrationTest and
 * OrganizationAdminApprovalMigrationTest are: DDL commits implicitly in MariaDB, so a test that runs a migration cannot rely on RefreshDatabase's
 * rollback. Tests that need rows around the DDL create them AFTER it (committed) and remove them by id in a finally block, and every test puts the
 * schema back exactly as the suite expects it (forward, with the portal-state columns).
 *
 * What it proves, on the supported database (MariaDB 11.4, where the CHECKs are ENFORCED, not merely accepted: error 4025):
 *   - the shape (columns, the renamed provenance column and its unchanged foreign key, the four named CHECKs, RESTRICT on membership);
 *   - the backfill of every existing Organization (portal_enabled_at from created_at, portal_enabled_by from the prior owner_id);
 *   - that each CHECK rejects the invalid row, and each state it protects can be reached only through a valid pair;
 *   - that a run that stopped part-way is completed by the next run (DDL commits implicitly), and a repeat changes nothing;
 *   - that down() is schema-only and REFUSES, changing nothing, while an Organization has no enabling operator.
 */

const PS1_MIGRATION = '2026_11_19_090000_add_organization_portal_state';
const PS1_CHECKS = [
    'organizations_portal_provenance_pair_check',
    'organizations_disabled_requires_portal_check',
    'organizations_partner_requires_portal_check',
    'organizations_managed_requires_portal_check',
];

function ps1Migration(): object
{
    return require database_path('migrations/'.PS1_MIGRATION.'.php');
}

/** Whatever a test did, leave the schema migrated forward. */
function ps1Forward(): void
{
    ps1Migration()->up();
}

/** @return list<string> */
function ps1Checks(): array
{
    return collect(DB::select("select constraint_name as n from information_schema.table_constraints where constraint_schema = database() and table_name = 'organizations' and constraint_type = 'CHECK' order by constraint_name"))
        ->pluck('n')->filter(fn ($n) => in_array($n, PS1_CHECKS, true))->values()->all();
}

/** @return object{name: string, delete_rule: string, target: string}|null */
function ps1ForeignKey(string $table, string $column): ?object
{
    return DB::selectOne(
        'select k.constraint_name as name, r.delete_rule, k.referenced_table_name as target
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         where k.table_schema = database() and k.table_name = ? and k.column_name = ?',
        [$table, $column]
    );
}

/** The whole shape the migration promises, as one list of failures (empty when right). @return list<string> */
function ps1ShapeProblems(): array
{
    $problems = [];
    $columns = collect(Schema::getColumns('organizations'))->keyBy('name');

    foreach (['portal_enabled_at', 'portal_enabled_by'] as $column) {
        if (! isset($columns[$column]) || ! $columns[$column]['nullable']) {
            $problems[] = "{$column} is missing or not nullable";
        }
    }

    if (isset($columns['owner_id'])) {
        $problems[] = 'owner_id still exists';
    }

    $key = ps1ForeignKey('organizations', 'portal_enabled_by');
    if ($key === null || $key->delete_rule !== 'RESTRICT' || $key->target !== 'users') {
        $problems[] = 'portal_enabled_by is not a RESTRICT foreign key to users';
    }

    $members = ps1ForeignKey('organization_members', 'organization_id');
    if ($members === null || $members->delete_rule !== 'RESTRICT' || $members->target !== 'organizations') {
        $problems[] = 'organization_members.organization_id is not RESTRICT';
    }

    if (ps1Checks() !== collect(PS1_CHECKS)->sort()->values()->all()) {
        $problems[] = 'the four CHECK constraints are not exactly present: '.json_encode(ps1Checks());
    }

    return $problems;
}

// ── Shape ────────────────────────────────────────────────────────────────────

it('leaves exactly the promised shape: nullable provenance, an unchanged foreign key, four named CHECKs and RESTRICT on membership', function () {
    expect(ps1ShapeProblems())->toBe([]);

    $columns = collect(Schema::getColumns('organizations'))->keyBy('name');
    expect($columns['portal_enabled_at']['type'])->toStartWith('datetime')
        ->and($columns['portal_enabled_by']['type'])->toContain('bigint')->toContain('unsigned')
        // The constraint keeps its original name, so the pinned history and the preservation tests still name what they always did.
        ->and(ps1ForeignKey('organizations', 'portal_enabled_by')->name)->toBe('organizations_owner_id_foreign')
        ->and(DB::table('migrations')->where('migration', PS1_MIGRATION)->exists())->toBeTrue();
});

it('enforces every CHECK on this database, not merely accepts the syntax', function () {
    $enabled = Organization::factory()->create();
    $directoryOnly = Organization::factory()->directoryOnly()->create();
    $other = Organization::factory()->create();

    $rejected = fn (Closure $write) => (function () use ($write) {
        try {
            $write();
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }

        return null;
    })();

    // The provenance pair: set or NULL together, in both directions.
    expect($rejected(fn () => DB::table('organizations')->where('id', $enabled->id)->update(['portal_enabled_at' => null])))->toBe(4025)
        ->and($rejected(fn () => DB::table('organizations')->where('id', $enabled->id)->update(['portal_enabled_by' => null])))->toBe(4025)
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['portal_enabled_at' => now()])))->toBe(4025)
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['portal_enabled_by' => $enabled->portal_enabled_by])))->toBe(4025)
        // A never-enabled Organization cannot be Disabled, a partner, or managed.
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['disabled_at' => now()])))->toBe(4025)
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['can_manage_customers' => 1])))->toBe(4025)
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['managed_by_organization_id' => $other->id])))->toBe(4025)
        ->and($rejected(fn () => Organization::factory()->directoryOnly()->disabled()->create()))->toBe(4025);

    // And the same states are reachable by a valid row.
    expect($rejected(fn () => DB::table('organizations')->where('id', $enabled->id)->update(['disabled_at' => now()])))->toBeNull()
        ->and($rejected(fn () => DB::table('organizations')->where('id', $other->id)->update(['can_manage_customers' => 1])))->toBeNull()
        ->and($rejected(fn () => DB::table('organizations')->where('id', $directoryOnly->id)->update(['portal_enabled_at' => now(), 'portal_enabled_by' => $enabled->portal_enabled_by])))->toBeNull();
});

it('makes the database refuse to delete an Organization that still has a member, and still deletes one that has none', function () {
    $organization = Organization::factory()->create();
    $empty = Organization::factory()->create();
    $member = User::factory()->create();
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('organizations')->where('id', $organization->id)->delete())->toThrow(QueryException::class)
        ->and(DB::table('organizations')->where('id', $organization->id)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('organization_id', $organization->id)->exists())->toBeTrue();

    DB::table('organizations')->where('id', $empty->id)->delete();
    expect(DB::table('organizations')->where('id', $empty->id)->exists())->toBeFalse();
});

it('restricts deleting the operator who enabled an Organization, as the owner column did', function () {
    $organization = Organization::factory()->create();

    expect(fn () => DB::table('users')->where('id', $organization->portal_enabled_by)->delete())->toThrow(QueryException::class);
});

// ── Backfill, repetition and resumption ──────────────────────────────────────

it('backfills every existing Organization from created_at and the prior owner, and a repeat changes nothing', function () {
    $migration = ps1Migration();
    $owner = null;
    $ids = [];

    try {
        $migration->down();   // DDL: the test transaction ends here. Everything below is committed and removed by hand.
        expect(Schema::hasColumn('organizations', 'owner_id'))->toBeTrue()->and(Schema::hasColumn('organizations', 'portal_enabled_at'))->toBeFalse();

        $owner = User::factory()->create();
        $row = fn (string $slug, ?string $createdAt, ?string $updatedAt) => DB::table('organizations')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'owner_id' => $owner->id, 'created_at' => $createdAt, 'updated_at' => $updatedAt,
        ]);
        $ids = [
            'normal' => $row('ps1-normal', '2025-03-04 05:06:07', '2026-01-01 00:00:00'),
            'noCreated' => $row('ps1-no-created', null, '2025-05-06 07:08:09'),
            'noDates' => $row('ps1-no-dates', null, null),
        ];
        $partner = $ids['normal'];
        DB::table('organizations')->where('id', $partner)->update(['can_manage_customers' => 1, 'disabled_at' => '2026-02-02 02:02:02']);

        $migration->up();
        $rows = DB::table('organizations')->whereIn('id', array_values($ids))->get()->keyBy('id');

        expect($rows[$ids['normal']]->portal_enabled_at)->toBe('2025-03-04 05:06:07')                // created_at
            ->and($rows[$ids['noCreated']]->portal_enabled_at)->toBe('2025-05-06 07:08:09')           // updated_at when created_at is missing
            ->and(abs((int) DB::selectOne('select timestampdiff(second, ?, now()) as d', [$rows[$ids['noDates']]->portal_enabled_at])->d))->toBeLessThan(30) // now, rather than a NULL the pairing CHECK forbids
            ->and($rows->pluck('portal_enabled_by')->map(fn ($v) => (int) $v)->unique()->all())->toBe([$owner->id])
            // The other columns came through the rename and the checks untouched.
            ->and((bool) $rows[$ids['normal']]->can_manage_customers)->toBeTrue()
            ->and($rows[$ids['normal']]->disabled_at)->toBe('2026-02-02 02:02:02')
            ->and(ps1ShapeProblems())->toBe([]);

        $snapshot = DB::table('organizations')->whereIn('id', array_values($ids))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $migration->up();      // everything already done: nothing to do, nothing fails, nothing moves
        expect(DB::table('organizations')->whereIn('id', array_values($ids))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot);
    } finally {
        DB::table('organizations')->whereIn('id', array_values($ids))->update(['managed_by_organization_id' => null]);
        DB::table('organizations')->whereIn('id', array_values($ids))->delete();

        if ($owner !== null) {
            CommittedUsers::delete([$owner->id]);
        }

        ps1Forward();
    }

    expect(ps1ShapeProblems())->toBe([]);
});

it('completes a run that stopped part-way, from each point DDL commits implicitly', function (string $label, Closure $leave) {
    $migration = ps1Migration();

    try {
        $migration->down();
        $leave();
        $migration->up();
        expect(ps1ShapeProblems())->toBe([], "{$label}: the resumed run did not finish the job");
    } finally {
        ps1Forward();
    }
})->with([
    'only the new column was added' => ['column added', fn () => DB::statement('alter table organizations add portal_enabled_at datetime null')],
    'the column was added and renamed, but is still NOT NULL' => ['renamed', function () {
        DB::statement('alter table organizations add portal_enabled_at datetime null');
        DB::statement('alter table organizations rename column owner_id to portal_enabled_by');
    }],
    'every step but the membership foreign key was done' => ['all but members key', function () {
        ps1Migration()->up();
        DB::statement('alter table organization_members drop foreign key organization_members_organization_id_foreign');
        DB::statement('alter table organization_members add constraint organization_members_organization_id_foreign foreign key (organization_id) references organizations (id) on delete cascade');
    }],
    'the membership foreign key was dropped and never re-added' => ['members key missing', function () {
        ps1Migration()->up();
        DB::statement('alter table organization_members drop foreign key organization_members_organization_id_foreign');
    }],
    'one CHECK is missing' => ['one check missing', function () {
        ps1Migration()->up();
        DB::statement('alter table organizations drop constraint organizations_partner_requires_portal_check');
    }],
    'no CHECK exists yet' => ['no checks', function () {
        ps1Migration()->up();
        foreach (PS1_CHECKS as $check) {
            DB::statement("alter table organizations drop constraint {$check}");
        }
    }],
]);

it('refuses a schema it was not written for instead of guessing', function () {
    $migration = ps1Migration();

    try {
        $migration->down();
        DB::statement('alter table organizations add portal_enabled_by bigint unsigned null'); // both owner_id and portal_enabled_by

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'has both owner_id and portal_enabled_by');
    } finally {
        DB::statement('alter table organizations drop column portal_enabled_by');
        ps1Forward();
    }
});

// ── down() ───────────────────────────────────────────────────────────────────

it('rolls back to the old schema, schema only, and puts it forward again', function () {
    $migration = ps1Migration();

    try {
        $migration->down();

        $columns = collect(Schema::getColumns('organizations'))->keyBy('name');
        expect(Schema::hasColumn('organizations', 'portal_enabled_at'))->toBeFalse()
            ->and(Schema::hasColumn('organizations', 'portal_enabled_by'))->toBeFalse()
            ->and($columns['owner_id']['nullable'])->toBeFalse()
            ->and(ps1ForeignKey('organizations', 'owner_id')->delete_rule)->toBe('RESTRICT')
            ->and(ps1ForeignKey('organization_members', 'organization_id')->delete_rule)->toBe('CASCADE')
            ->and(ps1Checks())->toBe([]);

        $migration->down();   // a repeat is harmless

        $migration->up();
        expect(ps1ShapeProblems())->toBe([]);
    } finally {
        ps1Forward();
    }
});

it('refuses to roll back, changing nothing, while an Organization has never had an enabling operator', function () {
    $directoryOnly = Organization::factory()->directoryOnly()->create();
    $shape = ps1ShapeProblems();

    expect(fn () => ps1Migration()->down())->toThrow(RuntimeException::class, 'have no portal_enabled_by')
        // Nothing was changed: the refusal comes before the first DDL statement, so the test transaction (and its row) is still here.
        ->and(DB::table('organizations')->where('id', $directoryOnly->id)->exists())->toBeTrue()
        ->and(Schema::hasColumn('organizations', 'portal_enabled_by'))->toBeTrue()
        ->and(ps1Checks())->toHaveCount(4)
        ->and(ps1ShapeProblems())->toBe($shape);
});
