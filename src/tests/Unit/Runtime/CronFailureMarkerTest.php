<?php

use App\Support\Runtime\CronFailureMarker;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * R0.9 WP3-B1: the failure marker the cron wrapper reads (App\Support\Runtime\CronFailureMarker). What it prints, what it must never print, and when it stays
 * silent. The real worker and scheduler behind the wrapper are in tests/Unit/Cron/PortalCronApplicationTest.php. docs/architecture/cron-execution.md.
 */

beforeEach(function () {
    CronFailureMarker::reset();
    $this->stream = fopen('php://memory', 'w+');
    CronFailureMarker::writeTo($this->stream);
    putenv(CronFailureMarker::ENVIRONMENT_FLAG);
    config(['logging.default' => 'null']);
});

afterEach(function () {
    putenv(CronFailureMarker::ENVIRONMENT_FLAG);
    CronFailureMarker::reset();
});

/** Everything written to the marker stream so far. */
function cfmWritten(): string
{
    rewind(test()->stream);

    return (string) stream_get_contents(test()->stream);
}

/** A fresh process: forget the remembered command and every emitted line, keeping the capture stream. */
function cfmRestart(): void
{
    CronFailureMarker::reset();
    CronFailureMarker::writeTo(test()->stream);
}

/** Run as the wrapper does: the flag set, and the named command started (through the application's own event listeners). */
function cfmUnderWrapper(string $command): void
{
    putenv(CronFailureMarker::ENVIRONMENT_FLAG.'=1');
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
}

describe('what it prints', function () {
    it('carries only a category, a class and a SQLSTATE, for a connection failure whose message holds the host', function () {
        $exception = new PDOException('SQLSTATE[HY000] [2002] Connection refused (host db.internal.example, user portal, password hunter2)', 2002);

        expect(CronFailureMarker::databaseLine($exception))->toBe('@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000');
    });

    it('never carries the SQL, the bindings or the connection of a query exception', function () {
        $previous = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'secretdb.users' doesn't exist");
        $previous->errorInfo = ['42S02', 1146, "Table 'secretdb.users' doesn't exist"];
        $exception = new QueryException('mysql', 'select * from users where password = ?', ['hunter2'], $previous);

        $line = CronFailureMarker::databaseLine($exception);

        expect($line)->toBe('@@PORTAL-CRON-FAILURE@@ v1 category=database class=Illuminate\Database\QueryException sqlstate=42S02');
        foreach (['hunter2', 'secretdb', 'users', 'select', 'mysql', "doesn't"] as $secret) {
            expect($line)->not->toContain($secret);
        }
    });

    it('finds a database exception wrapped inside another exception, and ignores everything else', function () {
        $wrapped = new RuntimeException('job failed', 0, new PDOException('SQLSTATE[HY000] [1045] Access denied for user', 1045));

        expect(CronFailureMarker::databaseLine($wrapped))->toContain('class=PDOException sqlstate=HY000')
            ->and(CronFailureMarker::databaseLine(new RuntimeException('SQLSTATE[HY000] looks like one but is not a database exception')))->toBeNull()
            ->and(CronFailureMarker::databaseLine(new InvalidArgumentException('Database file at path [/x] does not exist')))->toBeNull();
    });

    it('prints "none" when no SQLSTATE can be found, and "unknown" for a class name it cannot vouch for', function () {
        $anonymous = new class('no state here', 0) extends PDOException {};

        expect(CronFailureMarker::databaseLine($anonymous))->toBe('@@PORTAL-CRON-FAILURE@@ v1 category=database class=unknown sqlstate=none');
    });

    it('describes a failed scheduled task by the exception class and the task\'s exit status only', function () {
        expect(CronFailureMarker::schedulerLine(new Exception('Scheduled command [php artisan x --token=hunter2] failed with exit code [1].'), 1))
            ->toBe('@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1')
            ->and(CronFailureMarker::schedulerLine(new Exception('x'), null))->toEndWith('exit=none')
            ->and(CronFailureMarker::schedulerLine(new Exception('x'), 4096))->toEndWith('exit=none')
            ->and(CronFailureMarker::schedulerLine(new Exception('x'), -1))->toEndWith('exit=none');
    });

    it('matches the pattern the wrapper accepts', function () {
        $lines = [
            CronFailureMarker::databaseLine(new PDOException('SQLSTATE[08006] x', 7)),
            CronFailureMarker::schedulerLine(new Exception('x'), 255),
        ];

        expect($lines[0])->toMatch('/^@@PORTAL-CRON-FAILURE@@ v1 category=database class=[A-Za-z_][A-Za-z0-9_\\\\]{0,119} sqlstate=([0-9A-Z]{5}|none)$/')
            ->and($lines[1])->toMatch('/^@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=[A-Za-z_][A-Za-z0-9_\\\\]{0,119} exit=([0-9]{1,3}|none)$/');
    });
});

