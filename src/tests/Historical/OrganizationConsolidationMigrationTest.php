<?php

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Historical\Models\CrmCompany;
use Tests\Support\HistoricalSchema;

/*
 * Release 1.0 (ORG-2, CP1): the physical consolidation migration (2026_11_20_090000_consolidate_organizations_and_retire_crm_companies).
 *
 * Runs on the disposable rehearsal database, built to the schema ORG-2 was written for (tests/Support/HistoricalSchema.php): MariaDB commits DDL implicitly, so
 * nothing here may touch the shared testing database, and a failing or interrupted test cannot leave another suite on a half-migrated schema (the next use
 * rebuilds). The CHECK constraints and foreign keys are real MariaDB 11.4 ones, enforced, not merely accepted.
 *
 * What it proves:
 *   - a fresh migration yields the whole target schema, and `migrate` records it;
 *   - existing Organizations keep their ids, provenance and lifecycle, and `created_by` takes `portal_enabled_by`;
 *   - the starting check REFUSES, before the first DDL statement and changing nothing, each prohibited data shape and each contradictory schema shape,
 *     reporting counts and never a record;
 *   - every conversion stage judges itself: a run interrupted after each consequential step is completed by the next, and a stage that finds a state it was
 *     not written for (a populated column, an orphan, a foreign key with the wrong rule, a table with the wrong shape) refuses rather than trusting that the
 *     column "exists";
 *   - `down()` restores the historical schema EXACTLY (compared by fingerprint) when nothing would be lost, and refuses, changing nothing, otherwise.
 */

function o2Migration(): object
{
    return require database_path('migrations/'.HistoricalSchema::ORG2.'.php');
}

function o2Fingerprint(): string
{
    return HistoricalSchema::fingerprint(DB::connection(), RehearsalTarget::primary());
}

/** The stage methods in the order up() runs them. */
const O2_STAGES = [
    'stage1AddProfileColumns', 'stage2BackfillCreatedBy', 'stage3TightenCreatedBy', 'stage4aRenameContactColumn',
    'stage4bAddContactForeignKey', 'stage5aCreateProjectLink', 'stage5bDropOldProjectLink', 'stage6DropCompanies',
];

/** Run the starting check and the stages up to and including $last, then stop: the state a run interrupted right there leaves. */
function o2InterruptedAfter(object $migration, string $last): void
{
    $migration->assertStartingShape();

    foreach (O2_STAGES as $stage) {
        $migration->{$stage}();

        if ($stage === $last) {
            return;
        }
    }
}

/**
 * Existing data of the shape every real Release 1.0 database has: portal Organizations (one Disabled), People with no affiliation, a project with no link.
 *
 * @return array{operators: array<int, User>, organizations: list<int>, digest: array<int, array<string, mixed>>}
 */
function o2PortalData(): array
{
    $a = User::factory()->create();
    $b = User::factory()->create();
    $one = Organization::factory()->create(['portal_enabled_by' => $a->id, 'name' => 'First Org', 'portal_enabled_at' => '2026-10-01 09:00:00', 'created_at' => '2026-10-01 09:00:00']);
    $two = Organization::factory()->create(['portal_enabled_by' => $b->id, 'name' => 'Second Org', 'portal_enabled_at' => '2026-10-02 09:00:00', 'created_at' => '2026-10-02 09:00:00']);
    $three = Organization::factory()->disabled()->create(['portal_enabled_by' => $a->id, 'name' => 'Third Org', 'portal_enabled_at' => '2026-10-03 09:00:00', 'created_at' => '2026-10-03 09:00:00']);
    CrmContact::factory()->count(2)->create(['created_by' => $a->id]);
    Project::factory()->create(['created_by' => $a->id]);

    return ['operators' => [$a->id => $a, $b->id => $b], 'organizations' => [$one->id, $two->id, $three->id], 'digest' => o2OrganizationDigest()];
}

/** The columns an Organization already had, per row: what the migration must not disturb. @return array<int, array<string, mixed>> */
function o2OrganizationDigest(): array
{
    return DB::table('organizations')->orderBy('id')
        ->get(['id', 'name', 'slug', 'portal_enabled_by', 'portal_enabled_at', 'disabled_at', 'can_manage_customers', 'managed_by_organization_id', 'created_at', 'updated_at'])
        ->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
}

