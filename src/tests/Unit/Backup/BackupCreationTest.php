<?php

use App\Support\Backup\BackupCreator;
use App\Support\Backup\BackupRefused;
use App\Support\Backup\BackupSet;
use App\Support\Backup\MariaDbClient;
use App\Support\Backup\SetVerifier;
use App\Support\Rehearsal\RehearsalTarget;
use Symfony\Component\Process\Process;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 backup creation: what the creator refuses, what makes it fail, that a failed or interrupted backup never
 * looks complete, and that no plaintext or secret is left behind. The source is the primary rehearsal database and
 * its fixture files; a test that changes them is undone by the next RehearsalSandbox::baseline().
 */

beforeEach(function () {
    RehearsalSandbox::use();
    RehearsalSandbox::baseline();
    $this->files = RehearsalTarget::primary()->filesRoot();
    $this->root = BackupSandbox::directory();
});

/** Every entry under a backup root, relative. */
function entries(string $root): array
{
    return array_keys(BackupSet::tree($root));
}

/** The backup root holds nothing but its lock file. */
function nothingWritten(string $root): void
{
    expect(array_values(array_diff(entries($root), [BackupCreator::LOCK_FILE])))->toBe([]);
}

/** Run a backup expected to fail, and return its message. */
function failingBackup(string $root, Closure $afterSnapshot): string
{
    try {
        BackupSandbox::create($root, $afterSnapshot);
    } catch (RuntimeException $e) {
        expect($e)->not->toBeInstanceOf(BackupRefused::class);
        nothingWritten($root);

        return $e->getMessage();
    }

    throw new RuntimeException('The backup was expected to fail and did not.');
}

it('writes a private, complete set with no plaintext, no key material and no credentials', function () {
    $previous = umask(0);

    try {
        $result = BackupSandbox::create($this->root);
    } finally {
        expect(umask($previous))->toBe(0);
    }

    $set = $result['directory'];
    expect(entries($set))->toBe(BackupSet::sealedFiles())
        ->and(BackupSet::mode($set))->toBe('0700');
    foreach (BackupSet::sealedFiles() as $name) {
        expect(BackupSet::mode($set.'/'.$name))->toBe('0600');
    }
    expect(array_values(array_diff(entries($this->root), [BackupCreator::LOCK_FILE, $result['backup_id'], ...array_map(fn ($f) => $result['backup_id'].'/'.$f, BackupSet::sealedFiles())])))->toBe([]);

    // Nothing readable in the set mentions a credential or the key; the payload is encrypted.
    $key = BackupSandbox::APP_KEY;
    foreach (BackupSet::sealedFiles() as $name) {
        $bytes = (string) file_get_contents($set.'/'.$name);
        expect($bytes)->not->toContain($key)->not->toContain(base64_encode($key))->not->toContain('"password"');
    }
    expect((string) file_get_contents($set.'/database.sql.gz.gpg'))->not->toContain('INSERT INTO');

    $opened = BackupSandbox::open($set);
    $manifest = json_decode((string) file_get_contents($opened.'/manifest.json'), true);
    $manifestText = (string) file_get_contents($opened.'/manifest.json');

    expect($manifestText)->not->toContain($key)->not->toContain(base64_encode($key))->not->toContain('"password":')
        ->and($manifest['app_key']['key_id'])->toBe(BackupCreator::keyId($key))
        ->and(hash('sha256', BackupSandbox::encrypter()->decryptString($manifest['app_key']['canary'])))->toBe($manifest['app_key']['canary_sha256'])
        ->and($manifest['source']['release_id'])->toBe('20261102000000-test000')
        ->and($manifest['database']['tickets']['non_canonical'])->toBe(0)
        ->and(array_column($manifest['attachments'], 'path'))->toBe(['tickets/1/rehearsal-1.txt', 'tickets/1/rehearsal-2.txt', 'tickets/2/rehearsal-3.txt', 'tickets/5/rehearsal-4.txt'])
        ->and(SetVerifier::verifyOpened($opened, $result['digest'])['violations'])->toBe([]);
});

