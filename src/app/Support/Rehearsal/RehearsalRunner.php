<?php

namespace App\Support\Rehearsal;

use Throwable;

/**
 * The bounded rehearsal procedure (Release 0.9, R0.9-0 §8):
 *
 *   1. validate the migration set, the candidate selection and both targets, before anything is changed;
 *   2. reset the primary database and apply the PINNED baseline migrations, and nothing else;
 *   3. seed the deterministic fixtures and write their private files;
 *   4. capture the baseline snapshot (it must have no failing check);
 *   5. apply the selected, approved candidate migrations (none by default) to that POPULATED database;
 *   6. capture the post-migration snapshot, pinned to the baseline's columns, and compare;
 *   7. take a backup of the primary, restore it into the restore database, snapshot it, and compare it with
 *      the primary exactly;
 *   8. clean up the one temporary artifact this invocation made, its backup set: removed when the run
 *      passed (or when --keep-backup was not asked for), retained, private, when the run failed so the
 *      failure can be diagnosed. {@see cleanupBackup()}.
 *
 * Two kinds of failure, kept apart because they promise different things:
 *
 *  - a REFUSAL, before step 2 (an unapproved or drifted migration set, an unsafe target, another rehearsal
 *    holding the lock): an exception, exit 2, and nothing was changed;
 *  - a FAILURE, from step 2 on: reported in the result (`ok` false, a `failure` entry naming the step), exit 1.
 *    The rehearsal databases and files may be partly changed from that point; they hold only fixtures, and
 *    the next run or `rehearsal:clean` resets them. Nothing outside them is ever touched.
 *
 * Only one rehearsal runs at a time, whichever path started it ({@see RehearsalLock}): two would reset the same
 * databases under each other.
 *
 * The report says what was PROVEN. With no candidate it proves the baseline, the snapshot and the round
 * trip, and it says plainly that it proves nothing about any future migration.
 */
final class RehearsalRunner
{
    private string $phase = 'validation';

    private ?string $backupDirectory = null;

    /** The report so far. Kept on the runner so a failure at any step still reports everything known by then. */
    private array $report = [];

    public function __construct(
        private readonly MigrationSet $migrations,
        /** @var callable(string): void|null */
        private $progress = null,
    ) {}

    /**
     * @param  list<string>  $candidates  names of approved candidate migrations to apply (none by default)
     * @return array<string, mixed>
     *
     * @throws UnsafeRehearsalTarget|\InvalidArgumentException a refusal; raised before anything is changed
     */
    public function run(array $candidates = [], bool $roundTrip = true, bool $keepBackup = false): array
    {
        // 1. Everything that can be refused is refused first, without opening a connection to a target the
        // configuration alone has ruled out, and then again under the lock (another run may have just ended).
        $selected = $this->migrations->select($candidates);
        $primary = RehearsalTarget::primary();
        $restore = RehearsalTarget::restore();
        TargetGuard::assertConfiguration($primary);
        if ($roundTrip) {
            TargetGuard::assertConfiguration($restore);
        }

        return RehearsalLock::hold(function () use ($selected, $primary, $restore, $roundTrip, $keepBackup) {
            $targets = ['primary' => TargetGuard::assert($primary)];
            if ($roundTrip) {
                $targets['restore'] = TargetGuard::assert($restore);
            }

            $this->report = $this->skeleton($selected, $targets);

            // From here on the rehearsal databases and files are being changed.
            try {
                return $this->execute($selected, $primary, $restore, $roundTrip, $keepBackup);
            } catch (Throwable $e) {
                return $this->failed($e);
            }
        });
    }

    /**
     * @param  list<array{name: string, path: string, schema: list<string>, data: list<string>}>  $selected
     * @param  array<string, mixed>  $targets
     * @return array<string, mixed>
     */
    private function skeleton(array $selected, array $targets): array
    {
        $this->phase = 'validation';
        $this->backupDirectory = null;
        $names = array_column($selected, 'name');

        return [
            'ok' => true,
            'targets' => $targets,
            'candidates' => $names,
            'baseline' => [
                'migrations' => 0,
                'fixture_rows' => 0,
                'fixture_tables' => 0,
                'files' => 0,
                'snapshot_sha256' => '',
                'failures' => [],
            ],
            'migration' => [
                'differences' => [],
                'applied' => [],
                'unexercised' => array_values(array_diff($this->migrations->candidateNames(), $names)),
            ],
            'restore' => null,
            'cleanup' => ['backup' => 'none'],
            'failure' => null,
            'proves' => [],
        ];
    }

