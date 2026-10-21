<?php

namespace App\Support\Runtime;

use App\Jobs\RecordRuntimeObservation;
use App\Support\Database\DatabaseProfile;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The challenge and observation rows of the cross-context runtime check (R0.9 WP3-A, slice A2), and how they are written and read.
 *
 * Transport: the application's existing database cache table, written and read RAW on the default connection's primary, exactly as
 * {@see PermissionCacheEpoch} does. No new table, file, Redis or service. The keys are FIXED, under
 * `portal:runtime-context:`, and deliberately not under `cache.prefix`: a context with another prefix must show up as a MISMATCH on
 * `cache.prefix`, not as a mysteriously missing observation. (A context with another cache TABLE writes to that table and so is missing: both
 * fail the check.) The permission epoch row is never read, created or advanced here, and nothing here flushes the cache.
 *
 *  - `portal:runtime-context:challenge`                 the one current challenge (id, nonce, issued_at, expires_at), JSON
 *  - `portal:runtime-context:obs:<id>:<context>`        one observation per challenge and context, JSON ({@see RuntimeSnapshot})
 *  - `portal:runtime-context:conflict:<id>:<context>`   present when one context reported two different results for the challenge
 *
 * Validity is LOGICAL: a challenge is valid for {@see self::TTL} seconds, enforced from the `issued_at` and `expires_at` stored inside it. Every
 * row's own `expiration` column is that moment plus {@see self::GRACE}, but that does not make the database delete anything: Laravel's database
 * cache store removes an expired row only when that very key is read THROUGH the store (its random pruning covers the lock table only), and
 * these rows are read and written raw. Nothing therefore prunes them automatically. An expired challenge stays physically present and keeps
 * being reported as expired until the next issuance deletes the whole namespace (or the cache is cleared); the namespace stays small, one
 * challenge row and at most four observations (plus abnormal conflict markers), because each issuance replaces the previous ones. No decision
 * here depends on a row having been removed. Issuing a new challenge removes every row of the namespace, and only of the namespace.
 *
 * An observation is written once per challenge and context (INSERT IGNORE). A later identical result changes nothing; a later DIFFERENT
 * result (for example a second PHP worker pool running another release or configuration) leaves a conflict marker, so the outcome never
 * depends on which worker answered last.
 */
final class RuntimeChallenges
{
    /** Seconds a challenge stays valid (owner ruling: 30 minutes). */
    public const TTL = 1800;

    /** Seconds past its validity that a row is kept, so an expired challenge is diagnosed as expired. */
    private const GRACE = 3600;

    /** The execution contexts a verification requires, in the order they are reported. */
    public const CONTEXTS = ['cli', 'web', 'queue', 'scheduler'];

    private const NAMESPACE = 'portal:runtime-context:';

    private const MAX_ROW = 8192;

    /**
     * Create a new challenge, record the CLI's own observation and queue exactly one observation job, all in one transaction: either all
     * three exist afterwards or none does. Rows of any earlier challenge are removed first.
     *
     * @return array{id: string, nonce: string, issued_at: int, expires_at: int}
     *
     * @throws RuntimeException when the challenge cannot be fully created (the cause is not carried: it can hold SQL and hosts)
     */
    public static function issue(): array
    {
        $now = now()->getTimestamp();
        $challenge = [
            'id' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(32)),
            'issued_at' => $now,
            'expires_at' => $now + self::TTL,
        ];

        try {
            DB::transaction(function () use ($challenge) {
                $db = self::db();
                $table = $db->table(self::table());

                foreach (['obs', 'conflict'] as $kind) {
                    $db->table(self::table())->where('key', 'like', self::NAMESPACE.$kind.':%')->delete();
                }

                $table->upsert([self::row(self::NAMESPACE.'challenge', json_encode($challenge, JSON_THROW_ON_ERROR), $challenge['expires_at'])], ['key'], ['value', 'expiration']);

                self::record($challenge, 'cli');

                app('queue')->push(new RecordRuntimeObservation($challenge['id']));
            });
        } catch (Throwable $e) {
            throw new RuntimeException('the challenge could not be created: '.DatabaseProfile::describe($e));
        }

