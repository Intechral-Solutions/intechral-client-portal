<?php

use App\Jobs\RecordRuntimeObservation;
use App\Support\Permissions\PermissionCacheEpoch;
use App\Support\Runtime\RuntimeChallenges;
use App\Support\Runtime\RuntimeSnapshot;
use App\Support\Runtime\RuntimeVerification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A2): the cross-context runtime check. A challenge is issued by the CLI; the web (GET /up), a real database
 * queue worker run and a scheduler run each record what THEY observe about themselves; `runtime:preflight --verify` requires all four to
 * agree. docs/architecture/runtime-preflight.md.
 *
 * Isolation: everything runs in the test's own transaction on the testing database (RefreshDatabase). A "different process" is simulated by
 * changing configuration, the application base path or the key for the duration of one observation and restoring it; the real separate-process
 * behaviour (a real queue worker, a PHP web server, other release directories, other configuration caches) is in the host-parity smoke.
 */

/** A query exception whose PDO cause carries a SQLSTATE, a host and a password in its message, as a real one does. */
function rxcDbFailure(string $query, array $bindings, string $state = 'HY000'): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$state.'] cannot reach db-internal-7 as portal with password PWSECRET', 1205);
    $pdo->errorInfo = [$state, 1205, 'cannot reach db-internal-7 PWSECRET'];

    return new QueryException('mysql', $query, $bindings, $pdo);
}

function rxcProduction(array $override = []): void
{
    rtpUseProductionProfile($override);
    app()->detectEnvironment(fn () => 'production');
}

