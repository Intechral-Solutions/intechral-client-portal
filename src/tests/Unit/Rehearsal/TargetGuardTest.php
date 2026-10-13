<?php

use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\TargetGuard;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A: the destructive-operation safeguards of the migration rehearsal harness. Needs the two
 * rehearsal databases: if they are missing, run `./dev rehearse:provision`.
 */

beforeEach(fn () => RehearsalSandbox::use());

/** A connection configuration that satisfies every static rule, for a cases to break one rule at a time. */
function goodConfig(?RehearsalTarget $target = null): array
{
    return array_merge(($target ?? RehearsalTarget::primary())->connectionConfig(), ['username' => 'portal']);
}

function violations(array $config, string $runtime = 'testing', string $configured = 'testing', string $applicationDatabase = 'intechral_client_portal_testing', ?RehearsalTarget $target = null): array
{
    return TargetGuard::configViolations($target ?? RehearsalTarget::primary(), $config, $runtime, $configured, $applicationDatabase);
}

it('accepts a correct target in the local and testing environments', function (string $environment) {
    expect(violations(goodConfig(), $environment, $environment))->toBe([])
        ->and(violations(goodConfig(RehearsalTarget::restore()), $environment, $environment, 'portal', RehearsalTarget::restore()))->toBe([]);
})->with(['local', 'testing']);

it('refuses every environment except local and testing, for the runtime and the configured value separately', function (string $runtime, string $configured) {
    expect(violations(goodConfig(), $runtime, $configured))->not->toBe([]);
})->with([
    'production' => ['production', 'production'],
    'staging' => ['staging', 'staging'],
    'unset' => ['', ''],
    '--env=local over a production config' => ['local', 'production'],
    'production runtime over a local config' => ['production', 'local'],
]);

it('refuses every connection that is not exactly the reserved rehearsal database', function (string $label, array $override) {
    expect(violations(array_merge(goodConfig(), $override)))->not->toBe([], $label);
})->with([
    'sqlite' => ['sqlite', ['driver' => 'sqlite']],
    'other host' => ['host', ['host' => 'localhost']],
    'remote host' => ['remote host', ['host' => 'db.example.com']],
    'other port' => ['port', ['port' => 3307]],
    'the development database' => ['portal', ['database' => 'portal']],
    'the Pest testing database' => ['testing', ['database' => 'intechral_client_portal_testing']],
    'a system schema' => ['mysql', ['database' => 'mysql']],
    'a lookalike' => ['lookalike', ['database' => 'intechral_client_portal_rehearsal2']],
    'the other role\'s database' => ['restore db for primary', ['database' => RehearsalTarget::DATABASES[RehearsalTarget::RESTORE]]],
    'root' => ['root', ['username' => 'root']],
    'no account' => ['empty', ['username' => '']],
    'a url' => ['url', ['url' => 'mysql://u:p@elsewhere/portal']],
    'a socket' => ['socket', ['unix_socket' => '/tmp/mysql.sock']],
    'read hosts' => ['read', ['read' => ['host' => ['elsewhere']]]],
    'write hosts' => ['write', ['write' => ['host' => ['elsewhere']]]],
]);

it('refuses the database the application itself is configured to use', function () {
    $violations = violations(goodConfig(), 'testing', 'testing', RehearsalTarget::DATABASES[RehearsalTarget::PRIMARY]);

    expect(implode(' ', $violations))->toContain('configured to use');
});

it('builds its connection from fixed names, ignoring the application database settings', function () {
    config([
        'database.connections.mysql.host' => 'evil.example.com',
        'database.connections.mysql.port' => 3999,
        'database.connections.mysql.database' => 'portal',
        'database.connections.mysql.url' => 'mysql://x:y@evil.example.com/portal',
        'database.connections.mysql.unix_socket' => '/tmp/evil.sock',
        'database.connections.mysql.read' => ['host' => ['evil.example.com']],
    ]);

    foreach ([RehearsalTarget::primary(), RehearsalTarget::restore()] as $target) {
        $config = $target->connectionConfig();

        expect($config['host'])->toBe('db')
            ->and($config['port'])->toBe(3306)
            ->and($config['database'])->toBe($target->database())
            ->and($config['url'])->toBeNull()
            ->and($config['unix_socket'])->toBe('')
            ->and($config['read'])->toBeNull();
    }
});

it('has no standing rehearsal connection in config/database.php that migrate --database could reach', function () {
    expect(array_keys(config('database.connections')))->each->not->toStartWith('rehearsal')
        ->and(file_get_contents(base_path('config/database.php')))->not->toContain('rehearsal');
});

it('refuses to run in a production runtime environment before any statement runs', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    expect(fn () => TargetGuard::assert(RehearsalTarget::primary()))->toThrow(UnsafeRehearsalTarget::class, 'rehearsal runs only in')
        ->and(fn () => RehearsalDatabase::reset(RehearsalTarget::primary()))->toThrow(UnsafeRehearsalTarget::class)
        ->and(fn () => RehearsalDatabase::clean(RehearsalTarget::primary()))->toThrow(UnsafeRehearsalTarget::class);
});

