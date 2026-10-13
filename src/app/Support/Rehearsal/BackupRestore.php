<?php

namespace App\Support\Rehearsal;

use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

/**
 * A rehearsal-only backup and restore of one rehearsal database and its private files. It exists to prove
 * that records, identifiers, relationships and attachment files survive a round trip, and to give the
 * invariant snapshot something to be compared across. It is NOT a production backup system.
 *
 * Consistency. The database is dumped inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT` at
 * REPEATABLE READ, so every table is read as of a single instant whatever else is written meanwhile. The
 * files are copied AFTER that snapshot, so every attachment row in the dump has its file in the copy. That
 * ordering is sufficient only while committed attachment files are never deleted or overwritten after they
 * are written, which holds for the application's ordinary workflow and is an assumption to keep true (R0.9-0
 * §7.1). The set is not trusted until a restore of it reconciles: {@see verify()} checks the dump and every
 * file against the manifest before anything is loaded, and the caller compares snapshots afterwards.
 *
 * The dump is a logical dump written in PHP because the application container has no MariaDB client; each
 * statement is one line so a restore needs no SQL parser. It covers base tables only (no views, triggers or
 * routines, of which the schema has none), and it writes values from the live session, so it is a rehearsal
 * tool, not a substitute for `mariadb-dump`: the production backup package must rehearse its own tool.
 *
 * `APP_KEY` and `.env` are deliberately NOT part of the set. They encrypt two-factor secrets and recovery
 * codes inside the database; production recovery needs them held separately and recoverable (R0.9-0 §7.2,
 * decision H9, still open).
 */
final class BackupRestore
{
    private const BATCH = 100;

    /** The only shape of a backup set's directory name; {@see backup()} is the only thing that makes one. */
    private const SET_NAME = '/^rehearsal-\d{8}-\d{6}-[0-9a-f]{6}$/';

    /**
     * Take a backup of $source into a new directory under the backups root.
     *
     * @return array<string, mixed> the manifest, plus its directory under "directory"
     */
    public static function backup(RehearsalTarget $source): array
    {
        TargetGuard::assert($source);

        $root = RehearsalTarget::backupsRoot();
        if (! is_dir($root)) {
            RehearsalFiles::reset($root, RehearsalFiles::BACKUPS);
        }
        RehearsalFiles::assertSafe($root, RehearsalFiles::BACKUPS);

        $id = 'rehearsal-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $directory = $root.DIRECTORY_SEPARATOR.$id;

        // Everything in a backup set is private from the moment it is created: 0077 means no file or
        // directory this method creates can ever be group- or world-readable, even for an instant. The
        // permissions are then checked, not assumed (see write()).
        $previousUmask = umask(0077);

        try {
            File::ensureDirectoryExists($directory, 0700);

            try {
                $manifest = self::write($source, $directory, $id);
            } catch (\Throwable $e) {
                // A half-written set proves nothing and is not kept: remove what this call created.
                self::discard($directory);

                throw $e;
            }
        } finally {
            umask($previousUmask);
        }

        return $manifest + ['directory' => $directory];
    }

    /**
     * Delete a backup set this process created, and nothing else. The path must be a direct child of the
     * (marked) backups root, with the exact name shape {@see backup()} gives a set, not a symbolic link, and
     * a manifest, if it has one, that names this very set. Anything else is refused untouched.
     */
    public static function discard(string $directory): void
    {
        RehearsalFiles::assertSafe(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);

        $root = realpath(RehearsalTarget::backupsRoot());
        $name = basename($directory);

        if (is_link($directory) || ! is_dir($directory) || ! preg_match(self::SET_NAME, $name)
            || $root === false || realpath($directory) !== $root.DIRECTORY_SEPARATOR.$name) {
            throw new UnsafeRehearsalTarget(["{$directory} is not a backup set created by the rehearsal harness"]);
        }

        $manifestFile = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (is_file($manifestFile)) {
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            if (! is_array($manifest) || ($manifest['id'] ?? null) !== $name) {
                throw new UnsafeRehearsalTarget(["{$directory} carries a manifest that does not belong to it"]);
            }
        }

        if (! File::deleteDirectory($directory) || file_exists($directory)) {
            throw new RuntimeException("The backup set {$name} could not be fully removed; remove storage/rehearsal/backups/{$name} by hand.");
        }
    }

