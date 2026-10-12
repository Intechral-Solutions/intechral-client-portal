<?php

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalFiles;
use App\Support\Rehearsal\RehearsalRunner;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A: the whole rehearsal, driven through the same Artisan commands `./dev rehearse` runs. Storage is
 * a throw-away directory and candidate migrations come from a throw-away database path, so the real
 * storage/rehearsal, the committed migrations and the committed manifest are never touched. Each full run
 * takes several seconds: it resets and migrates the rehearsal database.
 */

const PROBE = '2026_10_12_000001_rehearsal_probe';

beforeEach(function () {
    RehearsalSandbox::use();
    File::deleteDirectory(RehearsalTarget::backupsRoot());
});

/** @return array{0: int, 1: array<string, mixed>|null, 2: string} exit code, JSON report (if any), raw output */
function rehearse(array $options = []): array
{
    [$exit, $output] = artisan('rehearsal:run', ['--json' => true] + $options);

    return [$exit, json_decode($output, true), $output];
}

/**
 * Run a command and return [exit code, its own output]. Artisan::output() would return the output of the last
 * NESTED call (the harness runs `migrate` inside the command), so the command gets its own buffer.
 *
 * @return array{0: int, 1: string}
 */
function artisan(string $command, array $parameters = []): array
{
    $buffer = new BufferedOutput;
    $exit = Artisan::call($command, $parameters, $buffer);

    return [$exit, $buffer->fetch()];
}

function backupSets(): array
{
    $root = RehearsalTarget::backupsRoot();

    return is_dir($root) ? array_values(array_filter(scandir($root), fn ($n) => str_starts_with($n, 'rehearsal-'))) : [];
}

/** Exact row counts of every table in a schema the rehearsal must never touch. */
function rowCounts(string $schema): ?array
{
    $tables = DB::select('select table_name as t from information_schema.tables where table_schema = ? and table_type = ? order by 1', [$schema, 'BASE TABLE']);
    if ($tables === []) {
        return null;
    }

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table->t] = (int) DB::selectOne("select count(*) as n from `{$schema}`.`{$table->t}`")->n;
    }

    return $counts;
}

/** A listing of the real, non-sandbox directories the rehearsal must leave alone. */
function realStorageFingerprint(): array
{
    $out = [];
    foreach ([base_path('storage/rehearsal'), base_path('storage/app/private')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $out[$file->getPathname()] = [$file->getSize(), $file->getMTime()];
        }
    }
    ksort($out);

    return $out;
}

/** A migration that adds a nullable column to an existing table. */
function addsColumn(array $extra = []): array
{
    return [PROBE => $extra + ['up' => "Schema::table('task_comments', fn (Blueprint \$t) => \$t->string('rehearsal_probe')->nullable());", 'schema' => ['column+:task_comments.rehearsal_probe']]];
}

it('passes a full rehearsal, removes its own backup, leaves nothing exposed and touches no development data', function () {
    $dev = rowCounts('portal');
    $real = realStorageFingerprint();

    [$exit, $report] = rehearse();

    expect($exit)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and($report['baseline']['failures'])->toBe([])
        ->and($report['migration']['differences'])->toBe([])
        ->and($report['restore']['matches_primary'])->toBeTrue()
        ->and($report['restore']['files'])->toBe(4)
        ->and($report['cleanup']['backup'])->toBe('removed')
        ->and($report['proves'])->toContain('NO candidate migration was exercised. This run proves nothing about any future migration.')
        ->and(backupSets())->toBe([])
        ->and(RehearsalFiles::exposedPaths(RehearsalTarget::baseDirectory()))->toBe([])
        ->and(rowCounts('portal'))->toBe($dev)
        ->and(realStorageFingerprint())->toBe($real);

    foreach ($report['targets'] as $target) {
        expect($target['database'])->toStartWith('intechral_client_portal_rehearsal')
            ->and($target['host'])->toBe('db');
    }
});

it('reproduces the same baseline snapshot on every run', function () {
    [, $first] = rehearse(['--no-restore' => true]);
    [, $second] = rehearse(['--no-restore' => true]);

    expect($first['baseline']['snapshot_sha256'])->toBe($second['baseline']['snapshot_sha256'])
        ->and($first['baseline']['fixture_rows'])->toBe($second['baseline']['fixture_rows'])
        ->and($first['restore'])->toBeNull()
        ->and($first['cleanup']['backup'])->toBe('none');
});

it('keeps the backup of a passing run only when asked to, privately, and cleans it up afterwards', function () {
    [$exit, $report] = rehearse(['--keep-backup' => true]);

    expect($exit)->toBe(0)
        ->and($report['cleanup']['backup'])->toBe('retained')
        ->and(backupSets())->toHaveCount(1)
        ->and(RehearsalFiles::exposedPaths(RehearsalTarget::baseDirectory()))->toBe([]);

    expect(Artisan::call('rehearsal:clean'))->toBe(0)
        ->and(backupSets())->toBe([]);
});

