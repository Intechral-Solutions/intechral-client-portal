<?php

use Symfony\Component\Process\Process;
use Tests\Support\PortalCronSandbox;

/*
 * R0.9 WP3-B1: the cron wrapper driving the REAL Laravel worker and scheduler (real `php artisan` processes, real database driver, real flock/timeout), and
 * the two failures Laravel itself reports with exit status zero. Every database here is either unreachable on purpose (127.0.0.1:1 is a closed port, so nothing
 * is ever reached) or a throw-away SQLite file in the sandbox. No development database, container or volume is touched, and nothing is written to
 * storage/logs (the application logs to stderr, which the wrapper captures into the sandbox).
 * docs/architecture/cron-execution.md.
 */

beforeEach(function () {
    $this->sb = new PortalCronSandbox;
    $this->sb->release('r1');
});

afterEach(function () {
    $this->sb->destroy();
    foreach (pcaCleanup() as $path) {
        @unlink($path);
    }
});

/** @return list<string> files this test file created outside the sandbox */
function pcaCleanup(?string $add = null): array
{
    static $paths = [];

    if ($add !== null) {
        $paths[] = $add;
    }

    return $paths;
}

/** The application environment of a disposable context. `DB_PASSWORD` and the host are secrets the notification must never carry. */
function pcaEnvironment(array $override = []): array
{
    return $override + [
        'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'debug',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_CONNECTION' => '', 'DB_URL' => '', 'DB_SOCKET' => '',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'pca_unreachable_db', 'DB_USERNAME' => 'pca_user', 'DB_PASSWORD' => 'PCASECRETPW',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/pca-no-config-cache-'.bin2hex(random_bytes(4)).'.php',
    ];
}

/** Run the wrapper at the REAL repository release (the wrapper in src/scripts/cron), with the sandbox's shared directories and this PHP. */
function pcaWrapper(PortalCronSandbox $sb, string $task, array $environment): Process
{
    $process = $sb->process([$task], $environment, PortalCronSandbox::WRAPPER, php: PHP_BINARY);
    $process->run();

    return $process;
}

