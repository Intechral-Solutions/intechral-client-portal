<?php

use App\Support\Runtime\RuntimeProfile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\PortalCronSandbox;

/*
 * R0.9 WP3-B1: the cron wrapper (scripts/cron/portal-cron.sh), exercised as cron runs it (`env -i`, a minimal PATH, `/bin/sh current/scripts/...`) against
 * a stub PHP, with real flock and real GNU timeout. Timings are shortened through the wrapper's test-only overrides, so the whole file takes tens of
 * seconds. The real Laravel worker and scheduler behind the wrapper are in PortalCronApplicationTest. docs/architecture/cron-execution.md.
 */

beforeEach(function () {
    $this->sb = new PortalCronSandbox;
});

afterEach(function () {
    $this->sb->destroy();
});

/** The wrapper's constants, read from the script itself so a test pins what ships and not a copy. */
function pcwConstants(): array
{
    preg_match_all('/^([A-Z_]+)=(\d+) /m', (string) file_get_contents(PortalCronSandbox::WRAPPER), $matches);

    return array_map('intval', array_combine($matches[1], $matches[2]));
}

/**
 * A `readlink` that switches `current` to r2 at its FIRST call: after the shell opened r1's script and before the wrapper resolved its own location.
 * Put its directory first on the PATH. Returns that directory.
 */
function pcwStartupSwapShim(PortalCronSandbox $sb): string
{
    $shim = $sb->dir.'/shim';
    @mkdir($shim);
    file_put_contents($shim.'/readlink', <<<SH
#!/bin/sh
if [ ! -e "{$sb->dir}/switched" ]; then
    : > "{$sb->dir}/switched"
    ln -sfn "{$sb->root}/releases/r2" "{$sb->root}/current.shim" && mv -T "{$sb->root}/current.shim" "{$sb->root}/current"
fi
exec /usr/bin/readlink "\$@"
SH);
    chmod($shim.'/readlink', 0755);

    return $shim;
}

