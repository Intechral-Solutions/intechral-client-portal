<?php

use App\Exceptions\OrganizationRelationshipException;
use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedUsers;

/*
 * Release 1.0 (MO-1A): the two migrations themselves. Kept in its own file for the reason PreservationMigrationsTest is:
 * DDL commits implicitly in MariaDB, so a test that runs a migration cannot rely on RefreshDatabase's rollback. These tests
 * create no rows before the DDL, and the one that must create rows after it (legacy ambiguity needs a duplicate link, which
 * the unique key forbids) removes them by id in a finally block and puts the schema back exactly as the suite expects it.
 *
 * What the populated-data behaviour is (rows survive, nothing else moves) is proven by the migration rehearsal harness.
 */

const MO_RELATIONSHIP = '2026_11_06_120000_add_organization_management_relationship';
const MO_UNIQUE = '2026_11_06_120100_unique_company_organization_link';

function moMigration(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

/** Whatever a test did, leave the schema migrated forward. */
function moRestoreForward(): void
{
    moMigration(MO_RELATIONSHIP)->up();
    moMigration(MO_UNIQUE)->up();
}

function moChecks(): array
{
    return array_map(fn ($row) => $row->constraint_name, DB::select(
        "select constraint_name from information_schema.table_constraints where constraint_schema = database() and table_name = 'organizations' and constraint_type = 'CHECK' order by constraint_name"
    ));
}

/** @return object{name: string, delete_rule: string, target: string, target_column: string}|null */
function moForeignKey(): ?object
{
    return DB::selectOne(
        'select k.constraint_name as name, r.delete_rule, k.referenced_table_name as target, k.referenced_column_name as target_column
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
         where k.table_schema = database() and k.table_name = ? and k.column_name = ?',
        ['organizations', 'managed_by_organization_id']
    );
}

function moIndexes(string $column, bool $unique): int
{
    return DB::selectOne(
        'select count(*) as n from information_schema.statistics where table_schema = database() and table_name = ? and column_name = ? and seq_in_index = 1 and non_unique = ?',
        ['crm_companies', $column, $unique ? 0 : 1]
    )->n;
}

describe('MO-1A relationship columns', function () {
    it('adds a nullable self-reference that restricts deletes, and a boolean defaulting to false, appended to the table', function () {
        $columns = collect(Schema::getColumns('organizations'))->keyBy('name');

        expect($columns['managed_by_organization_id']['nullable'])->toBeTrue()
            ->and($columns['managed_by_organization_id']['type'])->toContain('bigint')->toContain('unsigned')
            ->and($columns['can_manage_customers']['nullable'])->toBeFalse()
            ->and((string) $columns['can_manage_customers']['default'])->toBe('0')
            ->and($columns['can_manage_customers']['type'])->toStartWith('tinyint');

        $key = DB::selectOne(
            'select r.delete_rule as d, k.referenced_table_name as t, k.referenced_column_name as c, k.constraint_name as n
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = database() and k.table_name = ? and k.column_name = ?',
            ['organizations', 'managed_by_organization_id']
        );

        expect($key->d)->toBe('RESTRICT')->and($key->t)->toBe('organizations')->and($key->c)->toBe('id')
            ->and($key->n)->toBe('organizations_managed_by_organization_id_foreign');

        expect(collect(Schema::getIndexes('organizations'))->contains(fn ($index) => $index['columns'] === ['managed_by_organization_id']))->toBeTrue('the key is indexed');
    });

    it('leaves a new organization unmanaged and not a partner', function () {
        $organization = Organization::factory()->create();

        expect(DB::table('organizations')->where('id', $organization->id)->first(['can_manage_customers', 'managed_by_organization_id']))
            ->toEqual((object) ['can_manage_customers' => 0, 'managed_by_organization_id' => null]);
    });

    it('has the database itself refuse a partner that is managed, as a backstop for the service', function () {
        expect(moChecks())->toBe(['organizations_partner_not_managed_check']);

        $partner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $refused = function (array $change) {
            try {
                DB::table('organizations')->where('id', $change['id'])->update($change['set']);
            } catch (QueryException $e) {
                return (int) $e->errorInfo[1];
            }

            return null;
        };

        // 4025 is ER_CONSTRAINT_FAILED.
        expect($refused(['id' => $other->id, 'set' => ['can_manage_customers' => 1, 'managed_by_organization_id' => $partner->id]]))->toBe(4025);

        DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
        DB::table('organizations')->where('id', $other->id)->update(['managed_by_organization_id' => $partner->id]);
        expect($refused(['id' => $other->id, 'set' => ['can_manage_customers' => 1]]))->toBe(4025, 'a managed organization cannot become a partner')
            ->and($refused(['id' => $partner->id, 'set' => ['managed_by_organization_id' => $other->id]]))->toBe(4025, 'a partner cannot become managed');
    });

    it('restricts deleting an organization that still manages another', function () {
        $partner = Organization::factory()->create();
        DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
        $customer = Organization::factory()->create();
        DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $partner->id]);

        expect(fn () => DB::table('organizations')->where('id', $partner->id)->delete())->toThrow(QueryException::class);
        expect(DB::table('organizations')->where('id', $partner->id)->exists())->toBeTrue();
    });

    it('rolls back and forward, and a repeat is harmless', function () {
        $migration = moMigration(MO_RELATIONSHIP);

        try {
            $migration->down();
            expect(Schema::hasColumn('organizations', 'managed_by_organization_id'))->toBeFalse()
                ->and(Schema::hasColumn('organizations', 'can_manage_customers'))->toBeFalse()
                ->and(moChecks())->toBe([]);

            $migration->up();
            $migration->up();   // already there, correct shape: nothing to do
            expect(Schema::hasColumn('organizations', 'managed_by_organization_id'))->toBeTrue()
                ->and(moChecks())->toBe(['organizations_partner_not_managed_check']);

            $migration->down();
            $migration->down();
            expect(Schema::hasColumn('organizations', 'can_manage_customers'))->toBeFalse();
        } finally {
            moRestoreForward();
        }
    });

    it('completes a run that stopped after the foreign key was lost: the named key comes back, ON DELETE RESTRICT', function () {
        $migration = moMigration(MO_RELATIONSHIP);

        try {
            DB::statement('alter table organizations drop foreign key organizations_managed_by_organization_id_foreign');
            expect(moForeignKey())->toBeNull();

            $migration->up();

            expect(moForeignKey())->not->toBeNull()
                ->and(moForeignKey()->name)->toBe('organizations_managed_by_organization_id_foreign')
                ->and(moForeignKey()->delete_rule)->toBe('RESTRICT')
                ->and(moForeignKey()->target)->toBe('organizations')
                ->and(moForeignKey()->target_column)->toBe('id')
                ->and(moChecks())->toBe(['organizations_partner_not_managed_check']);
        } finally {
            moRestoreForward();
        }
    });

    it('completes an interrupted first run (the reference column exists, nothing else does), keeping the data already in it', function () {
        $migration = moMigration(MO_RELATIONSHIP);
        $owner = User::factory()->create();
        $ids = [];

        try {
            $migration->down();
            // What the first run left if it stopped right after creating the column (DDL commits as it goes).
            DB::statement('alter table organizations add column managed_by_organization_id bigint unsigned null');

            // DDL ended the test's transaction: these rows are committed and removed by hand below.
            $partner = Organization::factory()->create(['owner_id' => $owner->id]);
            $customer = Organization::factory()->create(['owner_id' => $owner->id]);
            $ids = [$partner->id, $customer->id];
            DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $partner->id]);

            $migration->up();

            expect(moForeignKey()?->name)->toBe('organizations_managed_by_organization_id_foreign')
                ->and(moForeignKey()->delete_rule)->toBe('RESTRICT')
                ->and(Schema::hasColumn('organizations', 'can_manage_customers'))->toBeTrue()
                ->and(moChecks())->toBe(['organizations_partner_not_managed_check'])
                ->and((int) DB::table('organizations')->where('id', $customer->id)->value('managed_by_organization_id'))->toBe($partner->id, 'existing data kept')
                ->and((int) DB::table('organizations')->where('id', $partner->id)->value('can_manage_customers'))->toBe(0);
        } finally {
            if (Schema::hasColumn('organizations', 'managed_by_organization_id')) {
                DB::table('organizations')->whereIn('id', $ids)->update(['managed_by_organization_id' => null]);
            }
            DB::table('organizations')->whereIn('id', $ids)->delete();
            CommittedUsers::delete([$owner->id]);
            moRestoreForward();
        }
    });

    it('stops rather than accept a foreign key of the same name that does not restrict deletes', function () {
        $migration = moMigration(MO_RELATIONSHIP);

        try {
            DB::statement('alter table organizations drop foreign key organizations_managed_by_organization_id_foreign');
            DB::statement('alter table organizations add constraint organizations_managed_by_organization_id_foreign foreign key (managed_by_organization_id) references organizations (id) on delete cascade');

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'ON DELETE RESTRICT');
        } finally {
            DB::statement('alter table organizations drop foreign key organizations_managed_by_organization_id_foreign');
            moRestoreForward();
        }

        expect(moForeignKey()->delete_rule)->toBe('RESTRICT');
    });

    it('stops rather than overwrite a differently-shaped column of the same name', function () {
        $migration = moMigration(MO_RELATIONSHIP);

        try {
            $migration->down();
            DB::statement('alter table organizations add column can_manage_customers varchar(20) null');

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'not a NOT NULL boolean');
        } finally {
            if (Schema::hasColumn('organizations', 'can_manage_customers')) {
                DB::statement('alter table organizations drop column can_manage_customers');
            }
            moRestoreForward();
        }

        expect(Schema::hasColumn('organizations', 'can_manage_customers'))->toBeTrue();
    });
});

