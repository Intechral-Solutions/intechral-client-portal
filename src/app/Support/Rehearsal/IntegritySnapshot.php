<?php

namespace App\Support\Rehearsal;

use App\Services\ProjectIntegrityAudit;
use App\Support\Database\DatabaseProfile;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A deterministic, secret-free description of everything a migration or a restore must not damage.
 *
 * It holds six things, and is comparable with another snapshot of the same data:
 *
 *  - schema: tables, columns, column and table collations, primary and unique keys, non-unique indexes, and
 *    every foreign key with its ON DELETE and ON UPDATE rules;
 *  - rows: a count per table and, per table, a SHA-1 of each row over the columns it had at baseline, keyed
 *    by primary key. Only hashes are stored, never values, so a report carries no personal data. A
 *    candidate migration that adds a column does not disturb the hashes (the baseline columns are pinned);
 *    one that rewrites, drops or loses a row does;
 *  - checks: counts that must be ZERO (foreign-key orphans, logical references the database does not
 *    enforce, ticket numbers, attachment rows against files and files against rows, tasks, milestones,
 *    dependencies, time entries and their blocks, invoice arithmetic, the EPIC-010D preflight gates P3b and
 *    P4b, and the existing project integrity audit). A check that cannot run is recorded as such and counts
 *    as a failure: a snapshot never passes by being unable to look;
 *  - facts: informational values that must merely not change (the EPIC-010D P0 to P4 figures, the next
 *    ticket id, role grants, counts by status);
 *  - files: size and SHA-256 of every private file, for restore equivalence;
 *  - migrations: the names recorded as run.
 *
 * Volatile fields are avoided at the source rather than scrubbed: fixture timestamps are fixed, and
 * `migrations.id`/`batch`, sessions, cache and queue tables are left out of the digests.
 */