    /**
     * @param  list<array{name: string, path: string, schema: list<string>, data: list<string>}>  $selected
     * @return array<string, mixed>
     */
    private function execute(array $selected, RehearsalTarget $primary, RehearsalTarget $restore, bool $roundTrip, bool $keepBackup): array
    {
        $report = &$this->report;

        // 2. Baseline schema: exactly the pinned migrations, and the migrations table must say so.
        $this->phase = 'reset';
        $this->say('Resetting the primary rehearsal database');
        RehearsalDatabase::reset($primary);
        $baselineNames = $this->migrations->baselineNames();

        $this->phase = 'baseline migrations';
        $this->say('Applying '.count($baselineNames).' pinned baseline migration(s)');
        RehearsalDatabase::migrate($primary, $this->migrations->baselinePaths());

        $recorded = RehearsalDatabase::ran($primary);
        $expected = $baselineNames;
        sort($expected);
        if ($recorded !== $expected) {
            throw new \RuntimeException('The baseline migrations recorded as run differ from the pinned baseline (extra: '
                .(implode(', ', array_diff($recorded, $expected)) ?: 'none').'; missing: '.(implode(', ', array_diff($expected, $recorded)) ?: 'none').').');
        }

        // 3. Fixtures, written against the schema as it is BEFORE any candidate.
        $this->phase = 'fixtures';
        $this->say('Seeding deterministic fixtures');
        $inventory = RehearsalDatabase::within($primary, fn () => (new BaselineFixtures($primary))->seed());
        if (array_sum($inventory) === 0) {
            throw new \RuntimeException('The fixtures wrote no rows, so a candidate would be rehearsed against an empty schema, not populated data.');
        }

        $report['baseline']['migrations'] = count($baselineNames);
        $report['baseline']['fixture_rows'] = array_sum($inventory);
        $report['baseline']['fixture_tables'] = count($inventory);

        // 4. Baseline snapshot.
        $this->phase = 'baseline snapshot';
        $this->say('Capturing the baseline snapshot');
        $baseline = IntegritySnapshot::capture($primary);
        $pinned = IntegritySnapshot::pinnedColumns($baseline);
        $baselineFailures = IntegritySnapshot::failures($baseline);

        $report['baseline'] = [
            'migrations' => count($baselineNames),
            'fixture_rows' => array_sum($inventory),
            'fixture_tables' => count($inventory),
            'files' => count($baseline['files']),
            'snapshot_sha256' => IntegritySnapshot::hash($baseline),
            'failures' => $baselineFailures,
        ];

        if ($baselineFailures !== []) {
            // A broken baseline makes every later comparison meaningless. Stop and say so.
            $report['ok'] = false;
            $report['proves'] = $this->claims($selected, $roundTrip, $report['migration']['unexercised']);

            return $report;
        }

        // 5 and 6. Candidates and the comparison.
        if ($selected !== []) {
            $names = array_column($selected, 'name');

            // The upgrade is only real if no candidate has been applied yet and the fixtures are in place.
            $already = array_intersect($names, $recorded);
            if ($already !== []) {
                throw new \RuntimeException('A candidate migration was already applied before the fixtures were seeded: '.implode(', ', $already).'.');
            }

            $this->phase = 'candidate migrations';
            $this->say('Applying '.count($names).' candidate migration(s) to the populated database: '.implode(', ', $names));
            RehearsalDatabase::migrate($primary, array_column($selected, 'path'));

            $this->phase = 'post-migration snapshot';
            $this->say('Capturing the post-migration snapshot and comparing');
            $current = IntegritySnapshot::capture($primary, $pinned);
            $differences = SnapshotComparator::compare(
                $baseline,
                $current,
                array_merge(...array_column($selected, 'schema')),
                array_merge(...array_column($selected, 'data')),
                $names,
            );
            $report['migration'] = [
                'applied' => $names,
                'differences' => $differences,
                'unexercised' => $report['migration']['unexercised'],
                // The evidence that this was an upgrade of existing data, not an installation on an empty schema.
                'applied_after_fixtures' => true,
                'populated_before' => ['migrations' => count($recorded), 'fixture_rows' => array_sum($inventory), 'fixture_tables' => count($inventory)],
            ];
            $report['ok'] = $differences === [];
        } else {
            $this->phase = 'repeat snapshot';
            $this->say('No candidate selected: re-capturing the baseline to prove the snapshot is repeatable');
            $again = IntegritySnapshot::capture($primary, $pinned);
            $differences = SnapshotComparator::compare($baseline, $again);
            $report['migration']['differences'] = $differences;
            $report['ok'] = $differences === [];
        }

        // 7. Backup and restore round trip, from whatever state the primary is now in. A failure here is a
        // failed rehearsal with a report, not a stack trace, and the backup set is kept for diagnosis.
        if ($roundTrip) {
            try {
                $this->phase = 'backup';
                $this->say('Backing up the primary database and its private files');
                $manifest = BackupRestore::backup($primary);
                $this->backupDirectory = $manifest['directory'];

                $this->phase = 'restore';
                $this->say('Restoring into the restore database');
                $restored = BackupRestore::restore($this->backupDirectory, $restore);

                $this->phase = 'restored snapshot';
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
        $this->phase = 'cleanup';
        $report['cleanup'] = $this->cleanupBackup($this->backupDirectory, $report['ok'], $keepBackup);
        $report['ok'] = $report['ok'] && $report['cleanup']['backup'] !== 'failed';

        $report['proves'] = $this->claims($selected, $roundTrip, $report['migration']['unexercised']);

        return $report;
    }

    /**
     * A failure after the rehearsal began: the report says which step, what went wrong, and that the rehearsal
     * resources may be partly changed. (Everything after the backup set is written runs inside the round-trip
     * guard, which keeps the set and reports a restore error instead, so no backup is left unaccounted for here.)
     *
     * @return array<string, mixed>
     */
    private function failed(Throwable $e): array
    {
        $report = $this->report;
        $report['ok'] = false;
        $report['failure'] = [
            'phase' => $this->phase,
            'error' => class_basename($e).': '.self::oneLine($e),
            'state' => 'The rehearsal databases and files may be partly changed by this run. They hold fixtures only; `./dev rehearse:clean` or the next `./dev rehearse` resets them. The development and testing databases are never touched.',
        ];

        $report['proves'] = [];

        return $report;
    }

    /**
     * Empty both rehearsal databases and delete every rehearsal file and backup. Rehearsal resources only.
     *
     * Everything that can be refused is refused before the first change; a failure after that raises
     * {@see RehearsalInterrupted}, which says what had already been cleaned.
     *
     * @return list<string>
     *
     * @throws UnsafeRehearsalTarget a refusal; nothing was changed
     * @throws RehearsalInterrupted a failure after cleaning began
     */
    public function clean(): array
    {
        $targets = [RehearsalTarget::primary(), RehearsalTarget::restore()];
        foreach ($targets as $target) {
            TargetGuard::assertConfiguration($target);
        }

        return RehearsalLock::hold(function () use ($targets) {
            foreach ($targets as $target) {
                TargetGuard::assert($target);
            }
            RehearsalFiles::assertSafe(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);

            return $this->wipe($targets);
        });
    }

    /**
     * @param  list<RehearsalTarget>  $targets
     * @return list<string>
     */
    private function wipe(array $targets): array
    {
        $cleaned = [];
        $step = null;

        try {
            foreach ($targets as $target) {
                $step = $target->database();
                RehearsalDatabase::clean($target);
                $cleaned[] = $step;
            }

            if (is_dir(RehearsalTarget::backupsRoot())) {
                $step = 'the backups directory';
                RehearsalFiles::remove(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);
                $cleaned[] = 'backups';
            }
        } catch (Throwable $e) {
            throw new RehearsalInterrupted($cleaned, (string) $step, $e);
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
     * @param  list<array{name: string, path: string, schema: list<string>, data: list<string>}>  $selected
     * @param  list<string>  $unexercised  approved candidates this run did not apply
     * @return list<string>
     */
    private function claims(array $selected, bool $roundTrip, array $unexercised): array
    {
        $claims = ['The pinned baseline schema, the fixtures and the snapshot are internally consistent and repeatable.'];

        if ($selected === []) {
            $claims[] = 'NO candidate migration was exercised. This run proves nothing about any future migration.';
        } else {
            $claims[] = 'The approved candidate migration(s) were applied to the populated baseline (after the fixtures) and changed exactly the declared structure and no existing record.';
        }

        if ($selected !== [] && $unexercised !== []) {
            $claims[] = count($unexercised).' other approved candidate(s) were NOT exercised by this run: '.implode(', ', $unexercised).'.';
        } elseif ($selected === [] && $unexercised !== []) {
            $claims[] = count($unexercised).' approved candidate(s) exist and were NOT exercised: '.implode(', ', $unexercised).'.';
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
