<?php

use App\Support\Backup\BackupSet;
use App\Support\Backup\SetVerifier;
use Symfony\Component\Process\Process;
use Tests\Support\BackupSandbox;
use Tests\Support\RehearsalSandbox;

/*
 * WP-B1 set verification: a sealed set's integrity, the owner-side opener, and an opened set's contents. Each
 * negative control damages a COPY of one good set. Where the damage would otherwise be caught by a checksum, the copy
 * is re-sealed consistently (BackupSandbox::tamper), so the deeper check is the one that must fail.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    static $made = null;

    if ($made === null || ! is_dir($made['directory'])) {
        RehearsalSandbox::baseline();
        $made = BackupSandbox::create();
        $made['opened'] = BackupSandbox::open($made['directory']);
    }

    $this->made = $made;
    $this->set = $made['directory'];
    $this->opened = BackupSandbox::copy($made['opened']);
});

afterEach(fn () => BackupSandbox::release());

/** A copy of the sealed set, in its own parent, named by its id. */
function sealedCopy(string $set): string
{
    $copy = BackupSandbox::directory('sealed').'/'.basename($set);
    mkdir($copy, 0700);
    foreach (BackupSet::sealedFiles() as $name) {
        copy($set.'/'.$name, $copy.'/'.$name);
        chmod($copy.'/'.$name, 0600);
    }

    return $copy;
}

function flipByte(string $file, int $offset = 200): void
{
    $bytes = (string) file_get_contents($file);
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
    file_put_contents($file, $bytes);
}

/** Opened-set violations as one string. */
function openedViolations(string $dir, ?string $digest = null): string
{
    $result = SetVerifier::verifyOpened($dir, $digest);
    expect($result['ok'])->toBeFalse();

    return implode("\n", $result['violations']);
}

// ── sealed ──────────────────────────────────────────────────────────────────────────────────

it('accepts the good sealed set, and authenticates it only against its own digest', function () {
    expect(BackupSet::sealedViolations($this->set, $this->made['digest']))->toBe([])
        ->and(implode(' ', BackupSet::sealedViolations($this->set, str_repeat('0', 64))))->toContain('not the one recorded');
});

it('rejects an incomplete or altered sealed set', function (Closure $damage, string $expected) {
    $copy = sealedCopy($this->set);
    $damage($copy);

    expect(implode("\n", BackupSet::sealedViolations($copy)))->toContain($expected);
})->with([
    'no COMPLETE' => [fn ($d) => unlink($d.'/COMPLETE'), 'COMPLETE is missing'],
    'no database artifact' => [fn ($d) => unlink($d.'/database.sql.gz.gpg'), 'database.sql.gz.gpg is missing'],
    'an extra file' => [fn ($d) => touch($d.'/notes.txt'), 'unexpected entry notes.txt'],
    'a corrupt database artifact' => [fn ($d) => flipByte($d.'/database.sql.gz.gpg'), 'database.sql.gz.gpg'],
    'a truncated attachments artifact' => [fn ($d) => file_put_contents($d.'/attachments.tar.gz.gpg', substr((string) file_get_contents($d.'/attachments.tar.gz.gpg'), 0, 100)), 'attachments.tar.gz.gpg'],
    'an artifact from another set' => [fn ($d) => copy($d.'/manifest.json.gpg', $d.'/database.sql.gz.gpg'), 'database.sql.gz.gpg'],
    'COMPLETE written for different sums' => [fn ($d) => file_put_contents($d.'/COMPLETE', str_replace('sha256sums=', 'sha256sums=0', (string) file_get_contents($d.'/COMPLETE'))), 'COMPLETE does not match'],
    'a group-readable file' => [fn ($d) => chmod($d.'/set.json', 0640), 'accessible to other users'],
    'a symbolic link' => [function ($d) {
        rename($d.'/manifest.json.gpg', $d.'/../m.gpg');
        symlink($d.'/../m.gpg', $d.'/manifest.json.gpg');
    }, 'not a regular file'],
]);

