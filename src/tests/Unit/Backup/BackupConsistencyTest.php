<?php

use App\Support\Backup\RestoreDrill;
use App\Support\Backup\SetVerifier;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use Symfony\Component\Process\Process;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 consistency under concurrent uploads. A child process uploads attachments the way TicketService does (file
 * first, then the row, then commit; some held "in flight" for a moment) as fast as it can, while real backups run.
 * Every set must still verify and restore with every check passing: it holds exactly the files its snapshot's rows
 * reference. Uploads after the snapshot, and uploads in flight at it, are not in the set; they are reported.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    RehearsalSandbox::baseline();
    $this->files = RehearsalTarget::primary()->filesRoot();
});

afterEach(function () {
    try {
        // The uploads changed the primary rehearsal database; the next baseline() rebuilds it.
        RehearsalDatabase::clean(RehearsalTarget::restore());
    } finally {
        BackupSandbox::release();
    }
});

it('makes sets that verify and restore while attachments are being uploaded', function () {
    $stop = BackupSandbox::directory('stop').'/stop';
    $worker = new Process(['php', base_path('tests/Support/backup_upload_worker.php'), $this->files, $stop, '1', '3'], base_path(), null, null, 300);
    $worker->start();

    try {
        // Wait for the uploads to be under way.
        $deadline = microtime(true) + 30;
        while (glob($this->files.'/tickets/1/wpb1-concurrent-*') === [] && microtime(true) < $deadline && $worker->isRunning()) {
            usleep(20_000);
        }
        expect(glob($this->files.'/tickets/1/wpb1-concurrent-*'))->not->toBe([]);

        $sets = [BackupSandbox::create(), BackupSandbox::create(), BackupSandbox::create()];
    } finally {
        touch($stop);
        $worker->wait();
    }

    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
    $uploaded = (int) $worker->getOutput();
    expect($uploaded)->toBeGreaterThan(10);

    $counts = [];
    foreach ($sets as $made) {
        $opened = BackupSandbox::open($made['directory']);
        $verified = SetVerifier::verifyOpened($opened, $made['digest']);
        expect($verified['violations'])->toBe([]);

        $report = (new RestoreDrill)->run($opened, BackupSandbox::encrypter(), $made['digest'], 0, false);
        expect($report['violations'])->toBe([]);

        $counts[] = $made['attachments'];
        // Every file on disk not in the snapshot is accounted for, never silently dropped or included.
        expect($made['live_files_outside_snapshot'])->toBe($verified['manifest']['live_files_outside_snapshot']['count']);
    }

    // The sets really captured uploads made during the run, and later sets saw more of them.
    expect($counts[2])->toBeGreaterThan(4)
        ->and($counts[0])->toBeLessThanOrEqual($counts[1])
        ->and($counts[1])->toBeLessThanOrEqual($counts[2])
        ->and($counts[2])->toBeLessThanOrEqual(4 + $uploaded);
});