it('refuses a candidate that is not approved with exit 2, before changing anything', function (array $options) {
    $baseline = IntegritySnapshot::hash(RehearsalSandbox::baseline());

    [$exit, $output] = artisan('rehearsal:run', ['--json' => true] + $options);

    expect($exit)->toBe(2)
        ->and($output)->toContain('Migration set refused', 'Nothing was changed')
        ->and(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe($baseline)
        ->and(backupSets())->toBe([]);
})->with([
    'all' => [['--candidate' => ['all']]],
    'a path' => [['--candidate' => ['../../database/migrations/x']]],
    'an unknown name' => [['--candidate' => [PROBE]]],
]);

it('refuses a candidate whose file changed since it was approved, with exit 2', function () {
    $this->app->useDatabasePath(RehearsalSandbox::databasePath(addsColumn(['sha256' => str_repeat('0', 64)])));
    $baseline = IntegritySnapshot::hash(RehearsalSandbox::baseline());

    [$exit, , $output] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

    expect($exit)->toBe(2)
        ->and($output)->toContain('sha256 mismatch')
        ->and(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe($baseline);
});

it('refuses to run, with exit 2 and no change, in a production environment', function () {
    $baseline = IntegritySnapshot::hash(RehearsalSandbox::baseline());
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    expect(Artisan::call('rehearsal:run', ['--json' => true]))->toBe(2)
        ->and(Artisan::call('rehearsal:clean'))->toBe(2);

    $this->app->detectEnvironment(fn () => 'testing');
    config(['app.env' => 'testing']);

    expect(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe($baseline);
});

it('refuses to run or clean while another database session holds the rehearsal lock, and changes nothing', function () {
    $baseline = IntegritySnapshot::hash(RehearsalSandbox::baseline());

    RehearsalSandbox::withLockHeldElsewhere(function () {
        [$exit, , $output] = rehearse(['--no-restore' => true]);
        [$cleanExit, $cleanOutput] = artisan('rehearsal:clean');

        expect($exit)->toBe(2)
            ->and($output)->toContain('another rehearsal is running', 'Nothing was changed')
            ->and($cleanExit)->toBe(2)
            ->and($cleanOutput)->toContain('another rehearsal is running', 'Nothing was changed');
    });

    expect(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe($baseline);
});

it('refuses, with exit 2 and no change, a migration file that is neither pinned nor an approved candidate', function () {
    $baseline = IntegritySnapshot::hash(RehearsalSandbox::baseline());
    $path = RehearsalSandbox::databasePath();
    file_put_contents($path.'/migrations/2026_12_31_000009_surprise_migration.php', '<?php return null;');
    $this->app->useDatabasePath($path);

    [$exit, , $output] = rehearse(['--no-restore' => true]);

    expect($exit)->toBe(2)
        ->and($output)->toContain('undeclared migration file "2026_12_31_000009_surprise_migration"', 'Nothing was changed')
        ->and(IntegritySnapshot::hash(IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe($baseline);
});

describe('candidate migrations', function () {
    it('passes a candidate that changes exactly what it declared, and the restored copy matches', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath(addsColumn()));

        [$exit, $report] = rehearse(['--candidate' => [PROBE]]);

        expect($exit)->toBe(0)
            ->and($report['candidates'])->toBe([PROBE])
            ->and($report['migration']['applied'])->toBe([PROBE])
            ->and($report['migration']['differences'])->toBe([])
            ->and($report['restore']['matches_primary'])->toBeTrue()
            ->and($report['proves'])->toContain('The approved candidate migration(s) were applied to the populated baseline (after the fixtures) and changed exactly the declared structure and no existing record.');
    });

    it('fails, exit 1, when a declared change did not happen', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => '', 'schema' => ['column+:task_comments.rehearsal_probe']]]));

        [$exit, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        expect($exit)->toBe(1)
            ->and(array_column($report['migration']['differences'], 'code'))->toBe(['schema.declared_change_missing']);
    });

    it('fails, exit 1, when a migration loses a record it did not declare', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => "DB::table('task_comments')->orderBy('id')->limit(1)->delete();"]]));

        [$exit, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        expect($exit)->toBe(1)
            ->and(array_column($report['migration']['differences'], 'code'))->toContain('rows.count_changed', 'row.missing');
    });

    it('fails, exit 1, when a migration leaves a dangling reference', function () {
        $up = "DB::statement('set foreign_key_checks = 0'); DB::table('ticket_replies')->orderBy('id')->limit(1)->update(['user_id' => 987654]); DB::statement('set foreign_key_checks = 1');";
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => $up, 'data' => ['ticket_replies']]]));

        [$exit, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        // The change to the row itself was declared; the broken reference it caused was not.
        expect($exit)->toBe(1)
            ->and(array_column($report['migration']['differences'], 'code'))->toContain('check.failed');
    });

    it('applies the candidate to the POPULATED baseline, after the fixtures, never to an empty schema', function () {
        $up = "if (DB::table('users')->count() < 5 || DB::table('tickets')->count() < 5) { throw new RuntimeException('applied to an empty schema'); } "
            ."Schema::table('task_comments', fn (Blueprint \$t) => \$t->string('rehearsal_probe')->nullable());";
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => $up, 'schema' => ['column+:task_comments.rehearsal_probe']]]));

        [$exit, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        expect($exit)->toBe(0)
            ->and($report['migration']['applied_after_fixtures'])->toBeTrue()
            ->and($report['migration']['populated_before']['fixture_rows'])->toBeGreaterThan(200)
            ->and($report['migration']['populated_before']['migrations'])->toBe(count(array_keys(RehearsalSandbox::pinnedBaseline())));
    });

    it('says which approved candidates this run did not exercise', function () {
        $second = '2026_10_12_000002_rehearsal_probe_two';
        $this->app->useDatabasePath(RehearsalSandbox::databasePath(addsColumn() + [$second => ['up' => '']]));

        [$exit, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        expect($exit)->toBe(0)
            ->and($report['migration']['unexercised'])->toBe([$second])
            ->and(implode("\n", $report['proves']))->toContain('NOT exercised', $second);
    });

    it('does not claim the unexercised candidates were rehearsed when none is selected', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath(addsColumn()));

        [$exit, $report] = rehearse(['--no-restore' => true]);

        expect($exit)->toBe(0)
            ->and($report['migration']['applied'])->toBe([])
            ->and($report['migration']['unexercised'])->toBe([PROBE])
            ->and($report['proves'])->toContain('NO candidate migration was exercised. This run proves nothing about any future migration.')
            ->and(implode("\n", $report['proves']))->toContain('NOT exercised', PROBE);
    });
});

