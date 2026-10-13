<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Release 0.9 (R0.9-1B, M1; decision H3, schema portion only): `users.disabled_at`, a nullable timestamp that
 * will record a reversible account suspension. This migration adds the column and nothing else: no backfill (every
 * existing user stays enabled, with NULL), no index, no default, and no enforcement. Login, session, assignment
 * and notification behaviour for disabled users, the operator action, and the last-active-operator guard belong
 * to R0.9-1C.
 *
 * Additive: the previous release's code ignores the column. Appended as the last column so it is a metadata-only
 * change on MariaDB and no existing row is rewritten. Rehearsed on populated data (docs/testing/migration-rehearsal.md).
 * If the column already exists it must be the nullable timestamp this migration would create, or the migration
 * stops: a differently-shaped column is a schema mismatch for a person to resolve, not something to overwrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'disabled_at')) {
            $this->assertShape();

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable();
        });

        $this->assertShape();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('disabled_at');
            });
        }
    }

    private function assertShape(): void
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'disabled_at');

        // MariaDB reports the default of a nullable column that has none as the text "NULL", not as a null.
        $hasDefault = $column !== null && $column['default'] !== null && strtoupper((string) $column['default']) !== 'NULL';

        if ($column === null || ! $column['nullable'] || ! str_starts_with((string) $column['type_name'], 'timestamp') || $hasDefault) {
            throw new RuntimeException('users.disabled_at exists but is not a nullable timestamp without a default; resolve the schema difference by hand before migrating.');
        }
    }
};