it('fails when a referenced file is missing', function () {
    expect(failingBackup($this->root, fn () => unlink($this->files.'/tickets/2/rehearsal-3.txt')))->toContain('attachment 3')->toContain('missing');
});

it('fails when the e2e cleanup (or anything) removes a ticket directory after the snapshot', function () {
    // e2e:cleanup-tickets deletes whole tickets/{id} directories; it refuses outside local and testing
    // (CleanupE2eTicketsTest), and if it ever ran during a backup the backup fails, it does not lose the file silently.
    $message = failingBackup($this->root, function () {
        foreach (glob($this->files.'/tickets/5/*') as $f) {
            unlink($f);
        }
        rmdir($this->files.'/tickets/5');
    });

    expect($message)->toContain('tickets/5');
});

it('fails when a referenced file differs in size from its row', function () {
    expect(failingBackup($this->root, fn () => file_put_contents($this->files.'/tickets/1/rehearsal-1.txt', 'x', FILE_APPEND)))->toContain('bytes but its row says');
});

it('fails when a referenced file is rewritten with the same size after the snapshot', function () {
    $message = failingBackup($this->root, function () {
        BackupSandbox::nextSecond();
        $path = $this->files.'/tickets/1/rehearsal-2.txt';
        file_put_contents($path, str_repeat('Z', filesize($path)));
    });

    expect($message)->toContain('changed after the database snapshot began');
});

it('fails when a referenced file is replaced by a symbolic link', function () {
    $message = failingBackup($this->root, function () {
        $path = $this->files.'/tickets/1/rehearsal-1.txt';
        $copy = $this->files.'/../elsewhere.txt';
        rename($path, $copy);
        symlink($copy, $path);
    });

    expect($message)->toContain('not a regular file');
});

it('fails when a ticket directory is a symbolic link', function () {
    $message = failingBackup($this->root, function () {
        rename($this->files.'/tickets/2', $this->files.'/../moved-2');
        symlink($this->files.'/../moved-2', $this->files.'/tickets/2');
    });

    expect($message)->toContain('symbolic link');
});

it('fails, and leaves nothing complete or plaintext, when the backup is interrupted after the snapshot', function () {
    $message = failingBackup($this->root, function () {
        throw new RuntimeException('simulated interruption');
    });

    expect($message)->toBe('simulated interruption');
});

it('leaves no complete set when the process is interrupted with SIGTERM', function () {
    $owner = BackupSandbox::owner();
    $ready = $this->root.'.ready';
    $process = new Process(['php', base_path('tests/Support/backup_interrupt_worker.php'), $this->files, $this->root, $owner['public'], $owner['fingerprint'], $ready], base_path(), null, null, 120);
    $process->start();

    while (! file_exists($ready) && $process->isRunning()) {
        usleep(20_000);
    }
    expect(file_exists($ready))->toBeTrue();
    $partial = array_values(array_filter(entries($this->root), fn ($e) => str_starts_with($e, BackupCreator::PARTIAL_PREFIX) && ! str_contains($e, '/')));
    expect($partial)->toHaveCount(1)
        ->and(entries($this->root))->toContain($partial[0].'/plain/database.sql');

    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('Interrupted by signal 15');
    nothingWritten($this->root);
});