/** @return array{0: int, 1: array<string, mixed>} */
function rxcRun(array $arguments): array
{
    $code = Artisan::call('runtime:preflight', $arguments + ['--json' => true]);

    return [$code, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function rxcIssue(): string
{
    [$code, $report] = rxcRun(['--issue' => true]);

    expect($code)->toBe(0, 'issue failed: '.implode(' | ', $report['violations']));

    return $report['challenge']['id'];
}

/** @return array{0: int, 1: array<string, mixed>} */
function rxcVerify(?string $id = null): array
{
    return rxcRun(['--verify' => true] + ($id === null ? [] : ['--challenge' => $id]));
}

function rxcWeb(): void
{
    test()->get('/up')->assertOk();
}

/** The real database queue worker code path, in this process: it pops the queued job from the jobs table and runs it. */
function rxcWorker(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

function rxcScheduler(): void
{
    Artisan::call('schedule:run');
}

/** Everything a healthy deployment does for one challenge. */
function rxcAnswerAll(): string
{
    $id = rxcIssue();
    rxcWeb();
    rxcWorker();
    rxcScheduler();

    return $id;
}

/** Make one observation as another "process": other configuration, base path or key for the duration of the call only. */
function rxcObserveAs(string $context, array $config = [], ?string $basePath = null): bool
{
    $keep = [];
    foreach ($config as $name => $value) {
        $keep[$name] = config($name);
    }
    $originalBase = app()->basePath();

    config($config);
    if ($basePath !== null) {
        app()->setBasePath($basePath);
    }

    try {
        return RuntimeChallenges::observe($context);
    } finally {
        app()->setBasePath($originalBase);
        config($keep);
    }
}

function rxcStored(string $id, string $context): ?array
{
    $raw = RuntimeChallenges::observationRow($id, $context);

    return $raw === null ? null : json_decode($raw, true);
}

function rxcPut(string $id, string $context, string $value): void
{
    DB::table('cache')->updateOrInsert(['key' => RuntimeChallenges::observationKey($id, $context)], ['value' => $value, 'expiration' => time() + 7200]);
}

/** The queries of the log that touch the cross-context namespace (by its bindings: the application's own cache traffic is not ours). */
function rxcNamespaceQueries(): array
{
    return collect(DB::getQueryLog())->filter(fn ($q) => collect($q['bindings'])->contains(fn ($b) => is_string($b) && str_starts_with($b, 'portal:runtime-context:')))->pluck('query')->values()->all();
}

/**
 * Run $body and, the moment the FIRST read of $context's observation or conflict marker for challenge $id has returned, issue a new challenge
 * (which deletes challenge $id's rows). That is an interleaving, not a sequence: the issuance happens between two statements of ONE verification.
 * `DB::listen` fires after a statement has executed and its result has been fetched, before the caller sees it. Returns how often it fired (0 means
 * the interleaving never happened and the test proved nothing).
 */
function rxcIssueBetweenReadsOf(string $id, string $context, Closure $body): int
{
    $fired = 0;
    $armed = true;

    DB::listen(function ($query) use (&$fired, &$armed, $id, $context) {
        if (! $armed) {
            return;
        }

        foreach ($query->bindings as $binding) {
            if (is_string($binding) && preg_match('/^portal:runtime-context:(obs|conflict):'.$id.':'.$context.'$/', $binding) === 1) {
                $armed = false;   // before issuing: the issuance runs queries too
                $fired++;
                RuntimeChallenges::issue();

                return;
            }
        }
    });

    try {
        $body();
    } finally {
        $armed = false;
    }

    return $fired;
}

function rxcNamespaceRows(): int
{
    return DB::table('cache')->where('key', 'like', 'portal:runtime-context:%')->count();
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
    app('cache')->forgetDriver();
    app()->forgetInstance('config_loaded_from_cache');
    Carbon::setTestNow();
});

describe('a healthy deployment', function () {
    it('passes when the CLI, the web, a real queue worker run and the scheduler all answer, in human and JSON form', function () {
        rxcProduction();
        $id = rxcAnswerAll();

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(0)->and($report['passed'])->toBeTrue()->and($report['violations'])->toBe([])
            ->and($report['mode'])->toBe('verify')->and($report['challenge_status'])->toBe('active')->and($report['challenge']['id'])->toBe($id)
            ->and(array_keys($report['contexts']))->toBe(['cli', 'web', 'queue', 'scheduler'])
            ->and(collect($report['contexts'])->pluck('status')->unique()->all())->toBe(['ok']);

        expect(Artisan::call('runtime:preflight', ['--verify' => true, '--challenge' => $id]))->toBe(0)
            ->and(Artisan::output())->toContain('Every context answered the challenge and agrees')->toContain($id);
    });

    it('verifies without naming the challenge, against the current one', function () {
        rxcProduction();
        rxcAnswerAll();

        expect(rxcVerify()[0])->toBe(0);
    });

    it('passes when only PHP version and SAPI differ', function () {
        rxcProduction();
        $id = rxcAnswerAll();

        foreach (['web' => ['8.3.0', 'fpm-fcgi'], 'scheduler' => ['8.5.1', 'cli-server']] as $context => [$php, $sapi]) {
            $stored = rxcStored($id, $context);
            $stored['info'] = ['php' => $php, 'sapi' => $sapi];
            rxcPut($id, $context, json_encode($stored));
        }

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(0)->and($report['contexts']['web']['sapi'])->toBe('fpm-fcgi')->and($report['contexts']['scheduler']['php'])->toBe('8.5.1');
    });

    it('answers the same however often it is verified, and leaves the cache and jobs tables as they were (net of the temporary probe rows)', function () {
        rxcProduction();
        Carbon::setTestNow(now());   // `seconds_remaining` is the one value that moves with the clock
        $id = rxcAnswerAll();
        $rows = DB::table('cache')->count();
        $jobs = DB::table('jobs')->count();

        $first = rxcVerify($id);
        $second = rxcVerify($id);

        expect($second)->toBe($first)->and(DB::table('cache')->count())->toBe($rows)->and(DB::table('jobs')->count())->toBe($jobs);
    });
});

describe('issuing a challenge', function () {
    it('runs the local preflight first and issues nothing when it fails', function () {
        rxcProduction(['mail.default' => 'log']);

        [$code, $report] = rxcRun(['--issue' => true]);

        expect($code)->toBe(1)->and($report['mode'])->toBe('issue')->and($report['challenge'])->toBeNull()
            ->and(implode(' ', $report['violations']))->toContain("mail.default is 'log'")
            ->and(rxcNamespaceRows())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('stores one challenge for 30 minutes, the CLI observation, and exactly one queue job, and prints how to continue', function () {
        rxcProduction();
        Carbon::setTestNow('2026-10-20 10:00:00');

        expect(Artisan::call('runtime:preflight', ['--issue' => true]))->toBe(0);
        $human = Artisan::output();
        $current = RuntimeChallenges::current();
        $job = DB::table('jobs')->first();

        expect($current['state'])->toBe('active')
            ->and($current['challenge']['expires_at'] - $current['challenge']['issued_at'])->toBe(1800)
            ->and($current['challenge']['id'])->toMatch('/^[0-9a-f]{32}$/')->and($current['challenge']['nonce'])->toMatch('/^[0-9a-f]{64}$/')
            ->and(RuntimeChallenges::observationRow($current['challenge']['id'], 'cli'))->not->toBeNull()
            ->and(DB::table('jobs')->count())->toBe(1)->and($job->payload)->toContain('RecordRuntimeObservation')->toContain($current['challenge']['id'])
            ->and($job->payload)->not->toContain($current['challenge']['nonce'])
            ->and($human)->toContain($current['challenge']['id'])->toContain('2026-10-20 10:30:00 UTC')->toContain('GET /up')->not->toContain($current['challenge']['nonce']);
    });

    it('gives every row a bounded lifetime: the challenge window plus one hour', function () {
        rxcProduction();
        $id = rxcIssue();
        $expires = RuntimeChallenges::current()['challenge']['expires_at'];

        $rows = DB::table('cache')->where('key', 'like', 'portal:runtime-context:%')->get();

        expect($rows)->toHaveCount(2)->and($rows->pluck('expiration')->unique()->all())->toBe([$expires + 3600])
            ->and($rows->pluck('key')->sort()->values()->all())->toBe(['portal:runtime-context:challenge', 'portal:runtime-context:obs:'.$id.':cli']);
    });

    it('replaces an earlier challenge: its rows are removed and none of its observations counts', function () {
        rxcProduction();
        $first = rxcIssue();
        rxcWeb();
        $second = rxcIssue();

        expect($second)->not->toBe($first)->and(RuntimeChallenges::observationRow($first, 'cli'))->toBeNull()->and(RuntimeChallenges::observationRow($first, 'web'))->toBeNull();

        [$code, $report] = rxcVerify();

        expect($code)->toBe(1)->and($report['challenge']['id'])->toBe($second)->and($report['contexts']['web']['status'])->toBe('missing')
            ->and($report['contexts']['cli']['status'])->toBe('ok');
    });

    it('removes only its own namespace, and never touches the permission epoch row or other cache rows', function () {
        rxcProduction();
        $epoch = app(PermissionCacheEpoch::class);
        $token = $epoch->current();
        DB::table('cache')->insert([['key' => 'portal_prod_something', 'value' => 's:1:"x";', 'expiration' => time() + 100], ['key' => 'portal:runtime-preflight:other', 'value' => 'y', 'expiration' => time() + 100]]);
        $others = DB::table('cache')->where('key', 'not like', 'portal:runtime-context:%')->orderBy('key')->get(['key', 'value', 'expiration']);

        $id = rxcAnswerAll();
        rxcVerify($id);
        rxcIssue();

        expect(DB::table('cache')->where('key', 'not like', 'portal:runtime-context:%')->orderBy('key')->get(['key', 'value', 'expiration']))->toEqual($others)
            ->and($epoch->current())->toBe($token);
    });

    it('does not report success when the queue job cannot be queued, and leaves nothing behind', function () {
        rxcProduction();
        Event::listen(JobQueueing::class, fn () => throw new RuntimeException('cannot reach db-internal-7 as portal with password PWSECRET'));

        [$code, $report] = rxcRun(['--issue' => true]);

        expect($code)->toBe(1)->and($report['passed'])->toBeFalse()->and($report['challenge'])->toBeNull()
            ->and($report['violations'])->toContain('the challenge could not be created: RuntimeException')
            ->and(json_encode($report))->not->toContain('PWSECRET')->not->toContain('db-internal-7')
            ->and(RuntimeChallenges::current()['state'])->toBe('none')->and(rxcNamespaceRows())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('does not report success when the challenge row cannot be written, and leaves nothing behind', function () {
        rxcProduction();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed) {
            if ($armed && str_starts_with($query, 'insert') && in_array('portal:runtime-context:challenge', $bindings, true)) {
                throw rxcDbFailure($query, $bindings);
            }
        });

        try {
            [$code, $report] = rxcRun(['--issue' => true]);
        } finally {
            $armed = false;
        }

        expect($code)->toBe(1)->and($report['violations'])->toContain('the challenge could not be created: QueryException (SQLSTATE HY000)')
            ->and(json_encode($report))->not->toContain('PWSECRET')->not->toContain('db-internal-7')
            ->and(rxcNamespaceRows())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('keeps the default run exactly as it was: no mode, challenge or contexts in its JSON, and no cross-context rows', function () {
        rxcProduction();

        [$code, $report] = rxcRun([]);

        expect($code)->toBe(0)->and(array_keys($report))->toBe(['passed', 'violations', 'probes_ran', 'diagnostics'])->and(rxcNamespaceRows())->toBe(0);
    });

    it('refuses contradictory options', function () {
        rxcProduction();

        expect(Artisan::call('runtime:preflight', ['--issue' => true, '--verify' => true]))->toBe(1)
            ->and(Artisan::call('runtime:preflight', ['--challenge' => str_repeat('a', 32)]))->toBe(1)->and(rxcNamespaceRows())->toBe(0);
    });
});

describe('a context that did not answer', function () {
    it('fails when it is missing, naming the context and how to trigger it', function (string $missing, string $hint) {
        rxcProduction();
        $id = rxcIssue();
        $contexts = ['web' => 'rxcWeb', 'queue' => 'rxcWorker', 'scheduler' => 'rxcScheduler'];

        foreach ($contexts as $context => $trigger) {
            if ($context !== $missing) {
                $trigger();
            }
        }

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts'][$missing]['status'])->toBe('missing')
            ->and(collect($report['contexts'])->except($missing)->pluck('status')->unique()->all())->toBe(['ok'])
            ->and(collect($report['violations'])->first(fn ($v) => str_starts_with($v, "{$missing}:")))->toContain('no observation was recorded')->toContain($hint);
    })->with([['web', 'GET /up'], ['queue', 'queue:work'], ['scheduler', 'schedule:run']]);

    it('fails when the CLI observation is gone', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        DB::table('cache')->where('key', RuntimeChallenges::observationKey($id, 'cli'))->delete();

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts']['cli']['status'])->toBe('missing');
    });

    it('fails closed when recording the observation fails, and the health response is unchanged', function () {
        rxcProduction();
        $id = rxcIssue();
        $baseline = test()->get('/up');
        DB::table('cache')->where('key', RuntimeChallenges::observationKey($id, 'web'))->delete();

        $armed = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed) {
            if ($armed && str_contains($query, 'insert') && in_array(true, array_map(fn ($b) => is_string($b) && str_contains($b, ':obs:'), $bindings), true)) {
                throw rxcDbFailure($query, $bindings);
            }
        });

        try {
            $response = test()->get('/up');
        } finally {
            $armed = false;
        }

        $response->assertOk();
        rxcWorker();
        rxcScheduler();
        [$code, $report] = rxcVerify($id);

        expect($response->getContent())->toBe($baseline->getContent())->and($code)->toBe(1)->and($report['contexts']['web']['status'])->toBe('missing');
    });
});

describe('a challenge that is not valid', function () {
    it('fails when it has expired, and no context records anything any more', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        Carbon::setTestNow(now()->addSeconds(1801));

        [$code, $report] = rxcVerify($id);
        $rows = rxcNamespaceRows();

        expect($code)->toBe(1)->and($report['challenge_status'])->toBe('expired')->and($report['contexts'])->toBe([])
            ->and(implode(' ', $report['violations']))->toContain('expired')
            ->and(RuntimeChallenges::observe('web'))->toBeFalse()->and(rxcNamespaceRows())->toBe($rows);
    });

    it('still holds at the last second of its window', function () {
        rxcProduction();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $id = rxcAnswerAll();
        Carbon::setTestNow('2026-10-20 10:30:00');

        expect(rxcVerify($id)[0])->toBe(0);
    });

    it('fails when it was superseded, by id and by the observations of the older challenge', function () {
        rxcProduction();
        $old = rxcAnswerAll();
        $new = rxcIssue();

        [$code, $report] = rxcVerify($old);
        expect($code)->toBe(1)->and($report['challenge_status'])->toBe('superseded')->and($report['challenge']['id'])->toBe($new);

        [$code, $report] = rxcVerify($new);
        expect($code)->toBe(1)->and(collect($report['contexts'])->pluck('status', null)->all())->toBe(['ok', 'missing', 'missing', 'missing']);
    });

    it('fails for a challenge id that is not one, or that does not exist', function () {
        rxcProduction();

        expect(rxcVerify('not-a-challenge')[1]['challenge_status'])->toBe('invalid')
            ->and(rxcVerify(str_repeat('a', 32))[1]['challenge_status'])->toBe('none');

        rxcIssue();

        expect(rxcVerify(str_repeat('a', 32))[1]['challenge_status'])->toBe('superseded');
    });

    it('fails when nothing was ever issued', function () {
        rxcProduction();

        [$code, $report] = rxcVerify();

        expect($code)->toBe(1)->and($report['challenge_status'])->toBe('none')->and($report['violations'])->toContain('no challenge is stored; issue one with `php artisan runtime:preflight --issue`');
    });

    it('does not trust a malformed challenge row, and no context records against it', function (string $value) {
        rxcProduction();
        DB::table('cache')->insert(['key' => 'portal:runtime-context:challenge', 'value' => $value, 'expiration' => time() + 7200]);

        [$code, $report] = rxcVerify();

        expect($code)->toBe(1)->and($report['challenge_status'])->toBe('invalid')
            ->and(RuntimeChallenges::observe('web'))->toBeFalse()->and(rxcNamespaceRows())->toBe(1);
    })->with([
        'not json' => ['garbage'],
        'a list' => ['[1,2]'],
        'id not hex' => [fn () => json_encode(['id' => 'zzzz', 'nonce' => str_repeat('a', 64), 'issued_at' => 1, 'expires_at' => 2])],
        'nonce too short' => [json_encode(['id' => 'a'.str_repeat('b', 31), 'nonce' => 'ab', 'issued_at' => 1, 'expires_at' => 2])],
        'window reversed' => [json_encode(['id' => str_repeat('a', 32), 'nonce' => str_repeat('b', 64), 'issued_at' => 5, 'expires_at' => 5])],
        'times as text' => [json_encode(['id' => str_repeat('a', 32), 'nonce' => str_repeat('b', 64), 'issued_at' => '1', 'expires_at' => '2'])],
        'too large' => [str_repeat('x', 9000)],
    ]);
});

describe('a context that disagrees', function () {
    it('fails and names the configuration value', function (string $context, array $override, array $names) {
        rxcProduction();
        $id = rxcIssue();
        foreach (array_diff(['web', 'queue', 'scheduler'], [$context]) as $other) {
            rxcObserveAs($other);
        }
        rxcObserveAs($context, $override);

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts'][$context]['status'])->toBe('mismatch')->and($report['contexts'][$context]['differences'])->toBe($names)
            ->and(collect($report['contexts'])->except($context)->pluck('status')->unique()->all())->not->toContain('mismatch');
    })->with([
        'CACHE_PREFIX on the queue' => ['queue', ['cache.prefix' => 'other_'], ['cache.prefix', 'permission.epoch_key']],
        'CACHE_PREFIX on the web' => ['web', ['cache.prefix' => 'other_'], ['cache.prefix', 'permission.epoch_key']],
        'APP_URL on the scheduler' => ['scheduler', ['app.url' => 'https://staging.provider.invalid'], ['app.url']],
        'APP_URL on the queue' => ['queue', ['app.url' => 'http://localhost'], ['app.url']],
        'lock table' => ['scheduler', ['cache.stores.database.lock_table' => 'locks'], ['cache.lock_table']],
        'permission cache key' => ['queue', ['permission.cache.key' => 'other.permission.cache'], ['permission.cache.key']],
        'permission cache store' => ['web', ['permission.cache.store' => 'redis'], ['permission.cache.store']],
        'cache store' => ['scheduler', ['cache.default' => 'redis'], ['cache.default']],
        'queue connection' => ['web', ['queue.default' => 'sync'], ['queue.default']],
        'environment' => ['queue', ['app.env' => 'staging'], ['app.env']],
    ]);

    it('does not see a context whose cache table is another one: it cannot even find the challenge, so it is missing', function () {
        rxcProduction();
        $id = rxcIssue();
        rxcWeb();
        rxcWorker();

        config(['cache.stores.database.table' => 'cache2']);
        RuntimeChallenges::observeQuietly('scheduler');
        config(['cache.stores.database.table' => 'cache']);

        expect(rxcVerify($id)[1]['contexts']['scheduler']['status'])->toBe('missing');
    });

    it('fails on a recorded value the in-process simulation cannot produce, because a real context with it could not find the challenge at all', function (string $name, string $value) {
        rxcProduction();
        $id = rxcAnswerAll();
        $stored = rxcStored($id, 'web');
        $stored['config'][$name] = $value;
        rxcPut($id, 'web', json_encode($stored));

        expect(rxcVerify($id)[1]['contexts']['web']['differences'])->toBe([$name]);
    })->with([['cache.table', 'cache2'], ['database.default', 'mariadb']]);

    it('fails on a different physical release even though every configuration value matches', function () {
        rxcProduction();
        $id = rxcIssue();
        $other = sys_get_temp_dir().'/rxc-release-'.bin2hex(random_bytes(4));
        mkdir($other);

        try {
            rxcWeb();
            rxcWorker();
            rxcObserveAs('scheduler', basePath: $other);
        } finally {
            rmdir($other);
        }

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts']['scheduler']['differences'])->toBe(['release'])
            ->and(collect($report['violations'])->first(fn ($v) => str_starts_with($v, 'scheduler:')))->toContain('a different physical release directory')
            ->and($report['contexts']['web']['status'])->toBe('ok');
    });

    it('passes when the other context runs from a symlink to the same release', function () {
        rxcProduction();
        $id = rxcIssue();
        $link = sys_get_temp_dir().'/rxc-current-'.bin2hex(random_bytes(4));
        symlink(base_path(), $link);

        try {
            rxcWeb();
            rxcWorker();
            expect(rxcObserveAs('scheduler', basePath: $link))->toBeTrue();
        } finally {
            unlink($link);
        }

        expect(rxcVerify($id)[0])->toBe(0);
    });

    it('fails on a different APP_KEY, and passes with a different key spelled the same', function () {
        rxcProduction();
        $id = rxcIssue();
        rxcWeb();
        rxcScheduler();
        rxcObserveAs('queue', ['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts']['queue']['differences'])->toBe(['app_key'])
            ->and(collect($report['violations'])->first(fn ($v) => str_starts_with($v, 'queue:')))->toContain('a different APP_KEY');
    });

    it('fails when the verifier itself has another key, release or configuration than the contexts', function () {
        rxcProduction();
        $id = rxcAnswerAll();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('y', 32))]);
        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and(collect($report['contexts'])->pluck('differences')->flatten()->unique()->values()->all())->toBe(['app_key']);
    });

    it('fails when the verifier has no usable key, rather than comparing nothing', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        config(['app.key' => '']);

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and(collect($report['contexts'])->pluck('status')->unique()->all())->toBe(['mismatch']);
    });
});

