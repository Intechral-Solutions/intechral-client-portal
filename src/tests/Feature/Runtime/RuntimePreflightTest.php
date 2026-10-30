<?php

use App\Support\Database\DatabaseProfile;
use App\Support\Permissions\PermissionCacheEpoch;
use App\Support\Runtime\RuntimeProbes;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A1): the live half of runtime:preflight (required tables, the cache round trip against the permission
 * epoch's own row path, the database cache lock) and the command around it. The configuration rules are tested one by one in
 * tests/Unit/Runtime/RuntimeProfileTest.php. docs/architecture/runtime-preflight.md.
 */

/** A database cache store whose locks are NOT exclusive (every request gets its own key), to prove the lock probe can fail. */
class RtpNonExclusiveLockStore extends DatabaseStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new DatabaseLock($this->lockConnection ?? $this->connection, $this->lockTable, $this->prefix.$name.'-'.bin2hex(random_bytes(4)), $seconds, $owner, $this->lockLottery, $this->defaultLockTimeoutInSeconds);
    }
}

function rtpResidue(): int
{
    return DB::table('cache')->where('key', 'like', '%runtime-preflight%')->count()
        + DB::table('cache_locks')->where('key', 'like', '%runtime-preflight%')->count();
}

afterEach(function () {
    app('cache')->forgetDriver();
    app()->forgetInstance('config_loaded_from_cache');
});

it('pins the epoch key layout the probe derives the shared prefix from', function () {
    rtpUseProductionProfile();

    expect(app(PermissionCacheEpoch::class)->key())->toBe('portal_prod_spatie.permission.epoch');
});

it('finds every required table, a working cache round trip and an exclusive cache lock, and leaves no row behind', function () {
    rtpUseProductionProfile();

    expect(RuntimeProbes::tables(DB::connection()))->toBe([])
        ->and(RuntimeProbes::cacheRoundTrip(DB::connection()))->toBe([])
        ->and(RuntimeProbes::lockExclusion(DB::connection()))->toBe([])
        ->and(RuntimeProbes::run(DB::connection()))->toBe([])
        ->and(rtpResidue())->toBe(0);
});

it('does not read, create or advance the permission epoch row', function () {
    rtpUseProductionProfile();
    $before = DB::table('cache')->where('key', 'portal_prod_spatie.permission.epoch')->count();

    RuntimeProbes::run(DB::connection());

    expect($before)->toBe(0)->and(DB::table('cache')->where('key', 'portal_prod_spatie.permission.epoch')->count())->toBe(0);
});

it('writes only disposable rows under the cache prefix', function () {
    rtpUseProductionProfile();
    $cacheBefore = DB::table('cache')->count();
    $locksBefore = DB::table('cache_locks')->count();

    RuntimeProbes::run(DB::connection());

    expect(DB::table('cache')->count())->toBe($cacheBefore)->and(DB::table('cache_locks')->count())->toBe($locksBefore);
});

