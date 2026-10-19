<?php

namespace App\Support\Database;

use App\Support\Rehearsal\IntegritySnapshot;
use Illuminate\Database\Connection;
use Throwable;

/**
 * The database profile Release 0.9 is built for (R0.9 WP3), and a read-only way to check a live database against it.
 *
 * It exists because a shared host's server defaults are not ours. The Namecheap probe of 2026-10-19 reported
 * `default_storage_engine=MyISAM`, `latin1`, a non-strict server `sql_mode` and `time_zone=SYSTEM` (Eastern time) on the
 * very MariaDB 11.4.13 that development and CI run with InnoDB, utf8mb4, strict mode and UTC. The application therefore
 * states its own profile and refuses to trust the server's:
 *
 *  - the engine and the session time zone are fixed in `config/database.php` (`engine`, `timezone`), so every
 *    `CREATE TABLE` the application issues says `engine = InnoDB` and every connection runs `SET time_zone = '+00:00'`;
 *  - this class verifies the result: the effective session (through the Laravel connection, not the server defaults),
 *    the engine and collation of every table and text column, and the foreign keys against a committed manifest.
 *
 * Nothing here writes. A check that cannot run is a violation (see {@see self::safely()}): a verifier never passes by
 * being unable to look. It is not a general schema auditor: it checks exactly the assumptions listed above.
 * docs/architecture/database-profile.md.
 */
final class DatabaseProfile
{
    public const ENGINE = 'InnoDB';

    public const TIME_ZONE = '+00:00';

    public const CHARSET = 'utf8mb4';

    public const COLLATION = 'utf8mb4_unicode_ci';

    public const ISOLATION = 'REPEATABLE-READ';

    /** Committed expected foreign keys, relative to the application base path. */
    public const MANIFEST = 'database/profile/foreign-keys.json';

    /** The connection names whose configuration must carry the profile. */
    public const CONNECTIONS = ['mysql', 'mariadb'];

    /**
     * The profile as `config/database.php` states it, for the supported MySQL and MariaDB connections.
     *
     * @return list<string>
     */
    public static function configuration(): array
    {
        $violations = [];

        foreach (self::CONNECTIONS as $name) {
            $config = (array) config("database.connections.{$name}");

            if (($config['engine'] ?? null) !== self::ENGINE) {
                $violations[] = "config database.connections.{$name}.engine is not '".self::ENGINE."'";
            }

            if (($config['timezone'] ?? null) !== self::TIME_ZONE) {
                $violations[] = "config database.connections.{$name}.timezone is not '".self::TIME_ZONE."'";
            }
        }

        return $violations;
    }