describe('observations that are not trusted', function () {
    it('rejects an observation from an earlier challenge copied under the current one, and one with its challenge rewritten', function () {
        rxcProduction();
        // Frozen on purpose: with the clock moving, the old observation falls before the new challenge's window and is reported `stale` instead
        // of `mismatch` whenever the two challenges are issued in different seconds. Both fail; this test pins the proof/release path.
        Carbon::setTestNow(now());
        $old = rxcAnswerAll();
        $oldWeb = RuntimeChallenges::observationRow($old, 'web');
        $new = rxcIssue();
        rxcWorker();
        rxcScheduler();

        // 1) verbatim replay: it names the old challenge.
        rxcPut($new, 'web', $oldWeb);
        expect(rxcVerify($new)[1]['contexts']['web']['status'])->toBe('stale');

        // 2) the challenge id rewritten to the new one: the proof (and release) are bound to the old nonce, so it cannot match.
        $rewritten = json_decode($oldWeb, true);
        $rewritten['challenge'] = $new;
        rxcPut($new, 'web', json_encode($rewritten));
        $web = rxcVerify($new)[1]['contexts']['web'];

        expect($web['status'])->toBe('mismatch')->and($web['differences'])->toContain('app_key')->toContain('release');

        // 3) the same rewritten row once the old observation predates the new challenge's window: stale, and still a failure.
        Carbon::setTestNow(now()->addSeconds(10));
        $newer = rxcIssue();
        rxcWorker();
        rxcScheduler();
        $rewritten['challenge'] = $newer;
        rxcPut($newer, 'web', json_encode($rewritten));

        [$code, $report] = rxcVerify($newer);

        expect($code)->toBe(1)->and($report['contexts']['web']['status'])->toBe('stale');
    });

    it('rejects an observation made outside the challenge window', function (int $at) {
        rxcProduction();
        $id = rxcAnswerAll();
        $stored = rxcStored($id, 'web');
        $stored['observed_at'] = $at;
        rxcPut($id, 'web', json_encode($stored));

        expect(rxcVerify($id)[1]['contexts']['web']['status'])->toBe('stale');
    })->with([[1], [4102444800]]);

    it('rejects a tampered or foreign row as malformed', function (Closure $tamper) {
        rxcProduction();
        $id = rxcAnswerAll();
        $stored = rxcStored($id, 'queue');
        rxcPut($id, 'queue', $tamper($stored));

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts']['queue']['status'])->toBe('malformed')
            ->and(collect($report['violations'])->first(fn ($v) => str_starts_with($v, 'queue:')))->toContain('malformed');
    })->with([
        'not json' => [fn ($s) => 'not json at all'],
        'truncated' => [fn ($s) => substr(json_encode($s), 0, 40)],
        'a string' => [fn ($s) => json_encode('x')],
        'another context inside' => [function ($s) {
            $s['context'] = 'web';

            return json_encode($s);
        }],
        'wrong version' => [function ($s) {
            $s['v'] = 9;

            return json_encode($s);
        }],
        'a config value removed' => [function ($s) {
            unset($s['config']['app.url']);

            return json_encode($s);
        }],
        'a config value an object' => [function ($s) {
            $s['config']['app.url'] = ['x' => 1];

            return json_encode($s);
        }],
        'proof not hex' => [function ($s) {
            $s['proof'] = 'zz';

            return json_encode($s);
        }],
        'oversized' => [function ($s) {
            $s['info']['php'] = str_repeat('x', 9000);

            return json_encode($s);
        }],
    ]);

    it('fails on a flipped proof bit, or a flipped release, as a mismatch rather than trusting it', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        $stored = rxcStored($id, 'web');
        $stored['proof'] = str_repeat('0', 64);
        $stored['release'] = str_repeat('0', 32);
        rxcPut($id, 'web', json_encode($stored));

        expect(rxcVerify($id)[1]['contexts']['web']['differences'])->toBe(['release', 'app_key']);
    });
});

