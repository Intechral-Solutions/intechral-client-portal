<?php

use App\Support\Rehearsal\BackupRestore;
use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalFiles;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\SnapshotComparator;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A: backup artifact permissions, the backup/restore round trip, and the boundaries of the cleanup
 * that deletes backup sets and rehearsal files. Storage is a throw-away directory (RehearsalSandbox).
 */

beforeEach(function () {
    RehearsalSandbox::use();
    $this->baseline = RehearsalSandbox::baseline();
    $this->backups = RehearsalTarget::backupsRoot();

    // Each test starts with no backups (sandbox storage only: the path is asserted, not assumed).
    expect($this->backups)->toStartWith(RehearsalSandbox::root());
    File::deleteDirectory($this->backups);
});

/** The backup sets (directories) currently under the backups root. */
function sets(): array
{
    return is_dir(RehearsalTarget::backupsRoot())
        ? array_values(array_filter(scandir(RehearsalTarget::backupsRoot()), fn ($n) => str_starts_with($n, 'rehearsal-')))
        : [];
}

function mode(string $path): string
{
    return substr(sprintf('%o', fileperms($path)), -4);
}

it('writes a backup whose files are 0600 and whose directories are 0700, whatever the umask', function () {
    $previous = umask(0);   // the most permissive process umask: nothing may rely on the caller's umask

    try {
        $manifest = BackupRestore::backup(RehearsalTarget::primary());
    } finally {
        $this->assertSame(0, umask($previous), 'backup() must restore the umask it changed');
    }

    $directory = $manifest['directory'];

    expect(mode($directory))->toBe('0700')
        ->and(mode($directory.'/db.sql'))->toBe('0600')
        ->and(mode($directory.'/manifest.json'))->toBe('0600')
        ->and(mode($directory.'/files'))->toBe('0700')
        ->and(RehearsalFiles::exposedPaths($directory))->toBe([])
        ->and(RehearsalFiles::exposedPaths(RehearsalTarget::baseDirectory()))->toBe([]);

    foreach (RehearsalFiles::listFiles($directory.'/files') as $file) {
        expect(mode($directory.'/files/'.$file))->toBe('0600');
    }

    BackupRestore::discard($directory);
});

it('leaves the umask of the process exactly as it found it, even when the backup fails', function () {
    RehearsalFiles::reset($this->backups, RehearsalFiles::BACKUPS);
    chmod($this->backups, 0500);   // the set cannot be created here
    $previous = umask(0022);

    try {
        expect(fn () => BackupRestore::backup(RehearsalTarget::primary()))->toThrow(Exception::class);
        expect(umask())->toBe(0022);
    } finally {
        umask($previous);
        chmod($this->backups, 0700);
    }
});

it('stores the fixture attachment files privately in the first place', function () {
    foreach (RehearsalFiles::listFiles(RehearsalTarget::primary()->filesRoot()) as $file) {
        expect(mode(RehearsalTarget::primary()->filesRoot().'/'.$file))->toBe('0600');
    }

    expect(RehearsalFiles::exposedPaths(RehearsalTarget::primary()->filesRoot()))->toBe([]);
});

it('keeps the rehearsal marker private too', function () {
    BackupRestore::discard(BackupRestore::backup(RehearsalTarget::primary())['directory']);

    expect(mode($this->backups.'/'.RehearsalTarget::MARKER_FILE))->toBe('0600')
        ->and(mode($this->backups))->toBe('0700');
});

it('detects an exposed file or directory in a backup set and names the path, never its contents', function () {
    $manifest = BackupRestore::backup(RehearsalTarget::primary());
    $directory = $manifest['directory'];

    chmod($directory.'/db.sql', 0644);
    chmod($directory.'/files', 0755);

    $exposed = RehearsalFiles::exposedPaths($directory);
    expect($exposed)->toBe(['db.sql', 'files']);

    $problems = BackupRestore::verify($directory);
    expect(implode("\n", $problems))->toContain('not private', 'db.sql');

    foreach ($problems as $problem) {
        expect($problem)->not->toContain('INSERT INTO');
    }

    BackupRestore::discard($directory);
});

it('refuses to restore a set that is not private', function () {
    $manifest = BackupRestore::backup(RehearsalTarget::primary());
    chmod($manifest['directory'].'/manifest.json', 0644);

    expect(fn () => BackupRestore::restore($manifest['directory'], RehearsalTarget::restore()))
        ->toThrow(RuntimeException::class, 'not private');

    BackupRestore::discard($manifest['directory']);
});

