<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseProfile;
use App\Support\Database\RollbackCompatibility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('database:rollback-assessment {--target=0.9 : The older release to roll the code back to} {--target-dir= : The unpacked rollback target release directory on this host (its RELEASE commit, foreign-key manifest and newest migration are checked against the reviewed declaration)} {--json : Print the assessment as JSON} {--expected : Print only the lines the older release\'s own verifier will report, one per line (fails with no such lines unless the assessment passes)}')]
#[Description('Read-only, fail-closed assessment of rolling the code back to an older release (Release 1.0, RE-C1): the target directory must be the reviewed release; the ran-migration set, foreign keys, added columns, CHECK constraints and queue must be exactly as reviewed. Predicts the older verifier\'s output; it does NOT make that verifier pass and does not verify the whole schema')]
class AssessRollbackCompatibility extends Command
{
    public function handle(): int
    {
        $release = (string) $this->option('target');
        $problems = [];
        $warnings = [];
        $expected = [];
        $facts = [];
        $declaration = null;

        try {
            $declaration = RollbackCompatibility::declaration($release);
            $targetManifest = RollbackCompatibility::targetManifest($declaration);
            $db = DB::connection();

            // The directory about to be selected must be the reviewed release.
            $dir = (string) $this->option('target-dir');
            array_push($problems, ...($dir === ''
                ? ['--target-dir was not given, so the identity of the rollback target was not verified']
                : RollbackCompatibility::targetDirectoryProblems($declaration, $dir)));

            // This build's own strict verifier must pass, unchanged: the assessment is an addition to it, never a replacement.
            foreach (DatabaseProfile::safelyWithMigrations($db) as $violation) {
                $problems[] = "this build's database verifier reports: {$violation}";
            }

            $live = DatabaseProfile::liveForeignKeys($db);
            array_push($problems, ...RollbackCompatibility::problems($declaration, $targetManifest, DatabaseProfile::expectedForeignKeys(), $live));
            $expected = RollbackCompatibility::expectedTargetVerifierOutput($targetManifest, $live);

            [$files, $ran] = $this->migrations($db);
            $facts['ran_migrations'] = count($ran);
            array_push($problems, ...RollbackCompatibility::migrationProblems($declaration, $files, $ran));
            array_push($problems, ...RollbackCompatibility::schemaProblems($declaration, $this->declaredColumns($declaration), $this->checkConstraints()));

            [$queueProblems, $queueWarnings, $queueFacts] = $this->queue($declaration);
            array_push($problems, ...$queueProblems);
            array_push($warnings, ...$queueWarnings);
            $facts += $queueFacts;

            $facts += [
                'managed_organizations' => (int) $db->table('organizations')->whereNotNull('managed_by_organization_id')->count(),
                'partner_organizations' => (int) $db->table('organizations')->where('can_manage_customers', true)->count(),
            ];

            // Release 0.9 cannot clear an administrator approval it does not know about: none may be left, complete or not.
            $approvals = $this->administratorApprovals();
            $facts += [
                'approvals_complete' => count(array_filter($approvals, fn (array $row) => $row['complete'])),
                'approvals_incomplete' => count(array_filter($approvals, fn (array $row) => ! $row['complete'])),
            ];
            array_push($problems, ...RollbackCompatibility::approvalProblems($approvals));

            if ($facts['managed_organizations'] + $facts['partner_organizations'] > 0) {
                $warnings[] = "{$facts['partner_organizations']} partner and {$facts['managed_organizations']} managed organization(s) exist. They grant no access under release {$release}, but company deletion must stay frozen for the whole rollback window, and organizations:preflight must pass before the re-upgrade (docs/operations/rollback-1.0-to-0.9.md)";
            }
        } catch (Throwable $e) {
            $problems[] = 'the rollback could not be assessed: '.RollbackCompatibility::describe($e).($e instanceof \RuntimeException && ! $e->getPrevious() ? ' ('.$e->getMessage().')' : '');
        }

        $problems = array_values(array_unique($problems));
        $passed = $problems === [];
        $fingerprint = hash('sha256', implode("\n", $expected));

        if ($this->option('expected')) {
            if (! $passed) {
                foreach ($problems as $problem) {
                    $this->components->error($problem);
                }

                return self::FAILURE;
            }

            foreach ($expected as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'problems' => $problems,
                'warnings' => $warnings,
                'target' => $declaration ? ['release' => $declaration['target']['release'], 'commit' => $declaration['target']['commit']] : ['release' => $release],
                'expected_target_verifier_output' => $passed ? $expected : [],
                'expected_sha256' => $passed ? $fingerprint : null,
                'facts' => $facts,
                'note' => 'An assessment made by this build of a narrow, reviewed contract (target identity, migration set, foreign keys, added columns, CHECK constraints, queue). It does not make the target release\'s own verifier pass, and it does not verify every column, index or row of the schema.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($passed) {
            foreach ($warnings as $warning) {
                $this->components->warn($warning);
            }

            $this->components->info("Assessment passed for release {$release} ({$declaration['target']['commit']}). Nothing was written.");
            $this->line('It checked: the target directory is that release; the ran-migration set, the foreign keys, the added columns, the CHECK constraints and the queue are exactly as reviewed.');
            $this->line('This is a narrow contract, not a verification of the whole schema.');
            $this->newLine();
            $this->line("The release {$release} verifier (database:verify; also the database part of runtime:preflight) is EXPECTED to report exactly these ".count($expected)." line(s) after a code rollback, and no others (sha256 {$fingerprint}):");

            foreach ($expected as $line) {
                $this->line("  {$line}");
            }

            $this->newLine();
            $this->line('This is this build\'s assessment, not a pass of that verifier: it will still exit non-zero. Compare its output with these lines exactly (docs/operations/rollback-1.0-to-0.9.md).');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Migration names present in this build (found the way the strict verifier finds them) and the rows of the migrations table.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function migrations($db): array
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $table = (string) config('database.migrations.table', 'migrations');

        if (! $db->getSchemaBuilder()->hasTable($table)) {
            throw new \RuntimeException("the {$table} table does not exist");
        }

        return [array_values($files), array_values($db->table($table)->pluck('migration')->all())];
    }

    /**
     * The live nullability and default of each declared added column that exists.
     *
     * @param  array<string, mixed>  $declaration
     * @return array<string, array{nullable: bool, default: ?string}>
     */
    private function declaredColumns(array $declaration): array
    {
        $live = [];

        foreach (array_keys((array) $declaration['additive_columns']) as $name) {
            [$table, $column] = explode('.', $name, 2);
            $row = DB::selectOne('select is_nullable, column_default from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$table, $column]);

            if ($row !== null) {
                // MariaDB reports the default of a nullable column that has none as the text "NULL".
                $default = $row->column_default === null || strtoupper((string) $row->column_default) === 'NULL' ? null : (string) $row->column_default;
                $live[$name] = ['nullable' => strtoupper((string) $row->is_nullable) === 'YES', 'default' => $default];
            }
        }

        return $live;
    }

    /**
     * Every membership with a value in EITHER approval column, complete or not (empty before the MO-2A1 columns exist: the declared-column
     * and migration checks report that). Ids only.
     *
     * @return list<array{organization_id: int, user_id: int, complete: bool}>
     */
    private function administratorApprovals(): array
    {
        if (! Schema::hasColumn('organization_members', 'admin_approved_at') || ! Schema::hasColumn('organization_members', 'admin_approved_by')) {
            return [];
        }

        return DB::table('organization_members')
            ->where(fn ($query) => $query->whereNotNull('admin_approved_at')->orWhereNotNull('admin_approved_by'))
            ->orderBy('organization_id')->orderBy('user_id')
            ->get(['organization_id', 'user_id', 'admin_approved_at', 'admin_approved_by'])
            ->map(fn ($row) => ['organization_id' => (int) $row->organization_id, 'user_id' => (int) $row->user_id, 'complete' => $row->admin_approved_at !== null && $row->admin_approved_by !== null])
            ->all();
    }

    /** @return list<string> "table.constraint" of every CHECK constraint in the schema */
    private function checkConstraints(): array
    {
        return array_map(
            fn ($row) => $row->table_name.'.'.$row->constraint_name,
            DB::select("select table_name, constraint_name from information_schema.table_constraints where constraint_schema = database() and constraint_type = 'CHECK' order by table_name, constraint_name")
        );
    }

    /**
     * The queue as the older release's worker will meet it: the database queue, nothing in flight, no waiting job of a class it lacks, and
     * the failed jobs it must never retry. A queued notification's payload names the notification class as its display name; the command
     * itself may be encrypted, the display name and command name are not. An unreadable queue is a problem, never a pass.
     *
     * @param  array<string, mixed>  $declaration
     * @return array{0: list<string>, 1: list<string>, 2: array<string, mixed>}
     */
    private function queue(array $declaration): array
    {
        $default = (string) config('queue.default');
        $driver = config("queue.connections.{$default}.driver");
        $table = (string) config('queue.connections.database.table', 'jobs');
        $facts = ['queue_connection' => $default];
        $problems = [];
        $warnings = [];

        if ($default !== 'database') {
            return [RollbackCompatibility::queueConfigProblems($default, is_string($driver) ? $driver : null, $table, 0), [], $facts];
        }

        if (! Schema::hasTable($table)) {
            throw new \RuntimeException("the {$table} table does not exist, so the queue could not be inspected");
        }

        $waiting = [];
        $reserved = 0;

        foreach (DB::table($table)->select('payload', 'reserved_at')->orderBy('id')->cursor() as $job) {
            if ($job->reserved_at !== null) {
                $reserved++;
            }

            $identity = RollbackCompatibility::jobIdentity((string) $job->payload);
            $waiting[$identity] = ($waiting[$identity] ?? 0) + 1;
        }

        ksort($waiting);
        $facts['queued_jobs'] = array_sum($waiting);
        $facts['reserved_jobs'] = $reserved;

        array_push($problems, ...RollbackCompatibility::queueConfigProblems($default, is_string($driver) ? $driver : null, $table, $reserved));
        array_push($problems, ...RollbackCompatibility::queueProblems($declaration, $waiting));

        $failedTable = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($failedTable)) {
            throw new \RuntimeException("the {$failedTable} table does not exist, so the failed jobs could not be inspected");
        }

        $failed = [];

        foreach (DB::table($failedTable)->select('payload')->orderBy('id')->cursor() as $job) {
            $identity = RollbackCompatibility::jobIdentity((string) $job->payload);
            $failed[$identity] = ($failed[$identity] ?? 0) + 1;
        }

        ksort($failed);
        $facts['failed_jobs'] = array_sum($failed);
        $unretryable = RollbackCompatibility::unretryableFailedJobs($declaration, $failed);

        if ($unretryable !== []) {
            $list = implode(', ', array_map(fn (string $class, int $count) => "{$count} x {$class}", array_keys($unretryable), $unretryable));
            $warnings[] = "failed jobs the release {$declaration['target']['release']} must never retry: {$list}. Do not run queue:retry for them (nor queue:retry all) under the older release";
        }

        return [$problems, $warnings, $facts];
    }
}
