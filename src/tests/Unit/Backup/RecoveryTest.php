<?php

use App\Support\Backup\BackupRefused;
use App\Support\Backup\Recovery;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 recovery restore (backup:restore). It is the code a real recovery runs against the application's own
 * database; here it is pointed at the rehearsal RESTORE database and a throw-away private root, which stand in for an
 * empty installation. It must restore only into empty targets, while the application is down.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    static $made = null;

    if ($made === null || ! is_dir($made['opened'])) {
        RehearsalSandbox::baseline();
        $made = BackupSandbox::create();
        $made['opened'] = BackupSandbox::open($made['directory']);
    }

    $this->made = $made;
    $this->target = RehearsalTarget::restore();
    RehearsalDatabase::clean($this->target);
    $this->private = BackupSandbox::directory('private');
});

afterEach(function () {
    try {
        // A recovered database carries no rehearsal marker, so the harness's guarded clean would (rightly) refuse it.
        // Drop its tables here, after proving the connection is the rehearsal restore database.
        $connection = $this->target->connection();
        expect($connection->selectOne('select database() as d')->d)->toBe('intechral_client_portal_rehearsal_restore');
        $connection->statement('set foreign_key_checks = 0');
        foreach ($connection->select('show tables') as $row) {
            $connection->statement('drop table `'.array_values((array) $row)[0].'`');
        }
        $connection->statement('set foreign_key_checks = 1');
    } finally {
        BackupSandbox::release();
    }
});

function recovery(string $private, bool $maintenance = true, ?string $key = null): Recovery
{
    $target = RehearsalTarget::restore();

    return new Recovery($target->connection(), $target->connectionConfig(), $private, BackupSandbox::encrypter($key ?? BackupSandbox::APP_KEY), $maintenance);
}

it('restores into empty targets, proves the result, invalidates runtime state and reports the queue', function () {
    $report = recovery($this->private)->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $this->made['digest']);

    expect($report['violations'])->toBe([])
        ->and($report['ok'])->toBeTrue()
        ->and($report['facts']['queue'])->toBe(['jobs' => [], 'failed_jobs' => 0])
        ->and($report['facts']['runtime_rows_removed'])->toHaveKeys(['sessions', 'cache', 'cache_locks', 'password_reset_tokens'])
        ->and(is_file($this->private.'/tickets/1/rehearsal-2.txt'))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($this->private.'/tickets/1/rehearsal-2.txt')), -3))->toBe('600');

    // verify-only: still equal; a new session is tolerated (volatile), a changed ticket is not.
    $connection = $this->target->connection();
    $connection->table('sessions')->insert(['id' => 'after-restore', 'payload' => '', 'last_activity' => 0]);
    $verify = recovery($this->private)->verify($this->made['opened'], $this->made['digest']);
    expect($verify['ok'])->toBeTrue()->and($verify['facts']['rows_now']['sessions'])->toBe(1);

    $connection->table('tickets')->where('id', 1)->update(['title' => 'changed']);
    expect(implode(' ', recovery($this->private)->verify($this->made['opened'], $this->made['digest'])['violations']))->toContain('table `tickets` differs');

    // And it will not restore again over what is now there.
    expect(fn () => recovery($this->private)->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $this->made['digest']))
        ->toThrow(BackupRefused::class, 'is not empty');
});

it('blanks every remember-me token, so no pre-recovery browser stays signed in, and still verifies (review M2)', function () {
    $opened = BackupSandbox::copy($this->made['opened']);
    BackupSandbox::tamper($opened, fn (string $sql) => str_replace(
        "0000000000',NULL,'2026-01-15 09:01:00'", "0000000000','remembered-before-the-loss','2026-01-15 09:01:00'", $sql
    ), rederive: true);
    $digest = hash_file('sha256', $opened.'/SHA256SUMS');

    $report = recovery($this->private)->restore($opened, 'intechral_client_portal_rehearsal_restore', $digest);
    $connection = $this->target->connection();

    expect($report['violations'])->toBe([])
        ->and($report['facts']['runtime_rows_removed']['users.remember_token'])->toBe(1)
        ->and($connection->table('users')->whereNotNull('remember_token')->count())->toBe(0)
        ->and(recovery($this->private)->verify($opened, $digest)['violations'])->toBe([]);

    // Only that column is excused: any other change to a user still fails the re-check.
    $connection->table('users')->where('id', 1)->update(['name' => 'changed']);
    expect(implode(' ', recovery($this->private)->verify($opened, $digest)['violations']))->toContain('table `users` differs');
});

