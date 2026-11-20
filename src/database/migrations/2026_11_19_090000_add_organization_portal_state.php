<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (ORG-1): the portal-state schema. See docs/architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md (section 2)
 * and docs/product/release-1.0-org-0-organization-consolidation.md (section 7, ORG-1).
 *
 * An Organization has one of three portal states, read from its own row and nothing else:
 *
 *   Not enabled   portal_enabled_at IS NULL                              (a Directory-only Organization; none can be created until ORG-2)
 *   Enabled       portal_enabled_at set, disabled_at NULL
 *   Disabled      portal_enabled_at set, disabled_at set
 *
 * What this migration does, in this order (every step detects its own completion, because MariaDB commits DDL implicitly and a run that
 * stopped part-way is completed by the next run, never recorded as done):
 *
 *   1. organizations.portal_enabled_at          added, nullable datetime.
 *   2. organizations.owner_id                    RENAMED to portal_enabled_by and made nullable. One column, one meaning: the operator who
 *                                                enabled portal access. It is provenance and never authority (OrganizationPolicy says so).
 *                                                The foreign key to users (RESTRICT) travels with the column and keeps its original name,
 *                                                `organizations_owner_id_foreign`, so the pinned history and the preservation tests still
 *                                                name the constraint they always did.
 *   3. Backfill: every EXISTING Organization is a portal Organization (nothing else could create one), so portal_enabled_at takes the row's
 *      created_at (its updated_at, then now, when a very old row has no created_at, because the pairing CHECK below forbids a NULL there)
 *      and portal_enabled_by keeps the prior owner_id value, which the rename carried across.
 *   4. CHECKs (named, backstops to the services): the provenance pair is set or NULL together, and `disabled_at`, `can_manage_customers`
 *      and `managed_by_organization_id` may be set only when portal_enabled_at is. They are added AFTER the backfill, so no existing row can
 *      violate them. database:verify pins foreign keys, not CHECK constraints, so organizations:preflight re-verifies them.
 *   5. organization_members.organization_id      CASCADE -> RESTRICT. Removing an Organization becomes an application path for the first
 *                                                time (ORG-2); the database must refuse a delete that still has members.
 *
 * `down()` is schema-only and REFUSES, changing nothing, while any Organization has no portal_enabled_by: the old column was NOT NULL, so
 * such a row cannot be put back, and a Directory-only Organization must be rolled back (ORG-2 first) or removed deliberately, never
 * silently given an owner. The enabling instant is not kept by the old schema and is dropped; it is recoverable only for rows backfilled
 * from created_at.
 */
