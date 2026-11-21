<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (ORG-2): physical consolidation. One Organization table. The Directory profile moves onto `organizations`, People and project links are
 * re-pointed at it, and `crm_companies` is retired. See docs/architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md (sections 1 and 12) and
 * docs/product/release-1.0-org-2-physical-consolidation.md (the owner's rulings and the as-built record of this migration).
 *
 *   organizations           + website, phone, address, notes (the profile), classification (varchar(32) NOT NULL DEFAULT 'general', no CHECK: PHP
 *                           validation and the integrity checks own the catalogue, and it is never an authority input), created_by (NOT NULL, RESTRICT to
 *                           users). `created_by` is BACKFILLED from `portal_enabled_by` (see PROVENANCE).
 *   crm_contacts            crm_company_id -> organization_id (nullable, SET NULL to organizations, constraint crm_contacts_organization_id_foreign).
 *   project_organization    replaces project_company: project_id, organization_id, composite primary key, CASCADE to projects, RESTRICT to organizations.
 *   crm_companies           dropped.
 *
 * REFUSES BEFORE THE FIRST DDL STATEMENT. A populated `crm_companies`, an old Company affiliation, an old project link, an Organization with no enabling
 * operator (it cannot satisfy `created_by`), and any shape this migration was not written for. Counts only: no value, name or record is printed. Release 1.0 is
 * a fresh install (R1-0): nothing is merged, repaired or reinterpreted, so a refusal is an instruction to the operator, never a half-done conversion.
 *
 * PROVENANCE. Before ORG-2 an Organization could only come into being by promoting a Company, so the operator who enabled portal access is also the operator
 * who, in effect, created it: `created_by` takes `portal_enabled_by`. It is the nearest recorded actor, NOT the creator of the Directory profile
 * (`crm_companies.created_by`), which this migration requires to be empty and then drops. From ORG-2 on `created_by` is the operator who created the record and
 * `portal_enabled_by` the one who enabled portal access; the two differ for an Organization created in the Directory and enabled later. Neither is authority.
 *
 * ORDER (MariaDB commits DDL implicitly, so every stage is its own unit and a run that stopped between two is completed by the next):
 *   1. add the profile columns (one ALTER)     2. backfill created_by     3. tighten created_by (NOT NULL + foreign key, one ALTER)
 *   4a. rename crm_contacts.crm_company_id to organization_id (the old key and its index dropped in the same ALTER)     4b. add the new foreign key
 *   5a. create project_organization            5b. drop project_company   6. drop crm_companies     7. verify the whole target schema.
 * The project link is CREATED and the old table dropped, not renamed: it is required empty, so no data moves, and a rename would leave a table with the old
 * constraint names. It is created by ONE raw `CREATE TABLE` statement that carries its columns, primary key, index and both foreign keys (createTable()), so
 * the table either exists complete or does not exist. (Laravel's Schema::create() is NOT used for this: it emits the CREATE TABLE and then each key as a
 * separate ALTER, and a run that died between them would leave a table that looks present but lacks its constraints.) The rollback recreates crm_companies
 * and project_company the same way. A table that exists when a stage meets it is never trusted for existing: its WHOLE shape is checked (columns, indexes,
 * foreign keys) and a table of any other shape is refused with a message, never completed in place and never reported as restored.
 *
 * EVERY STAGE JUDGES ITSELF. Each stage asserts its own preconditions (it never trusts that an earlier stage, or the starting check, ran) and decides it is
 * done only when its WHOLE postcondition holds: the column, its type, nullability and default, the index, the foreign key by name, parent, column and
 * delete/update rule. A column's existence alone proves nothing. A populated `crm_company_id` is never renamed into `organization_id` (unrelated ids could
 * happen to match), a foreign key is never added over an orphan, and an ambiguous mixed shape is refused with a manual-resolution message.
 * The foreign-key halves of the postconditions are waived for a table whose engine is not InnoDB. That is a concession to the MyISAM host-parity smoke only
 * (MariaDB parses and silently discards a FOREIGN KEY clause on such an engine, so there is nothing to inspect). The application's tables are InnoDB by
 * configuration (config/database.php `engine`), and `php artisan database:verify` against database/profile/foreign-keys.json is what proves the keys on a real
 * database; this migration does not claim to.
 *
 * `down()` is schema-only. It REFUSES, changing nothing, when anything could not be reconstructed: a Directory-only Organization, profile data, a
 * classification other than the default, a creator who is not the enabling operator, an affiliation, a project link, or a managing relationship. It restores the
 * historical schema exactly (constraint and index names included, so the pinned historical migrations and their tests stay valid) and never claims to
 * restore data. ORG-2 is rolled back BEFORE ORG-1: ORG-1's `down()` refuses while any Directory-only Organization exists.
 *
 * Not a rehearsal candidate (database/rehearsal/candidates.php): the rehearsal baseline fixtures populate `crm_companies`, so this migration would refuse them.
 * Run it under maintenance mode, after a verified backup, on a database the preflight (`php artisan organizations:preflight`) has cleared.
 */