it('refuses when the configured environment is production even if --env says local', function () {
    $this->app->detectEnvironment(fn () => 'local');
    config(['app.env' => 'production']);

    expect(fn () => TargetGuard::assert(RehearsalTarget::primary()))->toThrow(UnsafeRehearsalTarget::class, 'configured app.env');
});

it('refuses an account holding any global privilege and accepts usage only', function () {
    expect(TargetGuard::grantViolations(["GRANT USAGE ON *.* TO `portal`@`%` IDENTIFIED BY PASSWORD '*X'", 'GRANT ALL PRIVILEGES ON `portal`.* TO `portal`@`%`']))->toBe([])
        ->and(TargetGuard::grantViolations(['GRANT ALL PRIVILEGES ON *.* TO `portal`@`%`']))->not->toBe([])
        ->and(TargetGuard::grantViolations(['GRANT SELECT, INSERT ON *.* TO `portal`@`%`']))->not->toBe([])
        ->and(TargetGuard::grantViolations(['GRANT PROCESS ON `*`.* TO `portal`@`%`']))->not->toBe([]);
});

it('treats a database as the harness\'s own only when empty or carrying this harness\'s marker for this role', function () {
    $primary = RehearsalTarget::primary();
    $mark = fn (string $signature, string $role) => ['signature' => $signature, 'role' => $role];

    expect(TargetGuard::ownershipViolations([], [], $primary))->toBe([])
        ->and(TargetGuard::ownershipViolations(['rehearsal_marker', 'users'], [$mark(RehearsalTarget::SIGNATURE, 'primary')], $primary))->toBe([])
        ->and(TargetGuard::ownershipViolations(['users', 'tickets'], [], $primary))->not->toBe([])
        ->and(TargetGuard::ownershipViolations(['rehearsal_marker'], [], $primary))->not->toBe([])
        ->and(TargetGuard::ownershipViolations(['rehearsal_marker'], [$mark(RehearsalTarget::SIGNATURE, 'restore')], $primary))->not->toBe([])
        ->and(TargetGuard::ownershipViolations(['rehearsal_marker'], [$mark('someone-elses-marker', 'primary')], $primary))->not->toBe([])
        ->and(TargetGuard::ownershipViolations(['rehearsal_marker'], [$mark(RehearsalTarget::SIGNATURE, 'primary'), $mark(RehearsalTarget::SIGNATURE, 'primary')], $primary))->not->toBe([]);
});

it('refuses to reset, clean or restore into a non-empty database that has no rehearsal marker, leaving it intact', function () {
    $target = RehearsalTarget::restore();
    $target->register();
    $connection = DB::connection($target->connectionName());
    $schema = Schema::connection($target->connectionName());

    $schema->dropAllTables();
    $connection->statement('create table unmarked_probe (id int primary key, note varchar(20))');
    $connection->table('unmarked_probe')->insert(['id' => 1, 'note' => 'precious']);

    try {
        expect(fn () => TargetGuard::assert($target))->toThrow(UnsafeRehearsalTarget::class, 'unidentified')
            ->and(fn () => RehearsalDatabase::reset($target))->toThrow(UnsafeRehearsalTarget::class)
            ->and(fn () => RehearsalDatabase::clean($target))->toThrow(UnsafeRehearsalTarget::class);

        expect(DB::connection($target->connectionName())->table('unmarked_probe')->value('note'))->toBe('precious');
    } finally {
        Schema::connection($target->connectionName())->dropAllTables();
    }
});

it('grants the application account rehearsal authority on exactly the two rehearsal databases and no global privilege', function () {
    $target = RehearsalTarget::primary();
    $target->register();
    $connection = DB::connection($target->connectionName());

    $grants = array_map(fn ($row) => (string) array_values((array) $row)[0], $connection->select('show grants for current_user()'));
    expect(TargetGuard::grantViolations($grants))->toBe([]);

    // Any grant that names a rehearsal database must name it with escaped underscores. An unescaped `_` is a
    // one-character wildcard, so it would silently cover other databases that merely look similar.
    $scoped = [];
    foreach ($grants as $grant) {
        if (preg_match('/ON `(.+?)`\.\*/', $grant, $match) && str_contains(str_replace('\\', '', $match[1]), 'rehearsal')) {
            $scoped[] = str_replace('\\\\', '\\', $match[1]);
        }
    }
    sort($scoped);

    expect($scoped)->toBe([
        'intechral\_client\_portal\_rehearsal',
        'intechral\_client\_portal\_rehearsal\_restore',
    ]);
});

it('cannot create a database that only an unescaped wildcard grant would have covered', function () {
    $target = RehearsalTarget::primary();
    $target->register();
    $connection = DB::connection($target->connectionName());
    $lookalike = 'intechralXclientXportalXrehearsal';

    try {
        expect(fn () => $connection->statement("create database `{$lookalike}`"))->toThrow(QueryException::class, 'Access denied');
    } finally {
        // Only reachable if the grant were wrong; remove what the failed expectation would have created.
        try {
            $connection->statement("drop database if exists `{$lookalike}`");
        } catch (Throwable) {
        }
    }
});
