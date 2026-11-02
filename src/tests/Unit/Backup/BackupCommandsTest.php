<?php

use App\Console\Commands\BackupDrill;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 commands: exit codes, what they print, and what they refuse. For backup:create the application's own
 * connection and private disk are pointed at the primary rehearsal database and its files for the test.
 */

beforeEach(function () {
    RehearsalSandbox::use();
});

function asRehearsalPrimary(): void
{
    config([
        'database.connections.mysql' => RehearsalTarget::primary()->connectionConfig(),
        'filesystems.disks.local.root' => RehearsalTarget::primary()->filesRoot(),
        'app.key' => 'base64:'.base64_encode(BackupSandbox::APP_KEY),
    ]);
    app()->forgetInstance('encrypter');
    DB::purge('mysql');
}

it('backup:create writes a set, prints its digest, and the verify command accepts it', function () {
    RehearsalSandbox::baseline();
    asRehearsalPrimary();
    $owner = BackupSandbox::owner();
    $root = BackupSandbox::directory();

    $exit = Artisan::call('backup:create', ['--to' => $root, '--recipient-key' => $owner['public'], '--recipient-fingerprint' => $owner['fingerprint']]);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toContain('Backup set complete')->toMatch('/Set digest .*: [0-9a-f]{64}/');
    preg_match('/Set digest .*: ([0-9a-f]{64})/', $output, $m);
    preg_match('/Backup set complete: (\d{8}T\d{6}Z-[0-9a-f]{8})/', $output, $id);
    $set = $root.'/'.$id[1];

    expect(Artisan::call('backup:verify', ['set' => $set, '--expect-digest' => $m[1]]))->toBe(0)
        ->and(Artisan::output())->toContain('The sealed set is complete and intact');

    $opened = BackupSandbox::open($set);
    expect(Artisan::call('backup:verify', ['set' => $opened, '--expect-digest' => $m[1]]))->toBe(0)
        ->and(Artisan::output())->toContain('This does not prove it restores');

    unlink($opened.'/COMPLETE');
    expect(Artisan::call('backup:verify', ['set' => $opened]))->toBe(1)
        ->and(Artisan::output())->toContain('NOT valid')->toContain('COMPLETE is missing');
});

it('backup:create refuses (exit 2) without its options or with the wrong key', function (Closure $options) {
    expect(Artisan::call('backup:create', $options()))->toBe(2);
})->with([
    'nothing' => [fn () => []],
    'no fingerprint' => [fn () => ['--to' => BackupSandbox::directory(), '--recipient-key' => BackupSandbox::owner()['public']]],
    'another key' => [fn () => ['--to' => BackupSandbox::directory(), '--recipient-key' => BackupSandbox::stranger()['public'], '--recipient-fingerprint' => BackupSandbox::owner()['fingerprint']]],
]);

it('backup:drill needs the escrowed key from a file, never the command line', function () {
    expect(Artisan::call('backup:drill', ['set' => '/nonexistent']))->toBe(2)
        ->and(Artisan::output())->toContain('--app-key-file is required');

    $file = BackupSandbox::directory('key').'/app-key';
    file_put_contents($file, "APP_KEY=base64:short\n");
    expect(Artisan::call('backup:drill', ['set' => '/nonexistent', '--app-key-file' => $file]))->toBe(2)
        ->and(Artisan::output())->toContain('does not hold a valid key')->not->toContain('short');
});

it('reads the escrowed key in each accepted form', function (string $text) {
    $file = BackupSandbox::directory('key').'/app-key';
    file_put_contents($file, $text);
    $key = BackupDrill::readKey($file, BackupSandbox::CIPHER);

    expect($key)->not->toBeString()->and($key->getKey())->toBe(BackupSandbox::APP_KEY);
})->with([
    'env line' => ['APP_KEY=base64:'.base64_encode(BackupSandbox::APP_KEY)."\n"],
    'quoted env line' => ['APP_KEY="base64:'.base64_encode(BackupSandbox::APP_KEY).'"'],
    'bare base64' => ['base64:'.base64_encode(BackupSandbox::APP_KEY)],
]);

it('backup:restore refuses (exit 2) without the recorded digest, unless the database is confirmed, and while the application is up', function () {
    $database = (string) config('database.connections.mysql.database');
    $digest = str_repeat('a', 64);

    expect(Artisan::call('backup:restore', ['set' => '/nonexistent', '--confirm-database' => $database]))->toBe(2)
        ->and(Artisan::output())->toContain('--expect-digest is required')
        ->and(Artisan::call('backup:restore', ['set' => '/nonexistent', '--verify-only' => true]))->toBe(2)
        ->and(Artisan::output())->toContain('--expect-digest is required')
        ->and(Artisan::call('backup:restore', ['set' => '/nonexistent', '--confirm-database' => 'something-else', '--expect-digest' => $digest]))->toBe(2)
        ->and(Artisan::output())->toContain('--confirm-database must name')
        ->and(Artisan::call('backup:restore', ['set' => '/nonexistent', '--confirm-database' => $database, '--expect-digest' => $digest]))->toBe(2)
        ->and(Artisan::output())->toContain('not in maintenance mode')->toContain('Nothing was changed');
});

it('backup:drill needs the recorded digest, or an explicit --unauthenticated', function () {
    $file = BackupSandbox::directory('key').'/app-key';
    file_put_contents($file, 'base64:'.base64_encode(BackupSandbox::APP_KEY));

    expect(Artisan::call('backup:drill', ['set' => '/nonexistent', '--app-key-file' => $file]))->toBe(2)
        ->and(Artisan::output())->toContain('--expect-digest is required')
        ->and(Artisan::call('backup:drill', ['set' => '/nonexistent', '--app-key-file' => $file, '--expect-digest' => 'xyz']))->toBe(2)
        ->and(Artisan::output())->toContain('64 lowercase hexadecimal');
});