describe('failure reporting and temporary artifacts', function () {
    it('retains a failed run\'s backup, private, reports the failure with exit 1, and rehearsal:clean removes it', function () {
        $runner = new RehearsalRunner(MigrationSet::repository(), function (string $message) {
            if (str_starts_with($message, 'Restoring')) {
                $set = backupSets()[0];
                file_put_contents(RehearsalTarget::backupsRoot()."/{$set}/db.sql", '-- tampered', FILE_APPEND);
            }
        });

        $report = $runner->run();

        expect($report['ok'])->toBeFalse()
            ->and($report['restore']['error'])->toContain('incomplete backup set')
            ->and($report['cleanup']['backup'])->toBe('retained')
            ->and($report['cleanup']['reason'])->toContain('failed')
            ->and(backupSets())->toHaveCount(1)
            ->and(RehearsalFiles::exposedPaths(RehearsalTarget::baseDirectory()))->toBe([]);

        expect(Artisan::call('rehearsal:clean'))->toBe(0)
            ->and(backupSets())->toBe([]);
    });

    it('fails, exit 1, on a schema change it did not declare, and says the backup was retained', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath(addsColumn(['schema' => []])));

        [$exit, $output] = artisan('rehearsal:run', ['--candidate' => [PROBE]]);

        expect($exit)->toBe(1)
            ->and($output)->toContain('schema.undeclared_change: column+:task_comments.rehearsal_probe', 'Rehearsal FAILED', 'RETAINED')
            ->and(backupSets())->toHaveCount(1);

        Artisan::call('rehearsal:clean');
    });

    it('fails the run, visibly, when the temporary backup cannot be removed', function () {
        $runner = new RehearsalRunner(MigrationSet::repository(), function (string $message) {
            // After the restore has been verified, so only the cleanup is affected: the set now claims to be another.
            if (str_starts_with($message, 'Capturing the restored')) {
                $set = backupSets()[0];
                file_put_contents(RehearsalTarget::backupsRoot()."/{$set}/manifest.json", '{"id":"rehearsal-20260101-000000-abcdef"}');
            }
        });

        $report = $runner->run();

        expect($report['ok'])->toBeFalse()
            ->and($report['cleanup']['backup'])->toBe('failed')
            ->and($report['cleanup']['error'])->toContain('does not belong')
            ->and(backupSets())->toHaveCount(1);

        Artisan::call('rehearsal:clean');
    });

    it('reports an exception during the round trip as a failed rehearsal, not a stack trace', function () {
        $runner = new RehearsalRunner(MigrationSet::repository(), function (string $message) {
            if (str_starts_with($message, 'Restoring')) {
                unlink(RehearsalTarget::backupsRoot().'/'.backupSets()[0].'/manifest.json');
            }
        });

        $report = $runner->run();

        expect($report['ok'])->toBeFalse()
            ->and($report['restore']['error'])->toContain('RuntimeException')
            ->and($report['cleanup']['backup'])->toBe('retained');

        Artisan::call('rehearsal:clean');
    });

    it('cleans only rehearsal resources: databases, marked files and backups, never a sentinel beside them', function () {
        RehearsalSandbox::baseline();
        $sentinel = RehearsalSandbox::root().'/storage/app-private-sentinel.txt';
        file_put_contents($sentinel, 'precious');
        $beside = RehearsalTarget::baseDirectory().'/not-ours.txt';
        file_put_contents($beside, 'precious');
        $dev = rowCounts('portal');

        expect(Artisan::call('rehearsal:clean'))->toBe(0)
            ->and(file_get_contents($sentinel))->toBe('precious')
            ->and(file_get_contents($beside))->toBe('precious')
            ->and(is_dir(RehearsalTarget::primary()->filesRoot()))->toBeFalse()
            ->and(rowCounts('portal'))->toBe($dev)
            ->and(DB::select('select table_name from information_schema.tables where table_schema = ?', [RehearsalTarget::primary()->database()]))->toBe([]);

        unlink($sentinel);
        unlink($beside);
    });

    it('refuses to clean when the backups directory holds files the harness cannot prove are its own', function () {
        RehearsalFiles::reset(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);
        $stray = RehearsalTarget::backupsRoot().'/notes.txt';
        file_put_contents($stray, 'precious');
        unlink(RehearsalTarget::backupsRoot().'/'.RehearsalTarget::MARKER_FILE);

        expect(Artisan::call('rehearsal:clean'))->toBe(2)
            ->and(file_get_contents($stray))->toBe('precious');
    });
});