describe('probe negative controls', function () {
    it('reports a required table that is missing', function (string $key, string $value, string $message) {
        rtpUseProductionProfile([$key => $value]);

        expect(RuntimeProbes::run(DB::connection()))->toContain($message);
    })->with([
        ['session.table', 'sessions_gone', 'the sessions table is missing (run `php artisan migrate`)'],
        ['queue.connections.database.table', 'jobs_gone', 'the jobs table is missing (run `php artisan migrate`)'],
        ['queue.failed.table', 'failed_gone', 'the failed jobs table is missing (run `php artisan migrate`)'],
        ['cache.stores.database.table', 'cache_gone', 'the cache table is missing (run `php artisan migrate`)'],
        ['cache.stores.database.lock_table', 'locks_gone', 'the cache locks table is missing (run `php artisan migrate`)'],
    ]);

    it('reports an application cache store that is not the database store', function () {
        rtpUseProductionProfile(['cache.default' => 'array']);

        expect(RuntimeProbes::cacheRoundTrip(DB::connection()))->toBe(['the application cache store is not the database store, so the permission epoch and the cache would not share rows'])
            ->and(RuntimeProbes::lockExclusion(DB::connection()))->toHaveCount(1);
    });

    it('reports a cache store on another connection from the permission epoch', function () {
        rtpUseProductionProfile(['cache.stores.database.connection' => 'mariadb']);

        $violations = RuntimeProbes::cacheRoundTrip(DB::connection());

        // The store writes on its own connection and commits, outside this test's transaction (and its REPEATABLE READ snapshot), so
        // ask that connection whether anything is left.
        expect($violations)->toContain('the application cache store uses a different database connection from the permission epoch')
            ->and(DB::connection('mariadb')->table('cache')->where('key', 'like', '%runtime-preflight%')->count())->toBe(0);
    });

    it('reports a cache store whose prefix is not the one the permission epoch uses', function () {
        rtpUseProductionProfile();
        app('cache')->store();                      // the store resolves under the production prefix
        config(['cache.prefix' => 'other_prefix_']); // and the epoch, which reads configuration per call, now uses another

        $violations = RuntimeProbes::cacheRoundTrip(DB::connection());

        expect($violations)->toContain('a value written through the application cache store is not at the row the permission epoch would read (different connection, table or prefix)')
            ->and(rtpResidue())->toBe(0);
    });

    it('reports a database cache lock that does not exclude a second holder', function () {
        rtpUseProductionProfile();
        app('cache')->extend('rtp-broken-lock', fn ($app) => new Repository(new RtpNonExclusiveLockStore(DB::connection(), 'cache', (string) config('cache.prefix'), 'cache_locks')));
        config(['cache.stores.rtp' => ['driver' => 'rtp-broken-lock'], 'cache.default' => 'rtp']);
        app('cache')->forgetDriver();

        $violations = RuntimeProbes::lockExclusion(DB::connection());

        expect($violations)->toContain('a held cache lock is not recorded in the cache_locks table')
            ->toContain('the database cache lock did not exclude a second holder')
            ->and(rtpResidue())->toBe(0);
    });

    it('reports a failing lock probe as its exception class and SQLSTATE, never its message', function () {
        rtpUseProductionProfile(['cache.stores.database.lock_table' => 'cache']);   // the cache table has no owner column

        $violations = RuntimeProbes::lockExclusion(DB::connection());

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toMatch('/^the cache lock probe failed: QueryException \(SQLSTATE \w+\)$/')
            ->and($violations[0])->not->toContain('insert')->not->toContain('portal');
    });

    it('does not run the live probes when a required table is missing', function () {
        rtpUseProductionProfile(['session.table' => 'sessions_gone']);

        expect(RuntimeProbes::run(DB::connection()))->toBe(['the sessions table is missing (run `php artisan migrate`)']);
    });
});

