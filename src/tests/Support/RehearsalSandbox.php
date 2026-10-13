<?php

namespace Tests\Support;

use App\Support\Rehearsal\BaselineFixtures;
use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalLock;
use App\Support\Rehearsal\RehearsalTarget;
use PDO;

/**
 * What the R0.9-1A rehearsal tests share. The rehearsal harness is destructive by design, so these tests
 * give it nowhere real to write except its own two rehearsal databases:
 *
 *  - `storage_path()` is moved to a throw-away directory (`useStoragePath`), so every file, backup, marker and
 *    lock the harness makes lands there and the real `storage/rehearsal` and `storage/app/private` are
 *    never touched;
 *  - candidate migrations live in a throw-away `database_path()` copy of the migrations, so the committed
 *    manifest and migrations are never edited.
 *
 * The two rehearsal databases are shared with `./dev rehearse` (see docs/testing/migration-rehearsal.md).
 * The process holds the rehearsal database lock ({@see RehearsalLock}) from its first rehearsal test until it
 * ends, so a concurrent `./dev rehearse`, a second Pest process or a `--parallel` worker is REFUSED rather than
 * resetting the databases underneath these tests.
 */
final class RehearsalSandbox
{
    private static ?string $root = null;

    private static ?string $baselineHash = null;

    /** One temporary directory per process, removed when it ends. */
    public static function root(): string
    {
        if (self::$root === null) {
            self::$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rehearsal-tests-'.bin2hex(random_bytes(4));
            mkdir(self::$root, 0700);
            $root = self::$root;
            register_shutdown_function(static fn () => self::remove($root));
        }

        return self::$root;
    }

    /**
     * Point the booted application's storage at the sandbox and make sure this process holds the rehearsal
     * lock. Call from beforeEach.
     */
    public static function use(): void
    {
        $storage = self::root().DIRECTORY_SEPARATOR.'storage';
        is_dir($storage) || mkdir($storage, 0700);
        app()->useStoragePath($storage);

        if (! RehearsalLock::held()) {
            RehearsalLock::acquire();
            register_shutdown_function(static fn () => RehearsalLock::releaseAll());
        }
    }

    /**
     * Run $work while ANOTHER database session (standing in for another process) holds the rehearsal lock,
     * then give this process its lock back. Returns what $work returns.
     */
    public static function withLockHeldElsewhere(callable $work): mixed
    {
        RehearsalLock::releaseAll();
        $other = self::session();
        $got = (int) $other->query("select get_lock('".RehearsalLock::NAME."', 0)")->fetchColumn();

        try {
            if ($got !== 1) {
                throw new \RuntimeException('Another rehearsal (or test process) already holds the rehearsal lock; stop it and retry.');
            }

            return $work();
        } finally {
            $other = null;   // closing the session releases its lock
            self::use();
        }
    }

    /** A separate database session on the primary rehearsal database, built from the harness's fixed configuration. */
    public static function session(): PDO
    {
        $c = RehearsalTarget::primary()->connectionConfig();

        return new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            (string) $c['username'],
            (string) $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** The sandbox's rehearsal directory (what `storage_path('rehearsal')` is while the sandbox is in use). */
    public static function rehearsalDirectory(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'rehearsal';
    }

    /**
     * Make a throw-away database path holding a link to every PINNED BASELINE migration plus the given
     * candidates, the pinned baseline manifest, and a candidate manifest naming only the given candidates. The
     * committed migrations are linked, not copied: several are named classes, and the same class loaded from a
     * second path would be a fatal redeclaration. The committed candidates are deliberately not linked, so a
     * test's set contains exactly what the test declares. Returns the path; pass it to `useDatabasePath`.
     *
     * @param  array<string, array{up: string, schema?: list<string>, data?: list<string>, sha256?: string}>  $candidates
     *                                                                                                                     name => migration body (PHP statements for up()), declared changes, and (to simulate a file that changed after review) an overriding hash
     */
    public static function databasePath(array $candidates = []): string
    {
        $path = self::root().DIRECTORY_SEPARATOR.'database-'.bin2hex(random_bytes(3));
        mkdir($path.'/migrations', 0700, true);
        mkdir($path.'/rehearsal', 0700, true);

        $baseline = self::pinnedBaseline();
        foreach (array_keys($baseline) as $name) {
            symlink(base_path('database/migrations/'.$name.'.php'), $path.'/migrations/'.$name.'.php');
        }
        file_put_contents($path.'/rehearsal/baseline.php', "<?php\n\nreturn ".var_export($baseline, true).";\n");

        $manifest = [];
        foreach ($candidates as $name => $candidate) {
            $code = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
                ."use Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\n"
                ."return new class extends Migration\n{\n    public function up(): void\n    {\n        {$candidate['up']}\n    }\n\n    public function down(): void {}\n};\n";
            file_put_contents($path.'/migrations/'.$name.'.php', $code);

            $manifest[$name] = [
                'summary' => 'Rehearsal test candidate.',
                'sha256' => $candidate['sha256'] ?? hash('sha256', $code),
                'schema' => $candidate['schema'] ?? [],
                'data' => $candidate['data'] ?? [],
            ];
        }

        file_put_contents($path.'/rehearsal/candidates.php', "<?php\n\nreturn ".var_export($manifest, true).";\n");

        return $path;
    }

    /** @return array<string, string> the committed baseline pin, read from the repository (not from a sandbox) */
    public static function pinnedBaseline(): array
    {
        return require base_path('database/rehearsal/baseline.php');
    }

    /** A MigrationSet over a sandbox database path. */
    public static function migrationSet(array $candidates = []): MigrationSet
    {
        $path = self::databasePath($candidates);

        return new MigrationSet($path.'/migrations', require $path.'/rehearsal/candidates.php', require $path.'/rehearsal/baseline.php');
    }

    /**
     * Make sure the primary rehearsal database holds the untouched baseline (committed migrations, the
     * deterministic fixtures, their files) and return its snapshot. Rebuilt only when it is not already
     * that (or when $rebuild asks for a fresh one): tests that mutate it must put it back.
     *
     * @return array<string, mixed>
     */
    public static function baseline(bool $rebuild = false): array
    {
        $primary = RehearsalTarget::primary();

        if (self::$baselineHash !== null && ! $rebuild) {
            try {
                $snapshot = IntegritySnapshot::capture($primary);
                if (IntegritySnapshot::hash($snapshot) === self::$baselineHash) {
                    return $snapshot;
                }
            } catch (\Throwable) {
                // Rebuild below.
            }
        }

        $set = MigrationSet::repository();
        RehearsalDatabase::reset($primary);
        RehearsalDatabase::migrate($primary, $set->baselinePaths());
        RehearsalDatabase::within($primary, fn () => (new BaselineFixtures($primary))->seed());

        $snapshot = IntegritySnapshot::capture($primary);
        self::$baselineHash = IntegritySnapshot::hash($snapshot);

        return $snapshot;
    }

    private static function remove(string $path): void
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rehearsal-tests-';
        if (! str_starts_with($path, $prefix) || ! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
