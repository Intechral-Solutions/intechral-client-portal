<?php

use App\Jobs\RecordRuntimeObservation;
use App\Support\Runtime\RuntimeChallenges;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/runtime_profile_config.php';

/*
 * Release 0.9 (R0.9 WP3-A, slice A3): the startup guard through the whole console kernel, against the testing database. The pure listener tests are in
 * tests/Unit/Runtime/RuntimeStartupGuardTest.php.
 *
 * Laravel does not re-route Symfony's command events while it runs unit tests, so each test re-routes them itself (the same call the kernel makes for a
 * real process) BEFORE the first use of Artisan. Everything in-process runs in the test's transaction; the one test that needs a row a CHILD PROCESS can see
 * writes it on a second, auto-committing connection to the same testing database and removes it afterwards.
 */

beforeEach(fn () => app(Kernel::class)->rerouteSymfonyCommandEvents());

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
    app('cache')->forgetDriver();
    app()->forgetInstance('config_loaded_from_cache');
});

function sgiProduction(array $override = []): void
{
    rtpUseProductionProfile($override);
    app()->detectEnvironment(fn () => 'production');
}

/** The real kernel path: events, the exception handler and the exit status, exactly as `artisan` runs them. @return array{0: int, 1: string} */
function sgiHandle(array $arguments): array
{
    $output = new BufferedOutput;
    $code = app(Kernel::class)->handle(new ArgvInput(['artisan', ...$arguments]), $output);

    return [$code, $output->fetch()];
}

/** The console renderer hard-wraps long lines at the terminal width, even inside words; compare with every whitespace character removed. */
function sgiFlat(string $text): string
{
    return preg_replace('/\s+/', '', $text);
}

