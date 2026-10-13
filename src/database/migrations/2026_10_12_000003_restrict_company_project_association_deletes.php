<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Release 0.9 (R0.9-1B, M3; decision H2): a CRM company that projects still reference can no longer be deleted
 * out from under them. `project_company.crm_company_id` changes from ON DELETE CASCADE to ON DELETE RESTRICT, so a
 * company delete can no longer silently erase the company-to-project associations that the future Directory
 * migration needs. Nothing else changes: not the column (NOT NULL, stays so), not ON UPDATE (RESTRICT,
 * unspecified here so the engine default is kept), not the constraint name, and not `project_company.project_id`
 * or any other company reference (`crm_contacts.crm_company_id` and `tickets.company_id` keep SET NULL).
 *
 * Class: tightening, with the same method, safety checks and rollback character as M2
 * (2026_10_12_000002_restrict_user_business_record_deletes): two single-statement ALTERs per key, each atomic, one
 * foreign key on the column throughout, foreign-key checks never disabled, and a preflight of the live constraint
 * against the approved inventory before any change. No row is read, moved or rewritten.
 *
 * Consequence for the application, deliberately left to R0.9-1D (decision H6 is open): the CRM company delete
 * route has no object-level guard today. After this migration the DATABASE refuses to delete a company that
 * projects still reference, so that route fails with a database error instead of destroying the associations. A
 * friendly refusal belongs to the H6 work and is not added here.
 */