// ── opener (owner side) ─────────────────────────────────────────────────────────────────────

it('opens nothing with the wrong private key, and leaves no output or temporary directory', function () {
    $out = BackupSandbox::directory('open').'/out';
    $process = BackupSandbox::opener($this->set, $out, BackupSandbox::stranger());

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('decryption of manifest.json.gpg failed')
        ->and(scandir(dirname($out)))->toBe(['.', '..']);
});

it('refuses to open a damaged or unauthenticated set before decrypting', function (Closure $damage, array $extra, string $expected) {
    $copy = sealedCopy($this->set);
    $damage($copy);
    $out = BackupSandbox::directory('open').'/out';
    $process = BackupSandbox::opener($copy, $out, null, array_map(fn ($a) => $a === 'DIGEST' ? $this->made['digest'] : $a, $extra));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain($expected)
        ->and(scandir(dirname($out)))->toBe(['.', '..']);
})->with([
    'corrupt artifact' => [fn ($d) => flipByte($d.'/database.sql.gz.gpg'), [], 'does not match SHA256SUMS'],
    'no COMPLETE' => [fn ($d) => unlink($d.'/COMPLETE'), [], 'does not hold exactly'],
    'wrong recorded digest' => [fn ($d) => null, ['--expect-digest', str_repeat('a', 64)], 'not the set that was made'],
    'resealed by someone else' => [function ($d) {
        // An attacker who replaces an artifact and rewrites SHA256SUMS and COMPLETE still fails the recorded digest.
        flipByte($d.'/database.sql.gz.gpg');
        $sums = preg_replace('/^[0-9a-f]{64}(  database\.sql\.gz\.gpg)$/m', hash_file('sha256', $d.'/database.sql.gz.gpg').'$1', (string) file_get_contents($d.'/SHA256SUMS'));
        file_put_contents($d.'/SHA256SUMS', $sums);
        file_put_contents($d.'/COMPLETE', preg_replace('/sha256sums=.*/', 'sha256sums='.hash('sha256', $sums), (string) file_get_contents($d.'/COMPLETE')));
    }, ['--expect-digest', 'DIGEST'], 'not the set that was made'],
]);

it('opens with the recorded digest', function () {
    $out = BackupSandbox::directory('open').'/out';
    $process = BackupSandbox::opener($this->set, $out, null, ['--expect-digest', $this->made['digest']]);

    expect($process->isSuccessful())->toBeTrue()
        ->and(array_values(array_diff(scandir($out), ['.', '..'])))->toBe(BackupSet::openedFiles())
        ->and(BackupSet::mode($out))->toBe('0700');
});

// ── opened ──────────────────────────────────────────────────────────────────────────────────

it('accepts the good opened set', function () {
    expect(SetVerifier::verifyOpened($this->opened, $this->made['digest'])['violations'])->toBe([]);
});

it('rejects a payload that is not the file that was encrypted', function () {
    file_put_contents($this->opened.'/database.sql.gz', gzencode('-- empty'));

    expect(openedViolations($this->opened))->toContain('database.sql.gz is not the file that was encrypted');
});

it('rejects a missing payload', function () {
    unlink($this->opened.'/attachments.tar.gz');

    expect(openedViolations($this->opened))->toContain('attachments.tar.gz is missing');
});

it('rejects a corrupt (undecompressable) dump even when resealed', function () {
    file_put_contents($this->opened.'/database.sql.gz', substr((string) file_get_contents($this->opened.'/database.sql.gz'), 0, 300));
    BackupSandbox::reseal($this->opened);

    expect(openedViolations($this->opened))->toContain('dump');
});

it('rejects a truncated dump even when resealed', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => substr($sql, 0, (int) (strlen($sql) * 0.6)));

    expect(openedViolations($this->opened))->toContain('truncated');
});

it('rejects a dump that would switch database, even when dump and manifest agree', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace('-- Dump completed', "USE `portal`;\nDROP TABLE IF EXISTS `users`;\n-- Dump completed", $sql), rederive: true);

    expect(openedViolations($this->opened))->toContain('unexpected statement "USE');
});