/** @return array{0: int, 1: array<string, mixed>} */
function sgiPreflight(array $arguments): array
{
    $code = Artisan::call('runtime:preflight', $arguments + ['--json' => true]);

    return [$code, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function sgiQueueJob(): int
{
    return app('queue')->connection('database')->push(new RecordRuntimeObservation(str_repeat('a', 32)));
}

describe('the queue worker through the kernel', function () {
    it('starts and consumes its job under a valid production profile', function () {
        sgiProduction();
        $id = sgiQueueJob();

        [$code, $output] = sgiHandle(['queue:work', 'database', '--once', '--tries=1']);

        expect($code)->toBe(0)->and(sgiFlat($output))->not->toContain(sgiFlat('Refusing to start'))->and(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();
    });

    it('is refused before it reserves anything under an invalid one: non-zero exit, the job untouched', function (array $override, string $rule) {
        sgiProduction($override);
        $id = sgiQueueJob();
        Log::spy();

        [$code, $output] = sgiHandle(['queue:work', 'database', '--once', '--tries=1']);
        $job = DB::table('jobs')->where('id', $id)->first();

        expect($code)->toBe(1)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `queue:work`'))->toContain(sgiFlat($rule))
            ->and($job)->not->toBeNull()->and($job->reserved_at)->toBeNull()->and((int) $job->attempts)->toBe(0);
        Log::shouldHaveReceived('critical')->once();
        Log::shouldNotHaveReceived('error');
    })->with([
        'redis cache' => [['cache.default' => 'redis'], "cache.default is 'redis'"],
        'separate cache connection' => [['cache.stores.database.connection' => 'mariadb'], 'cache.stores.database.connection is set'],
        'retry_after below 300' => [['queue.connections.database.retry_after' => 299], 'retry_after must be an integer of at least 300'],
        'insecure session cookie' => [['session.secure' => false], 'session.secure must be true'],
        'MAIL_URL' => [['mail.mailers.smtp.url' => 'log://localhost'], 'mail.mailers.smtp.url is set'],
        'debug' => [['app.debug' => true], 'app.debug must be false'],
    ]);

    it('is refused when only the detected environment is overridden (`--env=local`, `--env=PRODUCTION`): the effective configuration is still production (review M-1)', function (string $detected) {
        sgiProduction(['mail.default' => 'log']);
        app()->detectEnvironment(fn () => $detected);
        $id = sgiQueueJob();

        [$code, $output] = sgiHandle(['queue:work', 'database', '--once', '--tries=1']);
        $job = DB::table('jobs')->where('id', $id)->first();

        expect($code)->toBe(1)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `queue:work`'))->toContain(sgiFlat("mail.default is 'log'"))
            ->and($job)->not->toBeNull()->and($job->reserved_at)->toBeNull()->and((int) $job->attempts)->toBe(0);
    })->with(['local', 'PRODUCTION', 'staging']);

    it('is refused when configuration is not cached', function () {
        sgiProduction();
        app()->instance('config_loaded_from_cache', false);
        $id = sgiQueueJob();

        [$code, $output] = sgiHandle(['queue:work', 'database', '--once', '--tries=1']);

        expect($code)->toBe(1)->and(sgiFlat($output))->toContain(sgiFlat('configuration is not cached'))->and(DB::table('jobs')->where('id', $id)->whereNull('reserved_at')->exists())->toBeTrue();
    });
});

describe('the scheduler through the kernel', function () {
    /** Add a scheduled callback that records that it ran. */
    function sgiProbe(): object
    {
        $probe = new stdClass;
        $probe->ran = 0;
        app(Schedule::class)->call(function () use ($probe) {
            $probe->ran++;
        })->name('a3-probe')->everyMinute();

        return $probe;
    }

    it('runs its due callbacks under a valid production profile', function () {
        sgiProduction();
        $probe = sgiProbe();

        [$code, $output] = sgiHandle(['schedule:run']);

        expect($code)->toBe(0)->and($probe->ran)->toBe(1)->and(sgiFlat($output))->not->toContain(sgiFlat('Refusing to start'));
    });

    it('is refused before it runs anything under an invalid one: non-zero exit, no callback executed', function () {
        sgiProduction(['cache.default' => 'redis']);
        $probe = sgiProbe();

        [$code, $output] = sgiHandle(['schedule:run']);

        expect($code)->toBe(1)->and($probe->ran)->toBe(0)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `schedule:run`'))->toContain(sgiFlat("cache.default is 'redis'"));
    });
});

describe('the scheduler under an environment override', function () {
    it('runs no callback when the detected environment is overridden but the effective profile is invalid', function (string $detected) {
        sgiProduction(['cache.default' => 'redis']);
        app()->detectEnvironment(fn () => $detected);
        $probe = sgiProbe();

        [$code, $output] = sgiHandle(['schedule:run']);

        expect($code)->toBe(1)->and($probe->ran)->toBe(0)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `schedule:run`'));
    })->with(['local', 'PRODUCTION']);
});

describe('the other commands', function () {
    it('stay usable under an invalid production profile', function () {
        sgiProduction(['cache.default' => 'redis', 'app.debug' => true]);

        [$code, $report] = sgiPreflight([]);
        [$listCode, $list] = sgiHandle(['list']);

        expect($code)->toBe(1)->and(implode(' ', $report['violations']))->toContain("cache.default is 'redis'")
            ->and($listCode)->toBe(0)->and(sgiFlat($list))->toContain('runtime:preflight')->not->toContain(sgiFlat('Refusing to start'));
    });
});

describe('with the A2 cross-context check', function () {
    it('keeps A2 working through the guard, and a refused worker or scheduler records no observation', function () {
        sgiProduction();
        [$code, $issued] = sgiPreflight(['--issue' => true]);
        expect($code)->toBe(0);
        $id = $issued['challenge']['id'];
        test()->get('/up')->assertOk();

        // A refused worker and a refused scheduler: nothing of them has run, so the challenge has no queue and no scheduler observation.
        config(['mail.default' => 'log']);
        expect(sgiHandle(['queue:work', 'database', '--once', '--tries=1'])[0])->toBe(1)->and(sgiHandle(['schedule:run'])[0])->toBe(1);
        config(['mail.default' => 'smtp']);

        expect(RuntimeChallenges::observationRow($id, 'queue'))->toBeNull()->and(RuntimeChallenges::observationRow($id, 'scheduler'))->toBeNull()
            ->and(DB::table('jobs')->count())->toBe(1);

        [$code, $report] = sgiPreflight(['--verify' => true, '--challenge' => $id]);
        expect($code)->toBe(1)->and($report['contexts']['queue']['status'])->toBe('missing')->and($report['contexts']['scheduler']['status'])->toBe('missing');

        // The same commands under the valid profile start, answer the challenge, and the verification passes.
        expect(sgiHandle(['queue:work', 'database', '--once', '--tries=1'])[0])->toBe(0)->and(sgiHandle(['schedule:run'])[0])->toBe(0);

        [$code, $report] = sgiPreflight(['--verify' => true, '--challenge' => $id]);
        expect($code)->toBe(0)->and(collect($report['contexts'])->pluck('status')->unique()->all())->toBe(['ok']);
    });
});

describe('a real process against a committed job', function () {
    /**
     * Insert a job on a second, auto-committing connection to the testing database, so that a child process can see it; the test's own transaction
     * cannot be seen from outside. The row is removed in the caller's `finally`.
     */
    function sgiCommittedJob(): int
    {
        expect((string) config('database.connections.mariadb.database'))->toContain('testing');

        $queue = new DatabaseQueue(DB::connection('mariadb'), 'jobs', 'default', 300);
        $queue->setContainer(app());
        $queue->setConnectionName('database');

        return $queue->push(new RecordRuntimeObservation(str_repeat('a', 32)));
    }

    function sgiWorkerProcess(array $environment, array $options = []): array
    {
        $mysql = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--once', '--tries=1', ...$options], base_path(), [
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/sgi-no-config-cache-'.bin2hex(random_bytes(4)).'.php',
            'LOG_CHANNEL' => 'stderr',
            'APP_DEBUG' => 'false',
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => $mysql['database'],
            'DB_USERNAME' => $mysql['username'],
            'DB_PASSWORD' => $mysql['password'],
        ] + $environment, null, 90);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    it('refuses an invalid production worker without consuming the job, and the same job is consumed once the guard does not apply', function () {
        $id = sgiCommittedJob();

        try {
            [$code, $output] = sgiWorkerProcess(['APP_ENV' => 'production', 'CACHE_STORE' => 'file']);
            $after = DB::connection('mariadb')->table('jobs')->where('id', $id)->first();

            expect($code)->not->toBe(0)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `queue:work`'))->toContain(sgiFlat("cache.default is 'file'"))
                ->and($after)->not->toBeNull()->and($after->reserved_at)->toBeNull()->and((int) $after->attempts)->toBe(0);

            // The same worker with the detected environment overridden on the command line (review M-1): the effective configuration is still
            // production, so it is refused again and the job is still untouched.
            [$code, $output] = sgiWorkerProcess(['APP_ENV' => 'production', 'CACHE_STORE' => 'file'], ['--env=local']);
            $after = DB::connection('mariadb')->table('jobs')->where('id', $id)->first();

            expect($code)->not->toBe(0)->and(sgiFlat($output))->toContain(sgiFlat('Refusing to start `queue:work`'))->toContain(sgiFlat("cache.default is 'file'"))
                ->and($after)->not->toBeNull()->and($after->reserved_at)->toBeNull()->and((int) $after->attempts)->toBe(0);

            // Control: identical job and database, a non-production environment. The worker starts and consumes it, so the refusal above, and
            // nothing else (no unrelated CLI error), is what left the job alone.
            [$code, $output] = sgiWorkerProcess(['APP_ENV' => 'local', 'CACHE_STORE' => 'array']);

            expect($code)->toBe(0)->and(sgiFlat($output))->not->toContain(sgiFlat('Refusing to start'))
                ->and(DB::connection('mariadb')->table('jobs')->where('id', $id)->exists())->toBeFalse();
        } finally {
            DB::connection('mariadb')->table('jobs')->where('id', $id)->delete();
        }
    });
});