return new class extends Migration
{
    /** The approved inventory: table, column, and the constraint name as it exists today. */
    private const KEYS = [
        ['project_company', 'crm_company_id', 'project_company_crm_company_id_foreign'],
    ];

    private const PARENT = 'crm_companies';

    private const TEMPORARY_SUFFIX = '_restrict';

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $plan = $this->plan($db, 'CASCADE', 'RESTRICT');

        foreach ($plan as [$table, $column, $name, $state]) {
            if ($state === 'interrupted') {
                $this->replace($db, $table, $column, $name.self::TEMPORARY_SUFFIX, $name, 'RESTRICT');
            } elseif ($state === 'change') {
                $this->replace($db, $table, $column, $name, $name.self::TEMPORARY_SUFFIX, 'RESTRICT');
                $this->replace($db, $table, $column, $name.self::TEMPORARY_SUFFIX, $name, 'RESTRICT');
            }
        }

        $this->verify($db, 'RESTRICT');
    }

    /** Development only (R0.9-0 section 6, item 1): the same two-step replacement, back to CASCADE. */
    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        foreach ($this->plan($db, 'RESTRICT', 'CASCADE') as [$table, $column, $name, $state]) {
            if ($state === 'interrupted') {
                $this->replace($db, $table, $column, $name.self::TEMPORARY_SUFFIX, $name, 'CASCADE');
            } elseif ($state === 'change') {
                $this->replace($db, $table, $column, $name, $name.self::TEMPORARY_SUFFIX, 'CASCADE');
                $this->replace($db, $table, $column, $name.self::TEMPORARY_SUFFIX, $name, 'CASCADE');
            }
        }

        $this->verify($db, 'CASCADE');
    }

    /**
     * Check everything before changing anything. Returns, per key, whether it needs changing, is already
     * done, or was interrupted between the two steps. Throws, with every problem listed, on any mismatch.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function plan(Connection $db, string $from, string $to): array
    {
        $problems = [];
        $plan = [];

        foreach (self::KEYS as [$table, $column, $name]) {
            $keys = $this->keysOn($db, $table, $column);
            $key = $keys[0] ?? null;

            if (count($keys) !== 1) {
                $problems[] = "{$table}.{$column} has ".count($keys).' foreign key(s), expected exactly one';

                continue;
            }

            $isFinal = $key->name === $name;
            $isTemporary = $key->name === $name.self::TEMPORARY_SUFFIX;

            if (! $isFinal && ! $isTemporary) {
                $problems[] = "{$table}.{$column} is constrained by \"{$key->name}\", not the expected \"{$name}\"";

                continue;
            }

            if ($key->parent !== self::PARENT || $key->parent_column !== 'id') {
                $problems[] = "{$table}.{$column} references {$key->parent}.{$key->parent_column}, not ".self::PARENT.'.id';

                continue;
            }

            if ($key->update_rule !== 'RESTRICT') {
                $problems[] = "{$table}.{$column} has ON UPDATE {$key->update_rule}, expected RESTRICT (this migration preserves it)";

                continue;
            }

            if ($key->nullable) {
                $problems[] = "{$table}.{$column} is nullable, expected NOT NULL";

                continue;
            }

            if ($isTemporary) {
                if ($key->delete_rule !== $to) {
                    $problems[] = "{$table}.{$column}: the temporary key \"{$key->name}\" has ON DELETE {$key->delete_rule}, expected {$to}";

                    continue;
                }
                $plan[] = [$table, $column, $name, 'interrupted'];
            } elseif ($key->delete_rule === $to) {
                $plan[] = [$table, $column, $name, 'done'];
            } elseif ($key->delete_rule === $from) {
                $plan[] = [$table, $column, $name, 'change'];
            } else {
                $problems[] = "{$table}.{$column} has ON DELETE {$key->delete_rule}, expected {$from}";

                continue;
            }

            if ($to === 'RESTRICT') {
                $orphans = (int) $db->selectOne(
                    "select count(*) as n from `{$table}` c left join `".self::PARENT."` p on p.id = c.`{$column}` where c.`{$column}` is not null and p.id is null"
                )->n;

                if ($orphans > 0) {
                    $problems[] = "{$table}.{$column} has {$orphans} row(s) pointing at a user that does not exist; the new key would be refused";
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "The live schema differs from the approved inventory for this migration. Nothing was changed.\n  - ".implode("\n  - ", $problems)
            );
        }

        return $plan;
    }

    private function replace(Connection $db, string $table, string $column, string $drop, string $add, string $rule): void
    {
        $parent = self::PARENT;
        $db->statement(
            "alter table `{$table}` drop foreign key `{$drop}`, add constraint `{$add}` foreign key (`{$column}`) references `{$parent}` (`id`) on delete {$rule}"
        );
    }

    /** After the last step: every key is back under its original name with exactly the intended rules. */
    private function verify(Connection $db, string $rule): void
    {
        $problems = [];

        foreach (self::KEYS as [$table, $column, $name]) {
            $keys = $this->keysOn($db, $table, $column);
            $key = $keys[0] ?? null;

            if (count($keys) !== 1 || $key->name !== $name || $key->delete_rule !== $rule || $key->update_rule !== 'RESTRICT' || $key->nullable) {
                $problems[] = "{$table}.{$column}: expected \"{$name}\" ON DELETE {$rule} ON UPDATE RESTRICT NOT NULL, found ".json_encode($keys);
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("Post-migration verification failed:\n  - ".implode("\n  - ", $problems));
        }
    }

    /** @return list<object{name: string, parent: string, parent_column: string, delete_rule: string, update_rule: string, nullable: bool}> */
    private function keysOn(Connection $db, string $table, string $column): array
    {
        return array_map(fn (object $row) => (object) [
            'name' => (string) $row->name,
            'parent' => (string) $row->parent,
            'parent_column' => (string) $row->parent_column,
            'delete_rule' => (string) $row->delete_rule,
            'update_rule' => (string) $row->update_rule,
            'nullable' => $row->nullable === 'YES',
        ], $db->select(
            'select k.constraint_name as name, k.referenced_table_name as parent, k.referenced_column_name as parent_column,
                    r.delete_rule, r.update_rule, c.is_nullable as nullable
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             join information_schema.columns c
               on c.table_schema = k.table_schema and c.table_name = k.table_name and c.column_name = k.column_name
             where k.table_schema = ? and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null
             order by k.constraint_name',
            [$db->getDatabaseName(), $table, $column]
        ));
    }
};