describe('shell and environment', function () {
    it('is valid POSIX shell for every shell it may meet', function () {
        $checked = 0;

        foreach ([['sh', '-n'], ['dash', '-n'], ['bash', '--posix', '-n']] as $command) {
            $binary = (new ExecutableFinder)->find($command[0]);

            if ($binary === null) {
                continue;
            }

            $process = new Process([$binary, ...array_slice($command, 1), PortalCronSandbox::WRAPPER]);
            $process->run();
            expect($process->getExitCode())->toBe(0, $command[0].': '.$process->getErrorOutput());
            $checked++;
        }

        expect($checked)->toBeGreaterThan(0);
    });

    it('runs under a minimal cron environment, silently, from the physical release', function () {
        $release = $this->sb->release('r1');

        $process = $this->sb->run('queue', path: '/usr/bin:/bin');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput().$process->getErrorOutput())->toBe('')
            ->and($this->sb->invocations())->toHaveCount(1)
            ->and($this->sb->invocations()[0])->toStartWith($release.'/artisan queue:work')
            ->and($this->sb->invocations()[0])->not->toContain('/current/')
            ->and($this->sb->log('queue'))->toMatch('/ queue pid=\d+ START release=r1 slot_offset_s=\d+\n/')
            ->and($this->sb->log('queue'))->toMatch('/ END status=ok category=ok exit=0 duration_s=\d+ release=r1\n/')
            ->and(file_get_contents($this->sb->stubDir.'/env.log'))->toBe("marker=1 stage=unset\n");   // the stage marker never reaches PHP
    });

    it('passes the pinned arguments, the explicit PHP binary and no environment override', function () {
        $release = $this->sb->release('r1');

        $this->sb->run('queue');
        $this->sb->run('schedule');

        expect($this->sb->invocations())->toBe([
            $release.'/artisan queue:work database --stop-when-empty --max-time=120 --timeout=120 --tries=3 --backoff=60 --sleep=1 --no-ansi --no-interaction',
            $release.'/artisan schedule:run --no-ansi --no-interaction',
        ]);

        foreach ($this->sb->invocations() as $invocation) {
            expect($invocation)->not->toContain('--env');
        }

        // Without an override the explicit host path is used: a machine without it is refused, not silently given another PHP.
        $default = $this->sb->process(['queue'], ['PORTAL_PHP' => ''], php: '');
        $default->run();
        expect($default->getExitCode())->toBe(70)->and($default->getErrorOutput())->toContain('portal-cron: the PHP CLI binary is not executable');
        expect(file_get_contents(PortalCronSandbox::WRAPPER))->toContain('PHP=${PORTAL_PHP:-/opt/alt/php85/usr/bin/php}');
    });

    it('refuses a bad call before it launches anything', function (array $arguments, array $environment, int $code, string $message) {
        $this->sb->release('r1');

        $process = $this->sb->process($arguments, $environment);
        $process->run();

        expect($process->getExitCode())->toBe($code)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain($message)
            ->and($this->sb->invocations())->toBe([]);
    })->with([
        'no task' => [[], [], 64, 'usage'],
        'unknown task' => [['bogus'], [], 64, 'usage'],
        'two arguments' => [['queue', 'extra'], [], 64, 'usage'],
        'no root' => [['queue'], ['PORTAL_ROOT' => ''], 64, 'PORTAL_ROOT must be set to an absolute path'],
        'relative root' => [['queue'], ['PORTAL_ROOT' => 'portal'], 64, 'PORTAL_ROOT must be set to an absolute path'],
        'root without shared' => [['queue'], ['PORTAL_ROOT' => '/nonexistent-portal-root'], 70, 'does not exist'],
        'relative PHP' => [['queue'], ['PORTAL_PHP' => 'php'], 64, 'PORTAL_PHP must be an absolute path'],
        'missing PHP' => [['queue'], ['PORTAL_PHP' => '/nonexistent/php'], 70, 'not executable'],
        'timing override outside tests' => [['queue'], ['PORTAL_CRON_QUEUE_TERM' => '3'], 64, 'test-only override'],
        'non-numeric override' => [['queue'], ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_QUEUE_TERM' => 'soon'], 64, 'whole number'],
        'worker window longer than the TERM limit' => [['queue'], ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_QUEUE_MAX_TIME' => '3', 'PORTAL_CRON_QUEUE_JOB_TIMEOUT' => '1', 'PORTAL_CRON_QUEUE_TERM' => '3'], 64, 'QUEUE_MAX_TIME + QUEUE_JOB_TIMEOUT must be below QUEUE_TERM'],
        'worker bound not below retry_after' => [['queue'], ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_QUEUE_MAX_TIME' => '1', 'PORTAL_CRON_QUEUE_JOB_TIMEOUT' => '1', 'PORTAL_CRON_QUEUE_TERM' => '5', 'PORTAL_CRON_KILL_AFTER' => '1', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '6'], 64, 'QUEUE_TERM + KILL_AFTER must be below the retry_after floor'],
        'scheduler bound not below the floor' => [['schedule'], ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_QUEUE_MAX_TIME' => '1', 'PORTAL_CRON_QUEUE_JOB_TIMEOUT' => '1', 'PORTAL_CRON_QUEUE_TERM' => '3', 'PORTAL_CRON_SCHED_TERM' => '5', 'PORTAL_CRON_KILL_AFTER' => '1', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '6'], 64, 'SCHED_TERM + KILL_AFTER must be below the retry_after floor'],
    ]);

    it('reports a release without artisan as a layout failure', function () {
        $this->sb->release('broken', withArtisan: false);

        $process = $this->sb->run('queue');

        expect($process->getExitCode())->toBe(70)
            ->and($process->getOutput())->toContain('FAILED task=queue category=layout status=70 release=broken')
            ->and($this->sb->log('queue'))->toContain('END status=fail category=layout exit=70 release=broken')
            ->and($this->sb->invocations())->toBe([]);
    });

    it('creates and keeps the shared directories and files restricted', function () {
        $this->sb->release('r1');
        mkdir($this->sb->root.'/shared/run', 0777);
        mkdir($this->sb->root.'/shared/logs', 0777);
        chmod($this->sb->root.'/shared/run', 0777);
        chmod($this->sb->root.'/shared/logs', 0777);
        file_put_contents($this->sb->logPath('queue'), "old\n");
        chmod($this->sb->logPath('queue'), 0644);

        $this->sb->mode('exit:1');
        $this->sb->run('queue');

        expect($this->sb->mode_of($this->sb->root.'/shared/run'))->toBe('0700')
            ->and($this->sb->mode_of($this->sb->root.'/shared/logs'))->toBe('0700')
            ->and($this->sb->mode_of($this->sb->logPath('queue')))->toBe('0600')
            ->and($this->sb->mode_of($this->sb->lockPath('queue')))->toBe('0600')
            ->and($this->sb->mode_of($this->sb->root.'/shared/run/queue.holder'))->toBe('0600')
            ->and($this->sb->mode_of($this->sb->root.'/shared/run/queue.alert'))->toBe('0600');
    });
});

describe('locking', function () {
    it('skips silently, and records it, while another run holds the lock', function () {
        $this->sb->release('r1');
        $holder = $this->sb->holdLock('queue', 5);
        $this->sb->writeHolder('queue', time() - 2, 'r0');

        $process = $this->sb->run('queue', $this->sb->timings());

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput().$process->getErrorOutput())->toBe('')
            ->and($this->sb->invocations())->toBe([])
            ->and($this->sb->log('queue'))->toMatch('/ SKIP lock held holder_pid=4242 holder_age_s=\d+ holder_release=r0\n/');

        $holder->stop(0);
    });

    it('keeps an independent lock for the queue and the scheduler', function () {
        $this->sb->release('r1');
        $holder = $this->sb->holdLock('queue', 5);

        $process = $this->sb->run('schedule', $this->sb->timings());

        expect($process->getExitCode())->toBe(0)->and($this->sb->invocations())->toHaveCount(1)->and($this->sb->invocations()[0])->toContain('schedule:run');

        $holder->stop(0);
    });

    it('releases the lock when a run ends, whatever its outcome', function (string $mode, int $expected) {
        $this->sb->release('r1');
        $this->sb->mode($mode);

        $process = $this->sb->run('queue', $this->sb->timings());

        expect($process->getExitCode())->toBe($expected)->and($this->sb->lockIsFree('queue'))->toBeTrue()->and($this->sb->survivors())->toBe([]);
    })->with([['ok', 0], ['exit:1', 1], ['exit:9', 9]]);

    it('treats a PHP process killed from outside as a SIGKILL failure and frees the lock', function () {
        $this->sb->release('r1');
        $this->sb->mode('hold');
        $process = $this->sb->start('queue', $this->sb->timings(['PORTAL_CRON_QUEUE_TERM' => '30', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '40']));
        expect($this->sb->waitFor(fn () => $this->sb->stubStarted()))->toBeTrue();

        posix_kill($this->sb->stubPid(), SIGKILL);
        $process->wait();

        expect($process->getExitCode())->toBe(137)
            ->and($process->getOutput())->toContain('category=sigkill status=137')
            ->and($this->sb->log('queue'))->toContain('category=sigkill php_status=137 exit=137');

        // The killed shell's own `sleep` child inherited the lock descriptor and may outlive it by a moment: the lock is free once that is gone.
        expect($this->sb->waitFor(fn () => $this->sb->lockIsFree('queue'), 3))->toBeTrue();
    });

    it('keeps the lock while PHP outlives a wrapper that was killed, and records a START without an END', function () {
        $this->sb->release('r1');
        $this->sb->mode('hold');
        $wrapper = $this->sb->start('queue', $this->sb->timings(['PORTAL_CRON_QUEUE_TERM' => '30', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '40']));
        expect($this->sb->waitFor(fn () => $this->sb->stubStarted()))->toBeTrue();

        $wrapperPid = (int) $wrapper->getPid();
        $stubPid = $this->sb->stubPid();
        posix_kill($wrapperPid, SIGKILL);

        expect($this->sb->waitFor(fn () => ! $this->sb->isAlive($wrapperPid), 5))->toBeTrue()
            ->and($this->sb->isAlive($stubPid))->toBeTrue()
            ->and($this->sb->lockIsFree('queue'))->toBeFalse();

        $contender = $this->sb->run('queue', $this->sb->timings());
        expect($contender->getExitCode())->toBe(0)->and($this->sb->log('queue'))->toContain('SKIP lock held');

        $this->sb->releaseStub();
        expect($this->sb->waitFor(fn () => $this->sb->lockIsFree('queue'), 10))->toBeTrue()
            ->and($this->sb->log('queue'))->toContain('START release=r1')->not->toContain('END ');
    });

    it('keeps the lock while a child outlives the whole run', function () {
        $this->sb->release('r1');
        $this->sb->mode('orphan');

        $first = $this->sb->run('queue', $this->sb->timings());
        expect($first->getExitCode())->toBe(0)->and($this->sb->lockIsFree('queue'))->toBeFalse();

        $second = $this->sb->run('queue', $this->sb->timings());
        expect($second->getExitCode())->toBe(0)->and($this->sb->invocations())->toHaveCount(1)->and($this->sb->log('queue'))->toContain('SKIP lock held');

        expect($this->sb->waitFor(fn () => $this->sb->lockIsFree('queue'), 8))->toBeTrue();
        $this->sb->mode('ok');
        $this->sb->run('queue', $this->sb->timings());
        expect($this->sb->invocations())->toHaveCount(2);
    });

    it('escalates a holder that is implausibly old, once, and does not escalate a fresh one', function () {
        $this->sb->release('r1');
        $holder = $this->sb->holdLock('queue', 12);

        $this->sb->writeHolder('queue', time() - 1000);
        $stale = $this->sb->run('queue', $this->sb->timings());

        expect($stale->getExitCode())->toBe(75)
            ->and($stale->getOutput())->toContain('FAILED task=queue category=lock-stale status=75')->toContain('holder_release=old-release')
            ->and($this->sb->log('queue'))->toContain('SKIP lock held and STALE')
            ->and($this->sb->invocations())->toBe([]);

        $again = $this->sb->run('queue', $this->sb->timings());
        expect($again->getExitCode())->toBe(75)->and($again->getOutput())->toBe('')->and($this->sb->log('queue'))->toContain('ALERT suppressed category=lock-stale');

        $this->sb->writeHolder('queue', time());
        $fresh = $this->sb->run('queue', $this->sb->timings());
        expect($fresh->getExitCode())->toBe(0)->and($fresh->getOutput())->toBe('');

        $holder->stop(0);
    });

    it('never replaces the lock file, however often the log rotates', function () {
        $this->sb->release('r1');
        $this->sb->mode('print:0');
        $this->sb->output(str_repeat("a line of ordinary output\n", 8));
        $this->sb->run('queue');
        $inode = fileinode($this->sb->lockPath('queue'));

        for ($i = 0; $i < 12; $i++) {
            $this->sb->run('queue', ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_LOG_MAX_BYTES' => '600']);
        }

        expect(fileinode($this->sb->lockPath('queue')))->toBe($inode)
            ->and(is_file($this->sb->logPath('queue').'.1'))->toBeTrue()
            ->and(glob($this->sb->root.'/shared/run/queue.lock*'))->toHaveCount(1);
    });
});

describe('timeouts', function () {
    it('ends a process that honours TERM at the TERM limit and reports a timeout', function () {
        $this->sb->release('r1');
        $this->sb->mode('honor-term');

        $process = $this->sb->run('queue', $this->sb->timings());

        expect($process->getExitCode())->toBe(124)
            ->and($process->getOutput())->toContain('category=timeout status=124')
            ->and($this->sb->log('queue'))->toMatch('/category=timeout php_status=124 exit=124 duration_s=[34] /')
            ->and($this->sb->survivors())->toBe([])
            ->and($this->sb->lockIsFree('queue'))->toBeTrue();
    });

    it('kills a process that ignores TERM after the grace interval', function () {
        $this->sb->release('r1');
        $this->sb->mode('ignore-term');

        $process = $this->sb->run('queue', $this->sb->timings());

        expect($process->getExitCode())->toBe(137)
            ->and($process->getOutput())->toContain('category=timeout status=137')
            ->and($this->sb->log('queue'))->toContain('killed_after_term=yes')->toMatch('/duration_s=[45] /')
            ->and($this->sb->isAlive($this->sb->stubPid()))->toBeFalse()
            ->and($this->sb->survivors())->toBe([])
            ->and($this->sb->lockIsFree('queue'))->toBeTrue();
    });

    it('applies the scheduler limit to the scheduler', function () {
        $this->sb->release('r1');
        $this->sb->mode('honor-term');

        $process = $this->sb->run('schedule', $this->sb->timings(['PORTAL_CRON_SCHED_TERM' => '2']));

        expect($process->getExitCode())->toBe(124)->and($this->sb->log('schedule'))->toMatch('/limit_s=2/');
    });

    it('keeps the shipped timings inside the retry_after floor the runtime profile enforces', function () {
        $c = pcwConstants();

        // The owner-approved defaults.
        expect($c)->toMatchArray([
            'QUEUE_MAX_TIME' => 120, 'QUEUE_JOB_TIMEOUT' => 120, 'QUEUE_TERM' => 255, 'SCHED_TERM' => 240, 'KILL_AFTER' => 15, 'RETRY_AFTER_FLOOR' => 300,
        ]);

        // The wrapper's floor is the profile's floor, so a change to either breaks this test and not production.
        expect($c['RETRY_AFTER_FLOOR'])->toBe(RuntimeProfile::MIN_RETRY_AFTER);

        // A worker's reservations all begin after the worker does: gone by TERM + KILL_AFTER, with retry_after longer, none can expire under it.
        expect($c['QUEUE_TERM'] + $c['KILL_AFTER'])->toBeLessThan(RuntimeProfile::MIN_RETRY_AFTER);
        // The normal exit is graceful: the admission window plus one job's alarm fits inside the TERM limit.
        expect($c['QUEUE_MAX_TIME'] + $c['QUEUE_JOB_TIMEOUT'])->toBeLessThan($c['QUEUE_TERM']);
        // A scheduler tick ends before the next cron interval.
        expect($c['SCHED_TERM'] + $c['KILL_AFTER'])->toBeLessThan(300);
    });
});

describe('logging and alerts', function () {
    it('mails a failure once, rate-limits the repeat, surfaces a change, reports recovery once, and reminds after the interval', function () {
        $this->sb->release('r1');
        $timings = $this->sb->timings();

        $this->sb->mode('exit:1');
        $first = $this->sb->run('queue', $timings);
        expect($first->getExitCode())->toBe(1)->and($first->getOutput())->toContain('portal-cron FAILED task=queue category=php-exit status=1 release=r1');

        $repeat = $this->sb->run('queue', $timings);
        expect($repeat->getExitCode())->toBe(1)->and($repeat->getOutput().$repeat->getErrorOutput())->toBe('')
            ->and($this->sb->log('queue'))->toContain('ALERT suppressed category=php-exit');

        $this->sb->mode('exit:2');
        $changed = $this->sb->run('queue', $timings);
        expect($changed->getExitCode())->toBe(2)->and($changed->getOutput())->toContain('category=php-exit status=2');

        $this->sb->mode('ok');
        $recovered = $this->sb->run('queue', $timings);
        expect($recovered->getExitCode())->toBe(0)->and($recovered->getOutput())->toContain('RECOVERED task=queue')->toContain('previous failure category=php-exit');

        $quiet = $this->sb->run('queue', $timings);
        expect($quiet->getExitCode())->toBe(0)->and($quiet->getOutput().$quiet->getErrorOutput())->toBe('');

        $this->sb->mode('exit:1');
        $reminder = $timings + ['PORTAL_CRON_ALERT_REPEAT' => '1'];
        expect($this->sb->run('queue', $reminder)->getOutput())->toContain('FAILED task=queue');
        sleep(2);
        expect($this->sb->run('queue', $reminder)->getOutput())->toContain('FAILED task=queue');
    });

    it('keeps the two tasks\' alert state apart', function () {
        $this->sb->release('r1');
        $this->sb->mode('exit:1');

        expect($this->sb->run('queue', $this->sb->timings())->getOutput())->toContain('task=queue')
            ->and($this->sb->run('schedule', $this->sb->timings())->getOutput())->toContain('task=schedule');
    });

    it('keeps secret-bearing output out of the notification, and out of the log except as quoted, restricted, bounded lines', function () {
        $this->sb->release('r1');
        $this->sb->mode('print:1');
        $this->sb->output(
            "SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select * from jobs where password = 'hunter2')\n"
            ."DB_PASSWORD=topsecret mysql://user:urlsecret@db.internal/portal\n"
            ."\e[31mcoloured\e[0m\n"
            ."2026-01-01T00:00:00Z queue pid=1 END status=ok category=ok exit=0 duration_s=0 release=forged\n"
        );

        $process = $this->sb->run('queue', $this->sb->timings());
        $mail = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(1)->and($mail)->toContain('category=php-exit');
        foreach (['hunter2', 'topsecret', 'urlsecret', 'db.internal', 'SQLSTATE', 'Connection refused'] as $secret) {
            expect($mail)->not->toContain($secret);
        }

        $log = $this->sb->log('queue');
        expect($log)->toContain('| DB_PASSWORD=topsecret')                       // retained locally, deliberately, as potentially sensitive text
            ->and($log)->toContain('| 2026-01-01T00:00:00Z queue pid=1 END status=ok')   // quoted, so it cannot pass for this script's own record
            ->and($log)->not->toMatch('/^2026-01-01T00:00:00Z queue pid=1 END/m')
            ->and($log)->not->toContain("\e")
            ->and($mail)->toContain('restricted file, may contain sensitive text')
            ->and($this->sb->mode_of($this->sb->logPath('queue')))->toBe('0600');
    });

    it('bounds the output kept per run and the size of the log', function () {
        $this->sb->release('r1');
        $this->sb->mode('print:0');
        $this->sb->output(str_repeat('x', 400)."\n");

        $this->sb->run('queue', ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_OUTPUT_MAX_BYTES' => '50']);
        expect($this->sb->log('queue'))->toContain('| '.str_repeat('x', 50))->not->toContain(str_repeat('x', 51))->toContain('[output truncated at 50 bytes]');

        for ($i = 0; $i < 25; $i++) {
            $this->sb->run('queue', ['PORTAL_CRON_TEST' => '1', 'PORTAL_CRON_LOG_MAX_BYTES' => '1500']);
        }
        clearstatcache();

        // One generation is kept; each file stays near the limit (a rotation happens at the start of a run, so the live file may exceed it by one run).
        expect(filesize($this->sb->logPath('queue')))->toBeLessThan(1500 + 1000)
            ->and(filesize($this->sb->logPath('queue').'.1'))->toBeLessThan(1500 + 1000)
            ->and(glob($this->sb->root.'/shared/logs/*'))->toHaveCount(2);
    });

    it('classifies the application\'s failure marker even when PHP exits zero', function (string $line, string $task, array $expected) {
        $this->sb->release('r1');
        $this->sb->mode('print:0');
        $this->sb->output("ordinary output\n{$line}\nmore output\n");

        $process = $this->sb->run($task, $this->sb->timings());

        expect($process->getExitCode())->toBe(90);
        foreach ($expected as $fragment) {
            expect($process->getOutput())->toContain($fragment);
        }
        expect($this->sb->log($task))->toContain('php_status=0 exit=90');
    })->with([
        'database' => ['@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000', 'queue', ['category=database', 'class=PDOException sqlstate=HY000']],
        'database with a namespaced class' => ['@@PORTAL-CRON-FAILURE@@ v1 category=database class=Illuminate\Database\QueryException sqlstate=42S02', 'queue', ['class=Illuminate\Database\QueryException sqlstate=42S02']],
        'scheduler' => ['@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1', 'schedule', ['category=scheduler', 'class=Exception task_exit=1']],
    ]);

    it('never quotes a malformed marker, and ignores marker text that is not at the start of a line', function () {
        $this->sb->release('r1');
        $this->sb->mode('print:0');

        $this->sb->output("@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000 password=hunter2\n");
        $malformed = $this->sb->run('queue', $this->sb->timings());
        expect($malformed->getExitCode())->toBe(90)->and($malformed->getOutput())->toContain('category=marker-invalid')->not->toContain('hunter2')->not->toContain('PDOException');

        $this->sb->output("see @@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000\nFAIL\n   Running [x] .... FAIL\n");
        $inline = $this->sb->run('queue', $this->sb->timings());
        expect($inline->getExitCode())->toBe(0)->and($inline->getOutput())->toContain('RECOVERED');
    });

    it('ends an unterminated last line of output, so the END record always starts on its own line', function (string $output, ?string $limit, string $quoted) {
        $this->sb->release('r1');
        $this->sb->mode('print:0');
        $this->sb->output($output);

        $this->sb->run('queue', ['PORTAL_CRON_TEST' => '1'] + ($limit === null ? [] : ['PORTAL_CRON_OUTPUT_MAX_BYTES' => $limit]));
        $log = $this->sb->log('queue');

        expect($log)->toContain($quoted."\n".'');
        // Machine-parseable: every line is the wrapper's own record or a quoted line of output, and nothing else.
        $records = 0;
        foreach (explode("\n", rtrim($log, "\n")) as $line) {
            expect($line)->toMatch('/^(\| .*|\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ queue pid=\d+ (START|END|SKIP|ALERT) .*)$/');
            $records += preg_match('/ queue pid=\d+ END status=ok category=ok exit=0 duration_s=\d+ release=r1$/', $line);
        }
        expect($records)->toBe(1)->and($log)->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ queue pid=\d+ END status=ok /m');
    })->with([
        'unterminated last line' => ["first line\nno trailing newline", null, '| no trailing newline'],
        'a single unterminated line' => ['only line', null, '| only line'],
        'unterminated and truncated mid-line' => ["abc\ndefghijklmnop", '8', "| defg\n| [output truncated at 8 bytes]"],
        'terminated last line (unchanged)' => ["one\ntwo\n", null, '| two'],
    ]);

    it('records the start time offset, the release, the task and the duration of every run', function () {
        $this->sb->release('r1');

        $this->sb->run('queue');
        $this->sb->run('schedule');

        expect($this->sb->log('queue'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ queue pid=\d+ START release=r1 slot_offset_s=\d+$/m')
            ->and($this->sb->log('schedule'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ schedule pid=\d+ END status=ok category=ok exit=0 duration_s=\d+ release=r1$/m');
    });
});

describe('release handling', function () {
    it('runs from the physical release behind current, with locks and logs shared across releases', function () {
        $r1 = $this->sb->release('r1');
        $r2 = $this->sb->release('r2');

        $this->sb->run('queue');
        $inode = fileinode($this->sb->lockPath('queue'));
        $this->sb->switchTo('r2');
        $this->sb->run('queue');

        expect($this->sb->invocations()[0])->toStartWith($r1.'/artisan')
            ->and($this->sb->invocations()[1])->toStartWith($r2.'/artisan')
            ->and(fileinode($this->sb->lockPath('queue')))->toBe($inode)
            ->and($this->sb->log('queue'))->toContain('START release=r1')->toContain('START release=r2')
            ->and(glob($this->sb->root.'/shared/run/queue.lock*'))->toHaveCount(1);
    });

    it('keeps a running job on its release when current is switched, holds the shared lock, and lets the next tick use the new release', function () {
        $r1 = $this->sb->release('r1');
        $r2 = $this->sb->release('r2');
        $this->sb->mode('hold');
        $timings = $this->sb->timings(['PORTAL_CRON_QUEUE_TERM' => '30', 'PORTAL_CRON_RETRY_AFTER_FLOOR' => '40']);

        $running = $this->sb->start('queue', $timings);
        expect($this->sb->waitFor(fn () => $this->sb->stubStarted()))->toBeTrue();

        $this->sb->switchTo('r2');

        // The next tick selects r2 but finds the lock (shared across releases) held by the r1 worker.
        $contender = $this->sb->run('queue', $timings);
        expect($contender->getExitCode())->toBe(0)->and($this->sb->invocations())->toHaveCount(1)->and($this->sb->log('queue'))->toContain('SKIP lock held')->toContain('holder_release=r1');

        // The retained previous release is still read lazily by the worker, as PHP autoloads, after the switch.
        $this->sb->releaseStub();
        $running->wait();
        expect($running->getExitCode())->toBe(0)->and(trim((string) file_get_contents($this->sb->stubDir.'/lazy.out')))->toBe('lazy-from-r1')
            ->and($this->sb->invocations()[0])->toStartWith($r1.'/artisan');

        // After the old worker ended, a new invocation uses the new release.
        $this->sb->mode('ok');
        $next = $this->sb->run('queue', $timings);
        expect($next->getExitCode())->toBe(0)->and($this->sb->invocations()[1])->toStartWith($r2.'/artisan');
    });

    it('uses ONE release for the whole invocation when current is switched while the wrapper is starting, whatever PORTAL_CRON_STAGE holds', function (?string $inherited) {
        // Each release's wrapper copy is distinguishable by an argument it passes to PHP, so a script from one release driving the other is visible.
        $this->sb->release('r1', ['--tries=3' => '--tries=7']);
        $r2 = $this->sb->release('r2', ['--tries=3' => '--tries=9']);
        $shim = pcwStartupSwapShim($this->sb);

        $process = $this->sb->run('queue', $inherited === null ? [] : ['PORTAL_CRON_STAGE' => $inherited], path: $shim.':/usr/bin:/bin');

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(is_file($this->sb->dir.'/switched'))->toBeTrue()
            ->and($this->sb->invocations())->toHaveCount(1)   // no re-execution loop: one run, one PHP call
            ->and($this->sb->invocations()[0])->toStartWith($r2.'/artisan')->toContain('--tries=9')->not->toContain('--tries=7')
            ->and($this->sb->log('queue'))->toContain('START release=r2')->not->toContain('release=r1');
    })->with([
        'not set' => [null],
        'the old marker value' => ['2'],
        'an arbitrary value' => ['banana'],
        'empty' => [''],
        'zero' => ['0'],
    ]);

    it('refuses, instead of mixing releases, when the stage marker equals the shell\'s own PID but the script was reached through current', function () {
        $this->sb->release('r1');
        $this->sb->release('r2');

        // `exec` keeps the PID, so this is exactly the coincidence the PID binding cannot exclude by itself: PORTAL_CRON_STAGE == $$ on entry.
        $process = new Process([
            'env', '-i', 'PATH=/usr/bin:/bin', 'PORTAL_ROOT='.$this->sb->root, 'PORTAL_PHP='.$this->sb->stub, 'STUB_DIR='.$this->sb->stubDir,
            '/bin/sh', '-c', 'PORTAL_CRON_STAGE=$$ exec /bin/sh "$0" "$@"', $this->sb->entry(), 'queue',
        ], $this->sb->dir, null, null, 60);
        $process->run();

        expect($process->getExitCode())->toBe(70)
            ->and($process->getErrorOutput())->toContain('not started through its physical path')
            ->and($this->sb->invocations())->toBe([]);

        // The same PID-bound start through the PHYSICAL path is legitimate and runs.
        $physical = new Process([
            'env', '-i', 'PATH=/usr/bin:/bin', 'PORTAL_ROOT='.$this->sb->root, 'PORTAL_PHP='.$this->sb->stub, 'STUB_DIR='.$this->sb->stubDir,
            '/bin/sh', '-c', 'PORTAL_CRON_STAGE=$$ exec /bin/sh "$0" "$@"', $this->sb->root.'/releases/r2/scripts/cron/portal-cron.sh', 'queue',
        ], $this->sb->dir, null, null, 60);
        $physical->run();
        expect($physical->getExitCode())->toBe(0, $physical->getErrorOutput())->and($this->sb->invocations())->toHaveCount(1);
    });

    it('works from a path that contains spaces, including a switch of current', function () {
        $sb = new PortalCronSandbox('a directory with spaces');
        try {
            $r1 = $sb->release('r1');
            $r2 = $sb->release('r2');

            expect($r1)->toContain('a directory with spaces');
            $first = $sb->run('queue', $sb->timings());
            $sb->switchTo('r2');
            $second = $sb->run('schedule', $sb->timings());

            expect($first->getExitCode())->toBe(0, $first->getErrorOutput())->and($second->getExitCode())->toBe(0, $second->getErrorOutput())
                ->and($sb->invocations())->toHaveCount(2)
                ->and($sb->invocations()[0])->toStartWith($r1.'/artisan queue:work')
                ->and($sb->invocations()[1])->toStartWith($r2.'/artisan schedule:run')
                ->and($sb->log('queue'))->toContain('START release=r1');
        } finally {
            $sb->destroy();
        }
    });

});