return new class extends Migration
{
    private const PAIR_CHECK = 'organizations_portal_provenance_pair_check';

    private const DISABLED_CHECK = 'organizations_disabled_requires_portal_check';

    private const PARTNER_CHECK = 'organizations_partner_requires_portal_check';

    private const MANAGED_CHECK = 'organizations_managed_requires_portal_check';

    private const MEMBERS_FOREIGN_KEY = 'organization_members_organization_id_foreign';

    /** @var array<string, string> name => the condition that must hold */
    private const CHECKS = [
        self::PAIR_CHECK => '(`portal_enabled_at` is null) = (`portal_enabled_by` is null)',
        self::DISABLED_CHECK => '`disabled_at` is null or `portal_enabled_at` is not null',
        self::PARTNER_CHECK => '`can_manage_customers` = 0 or `portal_enabled_at` is not null',
        self::MANAGED_CHECK => '`managed_by_organization_id` is null or `portal_enabled_at` is not null',
    ];

    public function up(): void
    {
        $this->assertStartingShape();

        if (! Schema::hasColumn('organizations', 'portal_enabled_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dateTime('portal_enabled_at')->nullable();
            });
        }

        if (Schema::hasColumn('organizations', 'owner_id') && ! Schema::hasColumn('organizations', 'portal_enabled_by')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->renameColumn('owner_id', 'portal_enabled_by');
            });
        }

        if (! $this->portalEnabledByIsNullable()) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('portal_enabled_by')->nullable()->change();
            });
        }

        $this->assertForeignKey('organizations', 'portal_enabled_by', 'organizations_owner_id_foreign', 'users', 'RESTRICT');

        // Only a half pair is completed: a row that already has both, or neither, is left exactly as it is. In this release no row can have
        // neither, so a re-run on a later database never turns a Directory-only Organization into a portal one.
        DB::table('organizations')
            ->whereNull('portal_enabled_at')->whereNotNull('portal_enabled_by')
            ->update(['portal_enabled_at' => DB::raw('coalesce(`created_at`, `updated_at`, now())')]);

        foreach (self::CHECKS as $name => $condition) {
            if (! $this->hasCheck($name)) {
                DB::statement("alter table `organizations` add constraint `{$name}` check ({$condition})");
            }
        }

        if ($this->membersForeignKeyRule() !== 'RESTRICT') {
            if ($this->membersForeignKeyRule() !== null) {
                Schema::table('organization_members', function (Blueprint $table) {
                    $table->dropForeign(self::MEMBERS_FOREIGN_KEY);
                });
            }

            Schema::table('organization_members', function (Blueprint $table) {
                $table->foreign('organization_id', self::MEMBERS_FOREIGN_KEY)->references('id')->on('organizations')->restrictOnDelete();
            });
        }

        $this->assertForeignKey('organization_members', 'organization_id', self::MEMBERS_FOREIGN_KEY, 'organizations', 'RESTRICT');
    }

    public function down(): void
    {
        // Refuse before changing anything. The old column is NOT NULL; a row without an enabling operator cannot be represented.
        if (Schema::hasColumn('organizations', 'portal_enabled_by')) {
            $without = DB::table('organizations')->whereNull('portal_enabled_by')->count();

            if ($without > 0) {
                throw new RuntimeException("Cannot roll back the portal-state migration: {$without} Organization(s) have no portal_enabled_by (they have never had portal access), and the previous schema has no place for them. Nothing was changed. Roll back the later consolidation migration first, or remove those Organizations deliberately.");
            }
        }

        foreach (array_keys(self::CHECKS) as $name) {
            if ($this->hasCheck($name)) {
                DB::statement("alter table `organizations` drop constraint `{$name}`");
            }
        }

        if ($this->membersForeignKeyRule() !== 'CASCADE') {
            if ($this->membersForeignKeyRule() !== null) {
                Schema::table('organization_members', function (Blueprint $table) {
                    $table->dropForeign(self::MEMBERS_FOREIGN_KEY);
                });
            }

            Schema::table('organization_members', function (Blueprint $table) {
                $table->foreign('organization_id', self::MEMBERS_FOREIGN_KEY)->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('organizations', 'portal_enabled_by') && $this->portalEnabledByIsNullable()) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('portal_enabled_by')->nullable(false)->change();
            });
        }

        if (Schema::hasColumn('organizations', 'portal_enabled_by') && ! Schema::hasColumn('organizations', 'owner_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->renameColumn('portal_enabled_by', 'owner_id');
            });
        }

        if (Schema::hasColumn('organizations', 'portal_enabled_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('portal_enabled_at');
            });
        }
    }

    /** Neither half of a previous, partial run may be a different shape from the one this migration builds on. */
    private function assertStartingShape(): void
    {
        $hasOwner = Schema::hasColumn('organizations', 'owner_id');
        $hasEnabledBy = Schema::hasColumn('organizations', 'portal_enabled_by');

        if ($hasOwner && $hasEnabledBy) {
            throw new RuntimeException('organizations has both owner_id and portal_enabled_by; resolve the schema difference by hand before migrating.');
        }

        if (! $hasOwner && ! $hasEnabledBy) {
            throw new RuntimeException('organizations has neither owner_id nor portal_enabled_by; resolve the schema difference by hand before migrating.');
        }
    }

    private function portalEnabledByIsNullable(): bool
    {
        $column = collect(Schema::getColumns('organizations'))->firstWhere('name', 'portal_enabled_by');

        return $column !== null && (bool) $column['nullable'];
    }

    private function hasCheck(string $name): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = ? and constraint_name = ?',
            ['organizations', 'CHECK', $name]
        )->n > 0;
    }

    /** The delete rule of the members foreign key, or null when it does not exist (a half-finished run, or an engine without foreign keys). */
    private function membersForeignKeyRule(): ?string
    {
        $rule = DB::selectOne(
            'select delete_rule as r from information_schema.referential_constraints where constraint_schema = database() and table_name = ? and constraint_name = ?',
            ['organization_members', self::MEMBERS_FOREIGN_KEY]
        )?->r;

        return $rule === null ? null : strtoupper($rule);
    }

    /**
     * A foreign key the migration relies on must be the one it expects. Where the engine keeps no foreign keys (the MyISAM host-parity
     * smoke) there is nothing to check, exactly as the earlier migrations behave there.
     */
    private function assertForeignKey(string $table, string $column, string $name, string $parent, string $rule): void
    {
        $key = DB::selectOne(
            'select r.referenced_table_name as target, r.delete_rule as on_delete, k.column_name as col
             from information_schema.referential_constraints r
             join information_schema.key_column_usage k
               on k.constraint_schema = r.constraint_schema and k.constraint_name = r.constraint_name and k.table_name = r.table_name
             where r.constraint_schema = database() and r.table_name = ? and r.constraint_name = ?',
            [$table, $name]
        );

        if ($key === null) {
            return;
        }

        if ($key->target !== $parent || $key->col !== $column || strtoupper($key->on_delete) !== $rule) {
            throw new RuntimeException("Foreign key {$name} exists but is not {$table}.{$column} -> {$parent}.id ON DELETE {$rule}; resolve the schema difference by hand before migrating.");
        }
    }
};
