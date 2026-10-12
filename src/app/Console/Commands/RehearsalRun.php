<?php

namespace App\Console\Commands;

use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalRunner;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Runs the Release 0.9 migration rehearsal (R0.9-1A): baseline, fixtures, snapshot, optional approved
 * candidate migrations, comparison, and a backup/restore round trip, all inside two dedicated rehearsal
 * databases and one rehearsal file area. It never touches the development, testing or E2E database.
 *
 * Exit codes: 0 every invariant held; 1 the rehearsal ran and found a difference or a broken invariant, or
 * could not remove its own temporary backup; 2 it refused to start (unsafe target, a candidate that is not
 * approved, or another rehearsal running). A refusal changes nothing.
 *
 * Temporary artifacts: the run's backup set is removed when the run passes, and kept (private) when it
 * fails or --keep-backup is given. `rehearsal:clean` removes anything kept.
 *
 * Use `./dev rehearse` (and `./dev rehearse:provision` once). See docs/testing/migration-rehearsal.md.
 */
#[Signature('rehearsal:run {--candidate=* : Name of an approved candidate migration (database/rehearsal/candidates.php) to apply; repeatable} {--no-restore : Skip the backup/restore round trip} {--keep-backup : Keep the backup set of this run even when the run passes (it stays private)} {--json : Print the report as JSON}')]
#[Description('Rehearse Release 0.9 schema changes on deterministic fixtures in an isolated database (never dev, testing or E2E)')]
class RehearsalRun extends Command
{
    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $runner = new RehearsalRunner(MigrationSet::repository(), $json ? null : fn (string $m) => $this->line("  · {$m}"));

        try {
            $report = $runner->run((array) $this->option('candidate'), ! $this->option('no-restore'), (bool) $this->option('keep-backup'));
        } catch (UnsafeRehearsalTarget|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        if ($json) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->report($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $report */
    private function report(array $report): void
    {
        $this->newLine();
        foreach ($report['targets'] as $target) {
            $this->line(sprintf(
                '  Target %-8s %s on %s:%d as %s (env %s)',
                $target['role'], $target['database'], $target['host'], $target['port'], $target['account'] ?? '?', $target['environment']
            ));
        }

        $b = $report['baseline'];
        $this->newLine();
        $this->line("  Baseline: {$b['migrations']} migrations, {$b['fixture_rows']} fixture rows in {$b['fixture_tables']} tables, {$b['files']} private files");
        $this->line("  Baseline snapshot: {$b['snapshot_sha256']}");

        foreach ($b['failures'] as $failure) {
            $this->components->error("Baseline integrity failure: {$failure}");
        }

        foreach ($report['migration']['differences'] as $d) {
            $this->components->error("{$d['code']}: {$d['subject']}".($d['detail'] !== '' ? " ({$d['detail']})" : ''));
        }

        if (isset($report['restore']['error'])) {
            $this->components->error('Backup/restore round trip failed: '.$report['restore']['error']);
        } elseif ($report['restore'] !== null) {
            $r = $report['restore'];
            $this->line("  Backup {$r['backup_id']}: {$r['tables']} tables, {$r['rows']} rows, {$r['files']} files restored; snapshot ".($r['matches_primary'] ? 'identical to the primary' : 'DIFFERS from the primary'));

            foreach ($r['differences'] as $d) {
                $this->components->error("restore {$d['code']}: {$d['subject']}".($d['detail'] !== '' ? " ({$d['detail']})" : ''));
            }
        }

        $c = $report['cleanup'];
        match ($c['backup']) {
            'removed' => $this->line("  Temporary backup {$c['path']} removed."),
            'retained' => $this->components->warn("Backup {$c['path']} RETAINED ({$c['reason']}). It is private (0700/0600); `./dev rehearse:clean` removes it."),
            'failed' => $this->components->error("Temporary backup {$c['path']} could NOT be removed: {$c['error']}"),
            default => null,
        };

        $this->newLine();
        foreach ($report['proves'] as $claim) {
            $this->line("  - {$claim}");
        }

        $report['ok']
            ? $this->components->info('Rehearsal passed.')
            : $this->components->error('Rehearsal FAILED: an invariant did not hold.');
    }
}
