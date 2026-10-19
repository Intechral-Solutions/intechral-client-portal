<?php

use App\Support\Database\DatabaseProfile;
use App\Support\Database\DatabaseProfileViolation;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Release 0.9 (R0.9 WP3): the database profile the application states for itself (InnoDB, UTC session, strict utf8mb4) and the
 * read-only verification of it. docs/architecture/database-profile.md.
 *
 * Why this exists: the Namecheap probe of 2026-10-19 reported MyISAM, latin1, a non-strict server sql_mode and an Eastern-time
 * session on the same MariaDB 11.4.13 that development and CI run with InnoDB, utf8mb4, strict mode and UTC. CI's server is
 * friendly, so these tests assert the Laravel CONNECTION's effective settings, never the server defaults, and they prove that
 * each check can fail (the violation tests) rather than only that it passes on a friendly server. The host-parity evidence (a
 * disposable server with the host's defaults) is kept with the package handoff and described in the documentation.
 */

/** Run $body with a session setting changed, and put the connection back to the profile afterwards. */
function dbProfileWithSession(string $set, Closure $body): void
{
    $mode = DB::selectOne('select @@session.sql_mode as m')->m;

    try {
        DB::statement($set);
        $body();
    } finally {
        DB::statement("SET SESSION time_zone = '+00:00'");
        DB::statement('SET SESSION sql_mode = ?', [$mode]);
        DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
    }
}

it('states the profile in both supported connection configurations, as literals', function () {
    expect(DatabaseProfile::configuration())->toBe([]);

    foreach (DatabaseProfile::CONNECTIONS as $name) {
        expect(config("database.connections.{$name}.engine"))->toBe('InnoDB')
            ->and(config("database.connections.{$name}.timezone"))->toBe('+00:00')
            ->and(config("database.connections.{$name}.strict"))->toBeTrue()
            ->and(config("database.connections.{$name}.charset"))->toBe('utf8mb4')
            ->and(config("database.connections.{$name}.collation"))->toBe('utf8mb4_unicode_ci');
    }
});

it('runs the Laravel connection in the supported session whatever the server defaults are', function () {
    $s = DB::selectOne('select @@session.time_zone as tz, @@session.sql_mode as mode, @@character_set_connection as cs, @@collation_connection as co');

    expect(DatabaseProfile::session(DB::connection()))->toBe([])
        ->and($s->tz)->toBe('+00:00')
        ->and($s->mode)->toContain('STRICT_TRANS_TABLES')->toContain('NO_ENGINE_SUBSTITUTION')
        ->and([$s->cs, $s->co])->toBe(['utf8mb4', 'utf8mb4_unicode_ci']);
});

it('finds the migrated schema in the supported profile and equal to the committed foreign-key manifest', function () {
    if (getenv('UPDATE_DATABASE_PROFILE_MANIFEST') === '1') {
        $live = DatabaseProfile::liveForeignKeys(DB::connection());
        ksort($live);
        file_put_contents(base_path(DatabaseProfile::MANIFEST), json_encode(['version' => 1, 'foreign_keys' => $live], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect(DatabaseProfile::expectedForeignKeys())->not->toBeEmpty()
        ->and(DatabaseProfile::verify(DB::connection()))->toBe([]);
})->note('A new migration that adds or changes a foreign key must update database/profile/foreign-keys.json: run this test once with UPDATE_DATABASE_PROFILE_MANIFEST=1 and review the diff.');

it('reports a session that is not UTC, not strict, or not utf8mb4', function () {
    dbProfileWithSession("SET SESSION time_zone = '-05:00'", function () {
        expect(DatabaseProfile::session(DB::connection()))->toBe(["session time_zone is '-05:00', expected '+00:00'"]);
    });

    dbProfileWithSession("SET SESSION sql_mode = ''", function () {
        $violations = DatabaseProfile::session(DB::connection());

        expect($violations)->toHaveCount(2)
            ->and($violations[0])->toContain('not strict')
            ->and($violations[1])->toContain('NO_ENGINE_SUBSTITUTION');
    });

    dbProfileWithSession('SET NAMES latin1', function () {
        expect(DatabaseProfile::session(DB::connection()))->toBe(["connection character set/collation is 'latin1/latin1_swedish_ci', expected 'utf8mb4/utf8mb4_unicode_ci'"]);
    });

    expect(DatabaseProfile::session(DB::connection()))->toBe([]);
});

it('reports a table that is not InnoDB or not utf8mb4, and a column in another character set', function () {
    try {
        DB::statement('create table wp3_profile_myisam (id int not null primary key) engine = MyISAM default charset = utf8mb4 collate utf8mb4_unicode_ci');
        DB::statement('create table wp3_profile_latin1 (id int not null primary key, note varchar(20)) engine = InnoDB default charset = latin1');

        expect(DatabaseProfile::tables(DB::connection()))->toBe([
            'table wp3_profile_latin1 has collation latin1_swedish_ci, expected utf8mb4_unicode_ci',
            'table wp3_profile_myisam uses engine MyISAM, expected InnoDB',
            'column wp3_profile_latin1.note has character set latin1, expected utf8mb4',
        ]);
    } finally {
        DB::statement('drop table if exists wp3_profile_myisam');
        DB::statement('drop table if exists wp3_profile_latin1');
    }

    expect(DatabaseProfile::tables(DB::connection()))->toBe([]);
});

it('accepts utf8mb4_bin only on a JSON column', function () {
    // activity_log.properties is the schema's one JSON column: MariaDB forces utf8mb4_bin on it and adds a json_valid check.
    $json = DB::selectOne("select collation_name as c from information_schema.columns where table_schema = database() and table_name = 'activity_log' and column_name = 'properties'");

    expect($json->c)->toBe('utf8mb4_bin')
        ->and(DatabaseProfile::tables(DB::connection()))->toBe([]);

    try {
        DB::statement('create table wp3_profile_bin (id int not null primary key, note varchar(20) collate utf8mb4_bin) engine = InnoDB default charset = utf8mb4 collate utf8mb4_unicode_ci');

        expect(DatabaseProfile::tables(DB::connection()))->toBe(['column wp3_profile_bin.note has collation utf8mb4_bin, expected utf8mb4_unicode_ci']);
    } finally {
        DB::statement('drop table if exists wp3_profile_bin');
    }
});

it('compares foreign keys with the manifest in both directions and by rule', function () {
    $expected = ['a.b_id->b.id' => 'RESTRICT/RESTRICT', 'a.c_id->c.id' => 'SET NULL/RESTRICT'];

    expect(DatabaseProfile::compareForeignKeys($expected, $expected))->toBe([])
        ->and(DatabaseProfile::compareForeignKeys($expected, ['a.b_id->b.id' => 'RESTRICT/RESTRICT']))->toBe(['foreign key missing: a.c_id->c.id (SET NULL/RESTRICT)'])
        ->and(DatabaseProfile::compareForeignKeys($expected, $expected + ['a.d_id->d.id' => 'CASCADE/RESTRICT']))->toBe(['unexpected foreign key: a.d_id->d.id (CASCADE/RESTRICT)'])
        ->and(DatabaseProfile::compareForeignKeys($expected, ['a.b_id->b.id' => 'CASCADE/RESTRICT', 'a.c_id->c.id' => 'SET NULL/RESTRICT']))
        ->toBe(['foreign key rules changed: a.b_id->b.id is CASCADE/RESTRICT, expected RESTRICT/RESTRICT']);
});

it('cannot pass by being unable to look', function () {
    $broken = Mockery::mock(Connection::class);
    $broken->shouldReceive('selectOne')->andThrow(new RuntimeException('connection refused to host db with password secret'));

    $violations = DatabaseProfile::safely($broken);

    expect($violations)->toBe(['the database could not be verified: RuntimeException'])
        ->and(implode(' ', $violations))->not->toContain('secret');
});

it('verifies from the command line: success, JSON, and failure with a non-zero exit', function () {
    $this->artisan('database:verify')->expectsOutputToContain('The database matches the supported profile')->assertExitCode(0);

    $this->artisan('database:verify', ['--json' => true])->expectsOutputToContain('"passed": true')->assertExitCode(0);

    dbProfileWithSession("SET SESSION time_zone = 'SYSTEM'", function () {
        $this->artisan('database:verify')->expectsOutputToContain("session time_zone is 'SYSTEM'")->assertExitCode(1);
    });
});

describe('production migration guard', function () {
    function dbProfileInEnvironment(string $environment, Closure $body): void
    {
        app()->detectEnvironment(fn () => $environment);

        try {
            $body();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    it('lets a migration start and end on a database in the profile', function () {
        dbProfileInEnvironment('production', function () {
            event(new MigrationsStarted('up'));
            event(new MigrationsEnded('up'));
        });

        expect(true)->toBeTrue();
    });

    it('refuses to start or end a migration on a session outside the profile', function (string $event) {
        dbProfileWithSession("SET SESSION time_zone = 'SYSTEM'", function () use ($event) {
            dbProfileInEnvironment('production', function () use ($event) {
                expect(fn () => event(new $event('up')))->toThrow(DatabaseProfileViolation::class, "session time_zone is 'SYSTEM'");
            });
        });
    })->with([MigrationsStarted::class, MigrationsEnded::class]);

    it('refuses a database that already holds a table outside the profile', function () {
        try {
            DB::statement('create table wp3_profile_myisam (id int not null primary key) engine = MyISAM');

            dbProfileInEnvironment('production', function () {
                expect(fn () => event(new MigrationsStarted('up')))->toThrow(DatabaseProfileViolation::class, 'table wp3_profile_myisam uses engine MyISAM');
            });
        } finally {
            DB::statement('drop table if exists wp3_profile_myisam');
        }
    });

    it('does nothing outside production and for a rollback, so development and CI are unchanged', function () {
        dbProfileWithSession("SET SESSION time_zone = 'SYSTEM'", function () {
            event(new MigrationsStarted('up'));

            dbProfileInEnvironment('production', fn () => event(new MigrationsStarted('down')));

            dbProfileInEnvironment('local', fn () => event(new MigrationsEnded('up')));
        });

        expect(true)->toBeTrue();
    });
});

describe('UTC timestamps through the connection', function () {
    /** Epoch seconds of the UTC wall-clock string as the application sends it, and as the database reads it back. */
    function dbProfileRoundTrip(string $utc): array
    {
        DB::table('wp3_tz_probe')->insert(['id' => 1, 'ts' => Carbon::parse($utc, 'UTC')]);

        $stored = DB::selectOne('select ts, unix_timestamp(ts) as e from wp3_tz_probe where id = 1');
        DB::table('wp3_tz_probe')->delete();

        return [$stored->ts, (int) $stored->e - Carbon::parse($utc, 'UTC')->timestamp];
    }

    beforeEach(fn () => DB::statement('create temporary table wp3_tz_probe (id int primary key, ts timestamp null) engine = InnoDB'));

    afterEach(fn () => DB::statement('drop temporary table if exists wp3_tz_probe'));

    it('stores and reads every instant around the 2026 US daylight-saving transitions without shifting it', function (string $utc) {
        expect(dbProfileRoundTrip($utc))->toBe([$utc, 0]);
    })->with([
        'before the spring gap' => '2026-03-08 01:59:59',
        'start of the spring gap' => '2026-03-08 02:00:00',
        'inside the spring gap' => '2026-03-08 02:30:00',
        'after the spring gap' => '2026-03-08 03:00:00',
        'before the autumn overlap' => '2026-11-01 00:59:59',
        'inside the autumn overlap' => '2026-11-01 01:30:00',
        'after the autumn overlap' => '2026-11-01 02:00:00',
    ]);

    it('would have caught the host: in an Eastern-time session the same writes are rejected or shifted', function () {
        if (DB::selectOne("select convert_tz('2026-03-08 06:59:59', '+00:00', 'America/New_York') as c")->c === null) {
            $this->markTestSkipped('The server has no named time zone tables, so an Eastern-time session cannot be reproduced.');
        }

        dbProfileWithSession("SET SESSION time_zone = 'America/New_York'", function () {
            expect(fn () => dbProfileRoundTrip('2026-03-08 02:30:00'))->toThrow(QueryException::class);

            DB::table('wp3_tz_probe')->delete();

            expect(dbProfileRoundTrip('2026-11-01 01:30:00')[1])->not->toBe(0);
        });
    });
});