describe('MO-1A unique company link', function () {
    it('makes crm_companies.organization_id unique while allowing any number of unpromoted companies', function () {
        $unique = DB::selectOne(
            "select count(*) as n from information_schema.statistics where table_schema = database() and table_name = 'crm_companies' and index_name = 'crm_companies_organization_id_unique' and non_unique = 0"
        )->n;
        $organization = Organization::factory()->create();

        CrmCompany::factory()->count(3)->create(['organization_id' => null]);
        CrmCompany::factory()->create(['organization_id' => $organization->id]);

        expect($unique)->toBe(1)
            ->and(fn () => CrmCompany::factory()->create(['organization_id' => $organization->id]))->toThrow(QueryException::class)
            ->and(CrmCompany::withoutGlobalScopes()->whereNull('organization_id')->count())->toBe(3);
    });

    it('rolls back and forward, and a repeat is harmless', function () {
        $migration = moMigration(MO_UNIQUE);

        try {
            $migration->down();
            $migration->down();
            expect(DB::selectOne("select count(*) as n from information_schema.statistics where table_schema = database() and table_name = 'crm_companies' and index_name = 'crm_companies_organization_id_unique'")->n)->toBe(0);

            $migration->up();
            $migration->up();
            expect(DB::selectOne("select count(*) as n from information_schema.statistics where table_schema = database() and table_name = 'crm_companies' and index_name = 'crm_companies_organization_id_unique'")->n)->toBe(1);
        } finally {
            moRestoreForward();
        }
    });

    it('rolls back from a restored database too, where the foreign key already has an explicit index of its own', function () {
        $migration = moMigration(MO_UNIQUE);

        try {
            // A dump carries the foreign key's index as a key in its own right; on a table the migration built it was replaced.
            DB::statement('alter table crm_companies add index crm_companies_organization_id_foreign (organization_id)');
            expect(moIndexes('organization_id', true))->toBe(1)->and(moIndexes('organization_id', false))->toBe(1);

            $migration->down();   // used to fail with error 1061 (duplicate key name)

            expect(moIndexes('organization_id', true))->toBe(0)->and(moIndexes('organization_id', false))->toBe(1, 'the foreign key keeps its index');
        } finally {
            moRestoreForward();
            // Leave exactly the schema the suite expects: the unique key serving as the foreign key's index.
            if (moIndexes('organization_id', true) === 1 && moIndexes('organization_id', false) === 1) {
                DB::statement('alter table crm_companies drop index crm_companies_organization_id_foreign');
            }
        }

        expect(moIndexes('organization_id', true))->toBe(1)->and(moIndexes('organization_id', false))->toBe(0);
    });

    it('refuses to run, naming the duplicates and changing nothing, while an organization has two companies; the service and the preflight see the ambiguity', function () {
        $migration = moMigration(MO_UNIQUE);
        $migration->down();   // DDL: the test transaction ends here. Everything below is committed and removed by hand.

        $owner = User::factory()->create();
        $created = ['users' => [$owner->id], 'organizations' => []];

        try {
            $ambiguous = Organization::factory()->create(['name' => 'Ambiguous', 'owner_id' => $owner->id]);
            $first = CrmCompany::factory()->create(['organization_id' => $ambiguous->id, 'created_by' => $owner->id]);
            $second = CrmCompany::factory()->create(['organization_id' => $ambiguous->id, 'created_by' => $owner->id]);
            $partner = Organization::factory()->create(['name' => 'Clean Partner', 'owner_id' => $owner->id]);
            CrmCompany::factory()->create(['organization_id' => $partner->id, 'created_by' => $owner->id]);
            $created['organizations'] = [$ambiguous->id, $partner->id];

            $before = DB::table('crm_companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, "organization {$ambiguous->id}: companies {$first->id},{$second->id}")
                ->and(DB::selectOne("select count(*) as n from information_schema.statistics where table_schema = database() and table_name = 'crm_companies' and index_name = 'crm_companies_organization_id_unique'")->n)->toBe(0)
                ->and(DB::table('crm_companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before, 'nothing was repaired, merged or deleted');

            // The relationship service treats the pair as ambiguous, so neither end of a relationship can be this organization.
            // The DDL above ended the real transaction while Laravel still counts one open, so the service's own transaction
            // would be a savepoint with nothing to belong to: open a real one, as the service finds it in production.
            DB::unprepared('start transaction');
            Gate::before(fn () => true);   // authorization is the subject of OrganizationRelationshipsTest, not of this one
            $service = app(OrganizationRelationships::class);
            $service->designatePartner($partner, $owner);

            $refusal = null;
            try {
                $service->assignCustomer($ambiguous, $partner, $owner);
            } catch (OrganizationRelationshipException $e) {
                $refusal = $e;
            }

            expect($refusal?->reason)->toBe(OrganizationRelationshipException::COMPANY_PAIRING)
                ->and($refusal->getMessage())->toContain('is linked to 2 CRM companies')
                ->and(fn () => $service->designatePartner($ambiguous, $owner))->toThrow(OrganizationRelationshipException::class);

            $report = app(OrganizationPreflight::class)->report();
            expect($report['duplicateLinks'])->toBe([['organization_id' => $ambiguous->id, 'company_ids' => [$first->id, $second->id]]])
                ->and($report['failures'])->not->toBeEmpty();
            $this->artisan('organizations:preflight')->assertFailed();
            DB::unprepared('commit');

            // Once a person has resolved it by hand, the migration runs.
            DB::table('crm_companies')->where('id', $second->id)->update(['organization_id' => null]);
            $migration->up();
            expect(DB::selectOne("select count(*) as n from information_schema.statistics where table_schema = database() and table_name = 'crm_companies' and index_name = 'crm_companies_organization_id_unique'")->n)->toBe(1);
        } finally {
            DB::table('activity_log')->where('causer_id', $owner->id)->delete();
            DB::table('organizations')->whereIn('id', $created['organizations'])->update(['managed_by_organization_id' => null]);
            CommittedUsers::delete($created['users']);
            moRestoreForward();
        }
    });
});