    /** @return array<string, mixed> the manifest */
    private static function write(RehearsalTarget $source, string $directory, string $id): array
    {
        $pdo = $source->connection()->getPdo();
        $started = now()->toIso8601String();
        $counts = [];

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            $dump = fopen($directory.DIRECTORY_SEPARATOR.'db.sql', 'wb');
            fwrite($dump, "-- Rehearsal logical dump {$id} of {$source->database()}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

            foreach (self::tables($pdo, $source) as $table) {
                $create = (string) $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
                fwrite($dump, "DROP TABLE IF EXISTS `{$table}`;\n".preg_replace('/\s*\n\s*/', ' ', $create).";\n");
                $counts[$table] = self::dumpRows($pdo, $dump, $table);
            }

            fwrite($dump, "SET FOREIGN_KEY_CHECKS=1;\n-- Dump completed\n");
            fclose($dump);
        } finally {
            $pdo->exec('COMMIT');
        }

        $dbEnded = now()->toIso8601String();

        // Files after the snapshot: every row in the dump has its file in the copy.
        $files = $directory.DIRECTORY_SEPARATOR.'files';
        File::ensureDirectoryExists($files, 0700);
        File::copyDirectory($source->filesRoot(), $files);
        @unlink($files.DIRECTORY_SEPARATOR.RehearsalTarget::MARKER_FILE);

        $manifest = [
            'id' => $id,
            'source_database' => $source->database(),
            'database_snapshot_started_at' => $started,
            'database_snapshot_finished_at' => $dbEnded,
            'files_copied_after_database_snapshot' => true,
            'dump' => ['sha256' => hash_file('sha256', $directory.DIRECTORY_SEPARATOR.'db.sql'), 'bytes' => filesize($directory.DIRECTORY_SEPARATOR.'db.sql')],
            'tables' => $counts,
            'files' => self::inventory($files),
            'excluded' => 'APP_KEY and .env are not part of this set; production recovery needs them held and recoverable separately.',
        ];

        file_put_contents($directory.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // Explicit, not merely inherited from the umask: 0700 directories, 0600 files, then verified.
        self::lockDown($directory);
        if ($exposed = RehearsalFiles::exposedPaths($directory)) {
            throw new RuntimeException('The backup set is not private (group or other access): '.implode(', ', $exposed));
        }

        return $manifest;
    }

    /**
     * Whether a backup set is complete and untampered. Checked before anything is restored from it.
     *
     * @return list<string>
     */
    public static function verify(string $directory): array
    {
        $manifestFile = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $dumpFile = $directory.DIRECTORY_SEPARATOR.'db.sql';

        if (! is_file($manifestFile) || ! is_file($dumpFile)) {
            return ['the backup set has no manifest or no dump'];
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (! is_array($manifest) || ! isset($manifest['dump']['sha256'], $manifest['files'], $manifest['tables'])) {
            return ['the backup manifest is unreadable'];
        }

        $violations = [];

        foreach (RehearsalFiles::exposedPaths($directory) as $path) {
            $violations[] = "the backup set is not private: {$path} allows group or other access";
        }

        if (! hash_equals($manifest['dump']['sha256'], (string) hash_file('sha256', $dumpFile))) {
            $violations[] = 'the dump does not match its recorded checksum';
        }
        if (! str_contains((string) file_get_contents($dumpFile, false, null, max(0, filesize($dumpFile) - 64)), '-- Dump completed')) {
            $violations[] = 'the dump is truncated (no completion marker)';
        }

        $files = $directory.DIRECTORY_SEPARATOR.'files';
        $actual = self::inventory($files);

        foreach (array_diff_key($manifest['files'], $actual) as $path => $_) {
            $violations[] = "the backup is missing the file {$path}";
        }
        foreach (array_diff_key($actual, $manifest['files']) as $path => $_) {
            $violations[] = "the backup contains an unrecorded file {$path}";
        }
        foreach ($manifest['files'] as $path => $info) {
            if (isset($actual[$path]) && $actual[$path] !== $info) {
                $violations[] = "the file {$path} differs from its recorded size or checksum";
            }
        }

        return $violations;
    }

    /**
     * Restore the set in $directory into $destination, which is reset first (and so must pass the guard).
     *
     * @return array{tables: int, rows: int, files: int}
     */
    public static function restore(string $directory, RehearsalTarget $destination): array
    {
        // The marker is on the backups root; the set must be provably inside it.
        RehearsalFiles::assertSafe(RehearsalTarget::backupsRoot(), RehearsalFiles::BACKUPS);
        $root = realpath(RehearsalTarget::backupsRoot());
        $real = realpath($directory);
        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new UnsafeRehearsalTarget(['the backup set is not inside storage/rehearsal/backups']);
        }

        if ($problems = self::verify($directory)) {
            throw new RuntimeException("Refusing to restore an incomplete backup set:\n  - ".implode("\n  - ", $problems));
        }

        $manifest = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'manifest.json'), true);

