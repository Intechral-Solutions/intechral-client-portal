<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedUsers;

/*
 * Release 1.0 (R1-2A): the ticket attribution migration. Kept in its own file for the reason PreservationMigrationsTest is: DDL commits
 * implicitly in MariaDB, so a test that runs a migration cannot rely on RefreshDatabase's rollback. Each test therefore steps the schema
 * BACK with down() (the table is empty at the start of every test), works, and in a finally block removes what it committed and moves the
 * schema forward again, so the rest of the suite sees the shape it expects.
 *
 * What the populated-data behaviour is NOT: the migration never converts data. It refuses a populated tickets table (no ticket has a
 * provable organization), so there is no "rows survive" case to rehearse; the refusal is the contract.
 */

const TA_MIGRATION = '2026_11_10_120000_add_ticket_organization_attribution';

function taMigration(): object
{
    return require database_path('migrations/'.TA_MIGRATION.'.php');
}

/** Columns and constraints of `tickets` as one comparable string, so "nothing changed" is a single equality. */
function taShape(): string
{
    $columns = DB::select('select column_name c, column_type t, is_nullable n, column_default d, ordinal_position p from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position', ['tickets']);
    $keys = DB::select('select constraint_name n, constraint_type t from information_schema.table_constraints where table_schema = database() and table_name = ? order by constraint_name', ['tickets']);
    $indexes = DB::select('select index_name n, column_name c, seq_in_index s from information_schema.statistics where table_schema = database() and table_name = ? order by index_name, seq_in_index', ['tickets']);

    return json_encode([$columns, $keys, $indexes]);
}

