<?php

namespace App\Support\Backup;

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalLock;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\TargetGuard;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;

/**
 * The restore drill (Release 0.9 WP-B1): restore one opened set into the disposable rehearsal RESTORE database and
 * its private-file root, and prove it.
 *
 * It can only ever write to the rehearsal restore target. That target, and every protection around it, belongs to
 * the migration-rehearsal harness and is reused unchanged: the names are constants, the environment must be `local`
 * or `testing`, the host is `db`, the database is exactly `intechral_client_portal_rehearsal_restore`, the account is
 * not root and holds no global privilege, the database is empty or carries the harness marker, and the files live
 * under `storage/rehearsal` ({@see TargetGuard}), all checked again immediately before each destructive step. One
 * rehearsal or drill runs at a time ({@see RehearsalLock}). Nothing here reads `DB_DATABASE`, so it cannot be pointed
 * at a staging or production database.
 *
 * What passing proves: the set verifies ({@see SetVerifier}); its dump loads with the `mariadb` client; the restored
 * database and files equal the set row for row and file for file ({@see RestoredState}); the escrowed APP_KEY
 * decrypts the canary; and the rehearsal invariant checks hold on the restored data ({@see IntegritySnapshot}): every
 * foreign-key and logical reference resolves (no reply, status history, task, time entry or attachment points at a
 * missing ticket), ticket references are canonical and unique, every attachment row has its file inside its own
 * `tickets/{id}/` directory, and no file or directory lacks its row. The runtime-state cleanup a recovery performs is
 * rehearsed too. Restored data is removed afterwards unless kept: a set holds customer data.
 */
final class RestoreDrill
{
    /** @param  Closure(string): void|null  $progress */
    public function __construct(private readonly ?Closure $progress = null) {}

    /**
     * @return array{ok: bool, phase: ?string, violations: list<string>, facts: array<string, mixed>, cleaned: bool}
     *
     * @throws UnsafeRehearsalTarget before anything is changed
     */
    public function run(string $openedDir, Encrypter $key, ?string $expectedDigest, int $expectLegacy, bool $keep): array
    {
        $target = RehearsalTarget::restore();
        TargetGuard::assertConfiguration($target);

        return RehearsalLock::hold(function () use ($target, $openedDir, $key, $expectedDigest, $expectLegacy, $keep) {
            TargetGuard::assert($target);
            $report = ['ok' => false, 'phase' => 'verify the set', 'violations' => [], 'facts' => [], 'cleaned' => false];

            $verified = SetVerifier::verifyOpened($openedDir, $expectedDigest);
            if (! $verified['ok']) {
                return [...$report, 'violations' => $verified['violations']];
            }
            $manifest = $verified['manifest'];
            if (isset($manifest['database']['tables'][RehearsalTarget::MARKER_TABLE])) {
                // Loading it would replace the target's own marker, which is what lets the guard recognise the target.
                return [...$report, 'violations' => ['the set contains the rehearsal harness\'s marker table; it is not a portal backup and is not restored here']];
            }
            $this->say("Set {$manifest['backup_id']} verified (digest {$verified['digest']})");

            try {
                $report['phase'] = 'reset the restore target';
                RehearsalDatabase::reset($target);

                $report['phase'] = 'load the dump';
                $config = $target->connectionConfig();
                $load = RestoreLoader::database($openedDir, $config, fn () => TargetGuard::assert($target));
                $this->say("Dump loaded into {$target->database()} ({$load['seconds']} s)");

                $report['phase'] = 'extract the attachments';
                TargetGuard::assert($target);
                $files = RestoreLoader::attachments($openedDir, $target->filesRoot());
                $this->say("Attachments extracted: {$files['files']} files");

                $report['phase'] = 'compare the restored state with the set';
                $state = RestoredState::compare($openedDir, $manifest, $config, $target->connection(), $target->filesRoot(), $key, true, [RehearsalTarget::MARKER_TABLE]);
                $report['facts'] = $state['facts'];

                $report['phase'] = 'run the integrity checks';
                $integrity = $this->integrityFailures(IntegritySnapshot::capture($target), $expectLegacy, (int) $manifest['database']['tickets']['non_canonical']);

                $report['phase'] = 'invalidate restored runtime state';
                TargetGuard::assert($target);
                $report['facts']['runtime_rows_removed'] = RestoreLoader::invalidateRuntimeState($target->connection());

                $report['violations'] = [...$state['violations'], ...$integrity];
                $report['phase'] = null;
                $report['ok'] = $report['violations'] === [];
            } catch (Throwable $e) {
                $report['violations'][] = $e->getMessage();
            } finally {
                if (! $keep) {
                    try {
                        RehearsalDatabase::clean($target);
                        $report['cleaned'] = true;
                    } catch (Throwable $e) {
                        $report['violations'][] = 'the restored data could not be removed: '.$e->getMessage();
                        $report['ok'] = false;
                    }
                }
            }

            return $report;
        });
    }

    /**
     * The rehearsal invariants that failed. Legacy (pre-C6-R) references are accepted only when the operator states
     * their exact number, and only when the set itself recorded that many.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function integrityFailures(array $snapshot, int $expectLegacy, int $recordedLegacy): array
    {
        $failures = [];

        foreach ($snapshot['checks'] as $name => $value) {
            if ($name === 'ticket_number_not_canonical' && $expectLegacy > 0) {
                if ($value !== $expectLegacy || $recordedLegacy !== $expectLegacy) {
                    $failures[] = "integrity: {$value} non-canonical ticket reference(s) restored and {$recordedLegacy} in the set; {$expectLegacy} were expected";
                }

                continue;
            }
            if ($value !== 0) {
                $failures[] = "integrity: {$name}: {$value}";
            }
        }

        return $failures;
    }

    private function say(string $line): void
    {
        $this->progress && ($this->progress)($line);
    }
}
