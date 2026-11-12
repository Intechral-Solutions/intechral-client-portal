<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-4): partner support access, schema only. See docs/product/release-1.0-r1-4-partner-support.md.
 *
 *   organization_members.support_granted_at   nullable datetime: when an operator designated this partner member for support.
 *   organization_members.support_granted_by   nullable reference to the operator who did, ON DELETE RESTRICT.
 *   organizations.disabled_at                 nullable datetime: the organization is disabled (administrative, reversible).
 *
 * Nothing here grants access or changes a row: every existing membership and organization keeps NULL in every new column, so nobody holds a
 * grant and no organization is disabled. Only a COMPLETE pair (both support columns set) is a grant; a half-written pair fails closed and the
 * organizations:preflight command reports it. No CHECK is added for the same reason the administrator-approval pair has none.
 *
 * Re-runnable: DDL commits implicitly, so each column and the named foreign key are detected on their own and added only when missing.
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'organization_members_support_granted_by_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('organization_members', 'support_granted_at')) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->dateTime('support_granted_at')->nullable();
            });
        }

        if (! Schema::hasColumn('organization_members', 'support_granted_by')) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->unsignedBigInteger('support_granted_by')->nullable();
            });
        }

        if (! Schema::hasColumn('organizations', 'disabled_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dateTime('disabled_at')->nullable();
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->foreign('support_granted_by', self::FOREIGN_KEY)->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('organization_members', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        foreach (['support_granted_by', 'support_granted_at'] as $column) {
            if (Schema::hasColumn('organization_members', $column)) {
                Schema::table('organization_members', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('organizations', 'disabled_at')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('disabled_at');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = ? and constraint_name = ?',
            ['organization_members', 'FOREIGN KEY', self::FOREIGN_KEY]
        )->n > 0;
    }
};
