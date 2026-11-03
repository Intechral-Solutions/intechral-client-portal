<?php

/*
 * Ordered event log shared by the test process and the child processes of the permission-cache security tests
 * (R0.9 WP2, database store).
 *
 * Every actor appends one JSON line per event with an exclusive lock, so the order of the lines IS the order in which the
 * appends happened; the monotonic `t` (hrtime, one clock for all processes on the host) is only informative. An actor logs an
 * event BEFORE it signals (creates a flag file) anything that lets another actor proceed past that event, and logs "resumed"
 * only after it has been released. Causally related events are therefore ordered by construction, and a test asserts the
 * sequence it intends instead of comparing wall-clock timestamps taken by different processes.
 */

function permissionCacheTrace(?string $barrier, string $actor, string $event, array $data = []): void
{
    if ($barrier === null) {
        return;
    }

    $line = json_encode(['t' => hrtime(true), 'actor' => $actor, 'event' => $event] + ($data === [] ? [] : ['data' => $data]), JSON_THROW_ON_ERROR)."\n";
    file_put_contents($barrier.'/trace.log', $line, FILE_APPEND | LOCK_EX);
}

/** @return list<array{t: int, actor: string, event: string, data?: array<string, mixed>}> in append order */
function permissionCacheTraceRead(string $barrier): array
{
    $path = $barrier.'/trace.log';
    if (! is_file($path)) {
        return [];
    }

    return array_map(
        fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents($path)))),
    );
}

/**
 * Make an uncaught exception in a child process a FAILED child, not a quiet success.
 *
 * A script that boots the application with `$app->make(Kernel::class)->bootstrap()` gets Laravel's console exception handler, which renders
 * the exception to STDOUT and then lets the process exit with status 0 (verified: `throw` after bootstrap exits 0). A child that dies this way
 * (for example when a cache write waits out `innodb_lock_wait_timeout`) therefore passes the harness's exit-status check, and the test sees a
 * stdout whose last line is exception text instead of its JSON result: a `JsonException: Syntax error` that names nothing. Call this right
 * after the bootstrap: it replaces that handler, writes the exception class and message to STDERR and exits 1, so the harness reports the
 * child's real failure. It changes no outcome of a child that succeeds.
 */
function permissionCacheChildFailsLoudly(): void
{
    set_exception_handler(function (Throwable $e): never {
        fwrite(STDERR, get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().($e->getPrevious() ? ' (previous: '.get_class($e->getPrevious()).': '.$e->getPrevious()->getMessage().')' : '')."\n");
        exit(1);
    });
}