/** A disposable release that runs the real application code with its own routes/console.php, so a scheduled task can be made to fail on purpose. */
function pcaScheduleRelease(PortalCronSandbox $sb, string $consolePhp): string
{
    $release = $sb->release('app');
    $source = base_path();

    foreach (['bootstrap/cache', 'routes', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
        @mkdir($release.'/'.$directory, 0755, true);
    }

    copy($source.'/artisan', $release.'/artisan');
    copy($source.'/composer.json', $release.'/composer.json');
    copy($source.'/bootstrap/app.php', $release.'/bootstrap/app.php');
    copy($source.'/bootstrap/providers.php', $release.'/bootstrap/providers.php');
    symlink($source.'/routes/web.php', $release.'/routes/web.php');

    foreach (['app', 'config', 'database', 'public', 'resources', 'vendor'] as $entry) {
        symlink($source.'/'.$entry, $release.'/'.$entry);
    }

    file_put_contents($release.'/routes/console.php', $consolePhp);

    return $release;
}

const PCA_SCHEDULE_OK = <<<'PHP'
<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('portal-cron-test:ok', function () { $this->line('FAIL'); $this->line('   Running [portal-cron-test:ok] ..... FAIL'); return 0; });
Schedule::command('portal-cron-test:ok')->everyMinute();
Schedule::call(function () { echo "something FAIL\n"; })->name('noisy-callback')->everyMinute();
PHP;

const PCA_SCHEDULE_FAILING = <<<'PHP'
<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('portal-cron-test:fail', function () { $this->error('boom TASKSECRET-IN-OUTPUT'); return 1; });
Schedule::command('portal-cron-test:fail')->everyMinute();
PHP;

const PCA_SCHEDULE_NESTED_THEN_FAILING = <<<'PHP'
<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('portal-cron-test:inner', function () { return 0; });
// The first callback starts another Artisan command in the same process (CommandStarting fires for it); the second then fails.
Schedule::call(function () { Artisan::call('portal-cron-test:inner'); })->name('starts-a-nested-command')->everyMinute();
Schedule::call(function () { throw new RuntimeException('boom NESTEDSECRET'); })->name('fails-afterwards')->everyMinute();
PHP;

describe('a worker whose database is unavailable', function () {
    it('exits zero on its own, and the wrapper turns the swallowed failure into a recorded operational failure', function () {
        $environment = pcaEnvironment();

        // 1 + 2. The real Laravel worker meets a real database failure (connection refused) and, without the wrapper, exits SUCCESSFULLY with nothing a cron job could see.
        $direct = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=1', '--tries=1', '--no-ansi'], base_path(), $environment, null, 60);
        $direct->run();

        expect($direct->getExitCode())->toBe(0)
            ->and($direct->getOutput().$direct->getErrorOutput())->toContain('SQLSTATE[HY000] [2002] Connection refused')   // reported to the log channel, status untouched
            ->and($direct->getOutput().$direct->getErrorOutput())->not->toContain('@@PORTAL-CRON-FAILURE@@');

        // 3 + 4. The same worker under the wrapper emits the marker, and the wrapper exits non-zero and records it.
        $process = pcaWrapper($this->sb, 'queue', $environment);
        $notice = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(90)
            ->and($notice)->toContain('portal-cron FAILED task=queue category=database status=0')->toContain('class=PDOException sqlstate=HY000');

        $log = $this->sb->log('queue');
        expect($log)->toContain("| \n| @@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000\n")
            ->and($log)->toMatch('/ END status=fail category=database php_status=0 exit=90 duration_s=\d+ release=\S+ class=PDOException sqlstate=HY000\n/');

        // The raw detail is in the restricted local log (the application's own report), and in no notification.
        expect($log)->toContain('Connection refused')->and($this->sb->mode_of($this->sb->logPath('queue')))->toBe('0600');
        foreach (['PCASECRETPW', 'pca_user', 'pca_unreachable_db', '127.0.0.1', 'Connection refused', 'SQLSTATE[', 'select '] as $secret) {
            expect($notice)->not->toContain($secret);
        }

        // The failure is rate limited like any other: the identical repeat sends nothing but still exits non-zero.
        $repeat = pcaWrapper($this->sb, 'queue', $environment);
        expect($repeat->getExitCode())->toBe(90)->and($repeat->getOutput().$repeat->getErrorOutput())->toBe('');
    });

    it('emits nothing from a worker the wrapper did not start', function () {
        $direct = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=1', '--tries=1', '--no-ansi'], base_path(), pcaEnvironment(), null, 60);
        $direct->run();

        expect($direct->getOutput().$direct->getErrorOutput())->not->toContain('@@PORTAL-CRON-FAILURE@@');
    });
});

describe('a scheduled task that fails', function () {
    it('exits zero in the installed Laravel, which renders it DONE: the reason the application emits a marker (pinned)', function () {
        $release = pcaScheduleRelease($this->sb, PCA_SCHEDULE_FAILING);

        $direct = new Process([PHP_BINARY, 'artisan', 'schedule:run', '--no-ansi'], $release, pcaEnvironment(), null, 60);
        $direct->run();
        $output = $direct->getOutput().$direct->getErrorOutput();

        // Laravel 13.36: ScheduleRunCommand hands the task component a boolean, which it does not match against TaskResult::Failure, so a failed task prints DONE.
        // If a Laravel upgrade makes this assertion fail, the marker still works; revisit docs/architecture/cron-execution.md ("Scheduler failure").
        expect($direct->getExitCode())->toBe(0)->and($output)->toContain('portal-cron-test:fail')->toContain('DONE')->not->toContain('FAIL');
    });

    it('is reported by the wrapper through the application marker, with only a class and the task\'s exit status', function () {
        $release = pcaScheduleRelease($this->sb, PCA_SCHEDULE_FAILING);

        // The wrapper is the disposable release's own copy, so the release it runs is that directory.
        $process = $this->sb->process(['schedule'], pcaEnvironment(), $release.'/scripts/cron/portal-cron.sh', php: PHP_BINARY);
        $process->run();
        $notice = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(90)
            ->and($notice)->toContain('portal-cron FAILED task=schedule category=scheduler status=0 release=app')->toContain('class=Exception task_exit=1')
            ->and($notice)->not->toContain('boom')->not->toContain('TASKSECRET')->not->toContain('failed with exit code')->not->toContain('artisan');

        $log = $this->sb->log('schedule');
        expect($log)->toContain('| @@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1')
            ->and($log)->toMatch('/ END status=fail category=scheduler php_status=0 exit=90 /');
    });

    it('is not mistaken for a failure by ordinary task output, whatever it says', function () {
        $release = pcaScheduleRelease($this->sb, PCA_SCHEDULE_OK);

        $process = $this->sb->process(['schedule'], pcaEnvironment(), $release.'/scripts/cron/portal-cron.sh', php: PHP_BINARY);
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput().$process->getErrorOutput())->toBe('')
            ->and($this->sb->log('schedule'))->toContain('something FAIL')->toContain('END status=ok category=ok exit=0');
    });
});

