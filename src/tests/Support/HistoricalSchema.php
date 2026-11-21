<?php

namespace Tests\Support;

use App\Support\Rehearsal\RehearsalDatabase;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\TargetGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\HistoricalTestCase;

/**
 * Release 1.0 (ORG-2, CP1): a disposable database migrated to a HISTORICAL schema, for the tests whose subject is a migration that has since been
 * superseded (or the schema before one).
 *
 * WHY. A migration test has to run the migration itself, and a migration is DDL, which MariaDB commits implicitly: no test transaction can undo it.
 * Until ORG-2 those tests rolled the newest migration back on the SHARED testing database and forward again, in a `finally`. ORG-2 retires
 * `crm_companies`, whose `down()` cannot restore data and refuses on any residue; reversing it on the schema every other Feature test runs on
 * would be a hazard, and an interrupted run would leave a suite using a partly rolled-back schema. So these tests no longer touch the testing database
 * at all: they run on the primary rehearsal database (`intechral_client_portal_rehearsal`), built here to the schema they were written for.
 *
 * WHICH DATABASE, AND WHY NOT A THIRD. The rehearsal primary is already the repository's one disposable, guarded place to build a schema
 * ({@see TargetGuard}: environment, fixed host and name, an account with no global privilege, an ownership marker). Its provisioning is run as the
 * database root and is deliberately limited to two databases (`./dev rehearse:provision` refuses anything else), so a third database would widen
 * that, which is a separate decision. The rehearsal suites and these tests share the database safely: the process holds the rehearsal lock
 * ({@see RehearsalSandbox::use()}), every rehearsal test establishes its own baseline and rebuilds when the database is not at it, and
 * {@see use()} below rebuilds when the database is not exactly at the schema asked for.
 *
 * POINTS. A schema point is "every migration BEFORE migration X" (or all of them, null): `use(Org2::NAME)` is the schema ORG-2 was written for.
 * What is built is the real files of `database/migrations`, in order, never a copy and never a different set.
 *
 * SELF-HEALING (an interrupted or failing test cannot leave the next one on a partly migrated schema). Building records a REUSE KEY: the point, a SHA-1 of
 * the CONTENT of every migration file that builds it (see {@see contentHash()}; never a timestamp, so a touched file does not rebuild and an edited one
 * always does), and a fingerprint of the schema it produced (tables, engines, columns with their order, indexes, foreign keys, CHECK constraints and the
 * migrations recorded as run). Every `use()` recomputes all three: equal, and the tables are emptied and reused; different (a migration changed, or a test,
 * a crash or another suite left the schema changed), and the database is dropped and rebuilt. Nothing is ever repaired in place.
 */
final class HistoricalSchema
{
    /** The table that records which point the database was built to, and the fingerprint it had then. It belongs to the rehearsal primary. */
    private const STATE_TABLE = 'historical_state';

    /** The ORG-2 migration: the point `before()` is relative to for the pre-ORG-2 schema. */
    public const ORG2 = '2026_11_20_090000_consolidate_organizations_and_retire_crm_companies';

    /** The ORG-1 migration (portal state): `before(ORG1)` is the schema the pinned M2/MO-1A/R1-2 tests were written for. */
    public const ORG1 = '2026_11_19_090000_add_organization_portal_state';

    /**
     * Make the primary rehearsal database hold exactly the schema of "every migration before $beforeMigration" (all of them when null), empty.
     * Call it from a test's `beforeEach`; the test then runs against the application's DEFAULT connection, which
     * {@see HistoricalTestCase} has already pointed at that database.
     */
    public static function use(?string $beforeMigration): void
    {
        $target = RehearsalTarget::primary();
        TargetGuard::assert($target);

        // The point AND the content of the migration files that build it: a changed migration (an edit to a historical one, or a new one that now falls
        // before the point) must rebuild the database even when the schema it left behind still fingerprints the same.
        $point = self::reuseKey($beforeMigration);
        $db = $target->connection();

        // A second call in one test (a test that moves between points) starts from nothing open.
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }

        $state = self::state($db);

        if ($state !== null && $state === $point.'|'.self::fingerprint($db, $target)) {
            self::empty($db, $target);
        } else {
            RehearsalDatabase::reset($target);
            RehearsalDatabase::migrate($target, self::paths($beforeMigration));

            $db = $target->connection();
            $db->statement('create table `'.self::STATE_TABLE.'` (`state` text not null)');
            $db->table(self::STATE_TABLE)->insert(['state' => $point.'|'.self::fingerprint($db, $target)]);
        }

        self::useFactoriesFor($beforeMigration);