describe('when it speaks', function () {
    it('is silent unless the wrapper set the flag', function () {
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe('');
    });

    it('is silent for every command but queue:work and schedule:run, even with the flag set', function (string $command) {
        cfmUnderWrapper($command);

        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe('');
    })->with(['migrate', 'queue:listen', 'schedule:work', 'tickets:auto-close', 'runtime:preflight', 'queue:retry']);

    it('speaks for queue:work and schedule:run, on its own line', function (string $command) {
        cfmUnderWrapper($command);

        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe("\n@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000\n");
    })->with(['queue:work', 'schedule:run']);

    it('reports a failed scheduled task only from schedule:run', function () {
        $event = new ScheduledEvent(app(CacheEventMutex::class), 'php artisan x');
        $event->exitCode = 1;

        cfmUnderWrapper('queue:work');
        app('events')->dispatch(new ScheduledTaskFailed($event, new Exception('failed')));
        expect(cfmWritten())->toBe('');

        cfmRestart();
        cfmUnderWrapper('schedule:run');
        app('events')->dispatch(new ScheduledTaskFailed($event, new Exception('failed')));
        expect(cfmWritten())->toBe("\n@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1\n");
    });

    it('does not report a non-database exception', function () {
        cfmUnderWrapper('queue:work');

        CronFailureMarker::reported(new RuntimeException('something else'));

        expect(cfmWritten())->toBe('');
    });

    it('writes each distinct failure once, and no more than five', function () {
        cfmUnderWrapper('queue:work');

        for ($i = 0; $i < 4; $i++) {
            CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));
        }
        expect(substr_count(cfmWritten(), '@@PORTAL-CRON-FAILURE@@'))->toBe(1);

        foreach (['10000', '20000', '30000', '40000', '50000', '60000', '70000'] as $state) {
            CronFailureMarker::reported(new PDOException("SQLSTATE[{$state}] x", 1));
        }
        expect(substr_count(cfmWritten(), '@@PORTAL-CRON-FAILURE@@'))->toBe(5);
    });

    it('is wired into the application\'s exception reporting and still lets the exception be logged', function () {
        Log::spy();
        cfmUnderWrapper('queue:work');

        app(ExceptionHandler::class)->report(new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002));

        expect(cfmWritten())->toBe("\n@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000\n");
        Log::shouldHaveReceived('error')->once();
    });
});

describe('nested commands', function () {
    it('keeps queue:work\'s identity when a nested Artisan command starts, so a later database failure still emits', function () {
        cfmUnderWrapper('queue:work');
        event(new CommandStarting('tickets:auto-close', new ArrayInput([]), new NullOutput));   // started through Artisan::call() by the outer command

        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe("\n@@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000\n");
    });

    it('keeps schedule:run\'s identity when a nested Artisan command starts, so a later task failure still emits', function () {
        $event = new ScheduledEvent(app(CacheEventMutex::class), 'php artisan x');
        $event->exitCode = 1;

        cfmUnderWrapper('schedule:run');
        event(new CommandStarting('tickets:auto-close', new ArrayInput([]), new NullOutput));
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));            // even a nested command with a guarded name changes nothing
        app('events')->dispatch(new ScheduledTaskFailed($event, new Exception('failed')));

        expect(cfmWritten())->toBe("\n@@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1\n");
    });

    it('does not lend a guarded identity to an unrelated outer command that starts a guarded one', function (string $nested) {
        cfmUnderWrapper('migrate');
        event(new CommandStarting($nested, new ArrayInput([]), new NullOutput));

        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe('');
    })->with(['queue:work', 'schedule:run']);

    it('keeps excluding unrelated top-level commands, and a scheduler event outside schedule:run', function () {
        cfmUnderWrapper('tickets:auto-close');
        CronFailureMarker::reported(new PDOException('SQLSTATE[HY000] [2002] x', 2002));

        expect(cfmWritten())->toBe('');
    });
});