describe('repeated and concurrent observations', function () {
    it('records a context once: an identical repeat changes nothing and leaves no conflict', function () {
        rxcProduction();
        $id = rxcIssue();

        rxcWeb();
        $first = RuntimeChallenges::observationRow($id, 'web');
        rxcWeb();
        rxcWeb();

        expect(RuntimeChallenges::observationRow($id, 'web'))->toBe($first)->and(RuntimeChallenges::hasConflict($id, 'web'))->toBeFalse()
            ->and(DB::table('cache')->where('key', RuntimeChallenges::observationKey($id, 'web'))->count())->toBe(1);
    });

    it('treats a second job run for the same challenge as a no-op', function () {
        rxcProduction();
        $id = rxcIssue();

        $job = new RecordRuntimeObservation($id);
        $job->handle();
        $first = RuntimeChallenges::observationRow($id, 'queue');
        $job->handle();
        $job->handle();

        expect(RuntimeChallenges::observationRow($id, 'queue'))->toBe($first)->and(RuntimeChallenges::hasConflict($id, 'queue'))->toBeFalse();
    });

    it('fails the context whichever of two differing results arrived first', function (bool $goodFirst) {
        rxcProduction();
        $id = rxcIssue();
        rxcWorker();
        rxcScheduler();
        rxcWeb();
        rxcObserveAs('cli');

        // A second web worker (another pool, another release or another configuration) answers differently.
        $calls = $goodFirst ? [[], ['cache.prefix' => 'other_']] : [['cache.prefix' => 'other_'], []];
        DB::table('cache')->where('key', RuntimeChallenges::observationKey($id, 'web'))->delete();
        foreach ($calls as $config) {
            rxcObserveAs('web', $config);
        }

        [$code, $report] = rxcVerify($id);

        expect($code)->toBe(1)->and($report['contexts']['web']['status'])->toBe('conflict')->and(RuntimeChallenges::hasConflict($id, 'web'))->toBeTrue()
            ->and(collect($report['violations'])->first(fn ($v) => str_starts_with($v, 'web:')))->toContain('more than once with differing results');
    })->with([[true], [false]]);

    it('fails the context when the stored row is not even readable as the same result', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        rxcPut($id, 'web', 'corrupted');

        rxcObserveAs('web');

        expect(RuntimeChallenges::hasConflict($id, 'web'))->toBeTrue()->and(rxcVerify($id)[1]['contexts']['web']['status'])->toBe('malformed');
    });

    it('cannot pass a conflicted context when a new challenge is issued between the two reads of its marker and its observation (review L1)', function () {
        rxcProduction();
        $a = rxcAnswerAll();

        // The scheduler (the LAST context verified) answered twice with differing results: observation and conflict marker both exist.
        rxcObserveAs('scheduler', ['cache.prefix' => 'other_']);
        expect(RuntimeChallenges::hasConflict($a, 'scheduler'))->toBeTrue();

        // Control, no interleaving: it fails on the conflict, and cli, web and queue are fine, so only the scheduler can fail it.
        $control = RuntimeVerification::run($a);
        expect($control['contexts']['scheduler']['status'])->toBe('conflict')
            ->and(collect($control['contexts'])->except('scheduler')->pluck('status')->unique()->all())->toBe(['ok'])
            ->and($control['violations'])->not->toBe([]);

        // The interleaving: challenge B is issued right after the first of the two scheduler reads of challenge A.
        $report = null;
        $fired = rxcIssueBetweenReadsOf($a, 'scheduler', function () use ($a, &$report) {
            $report = RuntimeVerification::run($a);
        });

        $b = RuntimeChallenges::current()['challenge']['id'];

        expect($fired)->toBe(1)->and($b)->not->toBe($a)
            ->and(RuntimeChallenges::observationRow($a, 'scheduler'))->toBeNull()->and(RuntimeChallenges::hasConflict($a, 'scheduler'))->toBeFalse()
            ->and(collect($report['contexts'])->except('scheduler')->pluck('status')->unique()->all())->toBe(['ok'])
            ->and($report['contexts']['scheduler']['status'])->not->toBe('ok')
            ->and($report['violations'])->not->toBe([]);
    });

    it('lets two issuances race to one winner: the later one, and the earlier one is superseded', function () {
        rxcProduction();
        $a = rxcIssue();
        $b = rxcIssue();

        expect(RuntimeChallenges::current()['challenge']['id'])->toBe($b)->and(rxcVerify($a)[1]['challenge_status'])->toBe('superseded')
            ->and(DB::table('cache')->where('key', 'portal:runtime-context:challenge')->count())->toBe(1);
    });
});