describe('failure classification', function () {
    it('reports a candidate that throws as a failure after the start, exit 1, never as "nothing changed"', function () {
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => "throw new RuntimeException('candidate exploded');"]]));

        [$exit, $output] = artisan('rehearsal:run', ['--candidate' => [PROBE], '--no-restore' => true]);
        [, $report] = rehearse(['--candidate' => [PROBE], '--no-restore' => true]);

        expect($exit)->toBe(1)
            ->and($output)->toContain('Rehearsal stopped during "candidate migrations"', 'may be partly changed', 'candidate exploded')
            ->and($output)->not->toContain('Nothing was changed')
            ->and($report['ok'])->toBeFalse()
            ->and($report['failure']['phase'])->toBe('candidate migrations')
            ->and($report['failure']['state'])->toContain('partly changed')
            ->and($report['baseline']['fixture_rows'])->toBeGreaterThan(200)   // what was known before the failure is still reported
            ->and(backupSets())->toBe([]);
    });

    it('reports a guard refusal that happens after the run began (a candidate removed the marker) as a failure, exit 1', function () {
        RehearsalSandbox::baseline();
        $this->app->useDatabasePath(RehearsalSandbox::databasePath([PROBE => ['up' => "Schema::dropIfExists('rehearsal_marker');"]]));

        try {
            [$exit, $output] = artisan('rehearsal:run', ['--candidate' => [PROBE], '--no-restore' => true]);

            expect($exit)->toBe(1)
                ->and($output)->toContain('Rehearsal stopped during', 'UnsafeRehearsalTarget', 'may be partly changed')
                ->and($output)->not->toContain('Nothing was changed');
        } finally {
            // The database is now "unidentified" (tables, no marker) and the harness will, rightly, not touch it.
            Schema::connection(RehearsalTarget::primary()->connectionName())->dropAllTables();
        }
    });

    it('stops a cleanup that fails part-way with exit 1, naming the step and what was already cleaned', function () {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A root user can remove a read-only directory, so the failure cannot be provoked.');
        }

        RehearsalSandbox::baseline();
        $restore = RehearsalTarget::restore();
        $files = $restore->filesRoot();
        RehearsalFiles::reset($files, RehearsalFiles::kindFor($restore));
        mkdir($files.'/locked', 0700);
        file_put_contents($files.'/locked/keep.txt', 'x');
        chmod($files.'/locked/keep.txt', 0600);
        chmod($files.'/locked', 0500);   // nothing inside it can be removed

        try {
            [$exit, $output] = artisan('rehearsal:clean');

            expect($exit)->toBe(1)
                ->and($output)->toContain('Cleanup stopped part-way while cleaning '.$restore->database(), 'already cleaned: '.RehearsalTarget::primary()->database(), 'Could not fully remove')
                ->and($output)->not->toContain('Nothing was changed');
        } finally {
            chmod($files.'/locked', 0700);
        }

        // Once the cause is fixed, running it again finishes the job.
        expect(Artisan::call('rehearsal:clean'))->toBe(0)
            ->and(is_dir($files))->toBeFalse();
    });
});
