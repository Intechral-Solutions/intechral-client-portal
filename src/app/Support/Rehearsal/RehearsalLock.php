<?php

namespace App\Support\Rehearsal;

use PDO;

/**
 * The one lock that keeps two rehearsals off the same databases, whichever path started them: `./dev rehearse`,
 * `rehearsal:clean`, and the Pest tests that drive the same databases.
 *
 * It is a MariaDB named lock (`GET_LOCK`), taken on the database server the rehearsal databases live on. A
 * file lock under `storage/` was not enough: the Pest tests deliberately move `storage_path()` to a throw-away
 * directory, so a test run and a CLI run held different files while resetting the same databases. A server
 * lock is the same for every process that can reach those databases.
 *
 *  - Not blocking: a second holder is refused at once (exit 2), never queued.
 *  - Bound to a database session, not to a file or a flag: when the holding process ends for any reason,
 *    including a kill, the server closes the session and releases the lock. A stale lock cannot survive its
 *    holder and so cannot silently disable the protection or block it for ever.
 *  - Reentrant within one process: the runner takes it while the test sandbox already holds it. Each
 *    {@see acquire()} must be matched by a {@see release()}; {@see releaseAll()} drops it outright.
 *  - Held on its own PDO session, owned by this class and not by the framework's connection manager. The
 *    harness purges and reopens its target connections freely, and a purge would close the session and so drop
 *    the lock without a word; and a test run builds a new application (and so a new connection manager) per
 *    test while the process, and so the lock, lives on.
 *  - Fail closed: holding is re-verified on every re-entry, and a lock that has been lost is an error.
 *
 * The lock connection is built from the same fixed configuration as the primary rehearsal target, and the
 * configuration is checked ({@see TargetGuard::assertConfiguration()}) before it is opened.
 */
final class RehearsalLock
{
    /** Server-wide name. It is only ever used by this harness. */
    public const NAME = 'intechral_client_portal_rehearsal';

    private static int $depth = 0;

    private static ?PDO $session = null;

    /**
     * Take the lock, or refuse because another session holds it.
     *
     * @throws UnsafeRehearsalTarget
     */
    public static function acquire(): void
    {
        if (self::$depth > 0) {
            if (! self::stillHeld()) {
                self::$depth = 0;
                self::close();

                throw new UnsafeRehearsalTarget(['the rehearsal lock was lost while this process held it (its database session ended); stopping rather than continuing unprotected']);
            }

            self::$depth++;

            return;
        }

        $primary = RehearsalTarget::primary();
        TargetGuard::assertConfiguration($primary);
        $c = $primary->connectionConfig();

        try {
            self::$session = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
                (string) $c['username'],
                (string) $c['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $got = self::scalar('select get_lock(?, 0)');
        } catch (\Throwable $e) {
            self::close();

            throw new UnsafeRehearsalTarget(['cannot take the rehearsal lock ('.strtok($e->getMessage(), "\n").'). Provision the rehearsal databases first: ./dev rehearse:provision']);
        }

        if ((int) $got !== 1) {
            $holder = null;
            try {
                $holder = self::scalar('select is_used_lock(?)');
            } catch (\Throwable) {
            }
            self::close();

            throw new UnsafeRehearsalTarget([
                'another rehearsal is running (the database lock "'.self::NAME.'" is held'.($holder ? " by database session {$holder}" : '').'); '
                .'two would reset the same databases under each other',
            ]);
        }

        self::$depth = 1;
    }

    /** Give back one level; the last one releases the lock. */
    public static function release(): void
    {
        if (self::$depth === 0) {
            return;
        }

        if (--self::$depth === 0) {
            self::free();
        }
    }

    /** Drop the lock outright, however many times it was taken in this process. */
    public static function releaseAll(): void
    {
        if (self::$depth > 0) {
            self::$depth = 0;
            self::free();
        }
    }

    public static function held(): bool
    {
        return self::$depth > 0 && self::stillHeld();
    }

    /**
     * Run $work holding the lock.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws UnsafeRehearsalTarget
     */
    public static function hold(callable $work)
    {
        self::acquire();

        try {
            return $work();
        } finally {
            self::release();
        }
    }

    private static function stillHeld(): bool
    {
        try {
            return self::$session !== null && (int) self::scalar('select is_used_lock(?) = connection_id()') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function free(): void
    {
        try {
            self::scalar('select release_lock(?)');
        } catch (\Throwable) {
            // The session is gone, which releases the lock on the server. Nothing more to do.
        } finally {
            self::close();
        }
    }

    /** Closing the session releases anything it still holds. */
    private static function close(): void
    {
        self::$session = null;
    }

    /** Run a one-parameter query that takes the lock name, on the lock's own session. */
    private static function scalar(string $sql): mixed
    {
        $statement = self::$session?->prepare($sql);

        if ($statement === null || $statement === false) {
            throw new \RuntimeException('no rehearsal lock session');
        }

        $statement->execute([self::NAME]);

        return $statement->fetchColumn();
    }
}
