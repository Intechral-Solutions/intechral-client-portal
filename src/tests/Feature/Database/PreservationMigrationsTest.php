<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PreservationKeys;

/*
 * Release 0.9 (R0.9-1B): the behaviour of the three schema-protection migrations themselves. Kept in its own file
 * with no fixtures: DDL statements commit implicitly in MariaDB, so these tests create no rows the RefreshDatabase
 * transaction would otherwise have rolled back, and every test puts the schema back exactly as the rest of the
 * suite expects it (in a finally block that runs the migration forward if the test left it behind).
 *
 * The populated-data behaviour (rows survive, the new column on a populated table) is proven by the migration
 * rehearsal harness: see PreservationRehearsalTest.
 */

const M1 = '2026_10_12_000001_add_disabled_at_to_users_table';
const M2 = '2026_10_12_000002_restrict_user_business_record_deletes';
const M3 = '2026_10_12_000003_restrict_company_project_association_deletes';

function preservationMigration(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

/** "table.column" => "RULE (constraint)" for the columns these migrations govern. */
function governedKeyRules(array $columns): array
{
    $rules = [];
    foreach ($columns as [$table, $column]) {
        $key = DB::selectOne(
            'select k.constraint_name as n, r.delete_rule as d from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = database() and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null',
            [$table, $column]
        );
        $rules["{$table}.{$column}"] = $key === null ? 'none' : "{$key->d} ({$key->n})";
    }

    return $rules;
}

function h1Columns(): array
{
    return array_map(fn (array $key) => [$key[0], $key[1]], PreservationKeys::USER_KEYS);
}

function h1Rules(string $rule): array
{
    $expected = [];
    foreach (PreservationKeys::USER_KEYS as [$table, $column, $constraint]) {
        $expected["{$table}.{$column}"] = "{$rule} ({$constraint})";
    }

    return $expected;
}

/** Whatever a test did, leave the schema migrated forward. */
function restoreForward(): void
{
    foreach ([M1, M2, M3] as $name) {
        preservationMigration($name)->up();
    }
}

describe('M1: users.disabled_at', function () {
    it('rolls back to no column and forward to a nullable timestamp, and a repeat is harmless', function () {
        $migration = preservationMigration(M1);

        try {
            $migration->down();
            expect(Schema::hasColumn('users', 'disabled_at'))->toBeFalse();

            $migration->up();
            $migration->up();   // already there, correct shape: nothing to do
            expect(Schema::hasColumn('users', 'disabled_at'))->toBeTrue();
        } finally {
            restoreForward();
        }
    });

    it('stops rather than overwrite a differently-shaped column of the same name', function () {
        $migration = preservationMigration(M1);

        try {
            $migration->down();
            DB::statement('alter table users add column disabled_at varchar(20) not null default ""');

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'not a nullable timestamp');
        } finally {
            if (Schema::hasColumn('users', 'disabled_at')) {
                DB::statement('alter table users drop column disabled_at');
            }
            restoreForward();
        }

        expect(Schema::hasColumn('users', 'disabled_at'))->toBeTrue();
    });
});

