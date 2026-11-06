<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (MO-1A): one organization, one CRM company. `Organization::company()` has always been a hasOne, but nothing
 * enforced it: `crm_companies.organization_id` had no UNIQUE key. This migration adds one (NULLs stay allowed, so every
 * unpromoted company is untouched), so the company/organization pair the managed-organization relationship depends on is real.
 *
 * A TIGHTENING migration (docs/product/release-0.9-scope-and-architecture.md §6.3), and the only way it can fail is data that
 * already violates it. It therefore REFUSES to run, changing nothing, when two companies point at one organization: it
 * names them and tells the operator to run `php artisan organizations:preflight`. It never merges, repairs or deletes
 * a record; resolving a duplicate is an operator decision.
 *
 * Adding the key makes InnoDB drop the plain index the foreign key was using, so the one key now serves both (on a table
 * restored from a dump the foreign key's index is an explicit key and stays; down() handles both). The previous
 * release's code cannot violate the key: its only writer of the column is the promotion of a company, which
 * always creates a new organization for it. Kept separate from the relationship migration so a legacy duplicate holds back
 * this one alone.
 */
return new class extends Migration
{
    private const INDEX = 'crm_companies_organization_id_unique';

    public function up(): void
    {
        if ($this->hasUniqueKey()) {
            return;
        }

        $duplicates = DB::table('crm_companies')
            ->select('organization_id', DB::raw('count(*) as companies'), DB::raw('group_concat(id order by id) as company_ids'))
            ->whereNotNull('organization_id')
            ->groupBy('organization_id')
            ->having('companies', '>', 1)
            ->orderBy('organization_id')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->map(fn ($row) => "organization {$row->organization_id}: companies {$row->company_ids}")->implode('; ');

            throw new RuntimeException(
                "Refusing to make crm_companies.organization_id unique: {$duplicates->count()} organization(s) are linked to more than one company ({$list}). "
                .'Nothing was changed. Run `php artisan organizations:preflight`, decide for each which company keeps the organization, and unlink the rest by hand, then migrate again.'
            );
        }

        Schema::table('crm_companies', function (Blueprint $table) {
            $table->unique('organization_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->hasUniqueKey()) {
            return;
        }

        if ($this->hasPlainIndex()) {
            // A table restored from a dump carries the foreign key's index as an explicit key, which the unique key never
            // replaced: the foreign key already has its index, so the unique key can simply go.
            DB::statement('alter table `crm_companies` drop index `'.self::INDEX.'`');

            return;
        }

        // On a table this migration built, the unique key has REPLACED the plain index the foreign key was using (InnoDB drops
        // the redundant one), so the key cannot simply be dropped: one statement gives the foreign key its plain index back
        // as the unique one goes.
        DB::statement('alter table `crm_companies` add index `crm_companies_organization_id_foreign` (`organization_id`), drop index `'.self::INDEX.'`');
    }

    /** A non-unique index that starts with organization_id: what the foreign key needs once the unique key is gone. */
    private function hasPlainIndex(): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.statistics where table_schema = database() and table_name = ? and column_name = ? and seq_in_index = 1 and non_unique = 1',
            ['crm_companies', 'organization_id']
        )->n > 0;
    }

    private function hasUniqueKey(): bool
    {
        return DB::selectOne(
            'select count(*) as n from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? and non_unique = 0',
            ['crm_companies', self::INDEX]
        )->n > 0;
    }
};
