<?php

namespace App\Support\Rehearsal;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The bounded rehearsal procedure (Release 0.9, R0.9-0 §8):
 *
 *   1. validate the candidate selection and both targets, before anything is changed;
 *   2. reset the primary database and apply the committed BASELINE migrations;
 *   3. seed the deterministic fixtures and write their private files;
 *   4. capture the baseline snapshot (it must have no failing check);
 *   5. apply the selected, approved candidate migrations (none by default);
 *   6. capture the post-migration snapshot, pinned to the baseline's columns, and compare;
 *   7. take a backup of the primary, restore it into the restore database, snapshot it, and compare it with
 *      the primary exactly;
 *   8. clean up the one temporary artifact this invocation made, its backup set: removed when the run
 *      passed (or when --keep-backup was not asked for), retained, private, when the run failed so the
 *      failure can be diagnosed. {@see cleanupBackup()}.
 *
 * Only one rehearsal runs at a time (an exclusive lock under storage/rehearsal): two would reset the same
 * databases under each other.
 *
 * The report says what was PROVEN. With no candidate it proves the baseline, the snapshot and the round
 * trip, and it says plainly that it proves nothing about any future migration.
 */
final class RehearsalRunner
{
    public function __construct(
        private readonly MigrationSet $migrations,
        /** @var callable(string): void|null */
        private $progress = null,
    ) {}

    /**
     * @param  list<string>  $candidates  names of approved candidate migrations to apply (none by default)
     * @return array<string, mixed>
     *
     * @throws UnsafeRehearsalTarget|\InvalidArgumentException before anything is changed
     */
    public function run(array $candidates = [], bool $roundTrip = true, bool $keepBackup = false): array
    {
        // 1. Everything that can be refused is refused first.
        $selected = $this->migrations->select($candidates);
        $primary = RehearsalTarget::primary();
        $restore = RehearsalTarget::restore();
        $targets = ['primary' => TargetGuard::assert($primary)];
        if ($roundTrip) {
            $targets['restore'] = TargetGuard::assert($restore);
        }

        return $this->locked(fn () => $this->execute($selected, $primary, $restore, $targets, $roundTrip, $keepBackup));
    }

    /**
     * @param  list<array{name: string, path: string, schema: list<string>, data: list<string>}>  $selected
     * @param  array<string, mixed>  $targets
     * @return array<string, mixed>
     */
    private function execute(array $selected, RehearsalTarget $primary, RehearsalTarget $restore, array $targets, bool $roundTrip, bool $keepBackup): array
    {
        // 2. Baseline schema.
        $this->say('Resetting the primary rehearsal database');
        RehearsalDatabase::reset($primary);
        $baselineNames = $this->migrations->baselineNames();
        $this->say('Applying '.count($baselineNames).' baseline migration(s)');
        RehearsalDatabase::migrate($primary, $this->migrations->baselinePaths());

        // 3. Fixtures.
        $this->say('Seeding deterministic fixtures');
        $inventory = RehearsalDatabase::within($primary, fn () => (new BaselineFixtures($primary))->seed());

        // 4. Baseline snapshot.
        $this->say('Capturing the baseline snapshot');
        $baseline = IntegritySnapshot::capture($primary);
        $pinned = IntegritySnapshot::pinnedColumns($baseline);
        $baselineFailures = IntegritySnapshot::failures($baseline);

        $report = [
            'ok' => true,
            'targets' => $targets,
            'candidates' => array_column($selected, 'name'),
            'baseline' => [
                'migrations' => count($baselineNames),
                'fixture_rows' => array_sum($inventory),
                'fixture_tables' => count($inventory),
                'files' => count($baseline['files']),
                'snapshot_sha256' => IntegritySnapshot::hash($baseline),
                'failures' => $baselineFailures,
            ],
            'migration' => ['differences' => [], 'applied' => []],
            'restore' => null,
            'cleanup' => ['backup' => 'none'],
            'proves' => [],
        ];

        if ($baselineFailures !== []) {
            // A broken baseline makes every later comparison meaningless. Stop and say so.
            $report['ok'] = false;

            return $report;
        }

        // 5 and 6. Candidates and the comparison.
        $current = $baseline;
        if ($selected !== []) {
            $names = array_column($selected, 'name');
            $this->say('Applying '.count($names).' candidate migration(s): '.implode(', ', $names));
            RehearsalDatabase::migrate($primary, array_column($selected, 'path'));

            $this->say('Capturing the post-migration snapshot and comparing');
            $current = IntegritySnapshot::capture($primary, $pinned);
            $differences = SnapshotComparator::compare(
                $baseline,
                $current,
                array_merge(...array_column($selected, 'schema')),
                array_merge(...array_column($selected, 'data')),
                $names,
            );
            $report['migration'] = ['applied' => $names, 'differences' => $differences];
            $report['ok'] = $differences === [];
        } else {
            $this->say('No candidate selected: re-capturing the baseline to prove the snapshot is repeatable');
            $again = IntegritySnapshot::capture($primary, $pinned);
            $differences = SnapshotComparator::compare($baseline, $again);
            $report['migration'] = ['applied' => [], 'differences' => $differences];
            $report['ok'] = $differences === [];
        }

        // 7. Backup and restore round trip, from whatever state the primary is now in. A failure here is a
        // failed rehearsal with a report, not a stack trace, and the backup set is kept for diagnosis.
        $backupDirectory = null;
        if ($roundTrip) {
            try {
                $this->say('Backing up the primary database and its private files');
                $manifest = BackupRestore::backup($primary);
                $backupDirectory = $manifest['directory'];

                $this->say('Restoring into the restore database');
                $restored = BackupRestore::restore($backupDirectory, $restore);

                $this->say('Capturing the restored snapshot and comparing');
                $reference = IntegritySnapshot::capture($primary, $pinned);
                $copy = IntegritySnapshot::capture($restore, $pinned);
                $roundTripDifferences = SnapshotComparator::compare($reference, $copy);

                $report['restore'] = [
                    'backup_id' => $manifest['id'],
                    'dump_bytes' => $manifest['dump']['bytes'],
                    'tables' => $restored['tables'],
                    'rows' => $restored['rows'],
                    'files' => $restored['files'],
                    'snapshot_sha256' => IntegritySnapshot::hash($copy),
                    'matches_primary' => IntegritySnapshot::hash($reference) === IntegritySnapshot::hash($copy),
                    'differences' => $roundTripDifferences,
                ];
                $report['ok'] = $report['ok'] && $roundTripDifferences === [];
            } catch (Throwable $e) {
                $report['restore'] = ['error' => class_basename($e).': '.self::oneLine($e)];
                $report['ok'] = false;
            }
        }

        // 8. The backup set is this invocation's only temporary artifact.
        $report['cleanup'] = $this->cleanupBackup($backupDirectory, $report['ok'], $keepBackup);
        $report['ok'] = $report['ok'] && $report['cleanup']['backup'] !== 'failed';

        $report['proves'] = $this->claims($selected, $roundTrip);

        return $report;
    }