        return $challenge;
    }

    /**
     * The current challenge and its state: `none`, `invalid` (present but not a well-formed challenge), `expired` or `active`.
     *
     * @return array{state: string, challenge: array{id: string, nonce: string, issued_at: int, expires_at: int}|null}
     */
    public static function current(): array
    {
        $raw = self::read(self::NAMESPACE.'challenge');

        if ($raw === null) {
            return ['state' => 'none', 'challenge' => null];
        }

        $value = self::decode($raw);

        if (! is_array($value)
            || ! is_string($value['id'] ?? null) || preg_match('/^[0-9a-f]{32}$/', $value['id']) !== 1
            || ! is_string($value['nonce'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $value['nonce']) !== 1
            || ! is_int($value['issued_at'] ?? null) || ! is_int($value['expires_at'] ?? null) || $value['expires_at'] <= $value['issued_at']) {
            return ['state' => 'invalid', 'challenge' => null];
        }

        $challenge = ['id' => $value['id'], 'nonce' => $value['nonce'], 'issued_at' => $value['issued_at'], 'expires_at' => $value['expires_at']];

        return ['state' => now()->getTimestamp() <= $challenge['expires_at'] ? 'active' : 'expired', 'challenge' => $challenge];
    }

    /**
     * Record this process's observation for the current challenge, if there is an active one (and, when `$only` is given, if it is that one:
     * a queued job for a superseded challenge records nothing). Does nothing else: with no active challenge it is one SELECT.
     *
     * @return bool whether an observation was recorded or was already recorded with the same result
     */
    public static function observe(string $context, ?string $only = null): bool
    {
        ['state' => $state, 'challenge' => $challenge] = self::current();

        if ($state !== 'active' || ($only !== null && $challenge['id'] !== $only)) {
            return false;
        }

        self::record($challenge, $context);

        return true;
    }

    /** {@see self::observe()} for callers that must never fail because of it (a health check, a scheduled callback): any failure is swallowed. */
    public static function observeQuietly(string $context, ?string $only = null): void
    {
        try {
            self::observe($context, $only);
        } catch (Throwable) {
            // The verifier reports a missing observation; this process has nothing safe to add.
        }
    }

    /**
     * @param  array{id: string, nonce: string, issued_at: int, expires_at: int}  $challenge
     */
    public static function record(array $challenge, string $context): void
    {
        $snapshot = RuntimeSnapshot::capture($challenge, $context);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $expires = $challenge['expires_at'];
        $table = self::db()->table(self::table());

        if ($table->insertOrIgnore(self::row(self::observationKey($challenge['id'], $context), $json, $expires)) > 0) {
            return;
        }

        $existing = RuntimeSnapshot::wellFormed(self::decode((string) self::read(self::observationKey($challenge['id'], $context))), $context);

        if ($existing === null || RuntimeSnapshot::comparable($existing) !== RuntimeSnapshot::comparable($snapshot)) {
            self::db()->table(self::table())->insertOrIgnore(self::row(self::NAMESPACE.'conflict:'.$challenge['id'].':'.$context, '1', $expires));
        }
    }

    /** The stored observation of a context for a challenge, as raw text (null when none). */
    public static function observationRow(string $id, string $context): ?string
    {
        return self::read(self::observationKey($id, $context));
    }

    public static function hasConflict(string $id, string $context): bool
    {
        return self::read(self::NAMESPACE.'conflict:'.$id.':'.$context) !== null;
    }

    /** Remove the whole namespace (challenge, observations, conflicts) and nothing else. For tests and tooling. */
    public static function clear(): void
    {
        self::db()->table(self::table())->where('key', 'like', self::NAMESPACE.'%')->delete();
    }

    public static function decode(string $raw): mixed
    {
        if (strlen($raw) > self::MAX_ROW) {
            return null;
        }

        try {
            return json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    public static function observationKey(string $id, string $context): string
    {
        return self::NAMESPACE.'obs:'.$id.':'.$context;
    }

    public static function table(): string
    {
        $table = config('cache.stores.database.table');

        return is_string($table) && $table !== '' ? $table : 'cache';
    }

    private static function read(string $key): ?string
    {
        $value = self::db()->table(self::table())->useWritePdo()->where('key', $key)->value('value');

        return is_string($value) ? $value : null;
    }

    /** @return array{key: string, value: string, expiration: int} */
    private static function row(string $key, string $value, int $validUntil): array
    {
        return ['key' => $key, 'value' => $value, 'expiration' => $validUntil + self::GRACE];
    }

    private static function db(): Connection
    {
        return DB::connection();
    }
}
