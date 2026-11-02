<?php

use App\Support\Backup\RestoreDrill;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 restore drill: a set restores into the disposable rehearsal restore target only, and the checks on the
 * RESTORED data catch what the set-level checks cannot (the corruption here is internally consistent: dump and
 * manifest agree, and the set is re-sealed).
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
    $this->opened = BackupSandbox::copy($made['opened']);
    $this->restore = RehearsalTarget::restore();
});

afterEach(function () {
    config(['app.env' => 'testing']);
    RehearsalDatabase::clean($this->restore);
});

function drill(string $opened, ?string $key = null, int $legacy = 0, bool $keep = false): array
{
    return (new RestoreDrill)->run($opened, BackupSandbox::encrypter($key ?? BackupSandbox::APP_KEY), null, $legacy, $keep);
}

function restoreTables(): array
{
    return array_map(fn ($r) => array_values((array) $r)[0], RehearsalTarget::restore()->connection()->select('show tables'));
}

it('restores the set, proves it, and removes the restored data', function () {
    $report = drill($this->opened);

    expect($report['violations'])->toBe([])
        ->and($report['ok'])->toBeTrue()
        ->and($report['facts']['attachments'])->toBe(4)
        ->and($report['facts']['runtime_rows_removed'])->toHaveKeys(['sessions', 'cache', 'cache_locks', 'password_reset_tokens'])
        ->and(restoreTables())->toBe([])
        ->and(is_dir($this->restore->filesRoot().'/tickets'))->toBeFalse();
});

it('keeps the restored data when asked, inside the marked rehearsal target', function () {
    $report = drill($this->opened, keep: true);

    expect($report['ok'])->toBeTrue()
        ->and($report['cleaned'])->toBeFalse()
        ->and(restoreTables())->toContain('tickets', RehearsalTarget::MARKER_TABLE)
        ->and(is_file($this->restore->filesRoot().'/tickets/5/rehearsal-4.txt'))->toBeTrue();
});

it('fails with the wrong APP_KEY', function () {
    $report = drill($this->opened, 'another-key-0123456789abcdefghij');

    expect($report['ok'])->toBeFalse()
        ->and(implode(' ', $report['violations']))->toContain('does not decrypt the canary');
});

it('fails when restored child records reference a ticket that does not exist', function (string $from, string $to, string $check) {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace($from, $to, $sql), rederive: true);

    $report = drill($this->opened);

    expect($report['ok'])->toBeFalse()
        ->and(implode("\n", $report['violations']))->toContain($check)
        ->and(restoreTables())->toBe([]);
})->with([
    'a reply' => ["VALUES (1,1,3,'It stopped printing", "VALUES (1,999,3,'It stopped printing", 'fk_orphans:ticket_replies.ticket_id'],
    'a status history' => ["VALUES (1,2,1,'open','in_progress'", "VALUES (1,998,1,'open','in_progress'", 'fk_orphans:ticket_status_histories.ticket_id'],
]);

it('fails on a non-canonical (legacy) ticket reference unless its exact count is expected', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace("'TK-REH00-00001'", "'TKT-0001'", $sql), rederive: true);

    expect(implode(' ', drill($this->opened)['violations']))->toContain('ticket_number_not_canonical: 1')
        ->and(drill($this->opened, legacy: 2)['ok'])->toBeFalse()
        ->and(drill($this->opened, legacy: 1)['violations'])->toBe([]);
});

it('fails on duplicate ticket references before touching the target', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace("'TK-REH00-00002'", "'TK-REH00-00001'", $sql), rederive: true);
    $report = drill($this->opened);

    expect($report['ok'])->toBeFalse()
        ->and($report['phase'])->toBe('verify the set')
        ->and(implode(' ', $report['violations']))->toContain('duplicate ticket reference');
});

it('fails, and restores nothing, for a set that does not verify', function () {
    unlink($this->opened.'/COMPLETE');
    $report = drill($this->opened);

    expect($report['ok'])->toBeFalse()->and($report['phase'])->toBe('verify the set')->and(restoreTables())->toBe([]);
});

it('refuses outside local and testing, before touching anything', function () {
    config(['app.env' => 'production']);

    expect(fn () => drill($this->opened))->toThrow(UnsafeRehearsalTarget::class, 'configured app.env is "production"');
});

it('refuses when the application itself is configured to use the restore database', function () {
    $previous = config('database.connections.mysql.database');
    config(['database.connections.mysql.database' => $this->restore->database()]);

    try {
        expect(fn () => drill($this->opened))->toThrow(UnsafeRehearsalTarget::class, 'the database the application itself is configured to use');
    } finally {
        config(['database.connections.mysql.database' => $previous]);
    }
});

it('refuses a restore database holding tables it did not create, and leaves them', function () {
    $connection = $this->restore->connection();
    expect($connection->selectOne('select database() as d')->d)->toBe('intechral_client_portal_rehearsal_restore');
    $connection->statement('create table someone_elses (id int)');

    try {
        expect(fn () => drill($this->opened))->toThrow(UnsafeRehearsalTarget::class, 'unidentified')
            ->and(restoreTables())->toBe(['someone_elses']);
    } finally {
        $connection->statement('drop table someone_elses');
    }
});