    /**
     * The session the application actually runs in on this connection.
     *
     * @return list<string>
     */
    public static function session(Connection $db): array
    {
        $s = $db->selectOne('select @@session.time_zone as tz, @@session.sql_mode as mode, @@character_set_connection as cs,
            @@collation_connection as co, @@session.transaction_isolation as iso');
        $modes = array_filter(explode(',', strtoupper((string) $s->mode)));
        $violations = [];

        if ($s->tz !== self::TIME_ZONE) {
            $violations[] = "session time_zone is '{$s->tz}', expected '".self::TIME_ZONE."'";
        }

        if (array_intersect($modes, ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES']) === []) {
            $violations[] = 'session sql_mode is not strict (neither STRICT_TRANS_TABLES nor STRICT_ALL_TABLES)';
        }

        if (! in_array('NO_ENGINE_SUBSTITUTION', $modes, true)) {
            $violations[] = 'session sql_mode lacks NO_ENGINE_SUBSTITUTION (an unavailable engine would be replaced silently)';
        }

        if ($s->cs !== self::CHARSET || $s->co !== self::COLLATION) {
            $violations[] = "connection character set/collation is '{$s->cs}/{$s->co}', expected '".self::CHARSET.'/'.self::COLLATION."'";
        }

        if (strtoupper((string) $s->iso) !== self::ISOLATION) {
            $violations[] = "session transaction isolation is '{$s->iso}', expected '".self::ISOLATION."'";
        }

        return $violations;
    }

    /**
     * Engine and collation of every base table and text column of the connected database.
     *
     * The one collation exception is a JSON column: MariaDB stores JSON as `longtext` with a `json_valid` check and
     * forces `utf8mb4_bin` on it, so that exact combination is accepted and nothing else is.
     *
     * @return list<string>
     */
    public static function tables(Connection $db): array
    {
        $violations = [];

        foreach ($db->select("select table_name as t, engine as e, table_collation as c from information_schema.tables
            where table_schema = database() and table_type = 'BASE TABLE' order by table_name") as $row) {
            if ($row->e !== self::ENGINE) {
                $violations[] = "table {$row->t} uses engine ".($row->e ?? '(none)').', expected '.self::ENGINE;
            }

            if ($row->c !== self::COLLATION) {
                $violations[] = "table {$row->t} has collation ".($row->c ?? '(none)').', expected '.self::COLLATION;
            }
        }

        foreach ($db->select("select c.table_name as t, c.column_name as col, c.character_set_name as cs, c.collation_name as co,
                (k.constraint_name is not null) as is_json
            from information_schema.columns c
            join information_schema.tables x on x.table_schema = c.table_schema and x.table_name = c.table_name and x.table_type = 'BASE TABLE'
            left join information_schema.check_constraints k on k.constraint_schema = c.table_schema and k.table_name = c.table_name
                and k.check_clause = concat('json_valid(`', c.column_name, '`)')
            where c.table_schema = database() and c.collation_name is not null order by c.table_name, c.ordinal_position") as $row) {
            if ($row->cs !== self::CHARSET) {
                $violations[] = "column {$row->t}.{$row->col} has character set {$row->cs}, expected ".self::CHARSET;
            } elseif ($row->co !== self::COLLATION && ! ($row->co === 'utf8mb4_bin' && (int) $row->is_json === 1)) {
                $violations[] = "column {$row->t}.{$row->col} has collation {$row->co}, expected ".self::COLLATION;
            }
        }

        return $violations;
    }

    /**
     * The committed expected foreign keys: `child.columns->parent.columns` => `ON DELETE rule/ON UPDATE rule`.
     *
     * @return array<string, string>
     */
    public static function expectedForeignKeys(): array
    {
        $manifest = json_decode((string) file_get_contents(base_path(self::MANIFEST)), true, 512, JSON_THROW_ON_ERROR);

        return (array) $manifest['foreign_keys'];
    }

    /**
     * The live foreign keys in manifest form, read through the same code the migration rehearsal snapshots with.
     *
     * @return array<string, string>
     */
    public static function liveForeignKeys(Connection $db): array
    {
        $keys = [];

        foreach (IntegritySnapshot::foreignKeys($db, (string) $db->getDatabaseName()) as $key => $fk) {
            $keys[$key] = $fk['rule'].'/'.$fk['update_rule'];
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $live
     * @return list<string>
     */
    public static function compareForeignKeys(array $expected, array $live): array
    {
        $violations = [];

        foreach ($expected as $key => $rules) {
            if (! array_key_exists($key, $live)) {
                $violations[] = "foreign key missing: {$key} ({$rules})";
            } elseif ($live[$key] !== $rules) {
                $violations[] = "foreign key rules changed: {$key} is {$live[$key]}, expected {$rules}";
            }
        }

        foreach (array_diff_key($live, $expected) as $key => $rules) {
            $violations[] = "unexpected foreign key: {$key} ({$rules})";
        }

        return $violations;
    }

    /**
     * Everything this class checks, on one connection. The foreign-key comparison is only meaningful once every
     * migration has run, so a caller that cannot guarantee that passes `$foreignKeys = false`.
     *
     * @return list<string>
     */
    public static function verify(Connection $db, bool $foreignKeys = true): array
    {
        $violations = [...self::configuration(), ...self::session($db), ...self::tables($db)];

        if ($foreignKeys) {
            $violations = [...$violations, ...self::compareForeignKeys(self::expectedForeignKeys(), self::liveForeignKeys($db))];
        }

        return $violations;
    }

    /**
     * {@see self::verify()} that cannot pass by failing: an exception becomes a violation. Only the exception class and
     * SQLSTATE are reported, because a query exception's message carries the host, database and SQL.
     *
     * @return list<string>
     */
    public static function safely(Connection $db, bool $foreignKeys = true): array
    {
        try {
            return self::verify($db, $foreignKeys);
        } catch (Throwable $e) {
            $state = $e instanceof \PDOException ? ' (SQLSTATE '.($e->errorInfo[0] ?? '?').')' : ($e->getPrevious() instanceof \PDOException ? ' (SQLSTATE '.($e->getPrevious()->errorInfo[0] ?? '?').')' : '');

            return ['the database could not be verified: '.class_basename($e).$state];
        }
    }
}
