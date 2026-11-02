<?php

use App\Support\Backup\BackupSet;
use App\Support\Backup\RestoreDrill;
use App\Support\Backup\SetVerifier;
use App\Support\Rehearsal\RehearsalTarget;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1: the whole path a real recovery takes, on the rehearsal fixtures: create a sealed, encrypted set from the
 * primary rehearsal database, open it with the owner's key, verify it, and restore it in the drill.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    $this->baseline = RehearsalSandbox::baseline();
});

it('creates a sealed set that opens, verifies and restores with every check passing', function () {
    $result = BackupSandbox::create();
    $set = $result['directory'];

    expect(basename($set))->toBe($result['backup_id'])
        ->and(BackupSet::sealedViolations($set, $result['digest']))->toBe([])
        ->and($result['attachments'])->toBe(4);

    $opened = BackupSandbox::open($set);
    $verified = SetVerifier::verifyOpened($opened, $result['digest']);
    expect($verified['violations'])->toBe([])->and($verified['ok'])->toBeTrue();

    $report = (new RestoreDrill)->run($opened, BackupSandbox::encrypter(), $result['digest'], 0, false);

    expect($report['violations'])->toBe([])
        ->and($report['ok'])->toBeTrue()
        ->and($report['cleaned'])->toBeTrue()
        ->and($report['facts']['tickets']['identity_sha256'])->toBe($verified['manifest']['database']['tickets']['identity_sha256'])
        ->and(RehearsalTarget::restore()->connection()->select('show tables'))->toBe([]);
});

it('backs up a pre-C6-R database holding legacy TKT- references, and restores it only when their exact count is expected', function () {
    // The initial staging backup (ADR-008 step 1) is taken before tickets:rereference, so the set holds legacy
    // references. They are written straight to the source, as the pre-C6-R application left them (the model guard is
    // bypassed on purpose); the next test's baseline() rebuilds the fixtures.
    $source = RehearsalTarget::primary()->connection();
    $source->table('tickets')->where('id', 1)->update(['ticket_number' => 'TKT-0001']);
    $source->table('tickets')->where('id', 2)->update(['ticket_number' => 'TKT-0002']);

    $result = BackupSandbox::create();
    $opened = BackupSandbox::open($result['directory']);
    $verified = SetVerifier::verifyOpened($opened, $result['digest']);

    expect($verified['violations'])->toBe([])
        ->and($verified['manifest']['database']['tickets'])->toMatchArray(['non_canonical' => 2, 'duplicates' => 0]);

    $drill = fn (int $legacy) => (new RestoreDrill)->run($opened, BackupSandbox::encrypter(), $result['digest'], $legacy, false);

    $unexpected = $drill(0);
    expect($unexpected['ok'])->toBeFalse()
        ->and(implode(' ', $unexpected['violations']))->toContain('ticket_number_not_canonical: 2');

    expect($drill(1)['ok'])->toBeFalse()
        ->and($drill(3)['ok'])->toBeFalse();

    $expected = $drill(2);
    expect($expected['violations'])->toBe([])
        ->and($expected['ok'])->toBeTrue()
        ->and($expected['facts']['tickets'])->toMatchArray(['non_canonical' => 2, 'canonical' => $expected['facts']['tickets']['count'] - 2])
        ->and(RehearsalTarget::restore()->connection()->select('show tables'))->toBe([]);
});
