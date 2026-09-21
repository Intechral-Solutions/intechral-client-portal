<?php

use Illuminate\Support\Facades\DB;

/*
 * EPIC-011E D4 / C3: the restrictOnDelete migration is reversible. Kept in its own file with no
 * fixtures: DDL statements commit implicitly in MariaDB, so this test must not create rows the
 * RefreshDatabase transaction would otherwise have rolled back.
 */

const TIME_ENTRY_FKS = ['time_entries_project_id_foreign', 'time_entries_task_id_foreign'];

function timeEntryDeleteRules(): array
{
    return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'time_entries')
        ->whereIn('CONSTRAINT_NAME', TIME_ENTRY_FKS)
        ->orderBy('CONSTRAINT_NAME')
        ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
        ->all();
}

it('rolls the time entry foreign keys back to SET NULL and forward to RESTRICT again', function () {
    $migration = require database_path('migrations/2026_09_21_120000_restrict_time_entry_project_and_task_deletes.php');

    expect(timeEntryDeleteRules())->toBe([
        'time_entries_project_id_foreign' => 'RESTRICT',
        'time_entries_task_id_foreign' => 'RESTRICT',
    ]);

    try {
        $migration->down();
        expect(timeEntryDeleteRules())->toBe([
            'time_entries_project_id_foreign' => 'SET NULL',
            'time_entries_task_id_foreign' => 'SET NULL',
        ]);
    } finally {
        // Whatever happened above, leave the schema as the rest of the suite expects it.
        if (timeEntryDeleteRules() !== ['time_entries_project_id_foreign' => 'RESTRICT', 'time_entries_task_id_foreign' => 'RESTRICT']) {
            $migration->up();
        }
    }

    expect(timeEntryDeleteRules())->toBe([
        'time_entries_project_id_foreign' => 'RESTRICT',
        'time_entries_task_id_foreign' => 'RESTRICT',
    ]);
});

it('keeps the referenced columns nullable so entries without that context stay valid', function () {
    $nullable = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'time_entries')
        ->whereIn('COLUMN_NAME', ['project_id', 'task_id'])
        ->pluck('IS_NULLABLE', 'COLUMN_NAME')
        ->all();

    expect($nullable)->toBe(['project_id' => 'YES', 'task_id' => 'YES']);
});