it('rejects a dump whose rows differ from the manifest', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace("'Printer offline'", "'Printer online'", $sql));

    expect(openedViolations($this->opened))->toContain('table `tickets` differs from the manifest');
});

it('rejects duplicate ticket references', function () {
    BackupSandbox::tamper($this->opened, fn (string $sql) => str_replace("'TK-REH00-00002'", "'TK-REH00-00001'", $sql), rederive: true);

    expect(openedViolations($this->opened))->toContain('duplicate ticket reference');
});

it('rejects an attachment row whose path leaves its ticket directory', function (string $path) {
    BackupSandbox::tamper($this->opened,
        fn (string $sql) => str_replace("'tickets/2/rehearsal-3.txt'", "'".addslashes($path)."'", $sql),
        rederive: true,
        manifest: function (array $m) use ($path) {
            $m['attachments'][2]['path'] = $path;

            return $m;
        });

    expect(openedViolations($this->opened))->toContain('attachment 3 has a path outside tickets/2/');
})->with(['parent traversal' => ['tickets/2/../5/rehearsal-4.txt'], 'another ticket' => ['tickets/5/rehearsal-3.txt'], 'absolute' => ['/etc/passwd'], 'nested' => ['tickets/2/x/rehearsal-3.txt']]);

it('rejects an archive without a referenced file', function () {
    BackupSandbox::tamper($this->opened, archive: fn (string $w) => unlink($w.'/tickets/2/rehearsal-3.txt'));

    expect(openedViolations($this->opened))->toContain('the file of attachment 3 is missing');
});

it('rejects an archive with an unexpected file or directory', function () {
    BackupSandbox::tamper($this->opened, archive: function (string $w) {
        file_put_contents($w.'/tickets/1/stray.txt', 'stray');
        mkdir($w.'/tickets/99');
    });

    expect(openedViolations($this->opened))->toContain('unexpected file tickets/1/stray.txt')->toContain('unexpected directory tickets/99');
});

it('rejects an archive file whose content differs, even at the same size', function () {
    BackupSandbox::tamper($this->opened, archive: function (string $w) {
        $f = $w.'/tickets/5/rehearsal-4.txt';
        file_put_contents($f, str_repeat('X', filesize($f)));
    });

    expect(openedViolations($this->opened))->toContain('the file of attachment 4 does not match its recorded size and SHA-256');
});

it('rejects an archive holding a symbolic or hard link under an allowed name', function (string $kind) {
    BackupSandbox::tamper($this->opened, archive: function (string $w) use ($kind) {
        $kind === 'symbolic' ? symlink('/etc/hostname', $w.'/tickets/1/linked.txt') : link($w.'/tickets/1/rehearsal-1.txt', $w.'/tickets/1/linked.txt');
    });

    expect(openedViolations($this->opened))->toContain('other than plain files and directories');
})->with(['symbolic', 'hard']);

it('rejects an archive member that escapes tickets/, before extracting anything', function (string $member) {
    $archive = $this->opened.'/attachments.tar.gz';
    $outside = dirname($this->opened).'/escaped.txt';
    (new Process(['python3', '-I', '-c', 'import sys,tarfile,io
t=tarfile.open(sys.argv[1],"w:gz")
for n in ["tickets/","tickets/1/"]:
    i=tarfile.TarInfo(n); i.type=tarfile.DIRTYPE; t.addfile(i)
i=tarfile.TarInfo(sys.argv[2]); d=b"escape"; i.size=len(d); t.addfile(i,io.BytesIO(d)); t.close()', $archive, $member]))->mustRun();
    BackupSandbox::reseal($this->opened);

    expect(openedViolations($this->opened))->toContain('unsafe or unexpected entry')
        ->and(file_exists($outside))->toBeFalse();
})->with(['../escaped.txt', 'tickets/1/../../escaped.txt', '/tmp/escaped.txt', 'other/file.txt']);
