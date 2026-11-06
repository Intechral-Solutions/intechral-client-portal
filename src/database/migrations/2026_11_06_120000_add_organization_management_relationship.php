<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (MO-1A): the managed-organization relationship, an ADMINISTRATIVE relationship between organizations.
 *
 *   organizations.managed_by_organization_id  nullable self-reference to the managing (partner) organization,
 *                                             ON DELETE RESTRICT. NULL for every existing row.
 *   organizations.can_manage_customers        boolean, default false: the organization is designated a partner.
 *
 * Nothing here grants access. The relationship is never read as membership or as a grant over tickets, projects, time or
 * invoices; this migration changes no existing row.
 *
 * One row-level CHECK constraint is a backstop for a rule the application enforces transactionally
 * (App\Services\OrganizationRelationships): a partner cannot itself be managed. Every other rule (the manager must be a
 * partner, one level only, an unambiguous company pairing) spans rows, and only the service can enforce it. CHECK is enforced
 * by MariaDB 10.2.1+ and parsed but ignored by MySQL before 8.0.16, which is acceptable for a backstop; the migration
 * rehearsal proves it survives a dump and restore.
 *
 * No CHECK stops an organization managing itself: MariaDB refuses a CHECK that reads an AUTO_INCREMENT column (error 1901,
 * verified on 11.4), and `id` is one. The service refuses self-management, the preflight and the rehearsal snapshot count it,
 * and the foreign key cannot (a row may reference itself).
 *
 * Additive: the previous release's code ignores the columns, never writes them, and so cannot violate the constraints. The
 * columns are appended, so adding them rewrites no existing row.
 *
 * Re-runnable. DDL commits implicitly, so a run can stop between a column and its foreign key or CHECK. Every piece (each
 * column, the foreign key, the CHECK) is therefore detected on its own and added only if missing, and a piece that already
 * exists must have the shape this migration would create (column type, nullability and default; the foreign key's target
 * and ON DELETE RESTRICT), or the migration stops: a differently-shaped piece is a schema mismatch for a person to resolve.
 * Existing data is never rewritten.
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'organizations_managed_by_organization_id_foreign';

    private const PARTNER_CHECK = 'organizations_partner_not_managed_check';

    public function up(): void
    {
        // Each piece is detected and added on its own, so a run that stopped part-way (DDL commits implicitly, so a column
        // can exist without its foreign key) is completed by the next run instead of being recorded as done.
        if (! Schema::hasColumn('organizations', 'managed_by_organization_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('managed_by_organization_id')->nullable();
            });
        }

        if (! Schema::hasColumn('organizations', 'can_manage_customers')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->boolean('can_manage_customers')->default(false);
            });
        }

        $this->assertShape();

        if (! $this->hasForeignKey()) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->foreign('managed_by_organization_id', self::FOREIGN_KEY)
                    ->references('id')->on('organizations')->restrictOnDelete();
            });
        }

        $this->assertForeignKey();

        if (! $this->hasCheck(self::PARTNER_CHECK)) {
            DB::statement('alter table `organizations` add constraint `'.self::PARTNER_CHECK.'` check (`can_manage_customers` = 0 or `managed_by_organization_id` is null)');
        }
    }

    public function down(): void
    {
        if ($this->hasCheck(self::PARTNER_CHECK)) {
            DB::statement('alter table `organizations` drop constraint `'.self::PARTNER_CHECK.'`');
        }

        if (Schema::hasColumn('organizations', 'managed_by_organization_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('managed_by_organization_id');
            });
        }

        if (Schema::hasColumn('organizations', 'can_manage_customers')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('can_manage_customers');
            });
        }
    }

    private function hasCheck(string $name): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = ? and constraint_name = ?',
            ['organizations', 'CHECK', $name]
        )->n > 0;
    }

    private function hasForeignKey(): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = ? and constraint_name = ?',
            ['organizations', 'FOREIGN KEY', self::FOREIGN_KEY]
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
            ['organizations', self::FOREIGN_KEY]
        );

        if ($key === null || $key->target !== 'organizations' || $key->col !== 'managed_by_organization_id' || $key->target_col !== 'id' || strtoupper($key->on_delete) !== 'RESTRICT') {
            throw new RuntimeException('Foreign key '.self::FOREIGN_KEY.' exists but is not managed_by_organization_id -> organizations.id ON DELETE RESTRICT; resolve the schema difference by hand before migrating.');
        }
    }

    private function assertShape(): void
    {
        $columns = collect(Schema::getColumns('organizations'))->keyBy('name');
        $managedBy = $columns->get('managed_by_organization_id');
        $partner = $columns->get('can_manage_customers');

        // MariaDB reports the default of a nullable column that has none as the text "NULL", not as a null.
        $hasDefault = $managedBy !== null && $managedBy['default'] !== null && strtoupper((string) $managedBy['default']) !== 'NULL';

        if ($managedBy === null || ! $managedBy['nullable'] || $hasDefault || ! str_contains((string) $managedBy['type'], 'bigint') || ! str_contains((string) $managedBy['type'], 'unsigned')) {
            throw new RuntimeException('organizations.managed_by_organization_id exists but is not a nullable unsigned bigint without a default; resolve the schema difference by hand before migrating.');
        }

        if ($partner === null || $partner['nullable'] || (string) $partner['default'] !== '0' || ! str_starts_with((string) $partner['type'], 'tinyint')) {
            throw new RuntimeException('organizations.can_manage_customers exists but is not a NOT NULL boolean defaulting to 0; resolve the schema difference by hand before migrating.');
        }
    }
};
