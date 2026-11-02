<?php

namespace App\Support\Backup;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recovery of a portal installation from one opened, verified set (Release 0.9 WP-B1;
 * docs/operations/backup-and-recovery.md). It restores only into EMPTY targets, never over data:
 *
 *  - the set must be authenticated by the digest recorded when it was made (`--expect-digest`);
 *  - the operator names the database (`--confirm-database`), and it must be the one the application is configured
 *    to use, live as well as by configuration;
 *  - the application must be in maintenance mode, so nothing writes, and the queue worker does not process jobs,
 *    while the restore runs or before the restored queue is reviewed;
 *  - the database must hold no table at all, checked again immediately before the load;
 *  - the private disk must hold no `tickets/` (a surviving one is moved aside by the operator first, never deleted).
 *
 * After loading it proves the restored state equals the set ({@see RestoredState}) with the configured APP_KEY, then
 * invalidates sessions, the database cache and reset tokens. Queued and failed jobs are reported, never replayed.
 * `verify()` repeats the comparison read-only.
 */
final class Recovery
{
    /** @param  Closure(string): void|null  $progress */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $config,
        private readonly string $privateRoot,
        private readonly Encrypter $key,
        private readonly bool $maintenance,
        private readonly ?Closure $progress = null,
    ) {}

    public static function forApplication(?Closure $progress = null): self
    {
        $name = (string) config('database.default');

        return new self(
            DB::connection($name),
            (array) config("database.connections.{$name}"),
            (string) config('filesystems.disks.local.root'),
            app('encrypter'),
            app()->isDownForMaintenance(),
            $progress,
        );
    }

    /**
     * @return array{ok: bool, phase: ?string, violations: list<string>, facts: array<string, mixed>}
     *
     * @throws BackupRefused before anything is changed
     */
    public function restore(string $openedDir, string $confirmDatabase, string $expectedDigest): array
    {
        $database = (string) ($this->config['database'] ?? '');

        self::assertDigest($expectedDigest);

        if (! in_array($this->config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new BackupRefused('The application database is not MariaDB/MySQL.');
        }
        if ($database === '' || $confirmDatabase !== $database) {
            throw new BackupRefused('--confirm-database must name the database the application is configured to use.');
        }
        if (! $this->maintenance) {
            throw new BackupRefused('The application is not in maintenance mode. Run `php artisan down` first: nothing may write during a restore.');
        }
        $this->assertEmptyDatabase($database);
        $this->assertNoAttachments();

        $verified = SetVerifier::verifyOpened($openedDir, $expectedDigest);
        if (! $verified['ok']) {
            throw new BackupRefused("The set did not verify:\n  - ".implode("\n  - ", $verified['violations']));
        }
        $manifest = $verified['manifest'];
        $this->say("Set {$manifest['backup_id']} verified (digest {$verified['digest']})");

        $report = ['ok' => false, 'phase' => 'load the dump', 'violations' => [], 'facts' => []];

        try {
            $load = RestoreLoader::database($openedDir, $this->config, function () use ($database) {
                $this->assertEmptyDatabase($database);
            });
            $this->say("Dump loaded into {$database} ({$load['seconds']} s)");

            $report['phase'] = 'extract the attachments';
            $this->assertNoAttachments();
            $files = RestoreLoader::attachments($openedDir, $this->privateRoot);
            $this->say("Attachments extracted: {$files['files']} files");

            $report['phase'] = 'compare the restored state with the set';
            $state = RestoredState::compare($openedDir, $manifest, $this->config, $this->connection, $this->privateRoot, $this->key, true);
            $report['facts'] = $state['facts'];
            $report['violations'] = $state['violations'];

            if ($state['ok']) {
                $report['phase'] = 'invalidate restored runtime state';
                $report['facts']['runtime_rows_removed'] = RestoreLoader::invalidateRuntimeState($this->connection);
                $report['phase'] = null;
                $report['ok'] = true;
            }
        } catch (Throwable $e) {
            $report['violations'][] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Read-only: does the installation still equal the set? Volatile tables are compared by definition only.
     *
     * @return array{ok: bool, phase: ?string, violations: list<string>, facts: array<string, mixed>}
     */
    public function verify(string $openedDir, string $expectedDigest): array
    {
        self::assertDigest($expectedDigest);
        $verified = SetVerifier::verifyOpened($openedDir, $expectedDigest);

        if (! $verified['ok']) {
            return ['ok' => false, 'phase' => 'verify the set', 'violations' => $verified['violations'], 'facts' => []];
        }

        $state = RestoredState::compare($openedDir, $verified['manifest'], $this->config, $this->connection, $this->privateRoot, $this->key, false);

        return [...$state, 'phase' => null];
    }

    /**
     * A recovery uses only an AUTHENTICATED set: the digest recorded when it was made. A set's own COMPLETE and
     * SHA256SUMS can be regenerated by anyone who can write the set, so they alone prove nothing about its origin.
     */
    public static function assertDigest(string $digest): void
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', trim($digest))) {
            throw new BackupRefused('--expect-digest is required: the 64-character set digest recorded when the set was made.');
        }
    }

    private function assertEmptyDatabase(string $database): void
    {
        try {
            $live = (string) $this->connection->selectOne('select database() as db')->db;
            $tables = (int) $this->connection->selectOne(
                'select count(*) as n from information_schema.tables where table_schema = ?', [$database]
            )->n;
        } catch (Throwable $e) {
            throw new BackupRefused("The database \"{$database}\" cannot be reached (".strtok($e->getMessage(), "\n").').');
        }

        if ($live !== $database) {
            throw new BackupRefused("The live connection is to \"{$live}\", not \"{$database}\".");
        }
        if ($tables !== 0) {
            throw new BackupRefused("The database \"{$database}\" is not empty ({$tables} tables). A restore never writes over data: restore into a new, empty database.");
        }
    }

    private function assertNoAttachments(): void
    {
        $tickets = rtrim($this->privateRoot, '/').'/tickets';

        if (! is_link($tickets) && ! file_exists($tickets)) {
            return;
        }

        // Present: acceptable only as an empty, readable directory (an unreadable one cannot be proven empty).
        if (is_link($tickets) || ! is_dir($tickets) || ! is_readable($tickets) || BackupSet::tree($tickets) !== [] || ! @rmdir($tickets)) {
            throw new BackupRefused('The private disk already holds tickets/ (or it cannot be read). Move it aside first (see the recovery procedure); it is never deleted or overwritten.');
        }
    }

    private function say(string $line): void
    {
        $this->progress && ($this->progress)($line);
    }
}
