<?php

namespace App\Support\Database;

use Throwable;

/**
 * The reviewed record of what this build adds to an OLDER release's database schema, and the fail-closed check that the live
 * database differs from that older release's schema by exactly that, and nothing else (Release 1.0, RE-C1).
 *
 * WHY THIS EXISTS. Each release's {@see DatabaseProfile} compares the live foreign keys with ITS OWN committed manifest, exactly.
 * After this build's migrations have run, an older, immutable build rolled back to reports every foreign key this build added as
 * `unexpected foreign key`, in `database:verify` and, because it runs the same comparison first, in `runtime:preflight` (so it never
 * issues an A2 challenge). Nothing in THIS build can change that: the older build reads the older manifest. So this is not a
 * tolerance and does not touch {@see DatabaseProfile}, which stays exact. It is an ASSESSMENT, made by this build before the
 * rollback and recorded in a reviewed declaration (`database/profile/rollback/<release>.json`):
 *
 *  - the live database must still pass this build's own strict verifier, so the live foreign keys ARE this build's manifest;
 *  - every foreign key the older release has must still exist here with the same rules (nothing missing, nothing changed);
 *  - the foreign keys only this build has must equal the declared additive list, key for key and rule for rule. There is no
 *    wildcard, and an unlisted addition fails until a person reviews it and adds it to the declaration;
 *  - the older build's own verifier output is then fully predicted: one `unexpected foreign key` line per declared key, and the
 *    declaration records the output actually captured from the real older build, which a test pins;
 *  - the set of migrations that have run is exactly the older release's plus the declared ones (nothing pending, nothing unknown);
 *  - every column the declaration lists as added exists and is one the older code can still INSERT around (nullable, or with a default),
 *    and the CHECK constraints are exactly the older release's plus the declared ones;
 *  - the queue is the database queue, holds no in-flight job, and no waiting job is of a class (or command) the older release lacks.
 *
 * It does NOT compare every column, index or row of the schema; it is a narrow, reviewed contract for one rollback target, not a
 * schema-diff tool. What it names is what it proves.
 *
 * The result is distinct from the older verifier passing. It never says it did: it names the lines that verifier will print and
 * leaves comparing them, exactly, to the operator. docs/operations/rollback-1.0-to-0.9.md.
 */
final class RollbackCompatibility
{
    public const DIRECTORY = 'database/profile/rollback';

    /** @return array<string, mixed> the validated declaration for a release such as "0.9" */
    public static function declaration(string $release): array
    {
        if (preg_match('/^\d+\.\d+$/', $release) !== 1) {
            throw new \RuntimeException("'{$release}' is not a release such as \"0.9\"");
        }

        $path = base_path(self::DIRECTORY.'/'.$release.'.json');

        if (! is_file($path)) {
            throw new \RuntimeException("no reviewed rollback declaration exists for release {$release} ({$path})");
        }

        $declaration = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach (['target', 'migrations_after_target', 'additive_foreign_keys', 'additive_columns', 'additive_check_constraints', 'tightenings', 'queued_classes_added_since_target', 'expected_target_verifier_output'] as $key) {
            if (! array_key_exists($key, $declaration)) {
                throw new \RuntimeException("the rollback declaration for {$release} lacks '{$key}'");
            }
        }

        foreach (['release', 'commit', 'foreign_key_manifest', 'foreign_key_manifest_sha256', 'last_migration', 'queued_classes', 'check_constraints'] as $key) {
            if (! array_key_exists($key, $declaration['target'])) {
                throw new \RuntimeException("the rollback declaration for {$release} lacks target.{$key}");
            }
        }

        if ((string) $declaration['target']['release'] !== $release) {
            throw new \RuntimeException("the rollback declaration file for {$release} describes release {$declaration['target']['release']}");
        }

        return $declaration;
    }

