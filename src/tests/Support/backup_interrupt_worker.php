<?php

/*
 * Child process for BackupCreationTest (WP-B1). Starts a real backup of the PRIMARY REHEARSAL database into <root>,
 * pauses after the database snapshot (writes <readyFile>, then sleeps), and is then interrupted by the test with
 * SIGTERM or SIGKILL. SIGTERM is turned into an exception the way `backup:create` does (Command::trap), so the
 * creator's cleanup runs; SIGKILL runs nothing.
 *
 * Usage: php backup_interrupt_worker.php <filesRoot> <root> <keyFile> <fingerprint> <readyFile> [command]
 *
 * With `command`, it runs the real `backup:create` (so its own Command::trap handling is what is tested) with the
 * application's connection and private disk pointed at the primary rehearsal database. The pause then comes from a
 * fake `mariadb-dump` first on PATH (the test provides it), and the process must run outside the testing environment,
 * because Laravel disables trap() while running unit tests.
 */

use App\Support\Backup\BackupCreator;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $filesRoot, $root, $keyFile, $fingerprint, $ready] = $argv;

if (($argv[6] ?? '') === 'command') {
    config([
        'database.connections.mysql' => RehearsalTarget::primary()->connectionConfig(),
        'filesystems.disks.local.root' => $filesRoot,
    ]);
    DB::purge('mysql');
    $exit = Artisan::call('backup:create', ['--to' => $root, '--recipient-key' => $keyFile, '--recipient-fingerprint' => $fingerprint]);
    echo Artisan::output();
    exit($exit);
}

pcntl_async_signals(true);
pcntl_signal(SIGTERM, function (): void {
    throw new RuntimeException('Interrupted by signal 15.');
});

$creator = new BackupCreator(
    RehearsalTarget::primary()->connectionConfig(),
    $filesRoot,
    new Encrypter('wp-b1-test-key-0123456789abcdefX', 'aes-256-cbc'),
    'aes-256-cbc',
    'testing',
    ['id' => null, 'commit' => null],
    null,
    function () use ($ready): void {
        file_put_contents($ready, 'ready');
        sleep(60);
    },
    [RehearsalTarget::MARKER_TABLE],
);

try {
    $creator->create($root, $keyFile, $fingerprint);
    echo 'completed';
} catch (Throwable $e) {
    echo 'failed: '.$e->getMessage();
    exit(1);
}
