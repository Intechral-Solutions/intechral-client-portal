<?php

use App\Support\Rehearsal\RehearsalLock;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1B (J-4): one lock for every path that can reset the rehearsal databases. It is a database-server lock,
 * not a file under storage_path(), because the Pest tests move storage_path() to a throw-away directory: a test
 * run and `./dev rehearse` used to hold different files while resetting the same databases.
 */

beforeEach(fn () => RehearsalSandbox::use());

/** Whether a brand-new database session (standing in for another process) can take the lock right now. */
function lockIsFreeForAnotherSession(): bool
{
    $session = RehearsalSandbox::session();

    return (int) $session->query("select get_lock('".RehearsalLock::NAME."', 0)")->fetchColumn() === 1;
}

it('is held by this process once a rehearsal test starts, so another session is refused', function () {
    expect(RehearsalLock::held())->toBeTrue()
        ->and(lockIsFreeForAnotherSession())->toBeFalse();
});

it('is the same lock whatever storage path the process uses, which is what the old file lock was not', function () {
    $other = RehearsalSandbox::root().'/other-storage';
    mkdir($other, 0700);

    RehearsalSandbox::withLockHeldElsewhere(function () use ($other) {
        // A process whose storage_path() differs from the holder's: it is refused all the same.
        $this->app->useStoragePath($other);

        [$exit, $output] = [Artisan::call('rehearsal:clean'), Artisan::output()];

        expect($exit)->toBe(2)
            ->and($output)->toContain('another rehearsal is running');
    });

    expect(file_exists($other.'/rehearsal/.lock'))->toBeFalse();
});

it('is reentrant within one process, and giving back a level does not release the process lock', function () {
    RehearsalLock::acquire();
    RehearsalLock::release();

    expect(RehearsalLock::hold(fn () => RehearsalLock::held()))->toBeTrue()
        ->and(RehearsalLock::held())->toBeTrue()
        ->and(lockIsFreeForAnotherSession())->toBeFalse();
});

it('is released by the runner when it is the only holder, whether the run passes, fails or is refused', function () {
    RehearsalLock::releaseAll();

    try {
        expect(RehearsalLock::held())->toBeFalse()
            ->and(lockIsFreeForAnotherSession())->toBeTrue();

        // Refused before it starts: never held.
        expect(Artisan::call('rehearsal:run', ['--candidate' => ['not-a-migration']]))->toBe(2)
            ->and(lockIsFreeForAnotherSession())->toBeTrue();

        // A run that fails after it began: released all the same.
        $path = RehearsalSandbox::databasePath(['2026_10_12_000001_rehearsal_probe' => ['up' => "throw new RuntimeException('boom');"]]);
        $this->app->useDatabasePath($path);

        expect(Artisan::call('rehearsal:run', ['--candidate' => ['2026_10_12_000001_rehearsal_probe'], '--no-restore' => true]))->toBe(1)
            ->and(RehearsalLock::held())->toBeFalse()
            ->and(lockIsFreeForAnotherSession())->toBeTrue();

        // A cleanup that passes: released.
        expect(Artisan::call('rehearsal:clean'))->toBe(0)
            ->and(RehearsalLock::held())->toBeFalse()
            ->and(lockIsFreeForAnotherSession())->toBeTrue();
    } finally {
        RehearsalSandbox::use();
    }
});

it('is released by the server when the holding process is killed, so a crash cannot leave a stale lock', function () {
    RehearsalLock::releaseAll();

    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        App\Support\Rehearsal\RehearsalLock::acquire();
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        sleep(120);
        PHP;

    $child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

    try {
        stream_set_timeout($pipes[1], 30);
        stream_set_blocking($pipes[2], false);
        $line = trim((string) fgets($pipes[1]));

        expect($line)->toBe('locked', 'the child process did not take the lock: '.(string) stream_get_contents($pipes[2]));
        expect(lockIsFreeForAnotherSession())->toBeFalse();

        proc_terminate($child, 9);   // SIGKILL: no shutdown handler, no release_lock
        proc_close($child);
        $child = null;

        $free = false;
        for ($i = 0; $i < 50 && ! $free; $i++) {
            $free = lockIsFreeForAnotherSession();
            $free || usleep(200_000);
        }

        expect($free)->toBeTrue('the lock was still held after its holder was killed');
    } finally {
        if (is_resource($child)) {
            proc_terminate($child, 9);
            proc_close($child);
        }
        RehearsalSandbox::use();
    }
});

it('fails closed when the lock is lost while it is held', function () {
    $session = RehearsalSandbox::session();
    $holder = (int) $session->query("select is_used_lock('".RehearsalLock::NAME."')")->fetchColumn();
    expect($holder)->toBeGreaterThan(0);

    $session->exec("kill {$holder}");   // the holder's database session ends: the server frees the lock

    try {
        expect(RehearsalLock::held())->toBeFalse()
            ->and(fn () => RehearsalLock::acquire())->toThrow(UnsafeRehearsalTarget::class, 'lock was lost');
    } finally {
        RehearsalLock::releaseAll();
        RehearsalSandbox::use();
    }

    expect(RehearsalLock::held())->toBeTrue();
});

it('refuses to open a connection for a target the configuration rules out', function () {
    RehearsalLock::releaseAll();
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    try {
        expect(fn () => RehearsalLock::acquire())->toThrow(UnsafeRehearsalTarget::class, 'rehearsal runs only in')
            ->and(RehearsalLock::held())->toBeFalse();
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
        config(['app.env' => 'testing']);
        RehearsalSandbox::use();
    }
});

it('names the session that holds the lock when it refuses', function () {
    RehearsalSandbox::withLockHeldElsewhere(function () {
        expect(fn () => RehearsalLock::acquire())->toThrow(UnsafeRehearsalTarget::class, 'held by database session');
    });
});

it('uses a database lock, not a file, for the rehearsal base directory', function () {
    expect(file_exists(RehearsalTarget::baseDirectory().'/.lock'))->toBeFalse();
});