return new class extends Migration
{
    private const PROFILE_FK = 'organizations_created_by_foreign';

    private const CONTACTS_OLD_FK = 'crm_contacts_crm_company_id_foreign';

    private const CONTACTS_NEW_FK = 'crm_contacts_organization_id_foreign';

    private const LINK_NEW = 'project_organization';

    private const LINK_OLD = 'project_company';

    /** The profile columns this migration adds: column => [normalized type, nullable, default]. `created_by` is checked separately (nullable until stage 3). */
    private const PROFILE_COLUMNS = [
        'website' => ['varchar(255)', true, null],
        'phone' => ['varchar(30)', true, null],
        'address' => ['text', true, null],
        'notes' => ['text', true, null],
        'classification' => ['varchar(32)', false, 'general'],
    ];

    // ── up ───────────────────────────────────────────────────────────────────────────────────

    public function up(): void
    {
        $this->assertStartingShape();

        $this->stage1AddProfileColumns();
        $this->stage2BackfillCreatedBy();
        $this->stage3TightenCreatedBy();
        $this->stage4aRenameContactColumn();
        $this->stage4bAddContactForeignKey();
        $this->stage5aCreateProjectLink();
        $this->stage5bDropOldProjectLink();
        $this->stage6DropCompanies();

        $problems = $this->targetProblems();

        if ($problems !== []) {
            throw new RuntimeException("Post-migration verification failed:\n  - ".implode("\n  - ", $problems));
        }
    }

    /**
     * The starting check. READS ONLY: it runs before the first DDL statement and refuses, listing every problem as a count or a structural fact, when the
     * database is not one this migration may convert. Schema-aware: a database that a previous run left part-converted is judged against what is left to
     * do, so a successfully renamed column is never rejected merely because it already has its new name.
     */
    public function assertStartingShape(): void
    {
        $problems = [];

        foreach (['portal_enabled_at', 'portal_enabled_by'] as $column) {
            if (! Schema::hasColumn('organizations', $column)) {
                $problems[] = "organizations.{$column} is missing: the ORG-1 portal-state migration has not run on this database";
            }
        }

        if (Schema::hasColumn('organizations', 'owner_id')) {
            $problems[] = 'organizations still has owner_id: the ORG-1 portal-state migration has not run on this database';
        }

        if ($problems !== []) {
            $this->refuse($problems);
        }

        $hasCompanies = Schema::hasTable('crm_companies');
        $contactsOld = Schema::hasColumn('crm_contacts', 'crm_company_id');
        $contactsNew = Schema::hasColumn('crm_contacts', 'organization_id');

        if ($hasCompanies) {
            if (($n = $this->count('crm_companies')) > 0) {
                $problems[] = "crm_companies holds {$n} row(s). The Directory profile cannot be moved: this release is a fresh install. Remove them deliberately, or rebuild the database";
            }
        } elseif ($remaining = $this->targetProblems()) {
            $problems[] = 'crm_companies is already gone but the rest of the schema is not the target schema, an ambiguous mixed state that must be resolved by hand ('.implode('; ', $remaining).')';
        }

        if ($contactsOld && $contactsNew) {
            $problems[] = 'crm_contacts has both crm_company_id and organization_id';
        } elseif (! $contactsOld && ! $contactsNew) {
            $problems[] = 'crm_contacts has neither crm_company_id nor organization_id';
        } elseif ($contactsOld && ($n = $this->nonNull('crm_contacts', 'crm_company_id')) > 0) {
            $problems[] = "{$n} Person row(s) still carry an old Company affiliation (crm_contacts.crm_company_id), which would be meaningless against organizations";
        } elseif ($contactsNew && ! $this->hasForeignKey('crm_contacts', 'organization_id') && $this->foreignKeysSupported('crm_contacts') && ($n = $this->nonNull('crm_contacts', 'organization_id')) > 0) {
            $problems[] = "crm_contacts.organization_id holds {$n} value(s) but has no foreign key: they cannot be shown to be Organization ids";
        }

        $linkOld = Schema::hasTable(self::LINK_OLD);
        $linkNew = Schema::hasTable(self::LINK_NEW);

        if (! $linkOld && ! $linkNew) {
            $problems[] = 'neither project_company nor project_organization exists';
        }

        if ($linkOld && ($n = $this->count(self::LINK_OLD)) > 0) {
            $problems[] = "project_company holds {$n} project link(s) to a Company, which would be meaningless against organizations";
        }

        if ($linkOld && $linkNew && ($n = $this->count(self::LINK_NEW)) > 0) {
            $problems[] = "both project_company and project_organization exist and the new one already holds {$n} row(s)";
        }

        if (($n = $this->organizationsWithoutCreator()) > 0) {
            $problems[] = "{$n} existing Organization(s) have no enabling operator (portal_enabled_by) and no created_by: the required created_by cannot be backfilled";
        }

        if ($problems !== []) {
            $this->refuse($problems);
        }
    }

    // ── stage 1: the profile columns ─────────────────────────────────────────────────────────

    public function stage1AddProfileColumns(): void
    {
        $add = [];

        foreach (self::PROFILE_COLUMNS as $column => [$type, $nullable, $default]) {
            if (! Schema::hasColumn('organizations', $column)) {
                $add[] = match ($column) {
                    'website' => 'add `website` varchar(255) null',
                    'phone' => 'add `phone` varchar(30) null',
                    'address' => 'add `address` text null',
                    'notes' => 'add `notes` text null',
                    'classification' => "add `classification` varchar(32) not null default 'general'",
                };

                continue;
            }

            $this->assertColumn('organizations', $column, $type, $nullable, $default);
        }

        if (! Schema::hasColumn('organizations', 'created_by')) {
            // Nullable here and tightened in stage 3, after the backfill: the column cannot be added NOT NULL to a populated table.
            $add[] = 'add `created_by` bigint unsigned null';
        } else {
            $this->assertColumn('organizations', 'created_by', 'bigint unsigned', null, null);
        }

        if ($add !== []) {
            DB::statement('alter table `organizations` '.implode(', ', $add));
        }
    }

    // ── stage 2: backfill created_by ─────────────────────────────────────────────────────────

    public function stage2BackfillCreatedBy(): void
    {
        $this->assertColumn('organizations', 'created_by', 'bigint unsigned', null, null);

        if (($n = $this->organizationsWithoutCreator()) > 0) {
            throw new RuntimeException("Cannot backfill organizations.created_by: {$n} Organization(s) have neither created_by nor an enabling operator. Nothing further was changed.");
        }

        // Fills ONLY an empty created_by, from the recorded enabling operator. A value already there is never overwritten (a re-run after a partial run, or a
        // database that has already been converted and then used).
        DB::statement('update `organizations` set `created_by` = `portal_enabled_by` where `created_by` is null and `portal_enabled_by` is not null');
    }

    // ── stage 3: created_by NOT NULL + foreign key ───────────────────────────────────────────

    public function stage3TightenCreatedBy(): void
    {
        $this->assertColumn('organizations', 'created_by', 'bigint unsigned', null, null);

        if (($n = $this->nullCount('organizations', 'created_by')) > 0) {
            throw new RuntimeException("organizations.created_by still has {$n} empty value(s) after the backfill; it cannot be made NOT NULL. Nothing further was changed.");
        }

        if (($n = $this->orphans('organizations', 'created_by', 'users')) > 0) {
            throw new RuntimeException("{$n} Organization(s) have a created_by that is not a user; the foreign key would be refused. Nothing further was changed.");
        }

        $clauses = [];

        if ($this->isNullable('organizations', 'created_by')) {
            $clauses[] = 'modify `created_by` bigint unsigned not null';
        }

        $keys = $this->foreignKeysOn('organizations', 'created_by');

        if ($keys === [] && $this->foreignKeysSupported('organizations')) {
            $clauses[] = 'add constraint `'.self::PROFILE_FK.'` foreign key (`created_by`) references `users` (`id`) on delete restrict on update restrict';
        } elseif ($keys !== []) {
            $this->assertForeignKey($keys, self::PROFILE_FK, 'organizations', 'created_by', 'users', 'RESTRICT', 'RESTRICT');
        }

        if ($clauses !== []) {
            DB::statement('alter table `organizations` '.implode(', ', $clauses));
        }
    }

    // ── stage 4: People ──────────────────────────────────────────────────────────────────────

    public function stage4aRenameContactColumn(): void
    {
        $old = Schema::hasColumn('crm_contacts', 'crm_company_id');
        $new = Schema::hasColumn('crm_contacts', 'organization_id');

        if ($old && $new) {
            $this->refuse(['crm_contacts has both crm_company_id and organization_id']);
        }

        if (! $old && ! $new) {
            $this->refuse(['crm_contacts has neither crm_company_id nor organization_id']);
        }

        if ($new) {
            $this->assertColumn('crm_contacts', 'organization_id', 'bigint unsigned', true, null);

            return;
        }

        // The column is renamed ONLY while it is empty: a Company id and an Organization id are unrelated numbers, and a populated rename would silently
        // attach every Person to whichever Organization happens to share the number.
        if (($n = $this->nonNull('crm_contacts', 'crm_company_id')) > 0) {
            $this->refuse(["{$n} Person row(s) still carry an old Company affiliation (crm_contacts.crm_company_id); it is never renamed into organization_id"]);
        }

        $this->assertColumn('crm_contacts', 'crm_company_id', 'bigint unsigned', true, null);

        $clauses = [];
        $keys = $this->foreignKeysOn('crm_contacts', 'crm_company_id');

        if ($keys !== []) {
            $this->assertForeignKey($keys, self::CONTACTS_OLD_FK, 'crm_contacts', 'crm_company_id', 'crm_companies', 'SET NULL', 'RESTRICT');
            $clauses[] = 'drop foreign key `'.self::CONTACTS_OLD_FK.'`';
        }

        // The index the old key made for itself goes with it, so the new key makes one under its own name. Any OTHER index on the column is not ours to drop.
        $indexes = $this->indexNamesStartingWith('crm_contacts', 'crm_company_id');

        foreach ($indexes as $index) {
            if ($index !== self::CONTACTS_OLD_FK) {
                $this->refuse(["crm_contacts has an unexpected index \"{$index}\" on crm_company_id"]);
            }
        }

        if ($indexes !== []) {
            $clauses[] = 'drop index `'.self::CONTACTS_OLD_FK.'`';
        }

        $clauses[] = 'change `crm_company_id` `organization_id` bigint unsigned null';

        DB::statement('alter table `crm_contacts` '.implode(', ', $clauses));
    }

    public function stage4bAddContactForeignKey(): void
    {
        $this->assertColumn('crm_contacts', 'organization_id', 'bigint unsigned', true, null);

        $keys = $this->foreignKeysOn('crm_contacts', 'organization_id');

        if ($keys !== []) {
            $this->assertForeignKey($keys, self::CONTACTS_NEW_FK, 'crm_contacts', 'organization_id', 'organizations', 'SET NULL', 'RESTRICT');

            return;
        }

        if (! $this->foreignKeysSupported('crm_contacts')) {
            return;
        }

        // With no foreign key there is nothing that proves a value here is an Organization id: the column was only ever renamed from an EMPTY one.
        if (($n = $this->nonNull('crm_contacts', 'organization_id')) > 0) {
            $this->refuse(["crm_contacts.organization_id holds {$n} value(s) but has no foreign key: they cannot be shown to be Organization ids"]);
        }

        if (($n = $this->orphans('crm_contacts', 'organization_id', 'organizations')) > 0) {
            $this->refuse(["{$n} Person row(s) point at an Organization that does not exist"]);
        }

        DB::statement('alter table `crm_contacts` add constraint `'.self::CONTACTS_NEW_FK.'` foreign key (`organization_id`) references `organizations` (`id`) on delete set null on update restrict');
    }

    // ── stage 5: the project link ────────────────────────────────────────────────────────────

    public function stage5aCreateProjectLink(): void
    {
        $linkNew = Schema::hasTable(self::LINK_NEW);

        if ($linkNew) {
            $problems = $this->projectLinkProblems();

            if ($problems !== []) {
                $this->refuse(['project_organization exists but is not the expected shape ('.implode('; ', $problems).')']);
            }

            return;
        }

        if (! Schema::hasTable(self::LINK_OLD)) {
            $this->refuse(['neither project_company nor project_organization exists']);
        }

        if (($n = $this->count(self::LINK_OLD)) > 0) {
            $this->refuse(["project_company holds {$n} project link(s) to a Company, which would be meaningless against organizations"]);
        }

        $this->createTable(self::LINK_NEW, '
            `project_id` bigint unsigned not null,
            `organization_id` bigint unsigned not null,
            `created_at` timestamp null,
            `updated_at` timestamp null,
            primary key (`project_id`, `organization_id`),
            key `project_organization_organization_id_foreign` (`organization_id`),
            constraint `project_organization_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade on update restrict,
            constraint `project_organization_organization_id_foreign` foreign key (`organization_id`) references `organizations` (`id`) on delete restrict on update restrict
        ');
    }

    public function stage5bDropOldProjectLink(): void
    {
        if (! Schema::hasTable(self::LINK_OLD)) {
            return;
        }

        // Dropped only when the new table is complete and nothing needs the old one.
        if (($problems = $this->projectLinkProblems()) !== []) {
            $this->refuse(['project_organization is not complete, so project_company is not dropped ('.implode('; ', $problems).')']);
        }

        if (($n = $this->count(self::LINK_OLD)) > 0) {
            $this->refuse(["project_company holds {$n} project link(s) to a Company"]);
        }

        if (($n = $this->count(self::LINK_NEW)) > 0) {
            $this->refuse(["both project_company and project_organization exist and the new one already holds {$n} row(s)"]);
        }

        if ($referencing = $this->tablesReferencing(self::LINK_OLD)) {
            $this->refuse(['a foreign key from '.implode(', ', $referencing).' still references project_company']);
        }

        Schema::drop(self::LINK_OLD);
    }

    // ── stage 6: crm_companies ───────────────────────────────────────────────────────────────

    public function stage6DropCompanies(): void
    {
        if (! Schema::hasTable('crm_companies')) {
            return;
        }

        if (($n = $this->count('crm_companies')) > 0) {
            $this->refuse(["crm_companies holds {$n} row(s)"]);
        }

        if ($referencing = $this->tablesReferencing('crm_companies')) {
            $this->refuse(['a foreign key from '.implode(', ', $referencing).' still references crm_companies, so it is not dropped']);
        }

        Schema::drop('crm_companies');
    }

    // ── down ─────────────────────────────────────────────────────────────────────────────────

    public function down(): void
    {
        $this->assertCanRollBack();

        $this->downCompanies();
        $this->downContacts();
        $this->downProjectLink();
        $this->downOrganizations();

        $problems = $this->historicalProblems();

        if ($problems !== []) {
            throw new RuntimeException("Rollback verification failed:\n  - ".implode("\n  - ", $problems));
        }
    }

    /** Refuses, changing nothing, when any data could not be put back. Counts only. */
    public function assertCanRollBack(): void
    {
        $problems = [];
        $has = fn (string $column) => Schema::hasColumn('organizations', $column);

        if (Schema::hasTable(self::LINK_NEW) && ($n = $this->count(self::LINK_NEW)) > 0) {
            $problems[] = "{$n} project link(s) to an Organization exist and cannot be mapped back to a Company";
        }

        if ($has('portal_enabled_at') && ($n = $this->countWhere('organizations', '`portal_enabled_at` is null')) > 0) {
            $problems[] = "{$n} Directory-only Organization(s) exist (never had portal access); the previous schema has no place for a profile without a Company";
        }

        if ($has('website') && ($n = $this->countWhere('organizations', '`website` is not null or `phone` is not null or `address` is not null or `notes` is not null')) > 0) {
            $problems[] = "{$n} Organization(s) carry profile data (website, phone, address or notes) that cannot be restored into a Company";
        }

        if ($has('classification') && ($n = $this->countWhere('organizations', "`classification` <> 'general'")) > 0) {
            $problems[] = "{$n} Organization(s) have a classification other than the default, which the previous schema cannot hold";
        }

        if ($has('created_by') && $has('portal_enabled_by') && ($n = $this->countWhere('organizations', '`created_by` is null or `portal_enabled_by` is null or `created_by` <> `portal_enabled_by`')) > 0) {
            $problems[] = "{$n} Organization(s) have a creator who is not their enabling operator, which the previous schema cannot hold";
        }

        if ($has('can_manage_customers') && ($n = $this->countWhere('organizations', '`can_manage_customers` = 1 or `managed_by_organization_id` is not null')) > 0) {
            $problems[] = "{$n} Organization(s) are in a partner or managed relationship; the previous schema required a Company for each, which cannot be reconstructed";
        }

        if (Schema::hasColumn('crm_contacts', 'organization_id') && ($n = $this->nonNull('crm_contacts', 'organization_id')) > 0) {
            $problems[] = "{$n} Person row(s) are affiliated with an Organization and cannot be mapped back to a Company";
        }

        if (Schema::hasTable('crm_companies') && ($n = $this->count('crm_companies')) > 0) {
            $problems[] = "crm_companies already exists and holds {$n} row(s)";
        }

        if ($problems !== []) {
            throw new RuntimeException("Cannot roll back the Organization consolidation: data would be lost or misrepresented. Nothing was changed.\n  - ".implode("\n  - ", $problems));
        }
    }

    public function downCompanies(): void
    {
        if (Schema::hasTable('crm_companies')) {
            // Present is not restored: a table an interrupted or foreign run left (or one made by hand) is judged by its whole shape.
            $this->requireHistoricalShape('crm_companies', $this->companiesProblems());

            return;
        }

        // The historical table exactly, in ONE statement: columns in order, the unique key MO-1A added (it serves the foreign key), the name index, RESTRICT to
        // users and SET NULL to organizations, every constraint under the name it had.
        $this->createTable('crm_companies', '
            `id` bigint unsigned not null auto_increment,
            `name` varchar(255) not null,
            `website` varchar(255) null,
            `phone` varchar(30) null,
            `address` text null,
            `notes` text null,
            `organization_id` bigint unsigned null,
            `created_by` bigint unsigned not null,
            `created_at` timestamp null,
            `updated_at` timestamp null,
            primary key (`id`),
            unique key `crm_companies_organization_id_unique` (`organization_id`),
            key `crm_companies_created_by_foreign` (`created_by`),
            key `crm_companies_name_index` (`name`),
            constraint `crm_companies_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete restrict on update restrict,
            constraint `crm_companies_organization_id_foreign` foreign key (`organization_id`) references `organizations` (`id`) on delete set null on update restrict
        ');
    }

    public function downContacts(): void
    {
        $old = Schema::hasColumn('crm_contacts', 'crm_company_id');
        $new = Schema::hasColumn('crm_contacts', 'organization_id');

        if ($old === $new) {
            $this->refuse([$old ? 'crm_contacts has both crm_company_id and organization_id' : 'crm_contacts has neither crm_company_id nor organization_id']);
        }

        if ($new) {
            $clauses = [];

            if ($this->foreignKeysOn('crm_contacts', 'organization_id') !== []) {
                $clauses[] = 'drop foreign key `'.self::CONTACTS_NEW_FK.'`';
            }

            if ($this->indexNamesStartingWith('crm_contacts', 'organization_id') !== []) {
                $clauses[] = 'drop index `'.self::CONTACTS_NEW_FK.'`';
            }

            $clauses[] = 'change `organization_id` `crm_company_id` bigint unsigned null';
            DB::statement('alter table `crm_contacts` '.implode(', ', $clauses));
        }

        // Reached with the column ALREADY renamed back (this run's ALTER above, or an earlier run that died before the next statement): the rename is not the
        // end of the stage. The column must be empty (an Organization id is not a Company id, and nothing may be reattached by number), and the foreign key
        // must be there, under its name, with its rules.
        if (($n = $this->nonNull('crm_contacts', 'crm_company_id')) > 0) {
            $this->refuse(["{$n} Person row(s) carry a value in crm_contacts.crm_company_id; an Organization id is not a Company id, so the foreign key is not restored over it"]);
        }

        $this->assertColumn('crm_contacts', 'crm_company_id', 'bigint unsigned', true, null);

        $keys = $this->foreignKeysOn('crm_contacts', 'crm_company_id');

        if ($keys !== []) {
            $this->assertForeignKey($keys, self::CONTACTS_OLD_FK, 'crm_contacts', 'crm_company_id', 'crm_companies', 'SET NULL', 'RESTRICT');

            return;
        }

        if ($this->foreignKeysSupported('crm_contacts')) {
            DB::statement('alter table `crm_contacts` add constraint `'.self::CONTACTS_OLD_FK.'` foreign key (`crm_company_id`) references `crm_companies` (`id`) on delete set null on update restrict');
        }
    }

    public function downProjectLink(): void
    {
        if (Schema::hasTable(self::LINK_OLD)) {
            $this->requireHistoricalShape(self::LINK_OLD, $this->projectCompanyProblems());
        } else {
            $this->createTable(self::LINK_OLD, '
                `project_id` bigint unsigned not null,
                `crm_company_id` bigint unsigned not null,
                `created_at` timestamp null,
                `updated_at` timestamp null,
                primary key (`project_id`, `crm_company_id`),
                key `project_company_crm_company_id_foreign` (`crm_company_id`),
                constraint `project_company_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade on update restrict,
                constraint `project_company_crm_company_id_foreign` foreign key (`crm_company_id`) references `crm_companies` (`id`) on delete restrict on update restrict
            ');
        }

        if (Schema::hasTable(self::LINK_NEW)) {
            Schema::drop(self::LINK_NEW);
        }
    }

    public function downOrganizations(): void
    {
        $drop = [];

        if ($this->foreignKeysOn('organizations', 'created_by') !== []) {
            $drop[] = 'drop foreign key `'.self::PROFILE_FK.'`';
        }

        // The index the key made for itself; a column cannot be dropped while an index of that name is still needed by nothing else, but it is removed explicitly
        // so the historical schema carries no stray index.
        if (in_array(self::PROFILE_FK, $this->indexNamesStartingWith('organizations', 'created_by'), true)) {
            $drop[] = 'drop index `'.self::PROFILE_FK.'`';
        }

        foreach (['created_by', 'classification', 'notes', 'address', 'phone', 'website'] as $column) {
            if (Schema::hasColumn('organizations', $column)) {
                $drop[] = "drop column `{$column}`";
            }
        }

        if ($drop !== []) {
            DB::statement('alter table `organizations` '.implode(', ', $drop));
        }
    }

    // ── verification ─────────────────────────────────────────────────────────────────────────

    /**
     * Everything the target schema promises, as a list of failures (empty when right). Used at the end of `up()`, and by the starting check to judge a
     * database whose `crm_companies` is already gone.
     *
     * @return list<string>
     */
    public function targetProblems(): array
    {
        $problems = [];

        foreach (self::PROFILE_COLUMNS as $column => [$type, $nullable, $default]) {
            $problems = [...$problems, ...$this->columnProblems('organizations', $column, $type, $nullable, $default)];
        }

        $problems = [...$problems, ...$this->columnProblems('organizations', 'created_by', 'bigint unsigned', false, null)];

        if ($this->foreignKeysSupported('organizations')) {
            $problems = [...$problems, ...$this->keyProblems('organizations', 'created_by', self::PROFILE_FK, 'users', 'RESTRICT', 'RESTRICT')];
        }

        if (Schema::hasColumn('crm_contacts', 'crm_company_id')) {
            $problems[] = 'crm_contacts.crm_company_id still exists';
        }

        $problems = [...$problems, ...$this->columnProblems('crm_contacts', 'organization_id', 'bigint unsigned', true, null)];

        if ($this->foreignKeysSupported('crm_contacts')) {
            $problems = [...$problems, ...$this->keyProblems('crm_contacts', 'organization_id', self::CONTACTS_NEW_FK, 'organizations', 'SET NULL', 'RESTRICT')];
        }

        if (! in_array(self::CONTACTS_NEW_FK, $this->indexNamesStartingWith('crm_contacts', 'organization_id'), true)) {
            $problems[] = 'crm_contacts has no index '.self::CONTACTS_NEW_FK.' on organization_id';
        }

        $problems = [...$problems, ...$this->projectLinkProblems()];

        foreach (['crm_companies', self::LINK_OLD] as $retired) {
            if (Schema::hasTable($retired)) {
                $problems[] = "{$retired} still exists";
            }
        }

        foreach (['crm_companies', self::LINK_OLD] as $retired) {
            if ($referencing = $this->tablesReferencing($retired)) {
                $problems[] = 'a foreign key from '.implode(', ', $referencing)." still references {$retired}";
            }
        }

        return $problems;
    }

    /** The historical schema, after `down()`: every restored table checked WHOLE, not merely present. @return list<string> */
    private function historicalProblems(): array
    {
        $problems = [];

        foreach (['website', 'phone', 'address', 'notes', 'classification', 'created_by'] as $column) {
            if (Schema::hasColumn('organizations', $column)) {
                $problems[] = "organizations.{$column} still exists";
            }
        }

        if (Schema::hasColumn('crm_contacts', 'organization_id') || ! Schema::hasColumn('crm_contacts', 'crm_company_id')) {
            $problems[] = 'crm_contacts is not back to crm_company_id';
        } else {
            $problems = [...$problems, ...$this->columnProblems('crm_contacts', 'crm_company_id', 'bigint unsigned', true, null)];

            if (! in_array(self::CONTACTS_OLD_FK, $this->indexNamesStartingWith('crm_contacts', 'crm_company_id'), true)) {
                $problems[] = 'crm_contacts has no index '.self::CONTACTS_OLD_FK.' on crm_company_id';
            }

            if ($this->foreignKeysSupported('crm_contacts')) {
                $problems = [...$problems, ...$this->keyProblems('crm_contacts', 'crm_company_id', self::CONTACTS_OLD_FK, 'crm_companies', 'SET NULL', 'RESTRICT')];
            }
        }

        foreach (['crm_companies' => $this->companiesProblems(), self::LINK_OLD => $this->projectCompanyProblems()] as $table => $tableProblems) {
            $problems = [...$problems, ...$tableProblems];
        }

        if (Schema::hasTable(self::LINK_NEW)) {
            $problems[] = 'project_organization still exists';
        }

        return $problems;
    }

    /** The historical crm_companies, whole. @return list<string> */
    private function companiesProblems(): array
    {
        return $this->tableProblems(
            'crm_companies',
            [
                'id' => ['bigint unsigned', false], 'name' => ['varchar(255)', false], 'website' => ['varchar(255)', true], 'phone' => ['varchar(30)', true],
                'address' => ['text', true], 'notes' => ['text', true], 'organization_id' => ['bigint unsigned', true], 'created_by' => ['bigint unsigned', false],
                'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            ],
            [
                'PRIMARY' => [true, ['id']], 'crm_companies_organization_id_unique' => [true, ['organization_id']],
                'crm_companies_created_by_foreign' => [false, ['created_by']], 'crm_companies_name_index' => [false, ['name']],
            ],
            [
                ['created_by', 'crm_companies_created_by_foreign', 'users', 'RESTRICT', 'RESTRICT'],
                ['organization_id', 'crm_companies_organization_id_foreign', 'organizations', 'SET NULL', 'RESTRICT'],
            ]
        );
    }

    /** The historical project_company, whole. @return list<string> */
    private function projectCompanyProblems(): array
    {
        return $this->tableProblems(
            self::LINK_OLD,
            ['project_id' => ['bigint unsigned', false], 'crm_company_id' => ['bigint unsigned', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
            ['PRIMARY' => [true, ['project_id', 'crm_company_id']], 'project_company_crm_company_id_foreign' => [false, ['crm_company_id']]],
            [
                ['project_id', 'project_company_project_id_foreign', 'projects', 'CASCADE', 'RESTRICT'],
                ['crm_company_id', 'project_company_crm_company_id_foreign', 'crm_companies', 'RESTRICT', 'RESTRICT'],
            ]
        );
    }

    /**
     * One table, whole: its columns in order with their types, nullability and defaults (none is expected to have a default), its indexes by name, and its foreign
     * keys by name, parent and rules. A missing table is a problem. Foreign keys are checked only on an engine that holds them.
     *
     * @param  array<string, array{0: string, 1: bool}>  $columns  name => [type, nullable]
     * @param  array<string, array{0: bool, 1: list<string>}>  $indexes  name => [unique, columns]
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $keys  [column, name, parent, delete rule, update rule]
     * @return list<string>
     */
    private function tableProblems(string $table, array $columns, array $indexes, array $keys): array
    {
        if (! Schema::hasTable($table)) {
            return ["{$table} is missing"];
        }

        $problems = [];
        $actual = array_column(Schema::getColumns($table), 'name');

        if ($actual !== array_keys($columns)) {
            $problems[] = "{$table} has the columns [".implode(', ', $actual).'], expected ['.implode(', ', array_keys($columns)).']';
        }

        foreach ($columns as $column => [$type, $nullable]) {
            if (in_array($column, $actual, true)) {
                $problems = [...$problems, ...$this->columnProblems($table, $column, $type, $nullable, null)];
            }
        }

        $found = $this->indexesOf($table);

        foreach ($indexes as $name => [$unique, $cols]) {
            if (! isset($found[$name])) {
                $problems[] = "{$table} has no index {$name}";
            } elseif ($found[$name] !== [$unique, $cols]) {
                $problems[] = "{$table} index {$name} is ".($found[$name][0] ? 'unique' : 'not unique').' on ('.implode(', ', $found[$name][1]).'), expected '.($unique ? 'unique' : 'not unique').' on ('.implode(', ', $cols).')';
            }
        }

        foreach (array_diff(array_keys($found), array_keys($indexes)) as $extra) {
            $problems[] = "{$table} has an unexpected index {$extra}";
        }

        if ($this->foreignKeysSupported($table)) {
            foreach ($keys as [$column, $name, $parent, $delete, $update]) {
                $problems = [...$problems, ...$this->keyProblems($table, $column, $name, $parent, $delete, $update)];
            }
        }

        return $problems;
    }

    /** All indexes of a table: name => [unique, columns in order]. @return array<string, array{0: bool, 1: list<string>}> */
    private function indexesOf(string $table): array
    {
        $indexes = [];

        foreach (DB::select('select index_name as i, non_unique as u, column_name as c from information_schema.statistics where table_schema = database() and table_name = ? order by index_name, seq_in_index', [$table]) as $row) {
            $indexes[(string) $row->i] ??= [(int) $row->u === 0, []];
            $indexes[(string) $row->i][1][] = (string) $row->c;
        }

        return $indexes;
    }

    /** @param  list<string>  $problems */
    private function requireHistoricalShape(string $table, array $problems): void
    {
        if ($problems !== []) {
            throw new RuntimeException("Cannot roll back the Organization consolidation: {$table} already exists but is not the historical table, so it is not trusted as restored (".implode('; ', $problems).'). Nothing further was changed. It holds no rows the rollback needs; drop it by hand and run the rollback again.');
        }
    }

    /**
     * One CREATE TABLE statement, so the table is whole or absent. Engine, character set and collation come from the connection's own configuration, as
     * Laravel's Schema::create() takes them.
     */
    private function createTable(string $table, string $definition): void
    {
        $config = DB::connection()->getConfig();
        $sql = "create table `{$table}` ({$definition})";

        foreach ([' engine = ' => $config['engine'] ?? null, ' default character set ' => $config['charset'] ?? null, ' collate ' => $config['collation'] ?? null] as $clause => $value) {
            if ($value !== null && $value !== '') {
                if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $value)) {
                    throw new RuntimeException("The database configuration has an unusable value for {$clause}.");
                }

                $sql .= $clause.$value;
            }
        }

        DB::statement($sql);
    }

    /** The target project_organization, whole. @return list<string> */
    private function projectLinkProblems(): array
    {
        return $this->tableProblems(
            self::LINK_NEW,
            ['project_id' => ['bigint unsigned', false], 'organization_id' => ['bigint unsigned', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]],
            ['PRIMARY' => [true, ['project_id', 'organization_id']], 'project_organization_organization_id_foreign' => [false, ['organization_id']]],
            [
                ['project_id', 'project_organization_project_id_foreign', 'projects', 'CASCADE', 'RESTRICT'],
                ['organization_id', 'project_organization_organization_id_foreign', 'organizations', 'RESTRICT', 'RESTRICT'],
            ]
        );
    }

    // ── inspection helpers ───────────────────────────────────────────────────────────────────

    /** @param  list<string>  $problems */
    private function refuse(array $problems): never
    {
        throw new RuntimeException(
            'Cannot consolidate Organizations: the database is not in a shape this migration may convert. Nothing was changed by this check; resolve each item by hand, then run it again. '
            ."(Counts only: no record is shown. `php artisan organizations:preflight` reports the data.)\n  - ".implode("\n  - ", $problems)
        );
    }

    private function count(string $table): int
    {
        return (int) DB::table($table)->count();
    }

    private function countWhere(string $table, string $condition): int
    {
        return (int) DB::selectOne("select count(*) as n from `{$table}` where {$condition}")->n;
    }

    private function nullCount(string $table, string $column): int
    {
        return $this->countWhere($table, "`{$column}` is null");
    }

    private function nonNull(string $table, string $column): int
    {
        return $this->countWhere($table, "`{$column}` is not null");
    }

    private function orphans(string $table, string $column, string $parent): int
    {
        return (int) DB::selectOne("select count(*) as n from `{$table}` c left join `{$parent}` p on p.`id` = c.`{$column}` where c.`{$column}` is not null and p.`id` is null")->n;
    }

    /** Organizations whose created_by is empty AND that have no enabling operator to take it from. */
    private function organizationsWithoutCreator(): int
    {
        if (! Schema::hasColumn('organizations', 'portal_enabled_by')) {
            return 0;
        }

        $creatorEmpty = Schema::hasColumn('organizations', 'created_by') ? '`created_by` is null and ' : '';

        return $this->countWhere('organizations', "{$creatorEmpty}`portal_enabled_by` is null");
    }

    /** Whether the table's engine can hold a foreign key at all. The application's tables are InnoDB by configuration; the host-parity smoke's server default is not. */
    private function foreignKeysSupported(string $table): bool
    {
        $engine = DB::selectOne('select engine as e from information_schema.tables where table_schema = database() and table_name = ?', [$table])?->e;

        return $engine !== null && strcasecmp((string) $engine, 'InnoDB') === 0;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return $this->foreignKeysOn($table, $column) !== [];
    }

    /** Type normalized without display width: `bigint(20) unsigned` and `bigint unsigned` are the same. */
    private function normalizedType(string $type): string
    {
        return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower(trim($type)));
    }

    /** @return object{type: string, nullable: bool, default: ?string}|null */
    private function column(string $table, string $column): ?object
    {
        $row = DB::selectOne(
            'select column_type as t, is_nullable as n, column_default as d from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column]
        );

        if ($row === null) {
            return null;
        }

        // MariaDB reports a string default quoted ('general') and NULL as the text NULL; MySQL reports neither quote. Both normalize to the bare value or null.
        $default = $row->d === null || strtoupper((string) $row->d) === 'NULL' ? null : trim((string) $row->d, "'");

        return (object) ['type' => $this->normalizedType($row->t), 'nullable' => $row->n === 'YES', 'default' => $default];
    }

    private function isNullable(string $table, string $column): bool
    {
        return (bool) $this->column($table, $column)?->nullable;
    }

    /**
     * Throws when the column exists with a different definition. $nullable null means either is acceptable (created_by is nullable until stage 3).
     */
    private function assertColumn(string $table, string $column, string $type, ?bool $nullable, ?string $default): void
    {
        $problems = $this->columnProblems($table, $column, $type, $nullable, $default);

        if ($problems !== []) {
            $this->refuse($problems);
        }
    }

    /** @return list<string> */
    private function columnProblems(string $table, string $column, string $type, ?bool $nullable, ?string $default): array
    {
        $actual = $this->column($table, $column);

        if ($actual === null) {
            return ["{$table}.{$column} is missing"];
        }

        $problems = [];

        if ($actual->type !== $type) {
            $problems[] = "{$table}.{$column} is {$actual->type}, expected {$type}";
        }

        if ($nullable !== null && $actual->nullable !== $nullable) {
            $problems[] = "{$table}.{$column} is ".($actual->nullable ? 'nullable' : 'NOT NULL').', expected '.($nullable ? 'nullable' : 'NOT NULL');
        }

        if ($nullable !== null && $actual->default !== $default) {
            $problems[] = "{$table}.{$column} has default ".($actual->default ?? 'NULL').', expected '.($default ?? 'NULL');
        }

        return $problems;
    }

    /** @return list<object{name: string, parent: string, parent_column: string, delete_rule: string, update_rule: string}> */
    private function foreignKeysOn(string $table, string $column): array
    {
        return array_map(fn (object $row) => (object) [
            'name' => (string) $row->name,
            'parent' => (string) $row->parent,
            'parent_column' => (string) $row->parent_column,
            'delete_rule' => strtoupper((string) $row->delete_rule),
            'update_rule' => strtoupper((string) $row->update_rule),
        ], DB::select(
            'select k.constraint_name as name, k.referenced_table_name as parent, k.referenced_column_name as parent_column, r.delete_rule, r.update_rule
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r
               on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = database() and k.table_name = ? and k.column_name = ? and k.referenced_table_name is not null
             order by k.constraint_name',
            [$table, $column]
        ));
    }

    /**
     * Throws when the foreign keys on the column are not exactly the one expected, with its name, parent and rules.
     *
     * @param  list<object>  $keys
     */
    private function assertForeignKey(array $keys, string $name, string $table, string $column, string $parent, string $delete, string $update): void
    {
        $problems = $this->keyProblemsFrom($keys, $table, $column, $name, $parent, $delete, $update);

        if ($problems !== []) {
            $this->refuse($problems);
        }
    }

    /** @return list<string> */
    private function keyProblems(string $table, string $column, string $name, string $parent, string $delete, string $update): array
    {
        return $this->keyProblemsFrom($this->foreignKeysOn($table, $column), $table, $column, $name, $parent, $delete, $update);
    }

    /**
     * @param  list<object>  $keys
     * @return list<string>
     */
    private function keyProblemsFrom(array $keys, string $table, string $column, string $name, string $parent, string $delete, string $update): array
    {
        if ($keys === []) {
            return ["{$table}.{$column} has no foreign key (expected {$name})"];
        }

        $key = $keys[0];

        if (count($keys) !== 1 || $key->name !== $name || $key->parent !== $parent || $key->parent_column !== 'id' || $key->delete_rule !== $delete || $key->update_rule !== $update) {
            return ["{$table}.{$column} is constrained by ".implode(', ', array_map(fn ($k) => "{$k->name} -> {$k->parent}.{$k->parent_column} ON DELETE {$k->delete_rule} ON UPDATE {$k->update_rule}", $keys))
                ."; expected {$name} -> {$parent}.id ON DELETE {$delete} ON UPDATE {$update}"];
        }

        return [];
    }

    /** Names of the indexes whose FIRST column is $column. @return list<string> */
    private function indexNamesStartingWith(string $table, string $column): array
    {
        return array_map(
            fn (object $row) => (string) $row->i,
            DB::select(
                'select distinct index_name as i from information_schema.statistics where table_schema = database() and table_name = ? and column_name = ? and seq_in_index = 1 and index_name <> ?',
                [$table, $column, 'PRIMARY']
            )
        );
    }

    /** Tables (other than the referenced one) holding a foreign key to $parent. @return list<string> */
    private function tablesReferencing(string $parent): array
    {
        return array_map(
            fn (object $row) => (string) $row->t,
            DB::select(
                'select distinct table_name as t from information_schema.referential_constraints where constraint_schema = database() and referenced_table_name = ? and table_name <> ? order by table_name',
                [$parent, $parent]
            )
        );
    }
};
