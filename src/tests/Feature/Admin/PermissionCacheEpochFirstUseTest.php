<?php

use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/*
 * Release 1.0 pre-RC1: the first-use defect of PermissionCacheEpoch::current() (docs/product/release-1.0-defect-permission-cache-epoch-first-use.md).
 *
 * With the epoch row absent, a transaction whose snapshot began BEFORE another transaction created and committed the row could neither insert it
 * (ignored: it exists) nor read it (its snapshot predates it), and current() threw: a 500 on the permission-check path. It now returns a token that
 * exists for that one load only, so the decision is made from the transaction's own snapshot and nothing stale can be filed under a key any other
 * reader trusts.
 *
 * Two REAL connections (the snapshot is a property of one MariaDB session), no mocks of the database: B takes its snapshot, A creates and commits the
 * row, B asks. Like the other epoch tests this ends RefreshDatabase's transaction so the second connection sees committed rows, and removes the row.
 */

function pcefEpochFor(Connection $connection): PermissionCacheEpoch
{
    $manager = Mockery::mock(DatabaseManager::class);
    $manager->shouldReceive('connection')->andReturn($connection);

    return new PermissionCacheEpoch($manager);
}

/** @param  Closure(PermissionCacheEpoch $a, PermissionCacheEpoch $b, Connection $bConnection, string $key): void  $body */
function pcefWorld(Closure $body): void
{
    DB::commit(); // end RefreshDatabase's transaction: A's row must be visible to a session that did not write it

    $default = config('database.connections.'.config('database.default'));
    config(['database.connections.epoch-a' => $default, 'database.connections.epoch-b' => $default]);
    $a = DB::connection('epoch-a');
    $b = DB::connection('epoch-b');
    $epochA = pcefEpochFor($a);
    $key = $epochA->key();
    $table = $epochA->tableName();

    DB::table($table)->where('key', $key)->delete();

    try {
        $body($epochA, pcefEpochFor($b), $b, $key);
    } finally {
        foreach ([$a, $b] as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::table($table)->where('key', $key)->delete();
        DB::purge('epoch-a');
        DB::purge('epoch-b');
    }
}

it('does not throw when another transaction created the epoch after this snapshot began; it returns a token no one else can know', function () {
    pcefWorld(function (PermissionCacheEpoch $a, PermissionCacheEpoch $b, Connection $bConnection, string $key) {
        // B: a transaction whose snapshot is taken while the row is absent (any first read of the request).
        $bConnection->beginTransaction();
        expect($bConnection->table('cache')->where('key', $key)->count())->toBe(0);

        // A: creates the row and commits.
        $committed = $a->advance();
        expect(DB::table('cache')->where('key', $key)->value('value'))->toBe($committed);

        // B asks: the insert is ignored (the row exists) and its snapshot still cannot see it.
        $first = $b->current();
        $second = $b->current();

        expect($first)->toStartWith(PermissionCacheEpoch::EPHEMERAL_PREFIX)
            ->and($first)->not->toBe($committed)
            ->and($second)->toStartWith(PermissionCacheEpoch::EPHEMERAL_PREFIX)
            ->and($second)->not->toBe($first, 'an ephemeral token is never reused')
            // Nothing was written or overwritten: the shared epoch is still the one A committed.
            ->and(DB::table('cache')->where('key', $key)->value('value'))->toBe($committed)
            ->and(DB::table('cache')->where('key', $key)->count())->toBe(1);

        // The ephemeral token is not an alias of the shared one: a reader after B's transaction sees the committed token.
        $bConnection->commit();
        expect($b->current())->toBe($committed);
    });
});

it('still converges on one stored token when the row is created first by the caller itself, inside a transaction', function () {
    pcefWorld(function (PermissionCacheEpoch $a, PermissionCacheEpoch $b, Connection $bConnection, string $key) {
        $bConnection->beginTransaction();
        $token = $b->current(); // creates the row inside B's own transaction: B sees its own write

        expect($token)->toMatch('/^[0-9a-f]{32}$/')->and($token)->not->toStartWith(PermissionCacheEpoch::EPHEMERAL_PREFIX);

        $bConnection->commit();
        expect($a->current())->toBe($token)->and(DB::table('cache')->where('key', $key)->value('value'))->toBe($token);
    });
});

it('is still a failure, not a silent token, outside a transaction when the epoch row exists but cannot be read', function () {
    pcefWorld(function (PermissionCacheEpoch $a, PermissionCacheEpoch $b, Connection $bConnection, string $key) {
        // An existing row with an empty value reads as "no epoch" and cannot be created over (the insert is ignored). Outside a transaction that has no
        // snapshot excuse, so it must stay an error; inside one it is the ephemeral token (the guard is what separates the two).
        DB::table('cache')->insert(['key' => $key, 'value' => '', 'expiration' => time() + 3600]);

        expect(fn () => $b->current())->toThrow(RuntimeException::class, 'The permission cache epoch could not be created.');

        $bConnection->beginTransaction();
        expect($b->current())->toStartWith(PermissionCacheEpoch::EPHEMERAL_PREFIX);
        $bConnection->rollBack();
    });
});