        // As RefreshDatabase does for every Feature test: one open transaction, which the test's rows live in and which tests/HistoricalTestCase rolls back.
        // These tests were written under it: a DDL statement in one ends the real transaction while Laravel still counts it, and some depend on that
        // accounting (ManagedOrganizationMigrationTest says so). Rows are removed by the next use() either way.
        $db->beginTransaction();
    }

    /**
     * The production factories serve only the CANONICAL schema (ORG-2). A point before ORG-2 builds rows as they were then (an Organization without `created_by`,
     * a Person affiliated through `crm_company_id`, the retired Company), through the test-only factories in tests/Support/Historical; every other model, and every
     * point at or after ORG-2, keeps the application's own convention. HistoricalTestCase flushes this in tearDown so nothing outlives a test.
     */
    private static function useFactoriesFor(?string $beforeMigration): void
    {
        if ($beforeMigration === null || $beforeMigration > self::ORG2) {
            Factory::flushState();

            return;
        }

        Factory::guessFactoryNamesUsing(function (string $model): string {
            $historical = 'Tests\\Support\\Historical\\Factories\\'.class_basename($model).'Factory';

            if (class_exists($historical)) {
                return $historical;
            }

            $name = Str::startsWith($model, 'App\\Models\\') ? Str::after($model, 'App\\Models\\') : Str::after($model, 'App\\');

            return 'Database\\Factories\\'.$name.'Factory';
        });
    }

    /** The migration files, absolute paths, in order, that make up "before $beforeMigration". @return list<string> */
    public static function paths(?string $beforeMigration): array
    {
        $files = glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        if ($beforeMigration !== null && ! is_file(database_path("migrations/{$beforeMigration}.php"))) {
            throw new RuntimeException("There is no migration \"{$beforeMigration}\" to build a historical schema before.");
        }

        return array_values(array_filter(
            $files,
            fn (string $file) => $beforeMigration === null || basename($file, '.php') < $beforeMigration
        ));
    }

    /** A stable, secret-free fingerprint of the schema (structure and migrations recorded), never of any row. */
    public static function fingerprint(Connection $db, RehearsalTarget $target): string
    {
        $name = $target->database();
        $skip = [RehearsalTarget::MARKER_TABLE, self::STATE_TABLE];
        $notIn = "table_name not in ('".implode("','", $skip)."')";

        $parts = [
            'tables' => $db->select("select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' and {$notIn} order by table_name", [$name]),
            'columns' => $db->select("select table_name as t, column_name as c, ordinal_position as o, column_type as ty, is_nullable as n, column_default as d, extra as x, collation_name as co from information_schema.columns where table_schema = ? and {$notIn} order by table_name, ordinal_position", [$name]),
            'indexes' => $db->select("select table_name as t, index_name as i, seq_in_index as s, column_name as c, non_unique as u from information_schema.statistics where table_schema = ? and {$notIn} order by table_name, index_name, seq_in_index", [$name]),
            'foreign_keys' => $db->select("select k.table_name as t, k.constraint_name as n, k.column_name as c, k.referenced_table_name as p, k.referenced_column_name as pc, r.delete_rule as d, r.update_rule as u from information_schema.key_column_usage k join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name where k.table_schema = ? and k.referenced_table_name is not null and k.{$notIn} order by k.table_name, k.constraint_name, k.ordinal_position", [$name]),
            'checks' => $db->select("select table_name as t, constraint_name as n, check_clause as c from information_schema.check_constraints where constraint_schema = ? and {$notIn} order by table_name, constraint_name", [$name]),
            'migrations' => $db->table('migrations')->orderBy('migration')->pluck('migration')->all(),
        ];

        return sha1(json_encode($parts, JSON_UNESCAPED_SLASHES));
    }

    private static function pointName(?string $beforeMigration): string
    {
        return $beforeMigration === null ? 'all' : "before:{$beforeMigration}";
    }

    /** What a build is keyed by, apart from the schema it produced: the point and the content of the files that build it. @param  list<string>|null  $paths  default: paths() */
    public static function reuseKey(?string $beforeMigration, ?array $paths = null): string
    {
        return self::pointName($beforeMigration).'|'.self::contentHash($paths ?? self::paths($beforeMigration));
    }

    /**
     * A deterministic hash of the migration files: each file's NAME (its order and identity) and the SHA-1 of its CONTENT, in name order. Nothing about the
     * files' times, owners or locations goes in, so the same migrations hash the same in any checkout.
     *
     * @param  list<string>  $paths
     */
    public static function contentHash(array $paths): string
    {
        usort($paths, fn (string $a, string $b) => strcmp(basename($a), basename($b)));

        $parts = array_map(function (string $path) {
            $content = @file_get_contents($path);

            if ($content === false) {
                throw new RuntimeException('A historical migration file cannot be read: '.basename($path));
            }

            return basename($path).':'.sha1($content);
        }, $paths);

        return sha1(implode("\n", $parts));
    }

    private static function state(Connection $db): ?string
    {
        $exists = $db->selectOne('select count(*) as n from information_schema.tables where table_schema = database() and table_name in (?, ?)', [self::STATE_TABLE, 'migrations'])->n;

        return (int) $exists === 2 ? $db->table(self::STATE_TABLE)->value('state') : null;
    }

    /** Remove every row a previous test left, keeping the schema, the migrations record and the harness's own tables. */
    private static function empty(Connection $db, RehearsalTarget $target): void
    {
        $keep = [RehearsalTarget::MARKER_TABLE, self::STATE_TABLE, 'migrations'];
        $tables = array_map(
            fn (object $row) => (string) $row->t,
            $db->select("select table_name as t from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name", [$target->database()])
        );

        $db->statement('set foreign_key_checks = 0');

        try {
            foreach ($tables as $table) {
                if (! in_array($table, $keep, true) && $db->table($table)->exists()) {
                    $db->statement("truncate table `{$table}`");
                }
            }
        } finally {
            $db->statement('set foreign_key_checks = 1');
        }
    }
}