    /** Empty both rehearsal databases and delete every rehearsal file and backup. Rehearsal resources only. */
    public function clean(): array
    {
        return $this->locked(fn () => $this->wipe());
    }

    /** @return list<string> */
    private function wipe(): array
    {
        $cleaned = [];

        foreach ([RehearsalTarget::primary(), RehearsalTarget::restore()] as $target) {
            RehearsalDatabase::clean($target);
            $cleaned[] = $target->database();
        }

        if (is_dir(RehearsalTarget::backupsRoot())) {
            RehearsalFiles::remove(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);
            $cleaned[] = 'backups';
        }

        return $cleaned;
    }

    /**
     * Apply the retention policy to the backup set THIS invocation made (never to any other):
     *  - the run passed and --keep-backup was not given: remove it;
     *  - the run failed: retain it, private (0700/0600, verified when it was written), for diagnosis. The
     *    next `rehearsal:clean` removes it;
     *  - --keep-backup: retain it.
     * A removal that fails is reported as such and fails the run: a leftover must never be silent.
     *
     * @return array{backup: string, path?: string, reason?: string, error?: string}
     */
    private function cleanupBackup(?string $directory, bool $passed, bool $keep): array
    {
        if ($directory === null) {
            return ['backup' => 'none'];
        }

        $relative = 'storage/rehearsal/backups/'.basename($directory);

        if (! $passed || $keep) {
            return ['backup' => 'retained', 'path' => $relative, 'reason' => $passed ? 'requested with --keep-backup' : 'the rehearsal failed; kept for diagnosis'];
        }

        try {
            BackupRestore::discard($directory);
        } catch (Throwable $e) {
            return ['backup' => 'failed', 'path' => $relative, 'error' => self::oneLine($e)];
        }

        return ['backup' => 'removed', 'path' => $relative];
    }

    /** A failure's message on one line, bounded, with no stack trace. Paths and reasons only. */
    private static function oneLine(Throwable $e): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 400, '…');
    }

    /**
     * Run $work holding the rehearsal lock, or refuse because another rehearsal holds it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(callable $work)
    {
        $base = RehearsalTarget::baseDirectory();
        if (is_link($base)) {
            throw new UnsafeRehearsalTarget(['storage/rehearsal is a symbolic link']);
        }
        File::ensureDirectoryExists($base, 0700);

        $previous = umask(0077);
        $handle = fopen($base.DIRECTORY_SEPARATOR.'.lock', 'cb');
        umask($previous);

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new UnsafeRehearsalTarget(['another rehearsal is running (storage/rehearsal/.lock is held); two would reset the same databases under each other']);
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function claims(array $selected, bool $roundTrip): array
    {
        $claims = ['The baseline schema, the fixtures and the snapshot are internally consistent and repeatable.'];

        if ($selected === []) {
            $claims[] = 'NO candidate migration was exercised. This run proves nothing about any future migration.';
        } else {
            $claims[] = 'The approved candidate migration(s) changed exactly the declared structure and no existing record.';
        }

        if ($roundTrip) {
            $claims[] = 'A consistent backup of the database and its private files restored into a separate database with an identical snapshot.';
            $claims[] = 'The backup tool is the rehearsal harness\'s PHP logical dump, not the production tool, and APP_KEY recovery is not exercised.';
        }

        return $claims;
    }

    private function say(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }
}