describe('a scheduler whose database-backed cache is unavailable', function () {
    it('fails on the scheduler\'s pause poll with a non-zero status, and the wrapper reports it by category without any connection detail', function () {
        $environment = pcaEnvironment(['CACHE_STORE' => 'database']);

        // 1 + 2 + 3 + 4. A real schedule:run, the database cache store, a refused connection. Before it evaluates any task Laravel reads the cache key
        // `illuminate:schedule:paused` (ScheduleRunCommand::isPaused); that read fails, is not caught, and the command exits non-zero on its own.
        $direct = new Process([PHP_BINARY, 'artisan', 'schedule:run', '--no-ansi'], base_path(), $environment, null, 60);
        $direct->run();
        $directOutput = $direct->getOutput().$direct->getErrorOutput();

        expect($direct->getExitCode())->toBe(1)
            ->and($directOutput)->toContain('QueryException')->toContain('SQLSTATE[HY000] [2002] Connection refused')->toContain('schedule:paused')
            ->and($directOutput)->not->toContain('@@PORTAL-CRON-FAILURE@@');

        // 5 + 6. Under the wrapper the same failure is a categorised database failure, and the notification carries nothing of the connection.
        $process = pcaWrapper($this->sb, 'schedule', $environment);
        $notice = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(1)
            ->and($notice)->toContain('portal-cron FAILED task=schedule category=database status=1')->toContain('class=Illuminate\Database\QueryException sqlstate=HY000');
        foreach (['PCASECRETPW', 'pca_user', 'pca_unreachable_db', '127.0.0.1', 'Connection refused', 'SQLSTATE[', 'schedule:paused', 'select '] as $secret) {
            expect($notice)->not->toContain($secret);
        }

        $log = $this->sb->log('schedule');
        expect($log)->toContain('| @@PORTAL-CRON-FAILURE@@ v1 category=database class=Illuminate\Database\QueryException sqlstate=HY000')
            ->and($log)->toMatch('/ END status=fail category=database php_status=1 exit=1 /');
    });

    it('is a property of the pause poll on the database cache store: with a cache that does not use the database the same tick succeeds (negative control)', function () {
        // A tick that happens to start inside 02:00 UTC would run tickets:auto-close, which does need the database: stay clear of that minute.
        if (in_array(gmdate('Hi'), ['0159', '0200'], true)) {
            $this->markTestSkipped('the real schedule runs tickets:auto-close in this minute');
        }

        $direct = new Process([PHP_BINARY, 'artisan', 'schedule:run', '--no-ansi'], base_path(), pcaEnvironment(['CACHE_STORE' => 'array']), null, 60);
        $direct->run();

        expect($direct->getExitCode())->toBe(0, $direct->getOutput().$direct->getErrorOutput());
    });
});