final class IntegritySnapshot
{
    /** Tables whose contents are machinery or runtime state, not business records. */
    private const NOT_DIGESTED = [
        'migrations', RehearsalTarget::MARKER_TABLE, 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /** Project-audit results that must be zero. The others are informational facts. */
    private const PROJECT_AUDIT_ZERO = [
        'tasks_with_column_from_another_project', 'tasks_with_milestone_from_another_project',
        'time_entries_with_missing_project_or_task', 'projects_with_no_done_column',
        'projects_with_multiple_done_columns', 'tasks_linked_to_project_and_ticket',
        'time_entries_with_task_and_project', 'time_entries_with_ticket_and_other_context',
    ];

    private const USER = 'App\\Models\\User';

    /** @var array<string, int|string> */
    private array $checks = [];

    private function __construct(
        private readonly Connection $db,
        private readonly RehearsalTarget $target,
        private readonly ?array $pinned,
    ) {}

    /**
     * @param  array<string, list<string>>|null  $pinnedColumns  the baseline's columns per table; null on the
     *                                                           baseline capture itself
     * @return array<string, mixed>
     */
    public static function capture(RehearsalTarget $target, ?array $pinnedColumns = null): array
    {
        return RehearsalDatabase::within($target, function () use ($target, $pinnedColumns) {
            return (new self($target->connection(), $target, $pinnedColumns))->build();
        });
    }

    /**
     * The column names per table of a snapshot, to pin the row digests of a later capture to the same
     * columns, so that a column a migration ADDS does not read as a changed row.
     *
     * @return array<string, list<string>>
     */
    public static function pinnedColumns(array $snapshot): array
    {
        return array_map(fn (array $columns) => array_keys($columns), $snapshot['schema']['columns']);
    }

    /** A stable fingerprint of the comparable content (not the database name). */
    public static function hash(array $snapshot): string
    {
        unset($snapshot['database']);

        return hash('sha256', json_encode(self::sorted($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Every check that did not pass, as "name: value".
     *
     * @return list<string>
     */
    public static function failures(array $snapshot): array
    {
        $failures = [];

        foreach ($snapshot['checks'] as $name => $value) {
            if ($value !== 0) {
                $failures[] = "{$name}: ".(is_int($value) ? $value : $value);
            }
        }

        return $failures;
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $schema = $this->schema();

        $this->referentialChecks($schema);
        $this->ticketChecks();
        $this->taskChecks();
        $this->timeChecks();
        $this->invoiceChecks();
        $files = $this->fileChecks();

        $digests = $this->digests($schema['columns']);

        $snapshot = [
            'version' => 1,
            'database' => $this->target->database(),
            'schema' => $schema,
            'migrations' => RehearsalDatabase::ran($this->target),
            'rows' => $digests['counts'],
            'digests' => $digests['rows'],
            'checks' => $this->checks,
            'facts' => $this->facts(),
            'files' => $files,
        ];

        ksort($snapshot['checks']);

        return $snapshot;
    }

    // ── schema ────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $db = $this->target->database();
        $marker = RehearsalTarget::MARKER_TABLE;

        $columns = [];
        $collations = [];
        foreach ($this->db->select(
            'select table_name as t, column_name as c, column_type as ty, is_nullable as n, column_default as d, extra as e,
                    character_set_name as cs, collation_name as co
             from information_schema.columns where table_schema = ? and table_name <> ? order by table_name, ordinal_position',
            [$db, $marker]
        ) as $row) {
            $columns[$row->t][$row->c] = $row->ty.'|'.($row->n === 'YES' ? 'null' : 'not null').'|'.($row->d ?? '-').'|'.$row->e;

            // Kept apart from the column signature so a collation change is reported as itself, and declared as itself.
            if ($row->co !== null) {
                $collations[$row->t][$row->c] = $row->cs.'/'.$row->co;
            }
        }

        $tableCollations = [];
        foreach ($this->db->select(
            "select table_name as t, table_collation as tc from information_schema.tables
             where table_schema = ? and table_type = 'BASE TABLE' and table_name <> ? order by table_name",
            [$db, $marker]
        ) as $row) {
            $tableCollations[$row->t] = (string) $row->tc;
        }

        $primary = [];
        $unique = [];
        $keys = [];
        $plain = [];
        foreach ($this->db->select(
            'select table_name as t, index_name as i, non_unique as nu, column_name as c from information_schema.statistics
             where table_schema = ? and table_name <> ? order by table_name, index_name, seq_in_index',
            [$db, $marker]
        ) as $row) {
            if ($row->i === 'PRIMARY') {
                $primary[$row->t][] = $row->c;
            } elseif ((int) $row->nu === 0) {
                $keys[$row->t][$row->i][] = $row->c;
            } else {
                $plain[$row->t][$row->i][] = $row->c;
            }
        }
        foreach ($keys as $table => $indexes) {
            foreach ($indexes as $cols) {
                $unique[] = $table.'('.implode(',', $cols).')';
            }
        }
        sort($unique);

        // Non-unique indexes by table and column list, not by name: a rename is not a change, a lost or added
        // index is. Foreign-key backing indexes are included, so a migration that drops one is seen.
        $indexes = [];
        foreach ($plain as $table => $byName) {
            foreach ($byName as $cols) {
                $indexes[] = $table.'('.implode(',', $cols).')';
            }
        }
        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        $foreignKeys = self::foreignKeys($this->db, $db, $marker);

        return [
            'tables' => array_keys($columns),
            'columns' => $columns,
            'primary' => $primary,
            'unique' => $unique,
            'indexes' => $indexes,
            'collations' => $collations,
            'table_collations' => $tableCollations,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * Every foreign key of one database, keyed `child.cols->parent.cols`, with its ON DELETE and ON UPDATE rules.
     *
     * Static and parameterised so the Release 0.9 database-profile verification (R0.9 WP3,
     * {@see DatabaseProfile}) reads foreign keys through exactly the code the migration
     * rehearsal compares them with, instead of keeping a second query that could disagree.
     *
     * @return array<string, array{rule: string, update_rule: string, child: string, columns: list<string>, parent: string, parent_columns: list<string>}>
     */
    public static function foreignKeys(Connection $db, string $database, ?string $excludeTable = null): array
    {
        $groups = [];
        foreach ($db->select(
            'select k.table_name as t, k.constraint_name as cn, k.column_name as c, k.referenced_table_name as pt,
                    k.referenced_column_name as pc, r.delete_rule as dr, r.update_rule as ur
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = ? and k.referenced_table_name is not null and k.table_name <> ?
             order by k.table_name, k.constraint_name, k.ordinal_position',
            [$database, $excludeTable ?? '']
        ) as $row) {
            $groups[$row->t.'|'.$row->cn]['t'] = $row->t;
            $groups[$row->t.'|'.$row->cn]['pt'] = $row->pt;
            $groups[$row->t.'|'.$row->cn]['rule'] = $row->dr;
            $groups[$row->t.'|'.$row->cn]['update'] = $row->ur;
            $groups[$row->t.'|'.$row->cn]['c'][] = $row->c;
            $groups[$row->t.'|'.$row->cn]['pc'][] = $row->pc;
        }

        $foreignKeys = [];
        foreach ($groups as $g) {
            $foreignKeys[$g['t'].'.'.implode(',', $g['c']).'->'.$g['pt'].'.'.implode(',', $g['pc'])] = [
                'rule' => $g['rule'], 'update_rule' => $g['update'], 'child' => $g['t'], 'columns' => $g['c'], 'parent' => $g['pt'], 'parent_columns' => $g['pc'],
            ];
        }
        ksort($foreignKeys);

        return $foreignKeys;
    }

    // ── checks ────────────────────────────────────────────────────────────────────────────────

    private function check(string $name, string $sql, array $bind = []): void
    {
        try {
            $this->checks[$name] = (int) $this->db->selectOne($sql, $bind)->n;
        } catch (Throwable $e) {
            // A check that cannot run is a failure, not a pass. A migration that removes something a check
            // reads must come with a harness update, and the run says so.
            $this->checks[$name] = 'unavailable: '.strtok($e->getMessage(), "\n");
        }
    }

    /** @param  array<string, mixed>  $schema */
    private function referentialChecks(array $schema): void
    {
        // Every foreign key, whether or not the engine enforces it right now (checks may be off during a load).
        foreach ($schema['foreign_keys'] as $key => $fk) {
            $join = [];
            $notNull = [];
            foreach ($fk['columns'] as $i => $col) {
                $join[] = "c.`{$col}` = p.`{$fk['parent_columns'][$i]}`";
                $notNull[] = "c.`{$col}` is not null";
            }
            $this->check(
                "fk_orphans:{$key}",
                "select count(*) as n from `{$fk['child']}` c left join `{$fk['parent']}` p on ".implode(' and ', $join)
                .' where p.`'.$fk['parent_columns'][0].'` is null and '.implode(' and ', $notNull)
            );
        }

        // References the database does NOT enforce (no foreign key): checked here so they cannot rot silently.
        $logical = [
            'sessions.user_id->users' => ['sessions', 'user_id', 'users', ''],
            'projects.client_id->users' => ['projects', 'client_id', 'users', ''],
            'model_has_roles.model_id->users' => ['model_has_roles', 'model_id', 'users', "c.model_type = '".addslashes(self::USER)."'"],
            'model_has_permissions.model_id->users' => ['model_has_permissions', 'model_id', 'users', "c.model_type = '".addslashes(self::USER)."'"],
        ];
        foreach (['Ticket' => 'tickets', 'Project' => 'projects', 'User' => 'users'] as $model => $table) {
            $type = addslashes('App\\Models\\'.$model);
            $logical["activity_log.subject->{$table}"] = ['activity_log', 'subject_id', $table, "c.subject_type = '{$type}'"];
            $logical["activity_log.causer->{$table}"] = ['activity_log', 'causer_id', $table, "c.causer_type = '{$type}'"];
        }
        foreach ($logical as $name => [$child, $column, $parent, $extra]) {
            $this->check(
                "logical_orphans:{$name}",
                "select count(*) as n from `{$child}` c where c.`{$column}` is not null".($extra !== '' ? " and {$extra}" : '')
                ." and not exists (select 1 from `{$parent}` p where p.id = c.`{$column}`)"
            );
        }
    }

    private function ticketChecks(): void
    {
        $this->check('ticket_number_differs_from_padded_id',
            "select count(*) as n from tickets where ticket_number regexp '^TKT-[0-9]{4,}\$' and ticket_number <> concat('TKT-', lpad(id, 4, '0'))");
        // EPIC-010D preflight P3b: a number above the highest id could be reissued by the allocator.
        $this->check('epic_010d_p3b_number_above_max_id',
            "select count(*) as n from tickets where ticket_number regexp '^TKT-[0-9]{4,}\$' and cast(substring(ticket_number, 5) as unsigned) > (select coalesce(max(id), 0) from tickets)");
        // EPIC-010D preflight P4b: an attachment and its reply on different tickets.
        $this->check('epic_010d_p4b_attachment_reply_ticket_mismatch',
            'select count(*) as n from ticket_attachments a join ticket_replies r on r.id = a.reply_id where r.ticket_id <> a.ticket_id');
        $this->check('attachment_path_outside_its_ticket_directory',
            "select count(*) as n from ticket_attachments where path not like concat('tickets/', ticket_id, '/%') or path like '%..%' or path like '/%'");
    }

    private function taskChecks(): void
    {
        $audit = DB::usingConnection($this->target->connectionName(), fn () => (new ProjectIntegrityAudit)->run());

        foreach ($audit as $name => $count) {
            if (in_array($name, self::PROJECT_AUDIT_ZERO, true)) {
                $this->checks["project_audit:{$name}"] = $count;
            }
        }

        $this->check('task_dependency_on_itself', 'select count(*) as n from task_dependencies where task_id = depends_on_task_id');
        $this->check('task_dependency_across_projects',
            'select count(*) as n from task_dependencies d join tasks a on a.id = d.task_id join tasks b on b.id = d.depends_on_task_id
             where a.project_id is not null and b.project_id is not null and a.project_id <> b.project_id');
        $this->check('milestone_completed_by_without_completed_at',
            'select count(*) as n from project_milestones where completed_by is not null and completed_at is null');

        $edges = [];
        foreach ($this->db->select('select task_id as a, depends_on_task_id as b from task_dependencies') as $e) {
            $edges[(int) $e->a][] = (int) $e->b;
        }
        $this->checks['task_dependency_cycles'] = $this->cycles($edges);
    }

    /** @param  array<int, list<int>>  $edges  The number of tasks that lie on a dependency cycle. */
    private function cycles(array $edges): int
    {
        $onCycle = [];
        foreach (array_keys($edges) as $start) {
            $stack = [[$start, [$start]]];
            while ($stack) {
                [$node, $path] = array_pop($stack);
                foreach ($edges[$node] ?? [] as $next) {
                    if ($next === $start) {
                        foreach ($path as $member) {
                            $onCycle[$member] = true;
                        }
                    } elseif (! in_array($next, $path, true)) {
                        $stack[] = [$next, [...$path, $next]];
                    }
                }
            }
        }

        return count($onCycle);
    }

    private function timeChecks(): void
    {
        $this->check('time_block_user_differs_from_entry_user',
            'select count(*) as n from time_entry_blocks b join time_entries e on e.id = b.time_entry_id where e.user_id <> b.user_id');
        $this->check('time_block_slots_not_summing_to_100',
            'select count(*) as n from (select sum(allocation_pct) as s from time_entry_blocks group by user_id, block_date, block_number) x where abs(x.s - 100) > 0.01');
        $this->check('running_timer_without_start',
            'select count(*) as n from time_entries where stopped_at is null and timer_started_at is null and duration_minutes = 0');
    }

    private function invoiceChecks(): void
    {
        $this->check('invoice_item_amount_not_quantity_times_price',
            'select count(*) as n from invoice_items where abs(amount - quantity * unit_price) > 0.005');
        $this->check('invoice_subtotal_not_sum_of_items',
            'select count(*) as n from invoices i where abs(i.subtotal - coalesce((select sum(amount) from invoice_items where invoice_id = i.id), 0)) > 0.005');
        $this->check('invoice_total_not_subtotal_plus_tax',
            'select count(*) as n from invoices where abs(total - subtotal - tax_amount) > 0.005');
    }

    /**
     * Attachment rows against files, and files against rows, on the rehearsal private disk.
     *
     * @return array<string, array{size: int, sha256: string}>
     */
    private function fileChecks(): array
    {
        $root = $this->target->filesRoot();
        $files = RehearsalFiles::listFiles($root);

        $inventory = [];
        foreach ($files as $path) {
            $inventory[$path] = ['size' => (int) filesize($root.'/'.$path), 'sha256' => (string) hash_file('sha256', $root.'/'.$path)];
        }

        $rows = $this->db->select('select id, ticket_id, path, size from ticket_attachments order by id');
        $referenced = [];
        $missing = $mismatched = $unsafe = 0;

        foreach ($rows as $row) {
            $path = (string) $row->path;
            if (str_contains($path, '..') || str_starts_with($path, '/') || $path === '') {
                $unsafe++;

                continue;
            }
            $referenced[$path] = true;
            if (! isset($inventory[$path])) {
                $missing++;
            } elseif ($inventory[$path]['size'] !== (int) $row->size) {
                $mismatched++;
            }
        }

        $unreferenced = array_filter($files, fn (string $f) => ! isset($referenced[$f]));
        $ticketsWithRows = array_flip(array_map(fn ($r) => (string) $r->ticket_id, $rows));
        $strayDirectories = array_filter(
            RehearsalFiles::listDirectories($root, 'tickets'),
            fn (string $dir) => ! isset($ticketsWithRows[$dir])
        );

        $this->checks['attachment_rows_without_file'] = $missing;
        $this->checks['attachment_size_differs_from_file'] = $mismatched;
        $this->checks['attachment_path_unsafe'] = $unsafe;
        $this->checks['files_without_attachment_row'] = count($unreferenced);
        $this->checks['ticket_directories_without_attachment_rows'] = count($strayDirectories);
        $this->checks['files_outside_tickets_directory'] = count(array_filter($files, fn (string $f) => ! str_starts_with($f, 'tickets/')));

        return $inventory;
    }

    // ── facts ─────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function facts(): array
    {
        $one = fn (string $sql, array $bind = []) => (int) $this->db->selectOne($sql, $bind)->n;
        $user = self::USER;

        $facts = [
            // EPIC-010D A1.10 preflight, informational figures.
            'epic_010d_p0_tickets' => $one('select count(*) as n from tickets'),
            'epic_010d_p1_company_linked_tickets' => $one('select count(*) as n from tickets where company_id is not null'),
            'epic_010d_p2_assignees_without_tickets_assign' => $one(
                "select count(*) as n from tickets t where t.assignee_id is not null
                 and not exists (select 1 from model_has_permissions mhp join permissions p on p.id = mhp.permission_id
                                 where mhp.model_type = ? and mhp.model_id = t.assignee_id and p.name = 'tickets.assign' and p.guard_name = 'web')
                 and not exists (select 1 from model_has_roles mhr join role_has_permissions rhp on rhp.role_id = mhr.role_id
                                 join permissions p on p.id = rhp.permission_id
                                 where mhr.model_type = ? and mhr.model_id = t.assignee_id and p.name = 'tickets.assign' and p.guard_name = 'web')",
                [$user, $user]
            ),
            'epic_010d_p3a_numbers_not_in_service_form' => $one("select count(*) as n from tickets where ticket_number not regexp '^TKT-[0-9]{4,}\$'"),
            'epic_010d_p4_internal_note_attachments' => $one('select count(*) as n from ticket_attachments a join ticket_replies r on r.id = a.reply_id where r.is_internal = 1'),
            'tickets_by_status' => $this->grouped('select status as k, count(*) as n from tickets group by status'),
            'ticket_ids' => $this->db->table('tickets')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'tickets_next_auto_increment' => (int) $this->db->selectOne(
                'select auto_increment as n from information_schema.tables where table_schema = ? and table_name = ?',
                [$this->target->database(), 'tickets']
            )->n,
            'user_emails_colliding_when_normalized' => $one('select count(*) as n from (select 1 from users group by lower(trim(email)) having count(*) > 1) x'),
            'time_entries_by_state' => [
                'running' => $one('select count(*) as n from time_entries where timer_started_at is not null and stopped_at is null'),
                'stopped' => $one('select count(*) as n from time_entries where timer_started_at is null and stopped_at is not null'),
                'billed' => $one('select count(*) as n from time_entries where billed = 1'),
            ],
            'billed_entries_without_invoice' => $one('select count(*) as n from time_entries where billed = 1 and invoice_id is null'),
            'roles' => $this->roles(),
            'assignments' => $this->assignments(),
        ];

        $audit = DB::usingConnection($this->target->connectionName(), fn () => (new ProjectIntegrityAudit)->run());
        foreach ($audit as $name => $count) {
            if (! in_array($name, self::PROJECT_AUDIT_ZERO, true)) {
                $facts["project_audit:{$name}"] = $count;
            }
        }

        ksort($facts);

        return $facts;
    }

    /** @return array<string, int> */
    private function grouped(string $sql): array
    {
        $out = [];
        foreach ($this->db->select($sql) as $row) {
            $out[(string) $row->k] = (int) $row->n;
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, array{permissions: int, sha1: string}> role name => grants */
    private function roles(): array
    {
        $out = [];
        foreach ($this->db->select('select id, name from roles order by name') as $role) {
            $names = $this->db->table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_id', $role->id)->orderBy('permissions.name')->pluck('permissions.name')->all();
            $out[$role->name] = ['permissions' => count($names), 'sha1' => sha1(implode("\n", $names))];
        }

        return $out;
    }

    /** @return array<string, list<string>> "user id" => role names and direct permissions, by id, no emails */
    private function assignments(): array
    {
        $out = [];
        foreach ($this->db->select(
            'select m.model_id as id, r.name as name from model_has_roles m join roles r on r.id = m.role_id order by m.model_id, r.name'
        ) as $row) {
            $out[(string) $row->id][] = 'role:'.$row->name;
        }
        foreach ($this->db->select(
            'select m.model_id as id, p.name as name from model_has_permissions m join permissions p on p.id = m.permission_id order by m.model_id, p.name'
        ) as $row) {
            $out[(string) $row->id][] = 'direct:'.$row->name;
        }
        ksort($out);

        return $out;
    }

    // ── row digests ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, string>>  $columns
     * @return array{counts: array<string, int>, rows: array<string, array<string, string>>}
     */
    private function digests(array $columns): array
    {
        $counts = [];
        $rows = [];

        foreach ($columns as $table => $cols) {
            $counts[$table] = (int) $this->db->selectOne("select count(*) as n from `{$table}`")->n;

            if (in_array($table, self::NOT_DIGESTED, true)) {
                continue;
            }

            $names = $this->pinned[$table] ?? array_keys($cols);
            $names = array_values(array_intersect($names, array_keys($cols)));
            sort($names);

            if ($names === []) {
                continue;
            }

            $primary = $this->primaryOf($table, $names);
            $list = implode(',', array_map(fn (string $c) => "`{$c}`", $names));

            foreach ($this->db->cursor("select {$list} from `{$table}`") as $row) {
                $values = array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row);
                $hash = sha1(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $key = $primary === [] ? $hash : implode('|', array_map(fn (string $c) => (string) $values[$c], $primary));
                $rows[$table][$key] = $hash;
            }

            if (isset($rows[$table])) {
                ksort($rows[$table]);
            }
        }

        ksort($counts);
        ksort($rows);

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $selected
     * @return list<string>
     */
    private function primaryOf(string $table, array $selected): array
    {
        $primary = array_map(
            fn (object $r) => $r->c,
            $this->db->select(
                "select column_name as c from information_schema.statistics where table_schema = ? and table_name = ? and index_name = 'PRIMARY' order by seq_in_index",
                [$this->target->database(), $table]
            )
        );

        return $primary !== [] && array_diff($primary, $selected) === [] ? $primary : [];
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'sorted'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
