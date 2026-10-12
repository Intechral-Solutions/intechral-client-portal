<?php

namespace App\Support\Rehearsal;

/**
 * Compares two {@see IntegritySnapshot}s and returns every difference that was not announced.
 *
 * Nothing is "probably fine". With no expectations, any difference at all is reported: a missing, added or
 * altered row, a lost or changed file, a table or column that appeared or vanished, a changed foreign-key
 * rule (ON DELETE or ON UPDATE), a changed index or collation, a changed fact, an unexpected migration, or a
 * check that is not zero. A candidate migration declares
 * what it is MEANT to change (see database/rehearsal/candidates.php), and the comparison then demands an
 * exact match: a declared change that did not happen is a difference, and so is an undeclared one.
 */
final class SnapshotComparator
{
    /**
     * @param  list<string>  $expectedSchema  the declared structural changes, in the manifest notation
     * @param  list<string>  $dataTables  tables whose existing rows the migration is meant to change
     * @param  list<string>  $appliedMigrations  the candidate migrations that were applied
     * @return list<array{code: string, subject: string, detail: string}>
     */
    public static function compare(
        array $before,
        array $after,
        array $expectedSchema = [],
        array $dataTables = [],
        array $appliedMigrations = [],
    ): array {
        $differences = [];
        $add = function (string $code, string $subject, string $detail = '') use (&$differences) {
            $differences[] = ['code' => $code, 'subject' => $subject, 'detail' => $detail];
        };

        // Migrations: exactly the baseline's plus the ones that were applied on purpose.
        $expected = array_values(array_unique([...$before['migrations'], ...$appliedMigrations]));
        sort($expected);
        if ($expected !== $after['migrations']) {
            $add('migrations.unexpected', 'migrations', 'added: '.implode(', ', array_diff($after['migrations'], $expected)).'; missing: '.implode(', ', array_diff($expected, $after['migrations'])));
        }

        // Structure: the observed change set must equal the declared one.
        $observed = self::schemaDelta($before['schema'], $after['schema']);
        foreach (array_diff($observed, $expectedSchema) as $token) {
            $add('schema.undeclared_change', $token, 'the schema changed in a way no approved candidate declared');
        }
        foreach (array_diff($expectedSchema, $observed) as $token) {
            $add('schema.declared_change_missing', $token, 'an approved candidate declared this change but it is not in the schema');
        }

        $droppedTables = array_diff($before['schema']['tables'], $after['schema']['tables']);

        // Rows: counts, then each row's digest.
        foreach ($before['rows'] as $table => $count) {
            // The migrations table gains one row per applied candidate; the "migrations" check above is what
            // judges it, exactly.
            if ($table === 'migrations' || in_array($table, $droppedTables, true)) {
                continue;
            }

            if (! array_key_exists($table, $after['rows'])) {
                $add('rows.table_unreadable', $table);

                continue;
            }

            if ($after['rows'][$table] !== $count && ! in_array($table, $dataTables, true)) {
                $add('rows.count_changed', $table, "{$count} -> {$after['rows'][$table]}");
            }
        }

        foreach ($before['digests'] as $table => $rows) {
            if (in_array($table, $dataTables, true) || in_array($table, $droppedTables, true)) {
                continue;
            }

            $now = $after['digests'][$table] ?? [];
            $missing = array_keys(array_diff_key($rows, $now));
            $added = array_keys(array_diff_key($now, $rows));
            $changed = array_keys(array_filter($rows, fn ($hash, $key) => isset($now[$key]) && $now[$key] !== $hash, ARRAY_FILTER_USE_BOTH));

            foreach (['row.missing' => $missing, 'row.added' => $added, 'row.changed' => $changed] as $code => $keys) {
                if ($keys !== []) {
                    $add($code, $table, count($keys).' row(s), first keys: '.implode(', ', array_slice($keys, 0, 5)));
                }
            }
        }

        // Checks that must be zero, wherever they fail.
        foreach ($after['checks'] as $name => $value) {
            if ($value !== 0) {
                $add('check.failed', $name, (string) $value);
            }
        }
        foreach (array_diff_key($before['checks'], $after['checks']) as $name => $value) {
            $add('check.no_longer_run', $name, 'the check existed before and is missing now');
        }

        // Facts must not move.
        foreach ($before['facts'] as $name => $value) {
            if (($after['facts'][$name] ?? null) !== $value) {
                $add('fact.changed', $name, json_encode($value).' -> '.json_encode($after['facts'][$name] ?? null));
            }
        }

        // Files must be identical.
        foreach (array_diff_key($before['files'], $after['files']) as $path => $info) {
            $add('file.missing', $path);
        }
        foreach (array_diff_key($after['files'], $before['files']) as $path => $info) {
            $add('file.added', $path);
        }
        foreach ($before['files'] as $path => $info) {
            if (isset($after['files'][$path]) && $after['files'][$path] !== $info) {
                $add('file.changed', $path, 'size or content differs');
            }
        }

        return $differences;
    }