function o2Counts(): array
{
    return collect(['users', 'organizations', 'crm_companies', 'crm_contacts', 'projects', 'project_company', 'organization_members'])
        ->filter(fn ($table) => Schema::hasTable($table))
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

/** Arrange a prohibited shape, run up(), and prove it refused with the fragment, before any DDL (the schema fingerprint and every count are unchanged), with no record in the message. */
function o2Refuses(Closure $arrange, string $fragment, array $mustNotAppear = []): void
{
    $arrange();
    $fingerprint = o2Fingerprint();
    $counts = o2Counts();

    try {
        o2Migration()->up();
        $message = null;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull('up() did not refuse')
        ->and($message)->toContain('Cannot consolidate Organizations')
        ->and($message)->toContain($fragment)
        ->and(o2Fingerprint())->toBe($fingerprint)
        ->and(o2Counts())->toBe($counts);

    foreach ($mustNotAppear as $secret) {
        expect($message)->not->toContain($secret);
    }
}

beforeEach(fn () => HistoricalSchema::use(HistoricalSchema::ORG2));

// ── A fresh migration ────────────────────────────────────────────────────────────────────────

it('migrates an empty historical schema to the whole target schema', function () {
    $migration = o2Migration();
    $migration->up();

    expect($migration->targetProblems())->toBe([]);

    $columns = collect(Schema::getColumns('organizations'))->keyBy('name');
    expect($columns['website']['type'])->toBe('varchar(255)')->and($columns['website']['nullable'])->toBeTrue()
        ->and($columns['phone']['type'])->toBe('varchar(30)')->and($columns['phone']['nullable'])->toBeTrue()
        ->and($columns['address']['type_name'])->toBe('text')->and($columns['notes']['type_name'])->toBe('text')
        ->and($columns['classification']['type'])->toBe('varchar(32)')->and($columns['classification']['nullable'])->toBeFalse()
        ->and(trim((string) $columns['classification']['default'], "'"))->toBe('general')
        ->and($columns['created_by']['nullable'])->toBeFalse()
        ->and(Schema::hasTable('crm_companies'))->toBeFalse()
        ->and(Schema::hasTable('project_company'))->toBeFalse()
        ->and(Schema::hasColumn('crm_contacts', 'crm_company_id'))->toBeFalse();

    $keys = fn (string $table, string $column) => DB::selectOne(
        'select k.constraint_name as n, k.referenced_table_name as p, r.delete_rule as d, r.update_rule as u
         from information_schema.key_column_usage k join information_schema.referential_constraints r
           on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         where k.table_schema = database() and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null',
        [$table, $column]
    );

    expect((array) $keys('organizations', 'created_by'))->toBe(['n' => 'organizations_created_by_foreign', 'p' => 'users', 'd' => 'RESTRICT', 'u' => 'RESTRICT'])
        ->and((array) $keys('crm_contacts', 'organization_id'))->toBe(['n' => 'crm_contacts_organization_id_foreign', 'p' => 'organizations', 'd' => 'SET NULL', 'u' => 'RESTRICT'])
        ->and((array) $keys('project_organization', 'organization_id'))->toBe(['n' => 'project_organization_organization_id_foreign', 'p' => 'organizations', 'd' => 'RESTRICT', 'u' => 'RESTRICT'])
        ->and((array) $keys('project_organization', 'project_id'))->toBe(['n' => 'project_organization_project_id_foreign', 'p' => 'projects', 'd' => 'CASCADE', 'u' => 'RESTRICT']);
});

it('runs through `migrate`, is recorded, leaves the ORG-1 CHECK constraints alone, and a second run changes nothing', function () {
    $code = Artisan::call('migrate', ['--path' => [database_path('migrations/'.HistoricalSchema::ORG2.'.php')], '--realpath' => true, '--force' => true]);
    $after = o2Fingerprint();

    expect($code)->toBe(0)
        ->and(DB::table('migrations')->where('migration', HistoricalSchema::ORG2)->count())->toBe(1);

    $checks = collect(DB::select("select constraint_name as n from information_schema.table_constraints where constraint_schema = database() and table_name = 'organizations' and constraint_type = 'CHECK'"))->pluck('n')->sort()->values()->all();
    expect($checks)->toBe([
        'organizations_disabled_requires_portal_check', 'organizations_managed_requires_portal_check', 'organizations_partner_not_managed_check',
        'organizations_partner_requires_portal_check', 'organizations_portal_provenance_pair_check',
    ]);

    // Directly, as a person re-running a half-recorded migration would.
    o2Migration()->up();
    expect(o2Fingerprint())->toBe($after);
});

it('passes database:verify against the committed foreign-key manifest on a fully migrated database', function () {
    HistoricalSchema::use(null);

    expect(Artisan::call('database:verify'))->toBe(0);
});

it('is neither a pinned baseline migration nor a rehearsal candidate, and edits no pinned file', function () {
    $baseline = require database_path('rehearsal/baseline.php');
    $candidates = require database_path('rehearsal/candidates.php');

    expect(array_keys($baseline))->not->toContain(HistoricalSchema::ORG2)
        ->and(array_keys($candidates))->not->toContain(HistoricalSchema::ORG2);

    foreach ($baseline as $name => $hash) {
        expect(hash_file('sha256', database_path("migrations/{$name}.php")))->toBe($hash, "{$name} was edited");
    }
});

// ── Existing data ────────────────────────────────────────────────────────────────────────────

it('keeps every Organization id and its lifecycle, and backfills created_by from the enabling operator', function () {
    $data = o2PortalData();

    o2Migration()->up();

    expect(o2OrganizationDigest())->toBe($data['digest']);

    foreach (DB::table('organizations')->orderBy('id')->get() as $row) {
        expect((int) $row->created_by)->toBe((int) $row->portal_enabled_by)
            ->and($row->classification)->toBe('general')
            ->and([$row->website, $row->phone, $row->address, $row->notes])->toBe([null, null, null, null]);
    }

    expect(DB::table('crm_contacts')->count())->toBe(2)
        ->and(DB::table('crm_contacts')->whereNotNull('organization_id')->count())->toBe(0)
        ->and(DB::table('projects')->count())->toBe(1);
});

it('makes created_by a RESTRICT reference to users: the creator of an Organization cannot be deleted', function () {
    $data = o2PortalData();
    o2Migration()->up();
    $creator = (int) DB::table('organizations')->where('id', $data['organizations'][0])->value('created_by');

    expect(fn () => DB::table('users')->where('id', $creator)->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row')
        ->and(DB::table('users')->where('id', $creator)->exists())->toBeTrue();
});

it('lets a Person be affiliated with an Organization, and clears the affiliation when the Organization goes (SET NULL)', function () {
    $data = o2PortalData();
    o2Migration()->up();
    $operator = array_key_first($data['operators']);
    $organization = DB::table('organizations')->insertGetId([
        'name' => 'Directory Only', 'slug' => 'directory-only', 'classification' => 'general', 'created_by' => $operator, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $contact = DB::table('crm_contacts')->insertGetId(['organization_id' => $organization, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => $operator, 'created_at' => now(), 'updated_at' => now()]);

    DB::table('organizations')->where('id', $organization)->delete();

    expect(DB::table('crm_contacts')->where('id', $contact)->value('organization_id'))->toBeNull();
});

it('refuses to delete an Organization a project is linked to (RESTRICT), and removes the link with the project (CASCADE)', function () {
    $data = o2PortalData();
    o2Migration()->up();
    $project = (int) DB::table('projects')->value('id');
    $organization = $data['organizations'][0];
    DB::table('project_organization')->insert(['project_id' => $project, 'organization_id' => $organization, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('project_organization')->insert(['project_id' => $project, 'organization_id' => $organization]))->toThrow(QueryException::class, 'Duplicate entry')
        ->and(fn () => DB::table('organizations')->where('id', $organization)->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    DB::table('projects')->where('id', $project)->delete();

    expect(DB::table('project_organization')->count())->toBe(0);
});

// ── The starting check refuses, before any DDL ──────────────────────────────────────────────

describe('the starting check refuses before the first DDL statement', function () {
    it('a populated crm_companies', function () {
        o2Refuses(function () {
            CrmCompany::factory()->create(['created_by' => User::factory()->create()->id, 'name' => 'SECRET COMPANY NAME', 'notes' => 'SECRET NOTE']);
        }, 'crm_companies holds 1 row(s)', ['SECRET COMPANY NAME', 'SECRET NOTE']);
    });

    it('an old Company affiliation, reported on its own when there is no Company at all', function () {
        o2Refuses(function () {
            $user = User::factory()->create();
            $contact = CrmContact::factory()->create(['created_by' => $user->id, 'first_name' => 'SECRET PERSON']);
            // A shape the foreign key forbids and a dump loaded with checks off can hold.
            DB::statement('set foreign_key_checks = 0');
            DB::table('crm_contacts')->where('id', $contact->id)->update(['crm_company_id' => 987654]);
            DB::statement('set foreign_key_checks = 1');
        }, '1 Person row(s) still carry an old Company affiliation', ['SECRET PERSON', '987654']);
    });

    it('an old project link', function () {
        o2Refuses(function () {
            $project = Project::factory()->create(['created_by' => User::factory()->create()->id]);
            DB::statement('set foreign_key_checks = 0');
            DB::table('project_company')->insert(['project_id' => $project->id, 'crm_company_id' => 424242, 'created_at' => now(), 'updated_at' => now()]);
            DB::statement('set foreign_key_checks = 1');
        }, 'project_company holds 1 project link(s) to a Company', ['424242']);
    });

    it('an Organization with no enabling operator, which cannot satisfy created_by', function () {
        o2Refuses(function () {
            Organization::factory()->create();
            Organization::factory()->directoryOnly()->create(['name' => 'SECRET DIRECTORY ORG']);
            Organization::factory()->directoryOnly()->create();
        }, '2 existing Organization(s) have no enabling operator', ['SECRET DIRECTORY ORG']);
    });

    it('a database the ORG-1 portal-state migration has not reached', function () {
        HistoricalSchema::use(HistoricalSchema::ORG1);

        o2Refuses(fn () => null, 'organizations.portal_enabled_at is missing: the ORG-1 portal-state migration has not run');
    });

    it('a Person table with both the old and the new column, or neither', function () {
        o2Refuses(fn () => DB::statement('alter table crm_contacts add organization_id bigint unsigned null'), 'crm_contacts has both crm_company_id and organization_id');

        HistoricalSchema::use(HistoricalSchema::ORG2);

        o2Refuses(function () {
            DB::statement('alter table crm_contacts drop foreign key crm_contacts_crm_company_id_foreign');
            DB::statement('alter table crm_contacts drop column crm_company_id');
        }, 'crm_contacts has neither crm_company_id nor organization_id');
    });

    it('neither project link table', function () {
        o2Refuses(fn () => DB::statement('drop table project_company'), 'neither project_company nor project_organization exists');
    });

    it('both project link tables, with the new one already populated', function () {
        o2Refuses(function () {
            $migration = o2Migration();
            $migration->stage5aCreateProjectLink();
            $operator = User::factory()->create();
            $project = Project::factory()->create(['created_by' => $operator->id]);
            $organization = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
            DB::table('project_organization')->insert(['project_id' => $project->id, 'organization_id' => $organization->id]);
        }, 'the new one already holds 1 row(s)');
    });

    it('crm_companies already gone while the rest of the schema is not the target schema', function () {
        o2Refuses(function () {
            DB::statement('set foreign_key_checks = 0');
            DB::statement('drop table crm_companies');
            DB::statement('set foreign_key_checks = 1');
        }, 'an ambiguous mixed state that must be resolved by hand');
    });

    it('reports every problem at once, as counts', function () {
        o2Refuses(function () {
            CrmCompany::factory()->count(2)->create(['created_by' => User::factory()->create()->id]);
            Organization::factory()->directoryOnly()->create();
        }, 'crm_companies holds 2 row(s)');

        $message = '';
        try {
            o2Migration()->up();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('1 existing Organization(s) have no enabling operator');
    });
});

it('does not reject a successfully converted database merely because a column already has its new name', function () {
    o2PortalData();
    $migration = o2Migration();
    o2InterruptedAfter($migration, 'stage6DropCompanies');

    // Everything is converted and `crm_companies` is gone, but the run died before Laravel recorded it: the starting check must accept this.
    expect(fn () => $migration->assertStartingShape())->not->toThrow(RuntimeException::class);

    $migration->up();
    expect($migration->targetProblems())->toBe([]);
});

// ── Recovery: an interrupted run is completed by the next ───────────────────────────────────

describe('a run interrupted after each consequential step is completed by the next run', function () {
    $expectedState = [
        'stage1AddProfileColumns' => fn () => Schema::hasColumn('organizations', 'classification') && Schema::hasColumn('organizations', 'created_by')
            && collect(Schema::getColumns('organizations'))->firstWhere('name', 'created_by')['nullable'] === true
            && DB::table('organizations')->whereNotNull('created_by')->count() === 0,
        'stage2BackfillCreatedBy' => fn () => DB::table('organizations')->whereNull('created_by')->count() === 0
            && collect(Schema::getColumns('organizations'))->firstWhere('name', 'created_by')['nullable'] === true,
        'stage3TightenCreatedBy' => fn () => collect(Schema::getColumns('organizations'))->firstWhere('name', 'created_by')['nullable'] === false
            && Schema::hasColumn('crm_contacts', 'crm_company_id'),
        // Renamed, with no foreign key yet: the state that must never be trusted because the column exists.
        'stage4aRenameContactColumn' => fn () => Schema::hasColumn('crm_contacts', 'organization_id') && ! Schema::hasColumn('crm_contacts', 'crm_company_id')
            && DB::select("select 1 from information_schema.key_column_usage where table_schema = database() and table_name = 'crm_contacts' and column_name = 'organization_id' and referenced_table_name is not null") === [],
        'stage4bAddContactForeignKey' => fn () => DB::select("select 1 from information_schema.key_column_usage where table_schema = database() and table_name = 'crm_contacts' and column_name = 'organization_id' and referenced_table_name = 'organizations'") !== []
            && Schema::hasTable('project_company'),
        'stage5aCreateProjectLink' => fn () => Schema::hasTable('project_company') && Schema::hasTable('project_organization'),
        'stage5bDropOldProjectLink' => fn () => ! Schema::hasTable('project_company') && Schema::hasTable('crm_companies'),
        'stage6DropCompanies' => fn () => ! Schema::hasTable('crm_companies'),
    ];

    foreach (O2_STAGES as $stage) {
        it("after {$stage}", function () use ($stage, $expectedState) {
            $data = o2PortalData();
            $migration = o2Migration();

            o2InterruptedAfter($migration, $stage);
            expect($expectedState[$stage]())->toBeTrue("the interrupted state after {$stage} is not the one this case means to test");

            $migration->up();

            expect($migration->targetProblems())->toBe([])
                ->and(o2OrganizationDigest())->toBe($data['digest'])
                ->and(DB::table('organizations')->whereColumn('created_by', '<>', 'portal_enabled_by')->count())->toBe(0)
                ->and(DB::table('crm_contacts')->count())->toBe(2);

            $settled = o2Fingerprint();
            $migration->up();   // and once more, which is what a re-run of a recorded migration would be
            expect(o2Fingerprint())->toBe($settled);
        });
    }

    it('after only some of the profile columns were added', function () {
        $data = o2PortalData();
        DB::statement('alter table organizations add website varchar(255) null, add notes text null');

        $migration = o2Migration();
        $migration->up();

        expect($migration->targetProblems())->toBe([])->and(o2OrganizationDigest())->toBe($data['digest']);
    });

    it('never overwrites a created_by an earlier run already wrote', function () {
        $data = o2PortalData();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage1AddProfileColumns');
        [$first, $second] = $data['organizations'];
        $other = User::factory()->create()->id;
        DB::table('organizations')->where('id', $first)->update(['created_by' => $other]);

        $migration->up();

        expect((int) DB::table('organizations')->where('id', $first)->value('created_by'))->toBe($other)
            ->and((int) DB::table('organizations')->where('id', $second)->value('created_by'))->toBe((int) DB::table('organizations')->where('id', $second)->value('portal_enabled_by'));
    });
});

// ── Every stage judges itself ────────────────────────────────────────────────────────────────

describe('every stage checks its own preconditions and its whole postcondition', function () {
    it('never renames a populated crm_company_id into organization_id, even when the ids happen to match an Organization', function () {
        $user = User::factory()->create();
        $company = CrmCompany::factory()->create(['created_by' => $user->id]);
        // The Organization that shares the Company's id: the rename would silently attach the Person to it.
        Organization::factory()->create(['portal_enabled_by' => $user->id]);
        $contact = CrmContact::factory()->create(['created_by' => $user->id, 'crm_company_id' => $company->id]);
        $fingerprint = o2Fingerprint();

        expect(fn () => o2Migration()->stage4aRenameContactColumn())->toThrow(RuntimeException::class, 'never renamed into organization_id')
            ->and(o2Fingerprint())->toBe($fingerprint)
            ->and((int) DB::table('crm_contacts')->where('id', $contact->id)->value('crm_company_id'))->toBe($company->id);
    });

    it('refuses to add the contact foreign key over a value it cannot prove is an Organization id', function () {
        $user = User::factory()->create();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage4aRenameContactColumn');
        // The column is `organization_id` now, so the old model (still Company-shaped until CP2) cannot write it: a plain insert.
        DB::table('crm_contacts')->insert(['organization_id' => 31337, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => $migration->stage4bAddContactForeignKey())->toThrow(RuntimeException::class, 'holds 1 value(s) but has no foreign key')
            ->and(fn () => $migration->up())->toThrow(RuntimeException::class, 'holds 1 value(s) but has no foreign key');
    });

    it('refuses an orphan created_by before it adds the foreign key', function () {
        o2PortalData();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage2BackfillCreatedBy');
        DB::table('organizations')->limit(1)->update(['created_by' => 777777]);

        expect(fn () => $migration->stage3TightenCreatedBy())->toThrow(RuntimeException::class, 'not a user')
            ->and(collect(Schema::getColumns('organizations'))->firstWhere('name', 'created_by')['nullable'])->toBeTrue();
    });

    it('refuses a created_by that is empty and has nobody to take it from', function () {
        o2PortalData();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage1AddProfileColumns');
        DB::statement('set foreign_key_checks = 0');
        DB::table('organizations')->limit(1)->update(['portal_enabled_by' => null, 'portal_enabled_at' => null]);
        DB::statement('set foreign_key_checks = 1');

        expect(fn () => $migration->stage2BackfillCreatedBy())->toThrow(RuntimeException::class, 'neither created_by nor an enabling operator');
    });

    it('does not trust a created_by foreign key with the wrong delete rule', function () {
        o2PortalData();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage3TightenCreatedBy');
        // A foreign key cannot be dropped and re-added under one name in one statement (error 121): two.
        DB::statement('alter table organizations drop foreign key organizations_created_by_foreign');
        DB::statement('alter table organizations add constraint organizations_created_by_foreign foreign key (created_by) references users (id) on delete cascade');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'organizations_created_by_foreign -> users.id ON DELETE CASCADE');
    });

    it('does not trust a column that exists with the wrong definition', function (string $alter, string $fragment) {
        DB::statement($alter);

        expect(fn () => o2Migration()->up())->toThrow(RuntimeException::class, $fragment);
    })->with([
        'a classification that defaults to something else' => ["alter table organizations add classification varchar(32) not null default 'client'", 'organizations.classification has default client, expected general'],
        'a classification that is nullable' => ['alter table organizations add classification varchar(32) null default \'general\'', 'organizations.classification is nullable, expected NOT NULL'],
        'a website of the wrong type' => ['alter table organizations add website text null', 'organizations.website is text, expected varchar(255)'],
        'a created_by of the wrong type' => ['alter table organizations add created_by int null', 'organizations.created_by is int, expected bigint unsigned'],
    ]);

    it('does not trust a contact foreign key with the wrong delete rule', function () {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage4bAddContactForeignKey');
        DB::statement('alter table crm_contacts drop foreign key crm_contacts_organization_id_foreign');
        DB::statement('alter table crm_contacts add constraint crm_contacts_organization_id_foreign foreign key (organization_id) references organizations (id) on delete cascade');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'crm_contacts_organization_id_foreign -> organizations.id ON DELETE CASCADE');
    });

    it('does not trust an existing project_organization that lacks a constraint, and does not drop project_company on its strength', function () {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage5aCreateProjectLink');
        DB::statement('alter table project_organization drop foreign key project_organization_organization_id_foreign');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'project_organization exists but is not the expected shape')
            ->and(fn () => $migration->stage5bDropOldProjectLink())->toThrow(RuntimeException::class, 'project_company is not dropped')
            ->and(Schema::hasTable('project_company'))->toBeTrue();
    });

    it('does not drop crm_companies while a foreign key still references it', function () {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage5bDropOldProjectLink');
        DB::statement('create table crm_probe (company_id bigint unsigned null, constraint crm_probe_company_foreign foreign key (company_id) references crm_companies (id)) engine=InnoDB');

        expect(fn () => $migration->stage6DropCompanies())->toThrow(RuntimeException::class, 'a foreign key from crm_probe still references crm_companies')
            ->and(Schema::hasTable('crm_companies'))->toBeTrue();

        DB::statement('drop table crm_probe');
    });

    it('does not drop crm_companies while it holds a row', function () {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage5bDropOldProjectLink');
        CrmCompany::factory()->create(['created_by' => User::factory()->create()->id]);

        expect(fn () => $migration->stage6DropCompanies())->toThrow(RuntimeException::class, 'crm_companies holds 1 row(s)')
            ->and(Schema::hasTable('crm_companies'))->toBeTrue();
    });

    it('fails its own final verification loudly when the end state is not the target schema', function () {
        $migration = o2Migration();
        $migration->up();
        DB::statement('alter table organizations alter classification set default \'client\'');

        expect($migration->targetProblems())->toContain('organizations.classification has default client, expected general');
    });
});

// ── down() ───────────────────────────────────────────────────────────────────────────────────

describe('down() restores the historical schema exactly, or refuses', function () {
    it('restores the pre-ORG-2 schema exactly (columns and their order, indexes, foreign keys by name, CHECKs) on an empty database', function () {
        $historical = o2Fingerprint();
        $migration = o2Migration();

        $migration->up();
        expect(o2Fingerprint())->not->toBe($historical);

        $migration->down();

        expect(o2Fingerprint())->toBe($historical);
    });

    it('restores it exactly after existing portal Organizations were converted, keeping every row', function () {
        $data = o2PortalData();
        $historical = o2Fingerprint();
        $migration = o2Migration();

        $migration->up();
        $migration->down();

        expect(o2Fingerprint())->toBe($historical)
            ->and(o2OrganizationDigest())->toBe($data['digest'])
            ->and(Schema::hasColumn('organizations', 'created_by'))->toBeFalse()
            ->and(DB::table('crm_companies')->count())->toBe(0);
    });

    it('is completed by the next run when it was interrupted after each of its steps', function (array $steps) {
        $historical = o2Fingerprint();
        $migration = o2Migration();
        $migration->up();

        foreach ($steps as $step) {
            $migration->{$step}();
        }

        $migration->down();

        expect(o2Fingerprint())->toBe($historical);
    })->with([
        'after crm_companies was recreated' => [['downCompanies']],
        'after the Person column was restored' => [['downCompanies', 'downContacts']],
        'after the project link was restored' => [['downCompanies', 'downContacts', 'downProjectLink']],
        'after everything but a repeat' => [['downCompanies', 'downContacts', 'downProjectLink', 'downOrganizations']],
    ]);

    it('refuses, changing nothing, when the data cannot be put back', function (Closure $arrange, string $fragment) {
        $operator = User::factory()->create();
        $organization = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
        o2Migration()->up();
        $arrange($operator, $organization);
        $fingerprint = o2Fingerprint();
        $counts = o2Counts();

        expect(fn () => o2Migration()->down())->toThrow(RuntimeException::class, $fragment)
            ->and(o2Fingerprint())->toBe($fingerprint)
            ->and(o2Counts())->toBe($counts);
    })->with([
        'a Directory-only Organization' => [
            fn (User $operator) => DB::table('organizations')->insert(['name' => 'Dir', 'slug' => 'dir', 'created_by' => $operator->id, 'created_at' => now(), 'updated_at' => now()]),
            '1 Directory-only Organization(s) exist',
        ],
        'profile data' => [
            fn (User $operator, Organization $organization) => DB::table('organizations')->where('id', $organization->id)->update(['notes' => 'kept']),
            'carry profile data',
        ],
        'a classification other than the default' => [
            fn (User $operator, Organization $organization) => DB::table('organizations')->where('id', $organization->id)->update(['classification' => 'vendor']),
            'a classification other than the default',
        ],
        'a creator who is not the enabling operator' => [
            fn (User $operator, Organization $organization) => DB::table('organizations')->where('id', $organization->id)->update(['created_by' => User::factory()->create()->id]),
            'a creator who is not their enabling operator',
        ],
        'a Person affiliation' => [
            fn (User $operator, Organization $organization) => DB::table('crm_contacts')->insert(['organization_id' => $organization->id, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => $operator->id, 'created_at' => now(), 'updated_at' => now()]),
            'affiliated with an Organization',
        ],
        'a project link' => [
            function (User $operator, Organization $organization) {
                $project = Project::factory()->create(['created_by' => $operator->id]);
                DB::table('project_organization')->insert(['project_id' => $project->id, 'organization_id' => $organization->id]);
            },
            'project link(s) to an Organization',
        ],
        'a partner relationship' => [
            fn (User $operator, Organization $organization) => DB::table('organizations')->where('id', $organization->id)->update(['can_manage_customers' => 1]),
            'in a partner or managed relationship',
        ],
    ]);

    it('is rolled back before ORG-1: with a Directory-only Organization it refuses, so ORG-1 cannot be reached', function () {
        HistoricalSchema::use(null);
        $operator = User::factory()->create();
        DB::table('organizations')->insert(['name' => 'Dir', 'slug' => 'dir', 'created_by' => $operator->id, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => o2Migration()->down())->toThrow(RuntimeException::class, 'Directory-only')
            ->and(fn () => (require database_path('migrations/'.HistoricalSchema::ORG1.'.php'))->down())->toThrow(RuntimeException::class, 'no portal_enabled_by');

        DB::table('organizations')->delete();

        // Emptied, the order works: ORG-2 first, then ORG-1.
        o2Migration()->down();
        (require database_path('migrations/'.HistoricalSchema::ORG1.'.php'))->down();
        expect(Schema::hasColumn('organizations', 'owner_id'))->toBeTrue()->and(Schema::hasTable('crm_companies'))->toBeTrue();
    });
});

// ── CP1 review corrections (B-1, B-2, B-3) ──────────────────────────────────────────────────────

/** The DDL statements a callback runs, in order. @return list<string> */
function o2Ddl(Closure $run): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        if (preg_match('/^\s*(create|alter|drop|rename)\s/i', $query->sql)) {
            $sql[] = $query->sql;
        }
    });
    $run();

    return $sql;
}

/** The ORG-2 state with `down()` run as far as it can go before crm_companies: the schema a rollback interrupted before its first step leaves. */
function o2Migrated(): object
{
    $migration = o2Migration();
    $migration->up();

    return $migration;
}

describe('B-3: a table is created by ONE statement that carries its constraints', function () {
    it('creates project_organization with a single CREATE TABLE holding the key, the index and both foreign keys, and no ALTER', function () {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage4bAddContactForeignKey');

        $ddl = o2Ddl(fn () => $migration->stage5aCreateProjectLink());

        expect($ddl)->toHaveCount(1)
            ->and($ddl[0])->toStartWith('create table `project_organization`')
            ->and($ddl[0])->toContain('primary key (`project_id`, `organization_id`)')
            ->and(substr_count($ddl[0], 'foreign key'))->toBe(2)
            ->and($ddl[0])->toContain('project_organization_project_id_foreign')->toContain('project_organization_organization_id_foreign')
            ->and($ddl[0])->toContain('engine = InnoDB');
    });

    it('recreates crm_companies and project_company on rollback the same way: one CREATE TABLE each, no ALTER of either', function () {
        $migration = o2Migrated();

        $ddl = o2Ddl(fn () => $migration->down());
        $creates = array_values(array_filter($ddl, fn ($sql) => str_starts_with($sql, 'create table')));

        expect($creates)->toHaveCount(2)
            ->and($creates[0])->toStartWith('create table `crm_companies`')->and(substr_count($creates[0], 'foreign key'))->toBe(2)->and($creates[0])->toContain('unique key `crm_companies_organization_id_unique`')
            ->and($creates[1])->toStartWith('create table `project_company`')->and(substr_count($creates[1], 'foreign key'))->toBe(2)->and($creates[1])->toContain('primary key (`project_id`, `crm_company_id`)')
            ->and(array_filter($ddl, fn ($sql) => preg_match('/^alter table `(crm_companies|project_company|project_organization)`/', $sql)))->toBe([]);
    });

    it('is whole or absent on the real server: a CREATE TABLE whose last constraint is invalid leaves no table behind', function () {
        expect(fn () => DB::statement(
            'create table atomic_probe (a bigint unsigned null, b bigint unsigned null, key k (a), constraint f1 foreign key (a) references users (id), constraint f2 foreign key (b) references no_such_table (id)) engine = InnoDB'
        ))->toThrow(QueryException::class)
            ->and(Schema::hasTable('atomic_probe'))->toBeFalse();
    });

    it('a run interrupted INSIDE the creation leaves no project_organization, keeps project_company, and the next run completes', function () {
        $data = o2PortalData();
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage4bAddContactForeignKey');

        $interrupt = true;
        DB::beforeExecuting(function (string $query) use (&$interrupt) {
            if ($interrupt && str_starts_with($query, 'create table `project_organization`')) {
                throw new RuntimeException('connection lost mid-create (simulated)');
            }
        });

        expect(fn () => $migration->stage5aCreateProjectLink())->toThrow(RuntimeException::class, 'simulated')
            ->and(Schema::hasTable('project_organization'))->toBeFalse()
            ->and(Schema::hasTable('project_company'))->toBeTrue();

        $interrupt = false;   // the connection is back
        $fresh = o2Migration();
        $fresh->up();

        expect($fresh->targetProblems())->toBe([])->and(o2OrganizationDigest())->toBe($data['digest']);
    })->skip(fn () => ! method_exists(DB::connection(), 'beforeExecuting'), 'needs Connection::beforeExecuting');

    it('refuses a project_organization an older, non-atomic creation could have left, by each missing piece', function (string $sql, string $fragment) {
        $migration = o2Migration();
        o2InterruptedAfter($migration, 'stage4bAddContactForeignKey');
        DB::statement($sql);

        expect(fn () => $migration->stage5aCreateProjectLink())->toThrow(RuntimeException::class, $fragment)
            ->and(fn () => $migration->stage5bDropOldProjectLink())->toThrow(RuntimeException::class, 'project_company is not dropped')
            ->and(Schema::hasTable('project_company'))->toBeTrue();
    })->with([
        'only the CREATE TABLE ran (no key, no foreign key)' => [
            'create table project_organization (project_id bigint unsigned not null, organization_id bigint unsigned not null, created_at timestamp null, updated_at timestamp null) engine = InnoDB',
            'project_organization has no index PRIMARY',
        ],
        'the primary key but no foreign key' => [
            'create table project_organization (project_id bigint unsigned not null, organization_id bigint unsigned not null, created_at timestamp null, updated_at timestamp null, primary key (project_id, organization_id)) engine = InnoDB',
            'project_organization_organization_id_foreign',
        ],
        'one foreign key but not the other' => [
            'create table project_organization (project_id bigint unsigned not null, organization_id bigint unsigned not null, created_at timestamp null, updated_at timestamp null, primary key (project_id, organization_id), key project_organization_organization_id_foreign (organization_id), constraint project_organization_project_id_foreign foreign key (project_id) references projects (id) on delete cascade) engine = InnoDB',
            'organization_id has no foreign key',
        ],
    ]);
});

describe('B-1: a crm_companies left by an interrupted rollback is judged by its whole shape', function () {
    /** Every way a half-built crm_companies could look: the columns exist, and the CREATE TABLE statement stopped before (or lacked) one of the rest. */
    $columns = 'id bigint unsigned not null auto_increment, name varchar(255) not null, website varchar(255) null, phone varchar(30) null, address text null, notes text null, organization_id bigint unsigned null, created_by bigint unsigned not null, created_at timestamp null, updated_at timestamp null, primary key (id)';
    $name = 'key crm_companies_name_index (name)';
    $unique = 'unique key crm_companies_organization_id_unique (organization_id)';
    $creator = 'key crm_companies_created_by_foreign (created_by), constraint crm_companies_created_by_foreign foreign key (created_by) references users (id) on delete restrict';
    $org = 'constraint crm_companies_organization_id_foreign foreign key (organization_id) references organizations (id) on delete set null';

    it('refuses a crm_companies with no indexes and no foreign keys (CREATE TABLE ran, nothing after it), changing nothing', function () use ($columns) {
        $migration = o2Migrated();
        DB::statement("create table crm_companies ({$columns}) engine = InnoDB");
        $fingerprint = o2Fingerprint();

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'crm_companies already exists but is not the historical table')
            ->and(o2Fingerprint())->toBe($fingerprint)
            ->and(Schema::hasColumn('crm_contacts', 'organization_id'))->toBeTrue();   // nothing after the refused step ran
    });

    it('refuses a crm_companies missing exactly one piece, by name', function (string $definition, string $fragment) {
        $migration = o2Migrated();
        DB::statement("create table crm_companies ({$definition}) engine = InnoDB");

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, $fragment);
        expect(Schema::hasColumn('crm_contacts', 'organization_id'))->toBeTrue();
    })->with(function () use ($columns, $name, $unique, $creator, $org) {
        return [
            'no name index' => ["{$columns}, {$unique}, {$creator}, {$org}", 'crm_companies has no index crm_companies_name_index'],
            'no unique Organization link (MO-1A)' => ["{$columns}, {$name}, {$creator}, {$org}", 'crm_companies has no index crm_companies_organization_id_unique'],
            'no creator foreign key' => ["{$columns}, {$name}, {$unique}, key crm_companies_created_by_foreign (created_by), {$org}", 'crm_companies.created_by has no foreign key'],
            'no Organization foreign key' => ["{$columns}, {$name}, {$unique}, {$creator}", 'crm_companies.organization_id has no foreign key'],
            'a creator key that cascades' => ["{$columns}, {$name}, {$unique}, key crm_companies_created_by_foreign (created_by), constraint crm_companies_created_by_foreign foreign key (created_by) references users (id) on delete cascade, {$org}", 'ON DELETE CASCADE'],
            'a non-unique Organization link' => ["{$columns}, {$name}, key crm_companies_organization_id_unique (organization_id), {$creator}, {$org}", 'crm_companies index crm_companies_organization_id_unique is not unique'],
            'a missing column' => [str_replace('notes text null, ', '', $columns).", {$name}, {$unique}, {$creator}, {$org}", 'crm_companies has the columns'],
            'a column of the wrong type' => [str_replace('phone varchar(30) null', 'phone varchar(10) null', $columns).", {$name}, {$unique}, {$creator}, {$org}", 'crm_companies.phone is varchar(10), expected varchar(30)'],
            'an extra index' => ["{$columns}, {$name}, {$unique}, {$creator}, {$org}, key stray (website)", 'unexpected index stray'],
        ];
    });

    it('finishes, rather than refuses, a COMPLETE crm_companies an earlier rollback created before it was interrupted', function () {
        $historical = (function () {
            HistoricalSchema::use(HistoricalSchema::ORG2);

            return o2Fingerprint();
        })();
        $migration = o2Migrated();
        $migration->downCompanies();      // the first step, then the interruption

        $migration->down();

        expect(o2Fingerprint())->toBe($historical);
    });

    it('never reports a rollback as done while a restored table is incomplete: the final verification names it', function () {
        $migration = o2Migrated();
        $migration->downCompanies();
        $migration->downContacts();
        $migration->downProjectLink();
        $migration->downOrganizations();
        DB::statement('alter table crm_companies drop index crm_companies_name_index');

        // down() over the rolled-back state: the table is present but incomplete, so the step refuses and the verification would not pass.
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'crm_companies has no index crm_companies_name_index');
    });

    it('refuses an incomplete project_company the same way', function () {
        $migration = o2Migrated();
        $migration->downCompanies();
        $migration->downContacts();
        DB::statement('create table project_company (project_id bigint unsigned not null, crm_company_id bigint unsigned not null, created_at timestamp null, updated_at timestamp null, primary key (project_id, crm_company_id)) engine = InnoDB');

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'project_company already exists but is not the historical table')
            ->and(Schema::hasTable('project_organization'))->toBeTrue();   // not dropped on the strength of an incomplete replacement
    });
});