it('stops the real backup:create on SIGTERM through its trap, leaving no set, no partial and no child process (review L3)', function () {
    $owner = BackupSandbox::owner();
    $ready = $this->root.'.ready';
    $bin = BackupSandbox::directory('fakebin');
    // The real dump is replaced by one that announces itself and then hangs, so the signal lands while it runs.
    file_put_contents($bin.'/mariadb-dump', "#!/bin/sh\n: > '{$ready}'\nexec sleep 617\n");
    chmod($bin.'/mariadb-dump', 0700);

    $process = new Process(['php', base_path('tests/Support/backup_interrupt_worker.php'), $this->files, $this->root, $owner['public'], $owner['fingerprint'], $ready, 'command'],
        base_path(), ['PATH' => $bin.':'.getenv('PATH'), 'APP_ENV' => 'local'], null, 120);
    $process->start();

    while (! file_exists($ready) && $process->isRunning()) {
        usleep(20_000);
    }
    expect(file_exists($ready))->toBeTrue($process->getOutput().$process->getErrorOutput());

    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('Backup FAILED: Interrupted by signal 15')->toContain('No complete set was written');
    nothingWritten($this->root);
    // No child left running: the creator stops the dump it started (read /proc; the image has no ps).
    $commands = array_map(fn (string $f) => str_replace("\0", ' ', (string) @file_get_contents($f)), glob('/proc/[0-9]*/cmdline'));
    expect($commands)->not->toBeEmpty()
        ->and(array_filter($commands, fn (string $c) => str_contains($c, 'sleep 617')))->toBe([]);
});

it('leaves only a private partial set when the process is killed, and the next backup removes it', function () {
    $owner = BackupSandbox::owner();
    $ready = $this->root.'.ready';
    $process = new Process(['php', base_path('tests/Support/backup_interrupt_worker.php'), $this->files, $this->root, $owner['public'], $owner['fingerprint'], $ready], base_path(), null, null, 120);
    $process->start();

    while (! file_exists($ready) && $process->isRunning()) {
        usleep(20_000);
    }
    $process->signal(SIGKILL);
    $process->wait();

    $partials = array_values(array_filter(entries($this->root), fn ($e) => str_starts_with($e, BackupCreator::PARTIAL_PREFIX) && ! str_contains($e, '/')));
    expect($partials)->toHaveCount(1);
    $partial = $this->root.'/'.$partials[0];
    // Killed: no completion marker, so nothing reads it as a set, and it is private.
    expect(file_exists($partial.'/'.BackupSet::COMPLETE))->toBeFalse()
        ->and(BackupSet::mode($partial))->toBe('0700')
        ->and(BackupSet::mode($partial.'/plain/database.sql'))->toBe('0600')
        ->and(BackupSet::sealedViolations($partial))->not->toBe([]);

    $result = BackupSandbox::create($this->root);

    expect($result['swept'])->toBe([$partials[0]])
        ->and(file_exists($partial))->toBeFalse()
        ->and(array_values(array_filter(entries($this->root), fn ($e) => ! str_contains($e, '/'))))->toBe([BackupCreator::LOCK_FILE, $result['backup_id']]);
});

it('removes a partial set killed between its marker and COMPLETE, which holds only encrypted files (review L6)', function () {
    $partial = $this->root.'/'.BackupCreator::PARTIAL_PREFIX.'20260101T000000Z-0000abcd';
    mkdir($partial, 0700);
    foreach (['set.json', 'SHA256SUMS', 'database.sql.gz.gpg'] as $name) {
        file_put_contents($partial.'/'.$name, 'x');
    }

    expect(BackupSandbox::create($this->root)['swept'])->toBe([basename($partial)])
        ->and(file_exists($partial))->toBeFalse();
});

it('leaves a directory that only looks like a partial set alone', function () {
    mkdir($this->root.'/'.BackupCreator::PARTIAL_PREFIX.'20260101T000000Z-00000000', 0700);
    file_put_contents($this->root.'/'.BackupCreator::PARTIAL_PREFIX.'20260101T000000Z-00000000/keep.txt', 'not ours');

    $result = BackupSandbox::create($this->root);

    expect($result['swept'])->toBe([])
        ->and(file_get_contents($this->root.'/'.BackupCreator::PARTIAL_PREFIX.'20260101T000000Z-00000000/keep.txt'))->toBe('not ours');
});