describe('M2: the 13 user business-record keys', function () {
    it('rolls back to CASCADE and forward to RESTRICT, under the original names, and a repeat is harmless', function () {
        $migration = preservationMigration(M2);

        try {
            $migration->down();
            expect(governedKeyRules(h1Columns()))->toBe(h1Rules('CASCADE'));

            $migration->up();
            expect(governedKeyRules(h1Columns()))->toBe(h1Rules('RESTRICT'));

            $migration->up();   // everything already done: nothing to do, nothing fails
            $migration->down();
            $migration->down();
            expect(governedKeyRules(h1Columns()))->toBe(h1Rules('CASCADE'));
        } finally {
            restoreForward();
        }

        expect(governedKeyRules(h1Columns()))->toBe(h1Rules('RESTRICT'));
    });

    it('refuses, changing nothing, when one key is not what the inventory says', function (string $label, string $rules, string $expected) {
        $migration = preservationMigration(M2);

        try {
            $migration->down();
            // tickets.user_id differs from the schema this migration was written for.
            DB::statement('alter table tickets drop foreign key tickets_user_id_foreign');
            DB::statement("alter table tickets add constraint tickets_user_id_foreign foreign key (user_id) references users (id) {$rules}");

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'differs from the approved inventory')
                ->and(fn () => $migration->up())->toThrow(RuntimeException::class, $expected);

            // None of the other twelve was touched by the refused run.
            $actual = governedKeyRules(h1Columns());
            unset($actual['tickets.user_id']);
            $cascade = h1Rules('CASCADE');
            unset($cascade['tickets.user_id']);
            expect($actual)->toBe($cascade);
        } finally {
            DB::statement('alter table tickets drop foreign key tickets_user_id_foreign');
            DB::statement('alter table tickets add constraint tickets_user_id_foreign foreign key (user_id) references users (id) on delete cascade');
            restoreForward();
        }

        expect(governedKeyRules(h1Columns()))->toBe(h1Rules('RESTRICT'));
    })->with([
        'an ON DELETE rule that is neither CASCADE nor RESTRICT' => ['delete', 'on delete no action', 'tickets.user_id has ON DELETE NO ACTION, expected CASCADE'],
        'an ON UPDATE rule that this migration would silently reset' => ['update', 'on delete cascade on update cascade', 'tickets.user_id has ON UPDATE CASCADE, expected RESTRICT'],
    ]);

    it('refuses a key under an unexpected constraint name instead of guessing', function () {
        $migration = preservationMigration(M2);

        try {
            $migration->down();
            DB::statement('alter table projects drop foreign key projects_created_by_foreign');
            DB::statement('alter table projects add constraint some_other_name foreign key (created_by) references users (id) on delete cascade');

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'projects.created_by is constrained by "some_other_name", not the expected "projects_created_by_foreign"');
        } finally {
            DB::statement('alter table projects drop foreign key some_other_name');
            DB::statement('alter table projects add constraint projects_created_by_foreign foreign key (created_by) references users (id) on delete cascade');
            restoreForward();
        }
    });

    it('finishes a replacement that was interrupted between its two steps', function () {
        $migration = preservationMigration(M2);

        try {
            $migration->down();
            // Step one only: the CASCADE key is gone and a RESTRICT key exists under the temporary name.
            DB::statement('alter table invoices drop foreign key invoices_client_id_foreign, add constraint invoices_client_id_foreign_restrict foreign key (client_id) references users (id) on delete restrict');
            expect(governedKeyRules([['invoices', 'client_id']]))->toBe(['invoices.client_id' => 'RESTRICT (invoices_client_id_foreign_restrict)']);

            $migration->up();

            expect(governedKeyRules(h1Columns()))->toBe(h1Rules('RESTRICT'))
                ->and(DB::select("select constraint_name from information_schema.referential_constraints where constraint_schema = database() and constraint_name like '%\\_restrict'"))->toBe([]);
        } finally {
            restoreForward();
        }
    });

    it('never leaves a column without a foreign key while it replaces one', function () {
        $migration = preservationMigration(M2);
        $unprotected = [];

        try {
            $migration->down();

            // Observe the schema after every ALTER the migration issues (the listener runs after each statement).
            DB::listen(function ($query) use (&$unprotected) {
                if (str_starts_with($query->sql, 'alter table')) {
                    foreach (governedKeyRules(h1Columns()) as $column => $rule) {
                        if ($rule === 'none') {
                            $unprotected[] = $column;
                        }
                    }
                }
            });

            $migration->up();
        } finally {
            restoreForward();
        }

        expect($unprotected)->toBe([]);
    });
});

describe('M3: project_company.crm_company_id', function () {
    it('rolls back to CASCADE and forward to RESTRICT, under the original name, and a repeat is harmless', function () {
        $migration = preservationMigration(M3);
        $column = [['project_company', 'crm_company_id']];

        try {
            $migration->down();
            expect(governedKeyRules($column))->toBe(['project_company.crm_company_id' => 'CASCADE (project_company_crm_company_id_foreign)']);

            $migration->up();
            $migration->up();
            expect(governedKeyRules($column))->toBe(['project_company.crm_company_id' => 'RESTRICT (project_company_crm_company_id_foreign)']);
        } finally {
            restoreForward();
        }
    });

    it('does not touch the other side of the association or the other company references', function () {
        $migration = preservationMigration(M3);
        $others = [['project_company', 'project_id'], ['crm_contacts', 'crm_company_id'], ['tickets', 'company_id']];
        $before = governedKeyRules($others);

        try {
            $migration->down();
            $migration->up();
            expect(governedKeyRules($others))->toBe($before)
                ->and($before['project_company.project_id'])->toStartWith('CASCADE');
        } finally {
            restoreForward();
        }
    });
});