describe('the observers when there is nothing to do', function () {
    it('do no substantive work without an active challenge: /up, the job and the scheduler write nothing', function () {
        rxcProduction();
        $baseline = test()->get('/up');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = test()->get('/up');
        $webQueries = rxcNamespaceQueries();
        DB::flushQueryLog();
        rxcScheduler();
        (new RecordRuntimeObservation(str_repeat('a', 32)))->handle();
        $other = array_values(array_filter(rxcNamespaceQueries(), fn ($q) => ! str_starts_with($q, 'select')));

        expect($response->getContent())->toBe($baseline->getContent())->and($webQueries)->toHaveCount(1)->and($webQueries[0])->toStartWith('select')
            ->and($other)->toBe([])->and(rxcNamespaceRows())->toBe(0);
    });

    it('do not touch the cache table at all outside production', function () {
        rtpUseProductionProfile();
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->get('/up')->assertOk();
        rxcScheduler();

        expect(rxcNamespaceQueries())->toBe([]);
    });

    it('do not record outside production even when a challenge exists', function () {
        rxcProduction();
        $id = rxcIssue();
        app()->detectEnvironment(fn () => 'testing');

        test()->get('/up')->assertOk();
        rxcScheduler();

        expect(RuntimeChallenges::observationRow($id, 'web'))->toBeNull()->and(RuntimeChallenges::observationRow($id, 'scheduler'))->toBeNull();
    });

    it('record nothing for an expired or superseded challenge when the job runs late', function () {
        rxcProduction();
        $old = rxcIssue();
        $new = rxcIssue();

        (new RecordRuntimeObservation($old))->handle();
        expect(RuntimeChallenges::observationRow($old, 'queue'))->toBeNull()->and(RuntimeChallenges::observationRow($new, 'queue'))->toBeNull();

        Carbon::setTestNow(now()->addSeconds(1801));
        (new RecordRuntimeObservation($new))->handle();
        expect(RuntimeChallenges::observationRow($new, 'queue'))->toBeNull();
    });

    it('keep the existing schedule and add exactly one callback, with no ongoing cost outside production', function () {
        Artisan::call('list');   // the console kernel defines the schedule when the console is first used
        $events = collect(app(Schedule::class)->events());

        expect($events->map->command->filter()->implode(' '))->toContain('tickets:auto-close')
            ->and($events->pluck('description')->filter()->values()->all())->toBe(['runtime-context-observation'])
            ->and($events->count())->toBe(2)
            ->and($events->firstWhere('description', 'runtime-context-observation')->expression)->toBe('* * * * *');

        expect($events->firstWhere('description', 'runtime-context-observation')->filtersPass(app()))->toBeFalse();
        rxcProduction();
        expect($events->firstWhere('description', 'runtime-context-observation')->filtersPass(app()))->toBeTrue();
    });
});