it('refuses while another backup holds the lock, before dumping anything', function () {
    $lock = fopen($this->root.'/'.BackupCreator::LOCK_FILE, 'c');
    flock($lock, LOCK_EX);

    try {
        expect(fn () => BackupSandbox::create($this->root))->toThrow(BackupRefused::class, 'Another backup is running');
    } finally {
        fclose($lock);
    }
    nothingWritten($this->root);
});

it('refuses an unsafe backup directory', function (Closure $make) {
    $root = $make();
    expect(fn () => BackupSandbox::create($root))->toThrow(BackupRefused::class);
})->with([
    'group-readable' => [fn () => tap(BackupSandbox::directory(), fn ($d) => chmod($d, 0750))],
    'a symbolic link' => [fn () => tap(BackupSandbox::directory().'-link', fn ($l) => symlink(BackupSandbox::directory(), $l))],
    'missing' => [fn () => BackupSandbox::directory().'/absent'],
    'inside storage' => [fn () => tap(storage_path('wpb1-backups'), fn ($d) => is_dir($d) || mkdir($d, 0700))],
    'containing the private disk' => [fn () => tap(dirname(RehearsalTarget::primary()->filesRoot()), fn ($d) => chmod($d, 0700))],
]);

it('refuses a recipient key that is not the stated one, before dumping anything', function () {
    $stranger = BackupSandbox::stranger();
    $owner = BackupSandbox::owner();

    expect(fn () => BackupSandbox::creator()->create($this->root, $stranger['public'], $owner['fingerprint']))
        ->toThrow(BackupRefused::class, 'not the expected');
    nothingWritten($this->root);

    $both = $this->root.'.both.asc';
    file_put_contents($both, file_get_contents($owner['public']).file_get_contents($stranger['public']));
    expect(fn () => BackupSandbox::creator()->create($this->root, $both, $owner['fingerprint']))
        ->toThrow(BackupRefused::class, 'exactly one public key');
    nothingWritten($this->root);
});

it('fails, and writes nothing, when the database cannot be dumped', function () {
    $config = [...RehearsalTarget::primary()->connectionConfig(), 'password' => 'definitely-wrong-password'];
    $owner = BackupSandbox::owner();

    try {
        BackupSandbox::creator(null, $config)->create($this->root, $owner['public'], $owner['fingerprint']);
        $this->fail('expected the dump to fail');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('mariadb-dump failed')->not->toContain('definitely-wrong-password');
    }
    nothingWritten($this->root);
});

it('passes credentials to the client tools on a pipe, never in their arguments or environment', function () {
    $fake = BackupSandbox::directory('fake').'/client';
    $seen = $fake.'.seen';
    file_put_contents($fake, "#!/bin/sh\n{ printf 'ARGS:%s\\n' \"\$*\"; env; echo FD3:; cat /dev/fd/3; } > '{$seen}'\necho 'server said: '\"\$WPB1_NOTHING\"'secret-pass-123' >&2\nexit 3\n");
    chmod($fake, 0700);

    $result = MariaDbClient::run($fake, ['--single-transaction', '--', 'somedb'], ['username' => 'backup-user', 'password' => 'secret-pass-123', 'host' => 'db', 'port' => 3306]);
    $captured = (string) file_get_contents($seen);
    [$before, $fd3] = explode("FD3:\n", $captured, 2);

    expect($before)->not->toContain('secret-pass-123')
        ->and($before)->toContain('ARGS:--defaults-extra-file=/dev/fd/3 --single-transaction -- somedb')
        ->and($fd3)->toContain('password="secret-pass-123"')->toContain('user="backup-user"')
        ->and($result['exit'])->toBe(3)
        ->and($result['stderr'])->toBe('server said: ***');
});

it('quotes option-file values so a password cannot add options', function () {
    expect(MariaDbClient::optionFile(['username' => 'u', 'password' => "a\"b\\c\nsocket=/x"]))
        ->toBe("[client]\nuser=\"u\"\npassword=\"a\\\"b\\\\c\\nsocket=/x\"\n");
});
