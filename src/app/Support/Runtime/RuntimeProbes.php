<?php

namespace App\Support\Runtime;

use App\Support\Database\DatabaseProfile;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Connection;
use Throwable;

/**
 * The live half of `runtime:preflight` (R0.9 WP3-A, slice A1): what configuration alone cannot prove.
 *
 *  - the tables the profile needs exist (they are migrated, not created here);
 *  - the application's cache store and {@see PermissionCacheEpoch} reach the SAME rows: a value written through the cache store is
 *    read back by the epoch's own path (the default connection's primary, the epoch's table, the epoch's key prefix);
 *  - the database cache lock really excludes a second holder, through the `cache_locks` table.
 *
 * Every write is narrowly scoped and disposable: one `cache` row (60 s) and one `cache_locks` row (15 s), each with a random key under
 * the cache prefix, removed in a `finally`; nothing else is created, and the permission epoch row itself is never read-created or advanced.
 * A failure is reported as the exception class and SQLSTATE only, never its message (which carries the host, database and SQL).
 */
final class RuntimeProbes
{
    /** The name {@see PermissionCacheEpoch} stores its token under; pinned against `PermissionCacheEpoch::key()` by a test. */
    private const EPOCH_NAME = 'spatie.permission.epoch';

    /** @return list<string> */
    public static function run(Connection $db): array
    {
        $missing = self::tables($db);

        if ($missing !== []) {
            return $missing;
        }

        return [...self::cacheRoundTrip($db), ...self::lockExclusion($db)];
    }

    /** @return list<string> */
    public static function tables(Connection $db): array
    {
        $tables = [
            'migrations' => config('database.migrations.table', 'migrations'),
            'cache' => config('cache.stores.database.table', 'cache'),
            'cache locks' => config('cache.stores.database.lock_table') ?: 'cache_locks',
            'jobs' => config('queue.connections.database.table', 'jobs'),
            'failed jobs' => config('queue.failed.table', 'failed_jobs'),
            'sessions' => config('session.table', 'sessions'),
        ];

        try {
            $schema = $db->getSchemaBuilder();
            $violations = [];

            foreach ($tables as $purpose => $table) {
                if (! is_string($table) || ! $schema->hasTable($table)) {
                    $violations[] = "the {$purpose} table is missing (run `php artisan migrate`)";
                }
            }

            return $violations;
        } catch (Throwable $e) {
            return ['the required tables could not be checked: '.DatabaseProfile::describe($e)];
        }
    }

    /** @return list<string> */
    public static function cacheRoundTrip(Connection $db): array
    {
        $cache = app('cache')->store();
        $store = $cache->getStore();

        if (! $store instanceof DatabaseStore) {
            return ['the application cache store is not the database store, so the permission epoch and the cache would not share rows'];
        }

        $epoch = app(PermissionCacheEpoch::class);
        $epochKey = $epoch->key();

        if (! str_ends_with($epochKey, self::EPOCH_NAME)) {
            return ['the permission epoch key layout is not the one the preflight understands'];
        }

        $prefix = substr($epochKey, 0, -strlen(self::EPOCH_NAME));
        $violations = [];

        if ($prefix !== (string) config('cache.prefix')) {
            $violations[] = 'the permission epoch key prefix differs from cache.prefix';
        }

        if ($store->getConnection()->getName() !== $db->getName()) {
            $violations[] = 'the application cache store uses a different database connection from the permission epoch';
        }

        $nonce = bin2hex(random_bytes(12));
        $name = 'runtime-preflight:'.$nonce;
        $rawKey = $prefix.$name;

        try {
            if (! $cache->put($name, $nonce, 60)) {
                $violations[] = 'the application cache store did not accept a write';
            } else {
                $raw = $db->table($epoch->tableName())->useWritePdo()->where('key', $rawKey)->value('value');
                $seen = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;

                if ($seen !== $nonce) {
                    $violations[] = 'a value written through the application cache store is not at the row the permission epoch would read (different connection, table or prefix)';
                }

                if ($cache->get($name) !== $nonce) {
                    $violations[] = 'the application cache store did not read back its own write';
                }
            }
        } catch (Throwable $e) {
            $violations[] = 'the cache round trip failed: '.DatabaseProfile::describe($e);
        } finally {
            self::discard($cache, $db, $epoch->tableName(), $name, $rawKey);
        }

        return $violations;
    }

    /** @return list<string> */
    public static function lockExclusion(Connection $db): array
    {
        $cache = app('cache')->store();
        $store = $cache->getStore();

        if (! $store instanceof DatabaseStore) {
            return ['the database cache lock could not be probed because the application cache store is not the database store'];
        }

        $prefix = (string) config('cache.prefix');
        $table = config('cache.stores.database.lock_table') ?: 'cache_locks';
        $name = 'runtime-preflight-lock:'.bin2hex(random_bytes(12));
        $violations = [];
        $first = $second = null;

        try {
            $first = $cache->lock($name, 15);

            if (! $first->get()) {
                return ['the database cache lock could not be acquired'];
            }

            if (! $db->table($table)->useWritePdo()->where('key', $prefix.$name)->exists()) {
                $violations[] = "a held cache lock is not recorded in the {$table} table";
            }

            $second = $cache->lock($name, 15);
            if ($second->get()) {
                $violations[] = 'the database cache lock did not exclude a second holder';
                $second->release();
            }

            $first->release();
            $first = null;

            if ($db->table($table)->useWritePdo()->where('key', $prefix.$name)->exists()) {
                $violations[] = 'a released cache lock is still recorded in the lock table';
            }
        } catch (Throwable $e) {
            $violations[] = 'the cache lock probe failed: '.DatabaseProfile::describe($e);
        } finally {
            try {
                $first?->release();
                $db->table($table)->where('key', $prefix.$name)->delete();
            } catch (Throwable) {
                // The row expires by itself within 15 seconds; there is nothing safer to do here.
            }
        }

        return $violations;
    }

    private static function discard(mixed $cache, Connection $db, string $table, string $name, string $rawKey): void
    {
        try {
            $cache->forget($name);
            $db->table($table)->where('key', $rawKey)->delete();
        } catch (Throwable) {
            // The 60-second row expires by itself.
        }
    }
}
