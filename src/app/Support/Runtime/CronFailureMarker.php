<?php

namespace App\Support\Runtime;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use PDOException;
use Throwable;

/**
 * The operational-failure marker the cron wrapper reads (R0.9 WP3-B1). docs/architecture/cron-execution.md.
 *
 * Two failures leave Laravel's own exit status at zero, so the wrapper cannot see them from the status alone:
 *
 *  - `queue:work` REPORTS a database failure raised while it pops a job (connection refused, access denied, unknown database ...) and carries on; with
 *    `--stop-when-empty` it then finds nothing, and exits 0. The report goes to the application log only. (Verified against the installed Laravel with a real worker.)
 *  - `schedule:run` exits 0 when a scheduled task fails, and in the installed Laravel (13.36) it does not even print `FAIL`: the task component matches the
 *    `TaskResult` integers while `schedule:run` hands it a boolean, so a failed task is rendered `DONE`. The event it dispatches, {@see ScheduledTaskFailed},
 *    is the one reliable signal.
 *
 * Under the wrapper this class writes ONE fixed-format line per distinct failure to STDERR, which the wrapper captures:
 *
 *     @@PORTAL-CRON-FAILURE@@ v1 category=database class=PDOException sqlstate=HY000
 *
 *     @@PORTAL-CRON-FAILURE@@ v1 category=scheduler class=Exception exit=1
 *
 * It carries a category, an exception CLASS name and a SQLSTATE or an exit status, each validated against a fixed pattern. Never the exception message
 * (a query exception's message carries SQL and bound values; a connection exception's carries the host), a connection string, a credential or a path.
 * The wrapper re-validates every field before it puts any of it in a notification.
 *
 * Deliberately narrow. It is silent unless BOTH hold: the wrapper set {@see self::ENVIRONMENT_FLAG}, and the running command is one of {@see self::COMMANDS}
 * (the scheduled task's own child process, an operator's `queue:work` typed by hand, a web request and every other command emit nothing). It does not change
 * what is logged, retried or failed, and the exception continues through Laravel's normal reporting.
 */
final class CronFailureMarker
{
    public const PREFIX = '@@PORTAL-CRON-FAILURE@@';

    public const VERSION = 'v1';

    /** Set to `1` by the wrapper, for the PHP process it launches and nothing else. */
    public const ENVIRONMENT_FLAG = 'PORTAL_CRON_MARKER';

    /** The only commands that emit. */
    public const COMMANDS = ['queue:work', 'schedule:run'];

    /** A worker that cannot reach its database reports the same failure on every loop; a few distinct lines are all the wrapper needs. */
    private const MAXIMUM_LINES = 5;

    private static ?string $command = null;

    /** @var array<string, true> */
    private static array $emitted = [];

    /** @var resource|null */
    private static $stream = null;

    /**
     * Remember which command is running. {@see CommandStarting} is dispatched before EVERY command, including one a running command starts through
     * `Artisan::call()` (a job, a scheduled callback), so only the first is the process's own command: a nested command must not replace the identity of the
     * `queue:work` or `schedule:run` that is running, nor lend a `queue:work` identity to an unrelated outer command. A process runs one top-level command.
     */
    public function handle(CommandStarting $event): void
    {
        self::$command ??= $event->command;
    }

    public function scheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        if (! self::active('schedule:run')) {
            return;
        }

        self::emit(self::schedulerLine($event->exception, $event->task->exitCode ?? null));
    }

    /** Called for every exception Laravel reports; does nothing unless the marker is active, so it is cheap and never throws. */
    public static function reported(Throwable $exception): void
    {
        try {
            if (! self::active('queue:work', 'schedule:run')) {
                return;
            }

            $line = self::databaseLine($exception);

            if ($line !== null) {
                self::emit($line);
            }
        } catch (Throwable) {
            // Reporting must never be what breaks a worker or a scheduler.
        }
    }

    private static function active(string ...$commands): bool
    {
        return getenv(self::ENVIRONMENT_FLAG) === '1' && in_array(self::$command, $commands, true);
    }

    /** The marker for a database failure, or null when the exception (or anything it wraps) is not one. */
    public static function databaseLine(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                return sprintf(
                    '%s %s category=database class=%s sqlstate=%s',
                    self::PREFIX,
                    self::VERSION,
                    self::safeClass($current),
                    self::sqlState($current),
                );
            }
        }

        return null;
    }

    public static function schedulerLine(Throwable $exception, mixed $exitCode): string
    {
        return sprintf(
            '%s %s category=scheduler class=%s exit=%s',
            self::PREFIX,
            self::VERSION,
            self::safeClass($exception),
            is_int($exitCode) && $exitCode >= 0 && $exitCode <= 255 ? (string) $exitCode : 'none',
        );
    }

    /** A class name made only of identifier characters and namespace separators; anything else (an anonymous class) is `unknown`. */
    private static function safeClass(Throwable $exception): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,119}$/', $exception::class) === 1 ? $exception::class : 'unknown';
    }

    /** The five-character SQLSTATE, from the exception's own fields; the message is only ever searched for that pattern, never copied. */
    private static function sqlState(PDOException $exception): string
    {
        $candidates = [$exception->getCode(), $exception->errorInfo[0] ?? null];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9A-Z]{5}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        // A connection failure carries the driver's numeric code (2002) as its code; the SQLSTATE is only in the text.
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $exception->getMessage(), $matches) === 1) {
            return $matches[1];
        }

        return 'none';
    }

    private static function emit(string $line): void
    {
        if (isset(self::$emitted[$line]) || count(self::$emitted) >= self::MAXIMUM_LINES) {
            return;
        }

        self::$emitted[$line] = true;

        $stream = self::$stream ?? (defined('STDERR') ? STDERR : null);

        if (is_resource($stream)) {
            // A leading newline keeps the marker at the start of its own line whatever was written before it.
            @fwrite($stream, "\n".$line."\n");
        }
    }

    /** Test seam: the stream the marker is written to (STDERR by default). */
    public static function writeTo(mixed $stream): void
    {
        self::$stream = is_resource($stream) ? $stream : null;
    }

    /** Test seam: forget the running command and every emitted line. */
    public static function reset(): void
    {
        self::$command = null;
        self::$emitted = [];
        self::$stream = null;
    }
}
