<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-2A): a Ticket carries the customer organization it belongs to and the authenticated actor who submitted it.
 *
 *   tickets.organization_id   NOT NULL, FK organizations.id, ON DELETE/UPDATE RESTRICT
 *   tickets.submitted_by_id   NOT NULL, FK users.id,         ON DELETE/UPDATE RESTRICT
 *   tickets.company_id        dropped (it was never written by any code path; docs/product/release-1.0-r1-2-ticket-attribution.md, E3)
 *
 * `tickets.user_id` stays the requester. Nothing here infers ownership: no ticket that exists today has a provable organization
 * (`company_id` was never populated and a user's CURRENT memberships say nothing about the past), so there is nothing to backfill from
 * and nothing may be invented. This migration therefore REFUSES a populated `tickets` table, as the very first thing it does, before any
 * column, key, index or drop exists to undo, and it changes no record. The refusal lists counts and ticket references only (no names,
 * addresses or message text). A database that holds tickets is rebuilt, or has them removed deliberately, before it migrates (owner
 * ruling E1, 2026-11-10: there is no authoritative customer data; disposable environments are rebuilt, not converted).
 *
 * Order of operations on a table that passed the check: ONE statement adds both columns and both foreign keys (so a ticket that raced in
 * after the check makes it fail whole, leaving the table exactly as it was, never a half-built shape), then `company_id` and its
 * foreign key go in a second statement.
 *
 * Resuming. A run that stopped between the two statements is finished by running it again, EVEN IF tickets now exist: from the moment
 * the attribution columns exist the application writes attributed tickets (that is what they are for), so a populated table is then
 * normal, and the legacy refusal applies only BEFORE the first statement. The columns' presence alone proves nothing, though: before the
 * migration continues, or declares itself already done, each attribution column must be a NOT NULL unsigned bigint with exactly one
 * single-column RESTRICT/RESTRICT foreign key to its parent (organizations.id, users.id). Anything else, and a `company_id` that still
 * carries a value, stops it with nothing changed, rather than building on a shape nobody checked.
 */
return new class extends Migration
{
    private const ORGANIZATION_FK = 'tickets_organization_id_foreign';

    private const SUBMITTER_FK = 'tickets_submitted_by_id_foreign';

    /** How many ticket references the refusal names. */
    private const LISTED = 10;

    public function up(): void
    {
        $hasOrganization = Schema::hasColumn('tickets', 'organization_id');
        $hasSubmitter = Schema::hasColumn('tickets', 'submitted_by_id');
        $hasCompany = Schema::hasColumn('tickets', 'company_id');

        if ($hasOrganization !== $hasSubmitter) {
            // Only a hand-edited table can be here: both columns are added by one statement.
            throw new RuntimeException('Refusing to continue: tickets has exactly one of organization_id and submitted_by_id. Nothing was changed. Restore the table to its previous shape or add the missing column by hand.');
        }

        if ($hasOrganization) {
            // The attribution is in place, either finished or interrupted before `company_id` went. Prove the shape; never assume it.
            $this->assertAttributionShape();

            if ($hasCompany) {
                $this->dropCompany();
            }

            return;
        }

        // FIRST: nothing below has run, so a refusal leaves schema and data exactly as they were.
        $this->refuseWhenPopulated($hasCompany);

        DB::statement(
            'alter table `tickets`'
            .' add column `organization_id` bigint unsigned not null after `user_id`,'
            .' add column `submitted_by_id` bigint unsigned not null after `organization_id`,'
            .' add constraint `'.self::ORGANIZATION_FK.'` foreign key (`organization_id`) references `organizations` (`id`) on delete restrict on update restrict,'
            .' add constraint `'.self::SUBMITTER_FK.'` foreign key (`submitted_by_id`) references `users` (`id`) on delete restrict on update restrict'
        );

        if ($hasCompany) {
            $this->dropCompany();
        }
    }

    /** Structure only: it cannot restore attribution that the rows would lose, so it is for a development rollback of an empty table. */
    public function down(): void
    {
        if (! Schema::hasColumn('tickets', 'company_id')) {
            DB::statement(
                'alter table `tickets` add column `company_id` bigint unsigned null after `user_id`,'
                .' add index `tickets_company_id_index` (`company_id`),'
                .' add constraint `tickets_company_id_foreign` foreign key (`company_id`) references `crm_companies` (`id`) on delete set null on update restrict'
            );
        }

        foreach (['organization_id', 'submitted_by_id'] as $column) {
            if (Schema::hasColumn('tickets', $column)) {
                foreach ($this->foreignKeyNames($column) as $key) {
                    DB::statement("alter table `tickets` drop foreign key `{$key}`");
                }

                DB::statement("alter table `tickets` drop column `{$column}`");
            }
        }
    }

    private function refuseWhenPopulated(bool $hasCompany): void
    {
        $total = (int) DB::table('tickets')->count();

        if ($total === 0) {
            return;
        }

        $byStatus = DB::table('tickets')->select('status', DB::raw('count(*) as n'))->groupBy('status')->orderBy('status')->get()
            ->map(fn ($row) => "{$row->status} {$row->n}")->implode(', ');

        $requesters = DB::table('tickets')->distinct()->count('user_id');

        // How many distinct requesters have zero / one / several CURRENT memberships. Information only: it is never used to attribute a ticket.
        $memberships = DB::table('tickets')->distinct()->pluck('user_id')->map(
            fn ($userId) => DB::table('organization_members')->where('user_id', $userId)->count()
        );
        $zero = $memberships->filter(fn ($n) => $n === 0)->count();
        $one = $memberships->filter(fn ($n) => $n === 1)->count();
        $several = $memberships->filter(fn ($n) => $n > 1)->count();

        $legacyCompany = $hasCompany ? (int) DB::table('tickets')->whereNotNull('company_id')->count() : 0;

        $references = DB::table('tickets')->orderBy('id')->limit(self::LISTED)->pluck('ticket_number')->implode(', ');
        $more = $total > self::LISTED ? ' and '.($total - self::LISTED).' more' : '';

        throw new RuntimeException(
            "Refusing to add ticket organization attribution: the tickets table holds {$total} ticket(s) ({$byStatus}) from {$requesters} requester(s), "
            ."of whom {$zero} have no organization membership now, {$one} have one and {$several} have several (information only: nothing is inferred from it). "
            ."{$legacyCompany} carry a legacy company link. First references: {$references}{$more}. "
            .'Nothing was changed: no column, key or record. No ticket here has a provable organization and none will be invented. '
            .'Rebuild this database (for a development or staging copy: `migrate:fresh`), or remove the tickets deliberately, then migrate again. '
            .'Run `php artisan organizations:preflight` for the membership picture.'
        );
    }

    private function dropCompany(): void
    {
        // Nothing has ever written this column. A value here means someone did by hand: dropping it would destroy data nobody asked to lose.
        $linked = (int) DB::table('tickets')->whereNotNull('company_id')->count();

        if ($linked > 0) {
            throw new RuntimeException("Refusing to drop tickets.company_id: {$linked} ticket(s) carry a company link. Nothing was changed. Resolve them by hand (the link is not used by anything), then migrate again.");
        }

        $drops = array_map(fn (string $key) => "drop foreign key `{$key}`", $this->foreignKeyNames('company_id'));

        if ($drops !== []) {
            // The foreign key goes first and on its own: MariaDB refuses to drop a column that a constraint still names.
            DB::statement('alter table `tickets` '.implode(', ', $drops));
        }

        // The column's own index goes with it.
        DB::statement('alter table `tickets` drop column `company_id`');
    }

    /**
     * Both attribution columns exactly as this migration builds them, or a RuntimeException that changes nothing. Presence of a column
     * proves nothing about its type, nullability or key, and a `company_id` drop must never build on a shape nobody checked.
     */
    private function assertAttributionShape(): void
    {
        $problems = [];

        foreach (['organization_id' => ['organizations', 'id'], 'submitted_by_id' => ['users', 'id']] as $column => [$parent, $parentColumn]) {
            $definition = DB::selectOne(
                'select column_type as type, is_nullable as nullable, extra, column_default as default_value from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                ['tickets', $column]
            );
            $type = strtolower((string) ($definition->type ?? ''));

            if (! str_starts_with($type, 'bigint') || ! str_contains($type, 'unsigned')) {
                $problems[] = "{$column} is `{$type}`, not bigint unsigned";
            }

            if (($definition->nullable ?? 'YES') !== 'NO') {
                $problems[] = "{$column} is nullable";
            }

            if (trim((string) ($definition->extra ?? '')) !== '' || ($definition->default_value ?? null) !== null) {
                $problems[] = "{$column} has a default or generated value";
            }

            $keys = DB::select(
                'select k.constraint_name as name, k.referenced_table_name as parent, k.referenced_column_name as parent_column, r.delete_rule as on_delete, r.update_rule as on_update,
                        (select count(*) from information_schema.key_column_usage c where c.constraint_schema = k.constraint_schema and c.table_name = k.table_name and c.constraint_name = k.constraint_name) as width
                 from information_schema.key_column_usage k
                 join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.table_name = k.table_name and r.constraint_name = k.constraint_name
                 where k.table_schema = database() and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null',
                ['tickets', $column]
            );

            if (count($keys) !== 1) {
                $problems[] = "{$column} has ".count($keys).' foreign key(s), expected exactly one';

                continue;
            }

            $key = $keys[0];

            if ((int) $key->width !== 1 || $key->parent !== $parent || $key->parent_column !== $parentColumn || $key->on_delete !== 'RESTRICT' || $key->on_update !== 'RESTRICT') {
                $problems[] = "{$column}'s foreign key `{$key->name}` is not a single-column {$parent}.{$parentColumn} RESTRICT/RESTRICT key (it is {$key->width} column(s) to {$key->parent}.{$key->parent_column}, ON DELETE {$key->on_delete}, ON UPDATE {$key->on_update})";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('Refusing to continue: the tickets attribution columns are not in the shape this migration builds ('.implode('; ', $problems).'). Nothing was changed. Restore the shape by hand or rebuild the database, then migrate again.');
        }
    }

    /** @return list<string> the names of the foreign keys that name this column, wherever they came from (a restored dump keeps its own names) */
    private function foreignKeyNames(string $column): array
    {
        return array_values(array_unique(array_map(fn ($row) => $row->name, DB::select(
            'select constraint_name as name from information_schema.key_column_usage where table_schema = database() and table_name = ? and column_name = ? and referenced_table_name is not null',
            ['tickets', $column]
        ))));
    }
};