describe('output safety', function () {
    it('prints no key, proof, nonce, release identity or path, on success or failure, in human and JSON form', function (bool $fail) {
        rxcProduction();
        $id = rxcAnswerAll();
        $challenge = RuntimeChallenges::current()['challenge'];
        $secrets = [
            $challenge['nonce'],
            RuntimeSnapshot::proof($id, $challenge['nonce'], 'web'),
            RuntimeSnapshot::release($challenge['nonce']),
            'KEYMARKER',
            base64_encode(str_pad('KEYMARKER', 32, 'x')),
            'MAILPWMARKER',
            base_path(),
        ];

        if ($fail) {
            rxcObserveAs('web', []);
            DB::table('cache')->where('key', RuntimeChallenges::observationKey($id, 'queue'))->delete();
            $stored = rxcStored($id, 'scheduler');
            $stored['config']['app.url'] = 'https://u:URLPWMARKER@evil.invalid';
            $stored['proof'] = str_repeat('f', 64);
            rxcPut($id, 'scheduler', json_encode($stored));
            $secrets[] = 'URLPWMARKER';
            $secrets[] = 'evil.invalid';
        }

        foreach ([['--json' => true], []] as $extra) {
            $code = Artisan::call('runtime:preflight', ['--verify' => true, '--challenge' => $id] + $extra);
            $output = Artisan::output();

            expect($code)->toBe($fail ? 1 : 0);
            foreach ($secrets as $secret) {
                expect($output)->not->toContain($secret);
            }
        }
    })->with([[false], [true]]);

    it('reports an unreadable challenge state as a class name only', function () {
        rxcProduction();
        $id = rxcAnswerAll();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed) {
            if ($armed && str_contains($query, 'portal') === false && in_array('portal:runtime-context:challenge', $bindings, true)) {
                throw rxcDbFailure($query, $bindings);
            }
        });

        try {
            [$code, $report] = rxcVerify($id);
        } finally {
            $armed = false;
        }

        expect($code)->toBe(1)->and($report['challenge_status'])->toBe('unreadable')
            ->and(implode(' ', $report['violations']))->toContain('the challenge state could not be read: QueryException (SQLSTATE HY000)')
            ->and(json_encode($report))->not->toContain('PWSECRET')->not->toContain('db-internal-7');
    });
});
