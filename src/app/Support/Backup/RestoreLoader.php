<?php

namespace App\Support\Backup;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Loads an opened, verified set into an EMPTY target (Release 0.9 WP-B1). Shared by the restore drill and the
 * recovery restore, so the drill rehearses the exact code a recovery runs. Neither decides where it may write:
 * each caller passes a guard that is run immediately before the database is touched.
 */
final class RestoreLoader
{
    /** Runtime state a restored installation must not keep (the recovery and the drill clear them). */
    public const RUNTIME_TABLES = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    /** Tables whose rows may change after the restore: the runtime state above, and the queue the operator reviews. */
    public const VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'];

    /**
     * Load the set's dump with the `mariadb` client into the database named in $config. The dump is decompressed into
     * a private working directory and checked statement by statement against an exact allowlist ({@see DumpReader})
     * before $guard runs and the client starts; the client runs with `--binary-mode --sandbox`. The dump's origin is
     * established by the set digest the caller verified, not by this check.
     *
     * @param  Closure(): void  $guard  throws to refuse
     * @return array{seconds: float}
     */
    public static function database(string $openedDir, array $config, Closure $guard): array
    {
        $work = self::workDirectory($openedDir, 'load');

        try {
            $sql = $work.'/database.sql';
            $in = @fopen('compress.zlib://'.$openedDir.'/'.BackupSet::DATABASE, 'rb');
            $out = @fopen($sql, 'x');
            if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('The dump could not be decompressed.');
            }
            fclose($in);
            fclose($out);

            $dump = DumpReader::read($sql);
            if ($dump['violations'] !== [] || ! $dump['trailer']) {
                throw new RuntimeException('The dump is not loadable: '.implode('; ', $dump['violations'] ?: ['no completion trailer']));
            }

            $guard();

            // --binary-mode and --sandbox: the client interprets no backslash command and touches no file, whatever the dump holds.
            // The session's SQL mode and character set are fixed here too, whatever the server's defaults: the quoting rules
            // the dump was checked under hold even if a dump line were skipped.
            $result = MariaDbClient::run('mariadb', ['--batch', '--no-auto-rehash', '--binary-mode', '--sandbox', '--default-character-set=utf8mb4',
                "--init-command=SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'", '--database='.$config['database']], $config, $sql);

            if ($result['exit'] !== 0) {
                throw new RuntimeException('Loading the dump failed (exit '.$result['exit'].'): '.$result['stderr']);
            }

            return ['seconds' => $result['seconds']];
        } finally {
            BackupSet::removeTree($work);
        }
    }

    /**
     * Extract the set's attachments into $privateRoot, which must not already hold `tickets/`.
     *
     * @return array{files: int}
     */
    public static function attachments(string $openedDir, string $privateRoot): array
    {
        $previous = umask(0077);

        try {
            return ['files' => count(AttachmentArchive::extract($openedDir.'/'.BackupSet::ATTACHMENTS, $privateRoot))];
        } finally {
            umask($previous);
        }
    }

    /**
     * Invalidate what a restored installation must not trust: every session (no page from before the loss can submit
     * a form; ADR-008), every remember-me token (so no browser stays signed in through a "remember me" cookie, and a
     * sign-out-everywhere made after the snapshot is not undone), the database cache and its locks (including the
     * permission-cache epoch), and password reset tokens. Queued and failed jobs are left for the operator to review;
     * nothing is replayed.
     *
     * @return array<string, int> rows removed (or, for users.remember_token, blanked) per table
     */
    public static function invalidateRuntimeState(Connection $connection): array
    {
        $removed = [];

        foreach (self::RUNTIME_TABLES as $table) {
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                $removed[$table] = $connection->table($table)->delete();
            }
        }

        foreach (DumpReader::RECOVERY_BLANKED as $table => $columns) {
            foreach ($columns as $column) {
                $removed["{$table}.{$column}"] = $connection->table($table)->whereNotNull($column)->update([$column => null]);
            }
        }

        return $removed;
    }

    /** A private working directory beside the opened set (same disk, same privacy), removed by the caller. */
    public static function workDirectory(string $openedDir, string $purpose): string
    {
        $work = dirname($openedDir).'/.'.basename($openedDir).'.'.$purpose.'-'.bin2hex(random_bytes(4));
        $previous = umask(0077);

        try {
            BackupSet::makePrivateDirectory($work);
        } finally {
            umask($previous);
        }

        return $work;
    }
}
