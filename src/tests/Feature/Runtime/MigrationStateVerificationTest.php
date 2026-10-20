<?php

use App\Support\Database\DatabaseProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, independent review F3): the migration state is verified by ONE rule, DatabaseProfile::safelyWithMigrations(),
 * shared by `database:verify` and `runtime:preflight`. The foreign-key manifest describes the schema after EVERY migration, so with a
 * migration pending (or the migration state unreadable) it cannot be compared, and an unverifiable check is a failure, never a pass.
 *
 * Test isolation: every simulation here is a DML change inside the test's own transaction (RefreshDatabase rolls it back) on the designated
 * testing database, or a guarded query hook disarmed in `finally`. Nothing here runs DDL, which would commit the transaction.
 */

const MSV_FK_READER = 'referential_constraints';
const MSV_PENDING = 'migration(s) are pending, so the foreign keys were not verified';
const MSV_UNREADABLE = 'the migration state could not be read, so the foreign keys were not verified';

afterEach(function () {
    app('cache')->forgetDriver();
    app()->forgetInstance('config_loaded_from_cache');
});

/** Run both commands and return [verify code, verify output, preflight code, preflight output] in the requested format. */
function msvRunBoth(bool $json = false): array
{
    $arguments = $json ? ['--json' => true] : [];

    $verify = Artisan::call('database:verify', $arguments);
    $verifyOutput = Artisan::output();
    $preflight = Artisan::call('runtime:preflight', $arguments);

    return [$verify, $verifyOutput, $preflight, Artisan::output()];
}

/** How many queries of the foreign-key reader ran while $body ran. */
function msvForeignKeyReads(Closure $body): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $body();

        return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], MSV_FK_READER))->count();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

describe('a pending migration', function () {
    it('is reported by database:verify and runtime:preflight, as a failure, in human and JSON form', function (bool $json) {
        rtpUseProductionProfile();

        // Control: on the migrated testing database the production profile passes both commands, so the failure below is the simulation's.
        [$verify, , $preflight] = msvRunBoth($json);
        expect($verify)->toBe(0)->and($preflight)->toBe(0);

        $latest = DB::table('migrations')->orderByDesc('id')->first();
        expect(DB::table('migrations')->where('id', $latest->id)->delete())->toBe(1);

        expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBe(1);

        [$verify, $verifyOutput, $preflight, $preflightOutput] = msvRunBoth($json);

        expect($verify)->toBe(1)->and($verifyOutput)->toContain('1 '.MSV_PENDING)->toContain('run `php artisan migrate` first')
            ->and($preflight)->toBe(1)->and($preflightOutput)->toContain('1 '.MSV_PENDING);
    })->with([[false], [true]]);

    it('does not compare the foreign keys, which only a fully migrated schema can be compared against', function () {
        rtpUseProductionProfile();

        // Control: the reader does run when nothing is pending, so a count of zero below is the rule, not a blind spot in the probe.
        expect(msvForeignKeyReads(fn () => Artisan::call('database:verify')))->toBeGreaterThan(0)
            ->and(msvForeignKeyReads(fn () => Artisan::call('runtime:preflight')))->toBeGreaterThan(0);

        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        expect(msvForeignKeyReads(fn () => Artisan::call('database:verify')))->toBe(0)
            ->and(msvForeignKeyReads(fn () => Artisan::call('runtime:preflight')))->toBe(0)
            ->and(msvForeignKeyReads(fn () => DatabaseProfile::safelyWithMigrations(DB::connection())))->toBe(0);
    });

    it('restores the migration state when the test ends', function () {
        // RefreshDatabase's rollback of the previous test's delete: every migration file is recorded again.
        expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBe(0);
    });
});

describe('an unreadable migration state', function () {
    /** Make the migration repository read fail with an exception whose message carries a host, credentials and SQL. */
    function msvBreakMigrationRead(Closure $body): void
    {
        $armed = true;
        $secret = 'SQLSTATE[HY000] [2002] cannot reach db-internal-7 as portal with password PWSECRET';

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed, $secret) {
            if ($armed && str_contains($query, '`migrations`')) {
                throw new QueryException('mysql', $query, $bindings, new PDOException($secret, 2002));
            }
        });

        try {
            $body();
        } finally {
            $armed = false;
        }
    }

    /** The migrator itself fails (its files cannot be listed). */
    function msvBreakMigrator(Closure $body): void
    {
        $original = app('migrator');
        app()->instance('migrator', new class($original)
        {
            public function __construct(private readonly object $real) {}

            public function __call(string $method, array $arguments): never
            {
                throw new RuntimeException('cannot read /home/portal/secret-release/database/migrations as portal with password PWSECRET');
            }
        });

        try {
            $body();
        } finally {
            app()->instance('migrator', $original);
        }
    }

    it('is reported as unverifiable by both commands, which fail closed (repository read fails)', function (bool $json) {
        rtpUseProductionProfile();

        msvBreakMigrationRead(function () use ($json) {
            expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBeNull();

            [$verify, $verifyOutput, $preflight, $preflightOutput] = msvRunBoth($json);

            expect($verify)->toBe(1)->and($verifyOutput)->toContain(MSV_UNREADABLE)
                ->and($preflight)->toBe(1)->and($preflightOutput)->toContain(MSV_UNREADABLE);

            foreach ([$verifyOutput, $preflightOutput] as $output) {
                expect($output)->not->toContain('PWSECRET')->not->toContain('db-internal-7')->not->toContain('select *')->not->toContain('`migrations`');
            }
        });
    })->with([[false], [true]]);

    it('is reported as unverifiable by both commands, which fail closed (migrator fails)', function () {
        rtpUseProductionProfile();

        msvBreakMigrator(function () {
            expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBeNull();

            [$verify, $verifyOutput, $preflight, $preflightOutput] = msvRunBoth();

            expect($verify)->toBe(1)->and($verifyOutput)->toContain(MSV_UNREADABLE)
                ->and($preflight)->toBe(1)->and($preflightOutput)->toContain(MSV_UNREADABLE);

            foreach ([$verifyOutput, $preflightOutput] as $output) {
                expect($output)->not->toContain('PWSECRET')->not->toContain('secret-release')->not->toContain('/home/portal');
            }
        });
    });

    it('does not compare the foreign keys', function () {
        rtpUseProductionProfile();

        msvBreakMigrationRead(function () {
            expect(msvForeignKeyReads(fn () => Artisan::call('database:verify')))->toBe(0)
                ->and(msvForeignKeyReads(fn () => Artisan::call('runtime:preflight')))->toBe(0);
        });
    });

    it('recovers once the failure is gone: the hook is disarmed and the state reads again', function () {
        rtpUseProductionProfile();
        msvBreakMigrationRead(fn () => null);

        expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBe(0);
    });
});