    /**
     * The older release's committed foreign-key manifest, read from the copy pinned in this build. The copy must be byte-for-byte the one
     * the declaration names (SHA-256), or the assessment would be comparing against something nobody reviewed.
     *
     * @param  array<string, mixed>  $declaration
     * @return array<string, string>
     */
    public static function targetManifest(array $declaration): array
    {
        $path = base_path((string) $declaration['target']['foreign_key_manifest']);
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if ($contents === '' || hash('sha256', $contents) !== (string) $declaration['target']['foreign_key_manifest_sha256']) {
            throw new \RuntimeException("the pinned foreign-key manifest of release {$declaration['target']['release']} is missing or is not the reviewed file (SHA-256 mismatch)");
        }

        return (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['foreign_keys'];
    }

    /**
     * Everything that stops the code from being rolled back to the older release with this reviewed evidence. An empty list is a pass.
     * Pure: the live foreign keys come in as an argument, so every refusal is testable without a database.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array<string, string>  $targetManifest  the older release's manifest
     * @param  array<string, string>  $currentManifest  this build's manifest
     * @param  array<string, string>  $liveForeignKeys  the live database, in manifest form
     * @return list<string>
     */
    public static function problems(array $declaration, array $targetManifest, array $currentManifest, array $liveForeignKeys): array
    {
        $problems = [];

        // 1. The live database IS this build's schema (this is the strict check, restated so the assessment cannot pass on its own say-so).
        foreach (DatabaseProfile::compareForeignKeys($currentManifest, $liveForeignKeys) as $violation) {
            $problems[] = "the live database is not this build's reviewed schema: {$violation}";
        }

        // 2. Nothing the older release relies on may be missing or changed here.
        foreach ($targetManifest as $key => $rules) {
            if (! array_key_exists($key, $currentManifest)) {
                $problems[] = "rollback blocked: foreign key {$key} ({$rules}) exists in release {$declaration['target']['release']} but not in this build";
            } elseif ($currentManifest[$key] !== $rules) {
                $problems[] = "rollback blocked: foreign key {$key} is {$currentManifest[$key]} in this build but {$rules} in release {$declaration['target']['release']}";
            }
        }

        // 3. What only this build has is EXACTLY the reviewed list.
        $added = array_diff_key($currentManifest, $targetManifest);
        $declared = (array) $declaration['additive_foreign_keys'];

        foreach ($added as $key => $rules) {
            if (! array_key_exists($key, $declared)) {
                $problems[] = "unreviewed additive foreign key: {$key} ({$rules}) is not in the rollback declaration";
            } elseif ($declared[$key] !== $rules) {
                $problems[] = "additive foreign key {$key} is {$rules} in this build but the declaration says {$declared[$key]}";
            }
        }

        foreach ($declared as $key => $rules) {
            if (! array_key_exists($key, $added)) {
                $problems[] = "declared additive foreign key {$key} ({$rules}) is not an addition of this build (absent, or already in release {$declaration['target']['release']})";
            }
        }

        // 4. The older verifier's whole output is predicted, line for line, and equals what was reviewed and captured.
        $expected = self::expectedTargetVerifierOutput($targetManifest, $liveForeignKeys);
        $recorded = array_values((array) $declaration['expected_target_verifier_output']);

        if ($expected !== $recorded) {
            foreach (array_diff($expected, $recorded) as $line) {
                $problems[] = "the release {$declaration['target']['release']} verifier would also report an unreviewed line: {$line}";
            }
            foreach (array_diff($recorded, $expected) as $line) {
                $problems[] = "the recorded release {$declaration['target']['release']} verifier line is not reproduced: {$line}";
            }
        }

        return $problems;
    }

    /**
     * The foreign-key lines the older release's own `database:verify` prints against this live database: the same comparison function, the older
     * manifest. Sorted as that command prints them.
     *
     * @param  array<string, string>  $targetManifest
     * @param  array<string, string>  $liveForeignKeys
     * @return list<string>
     */
    public static function expectedTargetVerifierOutput(array $targetManifest, array $liveForeignKeys): array
    {
        return array_values(DatabaseProfile::compareForeignKeys($targetManifest, $liveForeignKeys));
    }

    /**
     * The identity of the rollback target as it sits on the host: the directory the operator is about to select must BE the reviewed
     * release, not merely look like it. Three independent facts, each of which the older verifier itself depends on:
     *  - its `RELEASE` file records exactly the reviewed 40-character commit (the build stamps this; it cannot be edited into a build
     *    without changing the build);
     *  - its `database/profile/foreign-keys.json` is byte-for-byte the pinned manifest (that is the file its verifier reads);
     *  - its newest migration file is the declared last migration of that release (so a later release is never mistaken for it).
     *
     * @param  array<string, mixed>  $declaration
     * @return list<string>
     */
    public static function targetDirectoryProblems(array $declaration, string $directory): array
    {
        $release = (string) $declaration['target']['release'];
        $root = realpath($directory);

        if ($root === false || ! is_dir($root)) {
            return ["the rollback target directory '{$directory}' does not exist"];
        }

        if ($root === realpath(base_path())) {
            return ['the rollback target is this build itself, not release '.$release];
        }

        $problems = [];
        $releaseFile = is_file($root.'/RELEASE') ? (string) file_get_contents($root.'/RELEASE') : '';
        $commits = preg_match_all('/^commit=(\S*)$/m', $releaseFile, $m) > 0 ? $m[1] : [];

        if (count($commits) !== 1 || $commits[0] !== (string) $declaration['target']['commit']) {
            $problems[] = "the rollback target's RELEASE does not record the reviewed release {$release} commit {$declaration['target']['commit']} (found: ".($commits === [] ? 'no commit line' : implode(', ', $commits)).')';
        }

        $manifest = is_file($root.'/database/profile/foreign-keys.json') ? (string) file_get_contents($root.'/database/profile/foreign-keys.json') : '';

        if ($manifest === '' || hash('sha256', $manifest) !== (string) $declaration['target']['foreign_key_manifest_sha256']) {
            $problems[] = "the rollback target's foreign-key manifest is not the reviewed release {$release} manifest (SHA-256 mismatch or missing)";
        }

        $migrations = array_map(fn (string $path) => basename($path, '.php'), glob($root.'/database/migrations/*.php') ?: []);
        sort($migrations);

        if (($migrations[count($migrations) - 1] ?? null) !== (string) $declaration['target']['last_migration']) {
            $problems[] = "the rollback target's newest migration is not {$declaration['target']['last_migration']}, the last migration of release {$release}";
        }

        return $problems;
    }

    /**
     * The exact ran-migration set. This build's files after the target's last migration must be exactly the declared list, and the
     * `migrations` table must hold exactly the files of this build: nothing pending, nothing unknown. A migration added after the
     * declaration was written therefore fails here whether or not it has run.
     *
     * @param  array<string, mixed>  $declaration
     * @param  list<string>  $files  migration names present in this build
     * @param  list<string>  $ran  rows of the migrations table
     * @return list<string>
     */
    public static function migrationProblems(array $declaration, array $files, array $ran): array
    {
        $problems = [];
        $last = (string) $declaration['target']['last_migration'];
        $after = array_values(array_filter($files, fn (string $name) => strcmp($name, $last) > 0));
        $declared = array_values((array) $declaration['migrations_after_target']);
        sort($after);
        sort($declared);

        foreach (array_diff($after, $declared) as $name) {
            $problems[] = "migration {$name} follows release {$declaration['target']['release']} and is not covered by the rollback declaration: review it and declare it";
        }

        foreach (array_diff($declared, $after) as $name) {
            $problems[] = "declared migration {$name} is not a migration file of this build";
        }

        foreach (array_diff($files, $ran) as $name) {
            $problems[] = "migration {$name} is pending (it has not run), so the live schema is not the reviewed one";
        }

        foreach (array_diff($ran, $files) as $name) {
            $problems[] = "the migrations table records {$name}, which is not a migration file of this build";
        }

        return $problems;
    }

    /**
     * Whether a column Release 0.9 cannot know about is one its INSERTs can ignore: nullable, or with a default. A NOT NULL column without
     * a default makes every older insert into that table fail (error 1364), which a foreign-key comparison cannot see.
     *
     * @param  array<string, mixed>  $declaration
     * @return list<string>
     */
    public static function declaredColumnProblems(array $declaration): array
    {
        $problems = [];

        foreach ((array) $declaration['additive_columns'] as $column => $shape) {
            if (! (bool) ($shape['nullable'] ?? false) && ($shape['default'] ?? null) === null) {
                $problems[] = "declared column {$column} is NOT NULL without a default: release {$declaration['target']['release']} could not insert into its table";
            }
        }

        return $problems;
    }

    /**
     * The live shape of every declared added column, and the live CHECK constraints, against the declaration.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array<string, array{nullable: bool, default: ?string}>  $liveColumns  "table.column" => shape, for the declared columns that exist
     * @param  list<string>  $liveChecks  "table.constraint" of every CHECK constraint in the schema
     * @return list<string>
     */
    public static function schemaProblems(array $declaration, array $liveColumns, array $liveChecks): array
    {
        $problems = self::declaredColumnProblems($declaration);

        foreach ((array) $declaration['additive_columns'] as $column => $shape) {
            $live = $liveColumns[$column] ?? null;

            if ($live === null) {
                $problems[] = "declared column {$column} does not exist in the live database";
            } elseif ($live['nullable'] !== (bool) $shape['nullable'] || $live['default'] !== ($shape['default'] ?? null)) {
                $problems[] = "column {$column} is ".self::describeColumn($live).' in the live database but the declaration says '.self::describeColumn(['nullable' => (bool) $shape['nullable'], 'default' => $shape['default'] ?? null]);
            }
        }

        $expected = [...(array) $declaration['target']['check_constraints'], ...array_keys((array) $declaration['additive_check_constraints'])];
        sort($expected);
        $liveChecks = array_values(array_unique($liveChecks));
        sort($liveChecks);

        foreach (array_diff($liveChecks, $expected) as $check) {
            $problems[] = "unreviewed CHECK constraint {$check}: it is neither in release {$declaration['target']['release']} nor in the rollback declaration";
        }

        foreach (array_diff($expected, $liveChecks) as $check) {
            $problems[] = "CHECK constraint {$check} is expected but absent from the live database";
        }

        return $problems;
    }

    /** @param array{nullable: bool, default: ?string} $shape */
    private static function describeColumn(array $shape): string
    {
        return ($shape['nullable'] ? 'nullable' : 'NOT NULL').($shape['default'] === null ? ' with no default' : " default '{$shape['default']}'");
    }

    /**
     * The queue must be the one the older release's cron worker drains, and nothing may be in flight.
     *
     * @return list<string>
     */
    public static function queueConfigProblems(string $default, ?string $driver, string $table, int $reserved): array
    {
        $problems = [];

        if ($default !== 'database' || $driver !== 'database') {
            $problems[] = "the default queue connection is '{$default}' (driver ".($driver ?? 'unknown').'), not the database queue: the assessment cannot see the jobs of any other queue, so it cannot clear the rollback';
        }

        if ($table !== 'jobs') {
            $problems[] = "the database queue table is '{$table}', not 'jobs'";
        }

        if ($reserved > 0) {
            $problems[] = "{$reserved} job(s) are reserved (being processed): a worker is still running. Stop the producers and let the workers finish, then assess again";
        }

        return $problems;
    }

    /**
     * What a queued payload would run, as one class name, or a label that is never in the known list. A queued notification is run by
     * `Illuminate\Notifications\SendQueuedNotifications` with the notification class as the display name; every other job's command is
     * its own class. A display name that disagrees with the command it dispatches is not trusted.
     */
    public static function jobIdentity(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $display = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
        $command = is_array($decoded) ? ($decoded['data']['commandName'] ?? null) : null;

        if (! is_string($display) || $display === '' || ! is_string($command) || $command === '') {
            return '(unreadable payload)';
        }

        if ($command === 'Illuminate\\Notifications\\SendQueuedNotifications' || $command === $display) {
            return $display;
        }

        return "{$display} (command {$command})";
    }

    /**
     * Queued job classes waiting in the queue that the older release does not have. The older worker would fail them on unserialize.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array<string, int>  $waitingByClass  job identity => number of waiting jobs
     * @return list<string>
     */
    public static function queueProblems(array $declaration, array $waitingByClass): array
    {
        $known = (array) $declaration['target']['queued_classes'];
        $problems = [];

        foreach ($waitingByClass as $class => $count) {
            if (! in_array($class, $known, true)) {
                $problems[] = "{$count} queued job(s) of {$class} are waiting, and release {$declaration['target']['release']} has no such class: drain or delete them before the rollback";
            }
        }

        return $problems;
    }

    /**
     * Failed jobs the older release must never retry: those of a class (or unreadable payload) it does not have. A fact, not a refusal:
     * a failed job does nothing until someone retries it, so the rollback is not blocked, but the retry is prohibited.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array<string, int>  $failedByClass
     * @return array<string, int>
     */
    public static function unretryableFailedJobs(array $declaration, array $failedByClass): array
    {
        return array_filter($failedByClass, fn (int $count, string $class) => ! in_array($class, (array) $declaration['target']['queued_classes'], true), ARRAY_FILTER_USE_BOTH);
    }

    /** An exception reduced to what is safe to print. */
    public static function describe(Throwable $e): string
    {
        return DatabaseProfile::describe($e);
    }
}