it('restores a backup into the restore database with an identical snapshot, files included', function () {
    $manifest = BackupRestore::backup(RehearsalTarget::primary());
    $restored = BackupRestore::restore($manifest['directory'], RehearsalTarget::restore());

    $reference = IntegritySnapshot::capture(RehearsalTarget::primary());
    $copy = IntegritySnapshot::capture(RehearsalTarget::restore());

    expect($restored['files'])->toBe(4)
        ->and($restored['rows'])->toBe(array_sum($manifest['tables']))
        ->and(SnapshotComparator::compare($reference, $copy))->toBe([])
        ->and(IntegritySnapshot::hash($copy))->toBe(IntegritySnapshot::hash($reference))
        ->and(IntegritySnapshot::hash($copy))->toBe(IntegritySnapshot::hash($this->baseline))
        ->and(RehearsalFiles::exposedPaths(RehearsalTarget::restore()->filesRoot()))->toBe([]);

    // The round trip changed nothing in the source.
    expect(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe(IntegritySnapshot::hash($this->baseline));

    BackupRestore::discard($manifest['directory']);
});

it('refuses to restore an incomplete or altered backup before loading anything', function (string $label, Closure $damage, string $expected) {
    $manifest = BackupRestore::backup(RehearsalTarget::primary());
    $directory = $manifest['directory'];

    // Whatever the restore database holds now must be exactly what it holds after the refusal: a restore
    // resets it first, so any tables there are the proof that nothing was loaded.
    $restore = RehearsalTarget::restore();
    $tables = fn () => DB::connection($restore->connectionName())->select('select table_name as t from information_schema.tables where table_schema = ? order by 1', [$restore->database()]);
    $restore->register();
    $before = $tables();

    $damage($directory);

    expect(fn () => BackupRestore::restore($directory, $restore))->toThrow(RuntimeException::class, $expected)
        ->and($tables())->toEqual($before);
})->with([
    'a missing dump' => ['missing dump', fn (string $d) => unlink($d.'/db.sql'), 'no manifest or no dump'],
    'a missing manifest' => ['missing manifest', fn (string $d) => unlink($d.'/manifest.json'), 'no manifest or no dump'],
    'an unreadable manifest' => ['bad manifest', fn (string $d) => file_put_contents($d.'/manifest.json', '{'), 'unreadable'],
    'a truncated dump' => ['truncated', function (string $d) {
        $dump = file_get_contents($d.'/db.sql');
        file_put_contents($d.'/db.sql', substr($dump, 0, (int) (strlen($dump) / 2)));
    }, 'checksum'],
    'an edited dump' => ['edited dump', fn (string $d) => file_put_contents($d.'/db.sql', file_get_contents($d.'/db.sql').'-- edit'), 'checksum'],
    'a missing attachment file' => ['missing file', fn (string $d) => unlink($d.'/files/tickets/1/rehearsal-1.txt'), 'missing the file'],
    'an unrecorded extra file' => ['extra file', fn (string $d) => file_put_contents($d.'/files/tickets/1/extra.txt', 'x'), 'unrecorded file'],
    'an altered attachment file' => ['altered file', fn (string $d) => file_put_contents($d.'/files/tickets/1/rehearsal-1.txt', 'tampered'), 'differs'],
]);

it('refuses to restore a set from outside the backups root', function () {
    $manifest = BackupRestore::backup(RehearsalTarget::primary());
    $outside = RehearsalSandbox::root().'/outside-set';
    exec('cp -a '.escapeshellarg($manifest['directory']).' '.escapeshellarg($outside));

    expect(fn () => BackupRestore::restore($outside, RehearsalTarget::restore()))->toThrow(UnsafeRehearsalTarget::class, 'not inside storage/rehearsal/backups');

    BackupRestore::discard($manifest['directory']);
});

describe('cleanup boundaries', function () {
    it('removes the backup set it was given and nothing beside it', function () {
        $keep = BackupRestore::backup(RehearsalTarget::primary())['directory'];
        $drop = BackupRestore::backup(RehearsalTarget::primary())['directory'];

        BackupRestore::discard($drop);

        expect(file_exists($drop))->toBeFalse()
            ->and(is_dir($keep))->toBeTrue()
            ->and(file_exists($this->backups.'/'.RehearsalTarget::MARKER_FILE))->toBeTrue();

        BackupRestore::discard($keep);
    });

    it('refuses a path that merely looks like a backup set but lies outside the backups root', function () {
        $sentinel = RehearsalSandbox::root().'/rehearsal-20260101-000000-abcdef';
        mkdir($sentinel);
        file_put_contents($sentinel.'/keep.txt', 'precious');
        BackupRestore::discard(BackupRestore::backup(RehearsalTarget::primary())['directory']);

        expect(fn () => BackupRestore::discard($sentinel))->toThrow(UnsafeRehearsalTarget::class)
            ->and(file_get_contents($sentinel.'/keep.txt'))->toBe('precious');
    });

    it('refuses a name that is not the shape of a backup set, such as the root itself or its parent', function (string $relative) {
        BackupRestore::discard(BackupRestore::backup(RehearsalTarget::primary())['directory']);
        $target = $this->backups.'/'.$relative;
        @mkdir($target, 0700, true);
        file_put_contents($target.'/keep.txt', 'precious');

        expect(fn () => BackupRestore::discard($target))->toThrow(UnsafeRehearsalTarget::class)
            ->and(file_get_contents($target.'/keep.txt'))->toBe('precious');

        File::deleteDirectory($target);
    })->with(['an arbitrary name' => ['notes'], 'a near miss' => ['rehearsal-2026-bad'], 'a nested path' => ['rehearsal-20260101-000000-abcdef/inner']]);

    it('refuses to follow a symbolic link named like a backup set', function () {
        BackupRestore::discard(BackupRestore::backup(RehearsalTarget::primary())['directory']);
        $outside = RehearsalSandbox::root().'/linked-target';
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'precious');
        $link = $this->backups.'/rehearsal-20260101-000000-abcdef';
        symlink($outside, $link);

        try {
            expect(fn () => BackupRestore::discard($link))->toThrow(UnsafeRehearsalTarget::class)
                ->and(file_get_contents($outside.'/keep.txt'))->toBe('precious');
        } finally {
            unlink($link);
        }
    });

    it('refuses a backup set whose manifest names a different set', function () {
        $one = BackupRestore::backup(RehearsalTarget::primary())['directory'];
        $two = BackupRestore::backup(RehearsalTarget::primary())['directory'];
        copy($two.'/manifest.json', $one.'/manifest.json');

        expect(fn () => BackupRestore::discard($one))->toThrow(UnsafeRehearsalTarget::class, 'does not belong');
        expect(is_dir($one))->toBeTrue();

        file_put_contents($one.'/manifest.json', '{"id":"'.basename($one).'"}');
        BackupRestore::discard($one);
        BackupRestore::discard($two);
    });

    it('will not touch backups under a backups root that lacks the harness marker', function () {
        BackupRestore::discard(BackupRestore::backup(RehearsalTarget::primary())['directory']);
        $set = $this->backups.'/rehearsal-20260101-000000-abcdef';
        mkdir($set);
        file_put_contents($set.'/keep.txt', 'precious');
        unlink($this->backups.'/'.RehearsalTarget::MARKER_FILE);

        expect(fn () => BackupRestore::discard($set))->toThrow(UnsafeRehearsalTarget::class, 'no matching rehearsal marker')
            ->and(file_get_contents($set.'/keep.txt'))->toBe('precious');

        File::deleteDirectory($this->backups);
    });

    it('refuses a rehearsal directory that is unmarked and non-empty, outside the base, or a symbolic link', function () {
        $base = RehearsalTarget::baseDirectory();
        RehearsalFiles::reset($this->backups, RehearsalFiles::BACKUPS);

        $unmarked = $base.'/unmarked';
        mkdir($unmarked);
        file_put_contents($unmarked.'/keep.txt', 'precious');

        $outside = RehearsalSandbox::root().'/application-private';
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'precious');

        $link = $base.'/linked';
        symlink($outside, $link);

        try {
            foreach ([$unmarked, $outside, $link] as $path) {
                expect(fn () => RehearsalFiles::remove($path, RehearsalFiles::BACKUPS))->toThrow(UnsafeRehearsalTarget::class)
                    ->and(fn () => RehearsalFiles::reset($path, RehearsalFiles::BACKUPS))->toThrow(UnsafeRehearsalTarget::class)
                    ->and(file_get_contents($path.'/keep.txt'))->toBe('precious');
            }
        } finally {
            unlink($link);
            File::deleteDirectory($unmarked);
        }
    });

    it('refuses a marker of the wrong kind, so a backups directory is never cleaned as a files root', function () {
        RehearsalFiles::reset($this->backups, RehearsalFiles::BACKUPS);
        file_put_contents($this->backups.'/keep.txt', 'precious');

        expect(fn () => RehearsalFiles::remove($this->backups, RehearsalFiles::kindFor(RehearsalTarget::primary())))->toThrow(UnsafeRehearsalTarget::class)
            ->and(file_get_contents($this->backups.'/keep.txt'))->toBe('precious');

        File::deleteDirectory($this->backups);
    });

    it('reports a removal it could not finish as an error, not as a success', function () {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A root user can remove a read-only directory, so the failure cannot be provoked.');
        }

        RehearsalFiles::reset($this->backups, RehearsalFiles::BACKUPS);
        mkdir($this->backups.'/locked', 0700);
        file_put_contents($this->backups.'/locked/keep.txt', 'x');
        chmod($this->backups.'/locked/keep.txt', 0600);
        chmod($this->backups.'/locked', 0500);

        try {
            expect(fn () => RehearsalFiles::remove($this->backups, RehearsalFiles::BACKUPS))->toThrow(RuntimeException::class, 'Could not fully remove storage/rehearsal/backups')
                ->and(fn () => RehearsalFiles::reset($this->backups, RehearsalFiles::BACKUPS))->toThrow(RuntimeException::class, 'Could not empty storage/rehearsal/backups');
        } finally {
            chmod($this->backups.'/locked', 0700);
        }
    });
});