    /**
     * The structural changes between two schemas, as sorted tokens in the manifest notation.
     *
     * @return list<string>
     */
    public static function schemaDelta(array $before, array $after): array
    {
        $tokens = [];

        foreach (array_diff($after['tables'], $before['tables']) as $table) {
            $tokens[] = "table+:{$table}";
        }
        foreach (array_diff($before['tables'], $after['tables']) as $table) {
            $tokens[] = "table-:{$table}";
        }

        foreach (array_intersect($before['tables'], $after['tables']) as $table) {
            $old = $before['columns'][$table];
            $new = $after['columns'][$table];

            foreach (array_diff_key($new, $old) as $column => $_) {
                $tokens[] = "column+:{$table}.{$column}";
            }
            foreach (array_diff_key($old, $new) as $column => $_) {
                $tokens[] = "column-:{$table}.{$column}";
            }
            foreach ($old as $column => $signature) {
                if (isset($new[$column]) && $new[$column] !== $signature) {
                    $tokens[] = "column~:{$table}.{$column}";
                }
            }
            if (($before['primary'][$table] ?? []) !== ($after['primary'][$table] ?? [])) {
                $tokens[] = "primary~:{$table}";
            }

            // A column's collation, reported on its own (a new column's collation is part of "column+").
            $oldCollations = $before['collations'][$table] ?? [];
            $newCollations = $after['collations'][$table] ?? [];
            foreach ($oldCollations as $column => $collation) {
                if (isset($new[$column]) && ($newCollations[$column] ?? null) !== $collation) {
                    $tokens[] = "collation~:{$table}.{$column}";
                }
            }
            foreach (array_diff_key($newCollations, $oldCollations) as $column => $_) {
                if (isset($old[$column])) {
                    $tokens[] = "collation~:{$table}.{$column}";
                }
            }

            if (($before['table_collations'][$table] ?? null) !== ($after['table_collations'][$table] ?? null)) {
                $tokens[] = "tablecollation~:{$table}";
            }
        }

        foreach (array_diff($after['indexes'] ?? [], $before['indexes'] ?? []) as $key) {
            $tokens[] = "index+:{$key}";
        }
        foreach (array_diff($before['indexes'] ?? [], $after['indexes'] ?? []) as $key) {
            $tokens[] = "index-:{$key}";
        }

        foreach (array_diff($after['unique'], $before['unique']) as $key) {
            $tokens[] = "unique+:{$key}";
        }
        foreach (array_diff($before['unique'], $after['unique']) as $key) {
            $tokens[] = "unique-:{$key}";
        }

        // An added or removed foreign key states both rules (ON DELETE/ON UPDATE), so neither can change unseen.
        foreach (array_diff_key($after['foreign_keys'], $before['foreign_keys']) as $key => $fk) {
            $tokens[] = "fk+:{$key}:{$fk['rule']}/".($fk['update_rule'] ?? '?');
        }
        foreach (array_diff_key($before['foreign_keys'], $after['foreign_keys']) as $key => $fk) {
            $tokens[] = "fk-:{$key}:{$fk['rule']}/".($fk['update_rule'] ?? '?');
        }
        foreach ($before['foreign_keys'] as $key => $fk) {
            $now = $after['foreign_keys'][$key] ?? null;

            if ($now === null) {
                continue;
            }
            if ($now['rule'] !== $fk['rule']) {
                $tokens[] = "fk~:{$key}:{$fk['rule']}>{$now['rule']}";
            }
            if (($now['update_rule'] ?? null) !== ($fk['update_rule'] ?? null)) {
                $tokens[] = "fku~:{$key}:".($fk['update_rule'] ?? '?').'>'.($now['update_rule'] ?? '?');
            }
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }
}