it('refuses a restore or a re-check without the recorded digest (review M1)', function (string $digest) {
    expect(fn () => recovery($this->private)->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $digest))
        ->toThrow(BackupRefused::class, '--expect-digest is required')
        ->and(fn () => recovery($this->private)->verify($this->made['opened'], $digest))
        ->toThrow(BackupRefused::class, '--expect-digest is required')
        ->and($this->target->connection()->select('show tables'))->toBe([]);
})->with(['empty' => [''], 'malformed' => ['abc123'], 'upper case' => [str_repeat('A', 64)]]);

it('fails, and does not invalidate anything, with the wrong APP_KEY', function () {
    $report = recovery($this->private, key: 'another-key-0123456789abcdefghij')->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $this->made['digest']);

    expect($report['ok'])->toBeFalse()
        ->and(implode(' ', $report['violations']))->toContain('does not decrypt the canary')
        ->and($report['facts'])->not->toHaveKey('runtime_rows_removed');
});

it('refuses, changing nothing, unless every precondition holds', function (Closure $arrange, string $expected) {
    [$recovery, $database] = $arrange($this);

    expect(fn () => $recovery->restore($this->made['opened'], $database, $this->made['digest']))->toThrow(BackupRefused::class, $expected)
        ->and(is_dir($this->private.'/tickets/1'))->toBeFalse();
})->with([
    'not in maintenance mode' => [fn ($t) => [recovery($t->private, maintenance: false), 'intechral_client_portal_rehearsal_restore'], 'not in maintenance mode'],
    'database not confirmed' => [fn ($t) => [recovery($t->private), ''], '--confirm-database'],
    'another database confirmed' => [fn ($t) => [recovery($t->private), 'intechral_client_portal_rehearsal'], '--confirm-database'],
    'database not empty' => [function ($t) {
        RehearsalDatabase::reset($t->target);   // one marker table

        return [recovery($t->private), 'intechral_client_portal_rehearsal_restore'];
    }, 'is not empty'],
    'set does not verify' => [function ($t) {
        $copy = BackupSandbox::copy($t->made['opened']);
        unlink($copy.'/COMPLETE');
        $t->made['opened'] = $copy;

        return [recovery($t->private), 'intechral_client_portal_rehearsal_restore'];
    }, 'did not verify'],
]);

it('refuses, rather than erroring, when tickets/ exists but cannot be read (review L4)', function () {
    mkdir($this->private.'/tickets', 0700);
    chmod($this->private.'/tickets', 0000);

    try {
        expect(fn () => recovery($this->private)->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $this->made['digest']))
            ->toThrow(BackupRefused::class, 'cannot be read');
    } finally {
        chmod($this->private.'/tickets', 0700);
    }
    expect(is_dir($this->private.'/tickets'))->toBeTrue();
});

it('refuses when the private disk already holds attachments, and leaves them untouched', function () {
    mkdir($this->private.'/tickets/7', 0700, true);
    file_put_contents($this->private.'/tickets/7/surviving.txt', 'survivor');

    expect(fn () => recovery($this->private)->restore($this->made['opened'], 'intechral_client_portal_rehearsal_restore', $this->made['digest']))
        ->toThrow(BackupRefused::class, 'already holds tickets/')
        ->and(file_get_contents($this->private.'/tickets/7/surviving.txt'))->toBe('survivor')
        ->and($this->target->connection()->select('show tables'))->toBe([]);
});