describe('a nested Artisan command inside the scheduler', function () {
    it('does not stop a later task failure from being reported', function () {
        $release = pcaScheduleRelease($this->sb, PCA_SCHEDULE_NESTED_THEN_FAILING);

        $process = $this->sb->process(['schedule'], pcaEnvironment(), $release.'/scripts/cron/portal-cron.sh', php: PHP_BINARY);
        $process->run();
        $notice = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(90)
            ->and($notice)->toContain('category=scheduler')->toContain('class=RuntimeException')
            ->and($notice)->not->toContain('boom')->not->toContain('NESTEDSECRET');
    });
});

describe('successful invocations and refused starts', function () {
    it('drains an empty database queue and runs a scheduler tick silently, with the real arguments', function () {
        $database = $this->sb->dir.'/queue.sqlite';
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('create table jobs (id integer primary key autoincrement, queue varchar, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        $pdo->exec('create table failed_jobs (id integer primary key autoincrement, uuid varchar, connection text, queue text, payload text, exception text, failed_at datetime)');
        unset($pdo);
        $environment = pcaEnvironment(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);

        $queue = pcaWrapper($this->sb, 'queue', $environment);
        $schedule = pcaWrapper($this->sb, 'schedule', $environment);

        expect($queue->getExitCode())->toBe(0)->and($queue->getOutput().$queue->getErrorOutput())->toBe('')
            ->and($schedule->getExitCode())->toBe(0)->and($schedule->getOutput().$schedule->getErrorOutput())->toBe('')
            ->and($this->sb->log('queue'))->toContain('END status=ok category=ok exit=0')
            ->and($this->sb->log('schedule'))->toContain('END status=ok category=ok exit=0')
            ->and($this->sb->lockIsFree('queue'))->toBeTrue()->and($this->sb->lockIsFree('schedule'))->toBeTrue();
    });

    it('reports an A3 startup refusal through the wrapper, by category and without the rules\' detail', function () {
        // A real `config:cache` for a production-shaped but INVALID profile (a log mail transport), at a path of this test. The database port is closed: nothing is reached.
        $cache = sys_get_temp_dir().'/pca-invalid-profile-'.bin2hex(random_bytes(6)).'.php';
        pcaCleanup($cache);
        $production = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_TIMEZONE' => 'UTC', 'APP_URL' => 'https://portal.pca.invalid', 'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'pca_',
            'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => 'true', 'DB_QUEUE_RETRY_AFTER' => '300', 'MAIL_MAILER' => 'log', 'MAIL_HOST' => 'smtp.pca.invalid',
            'MAIL_PORT' => '587', 'MAIL_TIMEOUT' => '20', 'MAIL_FROM_ADDRESS' => 'portal@pca.invalid', 'MAIL_URL' => '', 'MAIL_PASSWORD' => 'PCAMAILPW', 'APP_CONFIG_CACHE' => $cache,
        ];
        $build = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), pcaEnvironment($production), null, 60);
        $build->run();
        expect($build->getExitCode())->toBe(0, $build->getOutput().$build->getErrorOutput())->and(is_file($cache))->toBeTrue();

        foreach (['queue', 'schedule'] as $task) {
            $process = pcaWrapper($this->sb, $task, pcaEnvironment($production));
            $notice = $process->getOutput().$process->getErrorOutput();

            expect($process->getExitCode())->toBe(1)
                ->and($notice)->toContain("portal-cron FAILED task={$task} category=profile-refused status=1")
                ->and($notice)->not->toContain('mail.default')->not->toContain('PCAMAILPW')->not->toContain('PCASECRETPW')
                ->and($this->sb->log($task))->toContain('Refusing to start `'.($task === 'queue' ? 'queue:work' : 'schedule:run').'`')->toContain("mail.default is 'log'")
                ->and($this->sb->log($task))->toContain('END status=fail category=profile-refused php_status=1 exit=1');
        }
    });
});