/** A committed legacy-shaped ticket (after down(): has `company_id`, no attribution columns). */
function taLegacyTicket(User $user, string $reference, string $status = 'open', ?int $companyId = null): int
{
    return DB::table('tickets')->insertGetId([
        'ticket_number' => $reference, 'user_id' => $user->id, 'company_id' => $companyId,
        'title' => 'Legacy request, private title', 'description' => 'Private description text', 'category' => 'General', 'priority' => 'low', 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function taWith(callable $body): void
{
    $createdUsers = [];
    $createdTickets = [];

    try {
        taMigration()->down();
        $body($createdUsers, $createdTickets);
    } finally {
        DB::table('tickets')->whereIn('id', $createdTickets)->delete();
        // Back to the canonical shape whatever the body did to it (a sabotaged shape would make up() refuse, by design): down() copes with any
        // of them, and up() then runs on an empty table.
        taMigration()->down();
        taMigration()->up();
        // Removes the users' organizations (and their memberships) and the users, in dependency order.
        CommittedUsers::delete($createdUsers);
    }
}

it('refuses a populated tickets table before touching the schema, names counts and references, and exposes no personal data', function () {
    taWith(function (array &$users, array &$tickets) {
        $a = User::factory()->create(['name' => 'Alice Private', 'email' => 'alice.private@example.test']);
        $b = User::factory()->create(['name' => 'Bob Private', 'email' => 'bob.private@example.test']);
        $users = [$a->id, $b->id];
        $organization = Organization::factory()->create(['owner_id' => $a->id]);
        DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $a->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        $tickets[] = taLegacyTicket($a, 'TK-AAAAA-AAAAA', 'open');
        $tickets[] = taLegacyTicket($a, 'TK-BBBBB-BBBBB', 'closed');
        $tickets[] = taLegacyTicket($b, 'TK-CCCCC-CCCCC', 'open');

        $before = taShape();
        $rows = DB::table('tickets')->orderBy('id')->get()->toJson();

        $message = null;
        try {
            taMigration()->up();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($message)->not->toBeNull()
            ->toContain('Refusing to add ticket organization attribution')
            ->toContain('holds 3 ticket(s)')->toContain('open 2, closed 1')->toContain('from 2 requester(s)')
            ->toContain('1 have no organization membership now, 1 have one and 0 have several')
            ->toContain('TK-AAAAA-AAAAA')->toContain('TK-CCCCC-CCCCC')
            ->toContain('Nothing was changed')
            ->not->toContain('alice.private')->not->toContain('Alice')->not->toContain('Private description');

        // BEFORE any DDL: the schema is byte-identical and every row is exactly as it was.
        expect(taShape())->toBe($before)
            ->and(Schema::hasColumn('tickets', 'company_id'))->toBeTrue()
            ->and(Schema::hasColumn('tickets', 'organization_id'))->toBeFalse()
            ->and(Schema::hasColumn('tickets', 'submitted_by_id'))->toBeFalse()
            ->and(DB::table('tickets')->orderBy('id')->get()->toJson())->toBe($rows);
    });
});

it('counts a legacy company link in the refusal and still invents nothing', function () {
    taWith(function (array &$users, array &$tickets) {
        $user = User::factory()->create();
        $users = [$user->id];
        $company = DB::table('crm_companies')->insertGetId(['name' => 'Legacy Co', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $tickets[] = taLegacyTicket($user, 'TK-DDDDD-DDDDD', 'open', $company);

        try {
            taMigration()->up();
            $message = '';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('1 carry a legacy company link');
        DB::table('tickets')->whereIn('id', $tickets)->delete();
        DB::table('crm_companies')->where('id', $company)->delete();
    });
});

it('migrates an empty table: two NOT NULL RESTRICT keys added, company_id and its key gone, and a second run changes nothing', function () {
    taWith(function () {
        $before = taShape();
        expect(Schema::hasColumn('tickets', 'company_id'))->toBeTrue();

        taMigration()->up();
        $after = taShape();

        expect(Schema::hasColumn('tickets', 'company_id'))->toBeFalse()
            ->and(Schema::hasColumn('tickets', 'organization_id'))->toBeTrue()
            ->and(Schema::hasColumn('tickets', 'submitted_by_id'))->toBeTrue()
            ->and($after)->not->toBe($before);

        $keys = collect(DB::select("select constraint_name as n from information_schema.table_constraints where table_schema = database() and table_name = 'tickets' and constraint_type = 'FOREIGN KEY'"))->pluck('n')->all();
        expect($keys)->toContain('tickets_organization_id_foreign', 'tickets_submitted_by_id_foreign')->not->toContain('tickets_company_id_foreign');

        taMigration()->up();
        expect(taShape())->toBe($after);
    });
});

const TA_ATTRIBUTION_STATEMENT = 'alter table `tickets` add column `organization_id` bigint unsigned not null after `user_id`, add column `submitted_by_id` bigint unsigned not null after `organization_id`,
    add constraint `tickets_organization_id_foreign` foreign key (`organization_id`) references `organizations` (`id`) on delete restrict on update restrict,
    add constraint `tickets_submitted_by_id_foreign` foreign key (`submitted_by_id`) references `users` (`id`) on delete restrict on update restrict';

it('finishes a run that stopped between its two statements, even though attributed tickets now exist (M-1)', function () {
    taWith(function (array &$users, array &$tickets) {
        // The state after statement one but before statement two: attribution added, company_id still there.
        DB::statement(TA_ATTRIBUTION_STATEMENT);

        // The application has been running since: it writes attributed tickets (that is what the columns are for).
        $user = User::factory()->create();
        $users = [$user->id];
        $organization = Organization::factory()->create(['owner_id' => $user->id]);
        $tickets[] = DB::table('tickets')->insertGetId([
            'ticket_number' => 'TK-EEEEE-EEEEE', 'user_id' => $user->id, 'organization_id' => $organization->id, 'submitted_by_id' => $user->id,
            'title' => 'Created after the first statement', 'description' => 'x', 'category' => 'General', 'priority' => 'low', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A populated table is normal here: the legacy refusal applies only BEFORE the first statement.
        taMigration()->up();

        expect(Schema::hasColumn('tickets', 'company_id'))->toBeFalse()
            ->and(Schema::hasColumn('tickets', 'organization_id'))->toBeTrue()
            ->and(DB::table('tickets')->where('id', $tickets[0])->value('organization_id'))->toBe($organization->id);

        $after = taShape();
        taMigration()->up();
        expect(taShape())->toBe($after, 'a finished migration run again changes nothing, populated or not');
    });
});

it('still refuses a populated table whose attribution columns have never been added, even if company_id is already gone', function () {
    taWith(function (array &$users, array &$tickets) {
        $user = User::factory()->create();
        $users = [$user->id];
        $tickets[] = taLegacyTicket($user, 'TK-FFFFF-FFFFF');
        DB::statement('alter table `tickets` drop foreign key `tickets_company_id_foreign`');
        DB::statement('alter table `tickets` drop column `company_id`');

        expect(fn () => taMigration()->up())->toThrow(RuntimeException::class, 'Refusing to add ticket organization attribution');
        expect(Schema::hasColumn('tickets', 'organization_id'))->toBeFalse();
    });
});

it('refuses, changing nothing, to build on or declare done an attribution shape it did not build (M-1: fail closed)', function (string $sabotage, string $expected, bool $companyStillThere) {
    taWith(function () use ($sabotage, $expected, $companyStillThere) {
        DB::statement(TA_ATTRIBUTION_STATEMENT);

        if (! $companyStillThere) {
            DB::statement('alter table `tickets` drop foreign key `tickets_company_id_foreign`');
            DB::statement('alter table `tickets` drop column `company_id`');
        }

        foreach (explode(';', $sabotage) as $statement) {
            DB::statement(trim($statement));
        }

        $before = taShape();

        expect(fn () => taMigration()->up())->toThrow(RuntimeException::class, $expected);
        expect(taShape())->toBe($before)
            ->and(Schema::hasColumn('tickets', 'company_id'))->toBe($companyStillThere);
    });
})->with([
    'nullable organization (interrupted, company_id still there)' => ['alter table `tickets` modify `organization_id` bigint unsigned null', 'organization_id is nullable', true],
    'nullable submitter (already "done")' => ['alter table `tickets` modify `submitted_by_id` bigint unsigned null', 'submitted_by_id is nullable', false],
    'narrower type (and, necessarily, no key: MariaDB will not change a column a key still uses)' => ['alter table `tickets` drop foreign key `tickets_organization_id_foreign`; alter table `tickets` modify `organization_id` int not null', 'not bigint unsigned', true],
    'a default value' => ['alter table `tickets` modify `submitted_by_id` bigint unsigned not null default 1', 'default or generated value', true],
    'cascade instead of restrict' => ['alter table `tickets` drop foreign key `tickets_organization_id_foreign`; alter table `tickets` add constraint `tickets_organization_id_foreign` foreign key (`organization_id`) references `organizations` (`id`) on delete cascade on update restrict', 'ON DELETE CASCADE', true],
    'update rule not restrict' => ['alter table `tickets` drop foreign key `tickets_submitted_by_id_foreign`; alter table `tickets` add constraint `tickets_submitted_by_id_foreign` foreign key (`submitted_by_id`) references `users` (`id`) on delete restrict on update cascade', 'ON UPDATE CASCADE', false],
    'no foreign key at all' => ['alter table `tickets` drop foreign key `tickets_organization_id_foreign`', 'has 0 foreign key(s)', false],
    'a foreign key to the wrong parent' => ['alter table `tickets` drop foreign key `tickets_submitted_by_id_foreign`; alter table `tickets` add constraint `tickets_submitted_by_id_foreign` foreign key (`submitted_by_id`) references `organizations` (`id`) on delete restrict on update restrict', 'organizations.id', true],
    'a second key on the same column' => ['alter table `tickets` add constraint `tickets_org_dup_foreign` foreign key (`organization_id`) references `organizations` (`id`) on delete restrict on update restrict', 'has 2 foreign key(s)', true],
]);

it('refuses to drop a company_id that still carries a value, rather than destroy it', function () {
    taWith(function (array &$users, array &$tickets) {
        DB::statement(TA_ATTRIBUTION_STATEMENT);
        $user = User::factory()->create();
        $users = [$user->id];
        $organization = Organization::factory()->create(['owner_id' => $user->id]);
        $company = DB::table('crm_companies')->insertGetId(['name' => 'Hand-linked Co', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $tickets[] = DB::table('tickets')->insertGetId([
            'ticket_number' => 'TK-GGGGG-GGGGG', 'user_id' => $user->id, 'organization_id' => $organization->id, 'submitted_by_id' => $user->id, 'company_id' => $company,
            'title' => 'x', 'description' => 'x', 'category' => 'General', 'priority' => 'low', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(fn () => taMigration()->up())->toThrow(RuntimeException::class, 'Refusing to drop tickets.company_id: 1 ticket(s) carry a company link')
            ->and(Schema::hasColumn('tickets', 'company_id'))->toBeTrue();

        DB::table('tickets')->whereIn('id', $tickets)->delete();
        DB::table('crm_companies')->where('id', $company)->delete();
    });
});

it('refuses a table with exactly one attribution column', function () {
    taWith(function () {
        DB::statement(TA_ATTRIBUTION_STATEMENT);
        DB::statement('alter table `tickets` drop foreign key `tickets_submitted_by_id_foreign`');
        DB::statement('alter table `tickets` drop column `submitted_by_id`');

        expect(fn () => taMigration()->up())->toThrow(RuntimeException::class, 'exactly one of organization_id and submitted_by_id');
    });
});

it('rolls back to the previous shape on an empty table', function () {
    taWith(function () {
        expect(Schema::hasColumn('tickets', 'company_id'))->toBeTrue()
            ->and(Schema::hasColumn('tickets', 'organization_id'))->toBeFalse();

        $fk = DB::selectOne("select r.delete_rule as d from information_schema.referential_constraints r where r.constraint_schema = database() and r.table_name = 'tickets' and r.constraint_name = 'tickets_company_id_foreign'");
        expect($fk->d)->toBe('SET NULL');
    });
});

it('is recorded in the foreign-key manifest, which no longer carries the company key', function () {
    $manifest = json_decode(file_get_contents(database_path('profile/foreign-keys.json')), true);
    $keys = collect($manifest)->flatten()->all();
    $flat = json_encode($manifest);

    expect($flat)->toContain('tickets.organization_id->organizations.id')->toContain('tickets.submitted_by_id->users.id')
        ->not->toContain('tickets.company_id');
    expect($keys)->not->toBeEmpty();
});
