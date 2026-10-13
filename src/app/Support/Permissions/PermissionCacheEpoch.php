<?php

namespace App\Support\Permissions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Release 0.9 (R0.9-1E): the database-backed generation ("epoch") that fences the shared permission cache.
 *
 * The permission library caches which roles carry each permission in a shared store (Redis in production). A request
 * that reloads that mapping from the database just before a revocation commits, and stores it after the writer has
 * cleared the cache, would keep the revoked grant alive for the cache lifetime. The cache key therefore carries this
 * epoch, {@see EpochPermissionRegistrar}:
 *
 *  - a reader reads the epoch BEFORE it reads the database, and can only store what it read under that epoch's key;
 *  - every change to the mapping replaces the epoch with a fresh random token, INSIDE the writer's own transaction, so
 *    the new token commits or rolls back together with the change and nobody else can see it before the commit.
 *
 * A reader that began before a commit therefore populates a key that no reader beginning afterward consults. Tokens are
 * 128 random bits and are never reused, so an obsolete key cannot become active again, whatever it still holds. There is
 * no post-commit step to lose: a writer that dies right after COMMIT has already moved the epoch.
 *
 * Storage: one row in the application's existing `cache` table (the Laravel cache table, already migrated; no new
 * schema), on the application's DEFAULT database connection, the same connection the permission models write through,
 * so the write joins the caller's transaction. It is not read through the `database` cache store because that store
 * reads through the read replica when one is configured and can be pointed at another connection (DB_CACHE_CONNECTION);
 * either would break the guarantee. Reads here always use the primary (write) connection.
 *
 * Failure semantics: nothing here swallows an error. If the epoch cannot be read, the permission check throws (it never
 * falls back to a possibly stale cache). If it cannot be advanced inside a transaction, the exception aborts that
 * transaction, so the related grant change does not commit.
 */
final class PermissionCacheEpoch
{
    /** Same horizon as Cache::forever(): the row is never expired by cache maintenance. */
    private const FOREVER_SECONDS = 315360000;

    private const NAME = 'spatie.permission.epoch';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * The active epoch. Creates it on first use; concurrent first uses converge on one token (INSERT IGNORE).
     *
     * @throws RuntimeException when the epoch can be neither read nor created
     */
    public function current(): string
    {
        $token = $this->read();
        if ($token !== null) {
            return $token;
        }

        $this->table()->insertOrIgnore($this->row($this->newToken()));

        return $this->read() ?? throw new RuntimeException('The permission cache epoch could not be created.');
    }

    /**
     * Replace the epoch with a fresh token on the default connection. Inside a transaction, the replacement is part of it.
     */
    public function advance(): string
    {
        $token = $this->newToken();

        $this->table()->upsert([$this->row($token)], ['key'], ['value', 'expiration']);

        return $token;
    }

    /** The `cache` table key, for tests and out-of-band tooling. */
    public function key(): string
    {
        return (string) config('cache.prefix', '').self::NAME;
    }

    public function tableName(): string
    {
        return (string) config('cache.stores.database.table', 'cache');
    }

    private function read(): ?string
    {
        $value = $this->table()->useWritePdo()->where('key', $this->key())->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function table(): Builder
    {
        return $this->connection()->table($this->tableName());
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection();
    }

    /** @return array{key: string, value: string, expiration: int} */
    private function row(string $token): array
    {
        return ['key' => $this->key(), 'value' => $token, 'expiration' => time() + self::FOREVER_SECONDS];
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