        RehearsalDatabase::reset($destination);
        $pdo = $destination->connection()->getPdo();

        $handle = fopen($directory.DIRECTORY_SEPARATOR.'db.sql', 'rb');
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '' && ! str_starts_with($line, '--')) {
                $pdo->exec($line);
            }
        }
        fclose($handle);

        $rows = 0;
        foreach ($manifest['tables'] as $table => $expected) {
            $actual = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            if ($actual !== $expected) {
                throw new RuntimeException("Restore mismatch: {$table} has {$actual} rows, the backup recorded {$expected}.");
            }
            $rows += $actual;
        }

        File::copyDirectory($directory.DIRECTORY_SEPARATOR.'files', $destination->filesRoot());
        self::lockDown($destination->filesRoot());

        if (self::inventory($destination->filesRoot()) !== $manifest['files']) {
            throw new RuntimeException('Restore mismatch: the restored files differ from the backup.');
        }

        return ['tables' => count($manifest['tables']), 'rows' => $rows, 'files' => count($manifest['files'])];
    }

    /** @return list<string> */
    private static function tables(PDO $pdo, RehearsalTarget $source): array
    {
        $statement = $pdo->prepare(
            "select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' and table_name <> ? order by table_name"
        );
        $statement->execute([$source->database(), RehearsalTarget::MARKER_TABLE]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param  resource  $dump */
    private static function dumpRows(PDO $pdo, $dump, string $table): int
    {
        $count = 0;
        $batch = [];
        $columns = null;

        $flush = function () use (&$batch, &$columns, $dump, $table) {
            if ($batch !== []) {
                fwrite($dump, "INSERT INTO `{$table}` ({$columns}) VALUES ".implode(',', $batch).";\n");
                $batch = [];
            }
        };

        foreach ($pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC) as $row) {
            $columns ??= implode(',', array_map(fn (string $c) => "`{$c}`", array_keys($row)));
            $batch[] = '('.implode(',', array_map(fn ($v) => self::literal($pdo, $v), $row)).')';
            $count++;

            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }
        $flush();

        return $count;
    }

    private static function literal(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Non-UTF-8 bytes are written as a hex literal so they cannot be altered by the connection charset.
        return mb_check_encoding($value, 'UTF-8')
            ? str_replace(["\n", "\r"], ['\\n', '\\r'], $pdo->quote($value))
            : '0x'.bin2hex($value);
    }

    /** @return array<string, array{size: int, sha256: string}> */
    private static function inventory(string $root): array
    {
        $out = [];
        foreach (RehearsalFiles::listFiles($root) as $path) {
            $out[$path] = ['size' => (int) filesize($root.'/'.$path), 'sha256' => (string) hash_file('sha256', $root.'/'.$path)];
        }

        return $out;
    }

    /** Private-file permissions, as the application's own disk uses: files 0600, directories 0700. */
    private static function lockDown(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        chmod($root, 0700);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
        }
    }
}