describe('B-2: a Person column renamed back but not yet constrained is not a finished stage', function () {
    /** The exact intermediate state: crm_company_id is back (the ALTER of downContacts ran), the foreign key to crm_companies is not. */
    function o2RenamedBackWithoutKey(): object
    {
        $migration = o2Migrated();
        $migration->downCompanies();
        DB::statement('alter table crm_contacts drop foreign key crm_contacts_organization_id_foreign, drop index crm_contacts_organization_id_foreign, change organization_id crm_company_id bigint unsigned null');

        expect(Schema::hasColumn('crm_contacts', 'crm_company_id'))->toBeTrue()
            ->and(DB::select("select 1 from information_schema.key_column_usage where table_schema = database() and table_name = 'crm_contacts' and column_name = 'crm_company_id' and referenced_table_name is not null"))->toBe([]);

        return $migration;
    }

    it('restores the missing foreign key when the old column is empty, ending on the exact historical schema', function () {
        $historical = (function () {
            HistoricalSchema::use(HistoricalSchema::ORG2);

            return o2Fingerprint();
        })();
        $migration = o2RenamedBackWithoutKey();

        $migration->down();

        expect(o2Fingerprint())->toBe($historical)
            ->and(DB::select("select 1 from information_schema.key_column_usage where table_schema = database() and table_name = 'crm_contacts' and constraint_name = 'crm_contacts_crm_company_id_foreign' and referenced_table_name = 'crm_companies'"))->not->toBe([]);
    });

    it('restores it through downContacts alone, not only through the whole down()', function () {
        $migration = o2RenamedBackWithoutKey();

        $migration->downContacts();

        expect($migration->downContacts())->toBeNull()   // and a repeat changes nothing
            ->and(DB::select("select 1 from information_schema.key_column_usage where table_schema = database() and table_name = 'crm_contacts' and constraint_name = 'crm_contacts_crm_company_id_foreign'"))->not->toBe([]);
    });

    it('refuses, and adds no key, when the old column already holds a value', function () {
        $migration = o2RenamedBackWithoutKey();
        DB::table('crm_contacts')->insert(['crm_company_id' => 4242, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        $fingerprint = o2Fingerprint();

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'carry a value in crm_contacts.crm_company_id')
            ->and(o2Fingerprint())->toBe($fingerprint);
    });

    it('refuses a foreign key it finds there with the wrong rule rather than accepting it', function () {
        $migration = o2RenamedBackWithoutKey();
        DB::statement('alter table crm_contacts add constraint crm_contacts_crm_company_id_foreign foreign key (crm_company_id) references crm_companies (id) on delete cascade');

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'ON DELETE CASCADE');
    });

    it('refuses a Person table with both columns, or neither', function () {
        $migration = o2Migrated();
        DB::statement('alter table crm_contacts add crm_company_id bigint unsigned null');

        expect(fn () => $migration->downContacts())->toThrow(RuntimeException::class, 'both crm_company_id and organization_id');
    });
});
