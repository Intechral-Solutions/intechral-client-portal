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
