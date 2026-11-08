<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (MO-2A1): explicit administrator APPROVAL on an organization membership.
 *
 *   organization_members.admin_approved_at   nullable datetime: when an operator approved this administrator.
 *   organization_members.admin_approved_by   nullable reference to the approving operator, ON DELETE RESTRICT.
 *
 * `role = admin` has never granted anything and still grants nothing. Approval is a SEPARATE, explicit, audited operator act,
 * recorded here, that a later package may honour: no code in this release reads it to authorize anything for a customer. Every
 * existing row keeps both columns NULL (no backfill, no inference): a historical administrator row is, by construction, not
 * approved, and stays that way until an operator approves that exact membership after the owner has reviewed it.
 *
 * Deliberately NO CHECK that approval implies `role = admin`. The previous release's code writes the role without knowing
 * these columns, so during a rollback window it could demote an approved administrator and leave the approval behind; a CHECK
 * would turn that ordinary write into an error. The application treats approval as authority only while the role is still
 * `admin` (App\Services\OrganizationAuthority), and the rehearsal snapshot and the preflight count the rows that disagree.
 *
 * Additive: the previous release ignores both columns and never writes them, so it cannot violate them. The columns are
 * appended, so adding them rewrites no existing row.
 *
 * Re-runnable. DDL commits implicitly, so a run can stop between a column and its foreign key. Every piece (each column and
 * the named foreign key) is detected on its own and added only if missing, and a piece that already exists must have the
 * shape this migration would create (column type, nullability and default; the foreign key's target and ON DELETE RESTRICT),
 * or the migration stops: a differently-shaped piece is a schema mismatch for a person to resolve. Existing data is never
 * rewritten.
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'organization_members_admin_approved_by_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('organization_members', 'admin_approved_at')) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->dateTime('admin_approved_at')->nullable();
            });
        }

        if (! Schema::hasColumn('organization_members', 'admin_approved_by')) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_approved_by')->nullable();
            });
        }

        $this->assertShape();

        if (! $this->hasForeignKey()) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->foreign('admin_approved_by', self::FOREIGN_KEY)->references('id')->on('users')->restrictOnDelete();
            });
        }

        $this->assertForeignKey();
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        foreach (['admin_approved_by', 'admin_approved_at'] as $column) {
            if (Schema::hasColumn('organization_members', $column)) {
                Schema::table('organization_members', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasForeignKey(): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = ? and constraint_name = ?',
            ['organization_members', 'FOREIGN KEY', self::FOREIGN_KEY]
        )->n > 0;
    }

    /** A foreign key that already carries the name must be the one this migration would create. */
    private function assertForeignKey(): void
    {
        $key = DB::selectOne(
            'select r.referenced_table_name as target, r.delete_rule as on_delete, k.column_name as col, k.referenced_column_name as target_col
             from information_schema.referential_constraints r
             join information_schema.key_column_usage k
               on k.constraint_schema = r.constraint_schema and k.constraint_name = r.constraint_name and k.table_name = r.table_name
             where r.constraint_schema = database() and r.table_name = ? and r.constraint_name = ?',
            ['organization_members', self::FOREIGN_KEY]
        );

        if ($key === null || $key->target !== 'users' || $key->col !== 'admin_approved_by' || $key->target_col !== 'id' || strtoupper($key->on_delete) !== 'RESTRICT') {
            throw new RuntimeException('Foreign key '.self::FOREIGN_KEY.' exists but is not admin_approved_by -> users.id ON DELETE RESTRICT; resolve the schema difference by hand before migrating.');
        }
    }

    private function assertShape(): void
    {
        $columns = collect(Schema::getColumns('organization_members'))->keyBy('name');
        $approvedAt = $columns->get('admin_approved_at');
        $approvedBy = $columns->get('admin_approved_by');

        // MariaDB reports the default of a nullable column that has none as the text "NULL", not as a null.
        $noDefault = fn (?array $column): bool => $column !== null && ($column['default'] === null || strtoupper((string) $column['default']) === 'NULL');

        if ($approvedAt === null || ! $approvedAt['nullable'] || ! $noDefault($approvedAt) || ! str_starts_with((string) $approvedAt['type'], 'datetime')) {
            throw new RuntimeException('organization_members.admin_approved_at exists but is not a nullable datetime without a default; resolve the schema difference by hand before migrating.');
        }

        if ($approvedBy === null || ! $approvedBy['nullable'] || ! $noDefault($approvedBy) || ! str_contains((string) $approvedBy['type'], 'bigint') || ! str_contains((string) $approvedBy['type'], 'unsigned')) {
            throw new RuntimeException('organization_members.admin_approved_by exists but is not a nullable unsigned bigint without a default; resolve the schema difference by hand before migrating.');
        }
    }
};
