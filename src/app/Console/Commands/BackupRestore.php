<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupRefused;
use App\Support\Backup\Recovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recovery restore: loads an OPENED, verified backup set into this installation's EMPTY database and private disk,
 * proves the result equals the set, then invalidates sessions, the database cache and password reset tokens
 * (Release 0.9 WP-B1; {@see Recovery}; docs/operations/backup-and-recovery.md).
 *
 * It never restores over data, and never from an unauthenticated set: it refuses unless --expect-digest is the digest
 * recorded when the set was made, the application is in maintenance mode, --confirm-database names
 * the configured database, that database has no tables, and the private disk has no `tickets/`. Queued and failed
 * jobs are reported for review and never replayed; the application stays down until the operator brings it up.
 * `--verify-only` changes nothing and repeats the comparison.
 *
 * Exit codes: 0 restored (or, with --verify-only, still equal to the set); 1 the restore failed part-way or the
 * restored state does not equal the set (the application stays down; start again from empty targets); 2 refused
 * before anything was changed.
 */
#[Signature('backup:restore {set : The opened set directory} {--confirm-database= : The configured database name, typed by the operator} {--expect-digest= : The set digest recorded when the set was made (required)} {--verify-only : Change nothing; compare this installation with the set}')]
#[Description('Restore an opened backup set into this installation\'s empty database and private disk, then verify it')]
class BackupRestore extends Command
{
    public function handle(): int
    {
        $recovery = Recovery::forApplication(fn (string $line) => $this->line("  · {$line}"));
        $set = (string) $this->argument('set');
        $digest = (string) $this->option('expect-digest');

        try {
            if ($this->option('verify-only')) {
                return self::report($this, $recovery->verify($set, $digest), 'Nothing was changed.');
            }

            $report = $recovery->restore($set, (string) $this->option('confirm-database'), $digest);
        } catch (BackupRefused $e) {
            $this->components->error($e->getMessage());
            $this->line('  Refused before the restore started. Nothing was changed.');

            return 2;
        }

        return self::report($this, $report, $report['ok']
            ? 'The application is still in maintenance mode. Review the queue above, then follow the procedure before `php artisan up`.'
            : 'The application stays in maintenance mode. Do not bring it up: start again from an empty database and private disk.');
    }

    /** @param  array{ok: bool, phase: ?string, violations: list<string>, facts: array<string, mixed>}  $report */
    public static function report(Command $command, array $report, string $closing): int
    {
        $command->newLine();

        if ($report['ok']) {
            $command->components->info('PASSED: the restored state equals the set and every check held.');
        } else {
            $command->components->error('FAILED'.($report['phase'] !== null ? " during \"{$report['phase']}\"" : '').':');
            foreach ($report['violations'] as $violation) {
                $command->line("  - {$violation}");
            }
        }

        $facts = $report['facts'];
        if (isset($facts['tickets'])) {
            $command->line(sprintf('  Restored: %d tables, %d rows, %d tickets (%d canonical, %d non-canonical), %d attachments, %d migrations',
                $facts['tables'], $facts['rows'], $facts['tickets']['count'], $facts['tickets']['canonical'], $facts['tickets']['non_canonical'],
                $facts['attachments'], $facts['migrations']));
            $command->line('  Ticket identity fingerprint (from the restored copy): '.$facts['tickets']['identity_sha256']);
        }
        if (isset($facts['queue'])) {
            $jobs = $facts['queue']['jobs'];
            $command->line('  Queue to review before processing resumes: '.($jobs === [] ? 'no queued jobs' : implode(', ', array_map(fn ($n, $c) => "{$c} x {$n}", array_keys($jobs), $jobs)))
                ."; {$facts['queue']['failed_jobs']} failed job(s). Nothing was replayed.");
        }
        if (isset($facts['runtime_rows_removed'])) {
            $command->line('  Invalidated: '.implode(', ', array_map(fn ($t, $n) => "{$t} ({$n})", array_keys($facts['runtime_rows_removed']), $facts['runtime_rows_removed'])));
        }
        if (isset($facts['rows_now'])) {
            foreach ($facts['rows_now'] as $table => $rows) {
                $command->line("  {$table}: {$rows} row(s) now, {$facts['rows_in_set'][$table]} in the set");
            }
        }

        $command->line('  '.$closing);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