describe('runtime:preflight', function () {
    /** @return array{0: int, 1: string} */
    function rtpRun(array $arguments = []): array
    {
        $code = Artisan::call('runtime:preflight', $arguments);

        return [$code, Artisan::output()];
    }

    it('passes on the production profile, in human and JSON form, and names what it wrote', function () {
        rtpUseProductionProfile();

        [$code, $human] = rtpRun();
        expect($code)->toBe(0)->and($human)->toContain('The runtime profile is satisfied')->toContain('portal_prod_')->toContain('one cache row and one cache lock row');

        [$code, $json] = rtpRun(['--json' => true]);
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)->and($report['passed'])->toBeTrue()->and($report['violations'])->toBe([])->and($report['probes_ran'])->toBeTrue()
            ->and($report['diagnostics']['cache.prefix'])->toBe('portal_prod_')->and($report['diagnostics']['app.url'])->toBe('https://portal.provider.invalid')
            ->and(rtpResidue())->toBe(0);
    });

    it('fails with the configuration violation and a non-zero exit', function () {
        rtpUseProductionProfile(['mail.default' => 'log']);

        [$code, $output] = rtpRun();

        expect($code)->toBe(1)->and($output)->toContain("mail.default is 'log'");
    });

    it('fails without an operator new-ticket alert destination, naming the rule and never the value (EPIC-017 O3, RE-H13)', function (?string $value, string $expected) {
        rtpUseProductionProfile(['helpdesk.notify_address' => $value]);

        [$code, $output] = rtpRun();

        expect($code)->toBe(1)->and($output)->toContain($expected)->toContain('HELPDESK_NOTIFY_ADDRESS')->not->toContain('LEAKMARKER');
    })->with([
        'unset' => [null, 'helpdesk.notify_address is not set'],
        'malformed' => ['LEAKMARKER', 'helpdesk.notify_address is not a single valid email address'],
    ]);

    it('fails when configuration is not cached, and still reports the diagnostics', function () {
        rtpUseProductionProfile();
        app()->instance('config_loaded_from_cache', false);

        [$code, $json] = rtpRun(['--json' => true]);
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(1)->and($report['violations'])->toHaveCount(1)->and($report['violations'][0])->toContain('configuration is not cached')
            ->and($report['diagnostics']['configuration_cached'])->toBeFalse();
    });

    it('surfaces a database-profile violation', function () {
        rtpUseProductionProfile();
        DB::statement("SET SESSION time_zone = 'SYSTEM'");

        try {
            [$code, $output] = rtpRun();
        } finally {
            DB::statement("SET SESSION time_zone = '+00:00'");
        }

        expect($code)->toBe(1)->and($output)->toContain("session time_zone is 'SYSTEM'");
    });

    it('shares the pending-migration rule with database:verify', function () {
        rtpUseProductionProfile();

        expect(DatabaseProfile::pendingMigrations(DB::connection()))->toBe(0)
            ->and(DatabaseProfile::safelyWithMigrations(DB::connection()))->toBe([]);
    });

    it('never prints a secret, in any form, on success or failure', function (bool $fail) {
        // The success case carries no mail URL: since review F1 a non-empty MAIL_URL is itself a violation, so a passing run has none.
        rtpUseProductionProfile([
            'app.url' => 'https://admin:URLPWMARKER@portal.provider.invalid/base?token=QUERYMARKER',
            'database.connections.mysql.password' => 'DBPWMARKER',
        ] + ($fail ? ['mail.default' => 'log', 'app.debug' => true, 'mail.mailers.smtp.url' => 'smtp://URLUSERMARKER:URLPWMARKER@mail.provider.invalid'] : []));

        foreach ([[], ['--json' => true]] as $arguments) {
            [$code, $output] = rtpRun($arguments);

            expect($code)->toBe($fail ? 1 : 0);

            foreach (['URLPWMARKER', 'URLUSERMARKER', 'QUERYMARKER', 'DBPWMARKER', 'MAILPWMARKER', 'MAILUSERMARKER', 'KEYMARKER', base64_encode(str_pad('KEYMARKER', 32, 'x'))] as $secret) {
                expect($output)->not->toContain($secret);
            }
        }
    })->with([[false], [true]]);

    it('refuses a mail transport URL, in human and JSON form, without printing it (review F1)', function (string $url) {
        rtpUseProductionProfile(['mail.mailers.smtp.url' => $url]);

        foreach ([[], ['--json' => true]] as $arguments) {
            [$code, $output] = rtpRun($arguments);

            expect($code)->toBe(1)->and($output)->toContain('mail.mailers.smtp.url is set')
                ->not->toContain('URLPWMARKER')->not->toContain('URLUSERMARKER')->not->toContain('log://')->not->toContain('smtp://')->not->toContain('localhost');
        }
    })->with([
        'log://URLUSERMARKER:URLPWMARKER@localhost',
        'smtp://URLUSERMARKER:URLPWMARKER@mailpit:1025',
    ]);

    it('refuses a smtp mailer whose transport is not smtp (review F1)', function () {
        rtpUseProductionProfile(['mail.mailers.smtp.transport' => 'log']);

        [$code, $output] = rtpRun();

        expect($code)->toBe(1)->and($output)->toContain("mail.mailers.smtp.transport is 'log', expected 'smtp'");
    });

    it('refuses a session cookie that is not Secure or not HttpOnly, whatever its SameSite (review F2)', function (array $override, string $message) {
        rtpUseProductionProfile($override);

        [$code, $output] = rtpRun();

        expect($code)->toBe(1)->and($output)->toContain($message);
    })->with([
        'secure unset' => [['session.secure' => null], 'session.secure must be true'],
        'secure false' => [['session.secure' => false], 'session.secure must be true'],
        'secure unset, SameSite=None' => [['session.secure' => null, 'session.same_site' => 'none'], 'session.secure must be true'],
        'secure false, SameSite=None' => [['session.secure' => false, 'session.same_site' => 'none'], 'session.secure must be true'],
        'http_only false' => [['session.http_only' => false], 'session.http_only must be true'],
    ]);

    it('accepts SameSite=None on a Secure, HttpOnly session cookie (review F2)', function () {
        rtpUseProductionProfile(['session.same_site' => 'none']);

        [$code] = rtpRun();

        expect($code)->toBe(0);
    });

    it('reports an unreachable database as a class name only', function () {
        rtpUseProductionProfile();
        DB::partialMock()->shouldReceive('connection')->andThrow(new RuntimeException('cannot connect to host db-internal-7 as portal with password PWSECRET'));

        [$code, $output] = rtpRun(['--json' => true]);

        expect($code)->toBe(1)->and($output)->toContain('the database connection could not be opened: RuntimeException')
            ->not->toContain('PWSECRET')->not->toContain('db-internal-7');
    });
});
