# Release 1.0: ORG-2 physical consolidation (implementation record, CP0 to CP8)

**Status (2026-11-21): MERGED. CP0 to CP8 were committed as one commit (`c0f6abb`) and squash-merged as PR #75 into `main` as `11a6a73`; hosted CI on the PR head was green (Pest 6498 passed, 0 skipped; Redis and database-store permission-cache gates; Playwright; the customer-pilot job), and post-merge CI on `main` `11a6a73` (run 44897642399) is green on its re-run (attempt 2): attempt 1 failed one step, the database-store permission-cache gate (DB-F2, `1205 Lock wait timeout` in `PermissionCacheDatabaseStoreTest`, a known flake family that ORG-2 does not touch), and nothing else. ORG-2 is NOT deployed and no populated database has been migrated (Q6 still applies).** The text below is the record as written before the merge: where it says UNCOMMITTED or that CI had not run, that was true when written and is superseded by this line. Reviews completed, each ACCEPT WITH CONDITIONS: CP1 (section 12), CP2 to CP5, CP6 (section 15) and the final independent pre-commit review (conditions addressed by CP8). Written against `main` `0f69fd9` (PR #74, ORG-1 merged). Branch `feature/org-2-cp0-cp1-physical-consolidation` (it holds CP0 to CP8), worktree `../intechral-client-portal-org2`. No existing development database, Release 0.9 staging, the main checkout or another worktree was touched. **The full-suite and frontend results of section 16 are for the code fingerprint recorded in 16.9, NOT for the CP8 state; section 17 records what was run against CP8.**

**What this record is.** The governing decisions are [ADR-011](../architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md) and the [ORG-0 record](./release-1.0-org-0-organization-consolidation.md), amended by the owner's rulings of 2026-11-20 (ORG-0 [section 12](./release-1.0-org-0-organization-consolidation.md#12-org-2-owner-rulings-and-amendments-2026-11-20), ADR-011 Amendment 1). This record holds what CP0 and CP1 found and built: the verified baseline, the inventory of everything later checkpoints must change, the migration and its safeguards, the historical-schema test infrastructure, the evidence, and what the reviewer is asked to decide. Where it says **as built** it describes code; everything else is plan.

## 1. Checkpoints

| Checkpoint | Scope | State |
|---|---|---|
| **CP0** | Characterization of the code ORG-2 rewrites, development isolation, the inventory and the migration plan. No production behaviour changed. | Done |
| **CP1** | The forward migration, the foreign-key manifest, migration tests, the historical-test infrastructure, and the integrity-snapshot and preflight support the verification needs. | Done; reviewed ACCEPT WITH CONDITIONS; B-1 to B-4 closed (section 12) |
| **CP2** | Canonical models, Classification, relationships and scopes. | Done (section 13) |
| **CP3** | `OrganizationDirectory`, the `manageDirectory` ability, Person and Project transaction safety. | Done (section 13) |
| **CP4** | Controllers, routes (transitional names kept), queries, presenters and views. | Done (section 13) |
| **CP5** | Security, classification, leakage and regression tests; the five CP0 `todo` controls made real. | Done (section 13) |
| **CP6** | Review findings F-1 to F-8; the real two-connection MariaDB matrix, in both orders, with mutation probes. | Done (section 15); reviewed ACCEPT WITH CONDITIONS (closed by CP7, section 16) |
| **CP8** | The final review's conditions: the suspended-actor check in the Project writers, owner ruling 11 (Disabled Organization Project links), documentation findings F1 to F4. | Done (section 17); awaits independent differential review |
| **CP7** | CP6 review findings G-1 to G-9, documentation (including `AGENTS.md`), the full uncontended verification, and the handback for the independent pre-commit security review. | Done (section 16); awaits that review. Not a security acceptance: that is the reviewer's and the owner's |

**(CP1-time note, now superseded by CP2 to CP5:) the branch was deliberately not mergeable at CP1.** The migration is in `database/migrations`, so every test database (`migrate:fresh`) and every fresh install gets the canonical schema, while the application code (models, services, controllers, views, a dozen test files) still names `crm_companies` and `crm_company_id` until CP2 to CP5. A sample of three untouched Feature files (`CrmTest`, `TenantScopingTest`, `ProjectCreateAtomicityTest`) gives 26 failures out of 39 tests, all of the class "Table `crm_companies` doesn't exist" or "Unknown column `crm_company_id`". The full application suite was **not** run (not authorized for CP1, and its result would only be that class of failure). CP1 is reviewed for the migration and the data-integrity machinery, not for a green suite.

## 2. Development isolation (hard prerequisite)

The development database was reported to hold three `crm_companies` rows, one affiliated Person, nine Organizations and an unapplied ORG-1 migration, and was **not authorized** to be cleared, rebuilt or migrated. It was not touched.

- A separate worktree from the verified `origin/main` (`0f69fd9`) on its own branch. The main checkout stayed on its own branch and clean.
- A private Docker network `org2_net` (subnet `172.26.0.0/16`; the development stack is `intechral-portal_portal`, `172.25.0.0/16`) holding exactly two containers: `org2_db` (`mariadb:11.4.13`, data on a tmpfs, **no published port**, its own root password, the repository's `my.cnf` and the two init scripts, so the testing and rehearsal databases exist as they do on CI) and `org2_app` (the project image, `src` of the worktree mounted, `vendor` mounted read-only from the main checkout). `org2_app` can resolve `db` only to `org2_db`; it has no route to `portal_db`.
- Pest's own fence (`tests/bootstrap.php`, `TestDatabaseSafety`) pins `intechral_client_portal_testing` on host `db`, and the rehearsal harness pins `intechral_client_portal_rehearsal` on host `db` (`TargetGuard`). Every command in this record ran through one wrapper that executes inside `org2_app` with `DB_HOST=db`; the first failing line of a run names the database.
- No local E2E run (`./dev test:e2e` runs on the development database); browser verification is a later package gate on hosted CI or an isolated stack. No existing container, volume, branch or worktree was removed.

The reported dev data means the migration's refusal **will fire** on that database, as designed (Q6): it needs the owner's explicit authorization to clear or rebuild before ORG-2 is ever run on it.

## 3. CP0: what the baseline does (verified)

Characterized in `tests/Historical/Org2BaselineCharacterizationTest.php` on the pre-ORG-2 schema (16 assertions that pass today, 5 `todo` entries naming the ORG-2 target so the two are never confused). Findings:

| ID | Verified baseline | ORG-2 target (checkpoint) |
|---|---|---|
| **A4** | `CrmService::updateCompany` falls back to the stored value on a blank or null optional field (`??`): `website`, `phone`, `address` and `notes` cannot be cleared, by HTTP or by service. A new value does replace an old one. | A blank clears; an omitted field is left alone (CP2) |
| **A5** | A scoped project manager (non-operator) who saves the linked-organization form detaches a link to an Organization they cannot see: `sync()` removes every pivot row absent from the submitted ids, and the submitted ids can only name what the tenant scope shows them. An operator replaces the whole set. A hidden id is refused on submission (`AccessibleCrmCompany`), so the link can vanish only by omission. | A scoped sync never detaches a link the actor cannot see; every association write takes the Organization locks in ascending id order (CP2, CP6) |
| Company deletion | A Company with no project deletes and its Person keeps their row with no Company (SET NULL). A promoted Company with no relationship and no project deletes, **leaving the portal Organization with no Directory profile**: the gap ORG-2 closes. (The refusals, for a project link and for a relationship, are pinned by `CompanyDeletionSafetyTest` and `PreservationDeletionTest`.) | Only a never-enabled Organization is deleted, by the service under the row lock; a project link refuses (CP2) |
| Legacy routes | `crm.companies.show` binds `{company}` to `CrmCompany`; `organizations.show` binds `{organization}`. An operator opens a Company by id, an unknown id is 404, and a **customer is answered 404, not 403** (S2). A member's model query sees only their own Organization's Company. | Keep `{company}` in ORG-2 (a controller parameter whose name differs from the route segment makes Laravel inject an empty model silently); ORG-3 retires the routes |
| Enablement | Promoting twice is an **idempotent no-op** returning the same Organization with one audit entry, by service and by route. The Organization is created Enabled, the Company name is copied once, the operator becomes an `admin` member. | A second enablement is a controlled `already_enabled` refusal with no side effect (CP2) |
| People and projects | A Person has at most one Company and is visible to a member only through that Company's Organization (`OrganizationThroughCompanyScope`); an operator sees all. A Project links Companies through `project_company` (timestamps), and a linked Company cannot be deleted from under it. `ProjectService::create` links companies in the creation transaction and takes no lock. | D7: People visible only to authorized operators; the scope is retired (CP2). Every association write, creation included, takes shared Organization locks in ascending id order (CP2, CP6) |

## 4. CP0: inventory of everything that must change in later checkpoints

Paths under `src/`. "Done" means CP1 already changed it.

**Eloquent and queries**

| File | Reference | Checkpoint |
|---|---|---|
| `app/Models/CrmCompany.php`, `database/factories/CrmCompanyFactory.php` | the retired model and factory | CP2 (remove) |
| `app/Models/Organization.php` | `company()` hasOne; needs the profile, `created_by`, `classification` attributes, `$hidden`, a `contacts()` and an explicit-pivot `projects()` | CP2 |
| `app/Models/CrmContact.php` | `crm_company_id` fillable, `company()`, `OrganizationThroughCompanyScope` | CP2 |
| `app/Models/Project.php` | `companies()` through `project_company` | CP2 (explicit `project_organization`) |
| `app/Models/User.php` | `orgCompanyIds()` (no caller; guarded dormant by `OrganizationReadersInventoryTest`) | CP2 |
| `app/Shared/Scopes/OrganizationThroughCompanyScope.php`, `OrganizationScope.php` | the through-company scope (replaced by an operator-only scope); `OrganizationScope` loses its only consumer | CP2 |
| `app/Queries/TaskQuery.php` | `CrmCompany::whereHas('projects', ...)` (the project filter) | CP3 |

**Services, controllers, presenters, rules**

| File | Reference | Checkpoint |
|---|---|---|
| `app/Services/CrmService.php` | the company methods (create, update with A4, delete with its two-table choreography, `promoteToOrganization`); contact methods write `crm_company_id` | CP2 |
| `app/Services/OrganizationRelationships.php` | `crm_companies` at the pair count, the candidate lists and the exists-subquery; the one-company rule (`assertSingleCompany`, `COMPANY_PAIRING`) | CP3 |
| `app/Services/ProjectService.php` | `create()` links `companies` with no lock | CP2 (locks) |
| `app/Services/OrganizationPreflight.php`, `app/Console/Commands/OrganizationPreflightCommand.php` | Company sections | **Done** (schema-aware, canonical checks added) |
| `app/Http/Controllers/Crm/CompanyController.php`, `ContactController.php` | `CrmCompany`, `crm_company_id`, `AccessibleCrmCompany` | CP4 |
| `app/Http/Controllers/ProjectController.php` | `companies.*` validation and `syncCompanies` | CP2, CP4 |
| `app/Http/Controllers/DashboardController.php` | `CrmCompany::count()` | CP4 |
| `app/Http/Controllers/Organization/OrganizationController.php` | eager-loads `company` | CP4 |
| `app/Http/Presenters/ProjectPresenter.php` | company options, linked ids through `crm_companies.id` | CP4 |
| `app/Rules/AccessibleCrmCompany.php`, `app/Exceptions/CompanyHasProjectsException.php`, `OrganizationRelationshipException` (`companyInRelationship`, pairing, enablement wording) | retired or re-pointed | CP2 to CP3 |
| `app/Shared/Navigation/NavigationBuilder.php` | comment and patterns that restate the pair model | CP4 (ORG-3 moves the items) |
| `app/Support/Rehearsal/IntegritySnapshot.php` | the Company relationship check | **Done** |
| `app/Support/Rehearsal/BaselineFixtures.php` | populates `crm_companies`, `crm_contacts`, `project_company` **on purpose** (the pinned 0.9 baseline) | unchanged, never |

**Views, routes, frontend**

| Area | Reference | Checkpoint |
|---|---|---|
| `resources/views/crm/companies/*`, `crm/contacts/*` (`_form`: `crm_company_id`), `organizations/show.blade.php` (`$organization->company`), `organizations/_relationship.blade.php` | Company-shaped pages and the field name | CP4 |
| `routes/web.php` | `crm.companies.*` (keep `{company}`), `projects.companies.sync` | CP4 (ORG-3 retires) |
| `resources/js/pages/projects/create.tsx`, `edit.tsx`, `components/projects/company-selector.tsx` (+ test) | the `companies` prop and the `companies[]` field | CP4 (keep the wire contract or rename deliberately) |

**Tests** (current-schema; each converted at equal strictness in CP2 to CP5, or relocated to `tests/Historical` where its subject is the historical schema)

`Feature/Crm`: `CrmTest`, `CompanyDeletionSafetyTest`, `DirectoryVocabularyTest`, `OrganizationDisableLifecycleTest`, `OrganizationPortalStateFenceTest`, `OrganizationRelationshipsTest`, `OrganizationRelationshipsConcurrencyTest`, `OrganizationVisibilityTest`, `TenantScopingTest`. `Feature/PartnerSupport`: `PartnerSupportConcurrencyTest`, `PartnerSupportTestHelpers`. `Feature/Pilot`: `AuthorizationBaselineHardeningTest`, `CustomerPilotProjectDisclosureTest`. `Feature/Projects`: `ProjectCreateAtomicityTest`, `ProjectDashboardCountTest`, `ProjectInertiaPagesTest`, `ProjectQueryBudgetTest`, `ProjectVisibilityTest`. `Feature/Tasks`: `TaskListPageTest`, `TaskQueryTest`. `Feature/Tickets`: `RequesterTicketPropsTest`, `TicketVisibilityCharacterizationTest`. `Feature/Ui`: `TargetAccessibleNamesTest`, `TargetFormAccessibilityTest`. Support: `tests/Support/portal_state_race_support.php` (committed race fixtures delete `crm_companies`). Browser: `tests/Browser/tasks-migration.spec.ts`. Docs: `AGENTS.md` (the two-record terminology paragraph), `docs/architecture/database-schema.md`, `rbac-design.md`, `role-governance.md`, `docs/epics/EPIC-005/008/010B/011E/013/014/015/017`, `docs/product/information-architecture.md`, `product-roadmap.md`, `release-0.9-scope-and-architecture.md`, `release-1.0-notes.md`, `release-1.0-managed-organizations-mo-1a.md`, `release-1.0-r1-2-ticket-attribution.md` (they describe the as-built pair until ORG-2 merges; CP7).

## 5. CP1: file-level plan and as-built

| File | Change |
|---|---|
| `database/migrations/2026_11_20_090000_consolidate_organizations_and_retire_crm_companies.php` | **new**: the forward migration (section 6) |
| `database/profile/foreign-keys.json` | regenerated: the four Company keys removed; `organizations.created_by`, `crm_contacts.organization_id`, `project_organization.*` added |
| `app/Support/Organizations/Classification.php` | **new**: the one catalogue (`general`, `client`, `vendor`; default `general`); data only, no behaviour |
| `app/Support/Rehearsal/IntegritySnapshot.php` | schema-conditional checks (section 8) |
| `app/Services/OrganizationPreflight.php`, `app/Console/Commands/OrganizationPreflightCommand.php` | schema-aware; canonical checks and a classification line |
| `database/factories/OrganizationFactory.php`, `CrmContactFactory.php` | serve both schemas (`created_by`; `organization_id` or `crm_company_id`), decided per factory instance from the live columns |
| `tests/Support/HistoricalSchema.php`, `tests/HistoricalTestCase.php`, `tests/Pest.php`, `phpunit.xml` | the historical-schema infrastructure and its `Historical` suite (section 7) |
| `tests/Historical/*` | new: infrastructure, ORG-2 migration, snapshot and preflight on both schemas, CP0 characterization. Moved (a setup line added, assertions unchanged): `PreservationMigrationsTest`, `TicketAttributionMigrationTest`, `ManagedOrganizationMigrationTest`, `OrganizationPortalStateMigrationTest`, `OrganizationAdminApprovalMigrationTest`, `OrganizationPreflightTest` |
| `tests/Feature/Database/PreservationSchemaTest.php`, `PreservationDeletionTest.php`, `tests/Support/PreservationKeys.php`, `CommittedUsers.php` | current-schema contracts updated at equal strictness (section 9) |
| `tests/Unit/Architecture/OrganizationReadersInventoryTest.php` | one exemption, with its reason, for the new read-only `OrganizationPreflight::canonical()` |
| `docs/*` | ORG-0 section 12 and callouts, ADR-011 Amendment 1, `migration-rehearsal.md`, this record |

Not changed: any existing migration (pinned or not), `database/rehearsal/baseline.php` and `candidates.php`, the rehearsal `BaselineFixtures`, `docker-compose.yml`, the provisioning SQL, `AGENTS.md`, and every model, service, controller, route, view and frontend file.

## 6. The migration

Target schema: `organizations` gains `website varchar(255) NULL`, `phone varchar(30) NULL`, `address text NULL`, `notes text NULL`, `classification varchar(32) NOT NULL DEFAULT 'general'` (no CHECK) and `created_by` (NOT NULL, `organizations_created_by_foreign` to `users`, RESTRICT/RESTRICT). `crm_contacts.crm_company_id` becomes `organization_id` (nullable, `crm_contacts_organization_id_foreign` to `organizations`, SET NULL/RESTRICT). `project_organization` (`project_id`, `organization_id`, timestamps, composite primary key, CASCADE to `projects`, RESTRICT to `organizations`) replaces `project_company`. `crm_companies` is dropped. Organization ids, `portal_enabled_at`, `portal_enabled_by`, `disabled_at` and the ORG-1 CHECKs are untouched.

**Provenance.** Before ORG-2 an Organization could only exist by promoting a Company, so the enabling operator is the nearest recorded creator: `created_by` takes `portal_enabled_by`. It is **not** the creator of the Directory profile (`crm_companies.created_by`), which the migration requires to be empty and then drops. From ORG-2 on the two differ for an Organization created in the Directory and enabled later. Neither is authority.

**Stages** (MariaDB commits DDL implicitly, so each is its own unit):

| # | Stage | Postcondition that makes it done |
|---|---|---|
| 0 | Starting check (reads only) | n/a: refuses or proceeds |
| 1 | Add the profile columns, `classification` and a **nullable** `created_by` (one ALTER) | every column present with its exact type, nullability and default |
| 2 | Backfill `created_by` from `portal_enabled_by`, **only where empty** | no empty `created_by` |
| 3 | `created_by` NOT NULL and its foreign key (one ALTER) | NOT NULL, no orphan, the key by name, parent, column and both rules |
| 4a | Drop the old Person key and its index and rename the column to `organization_id` (one ALTER), **only while the column is entirely NULL** | column renamed, nullable, no old column |
| 4b | Add the new Person foreign key | the key exactly as named; no value present without a key; no orphan |
| 5a | Create `project_organization` | the whole expected shape: columns in order, composite primary key, both keys by name and rule |
| 5b | Drop `project_company` | only when the new table is complete, both are empty, and nothing references the old one |
| 6 | Drop `crm_companies` | only when empty and no foreign key references it |
| 7 | Full target verification (`targetProblems()`) | every item above, plus no retired table and no foreign key to one |

The project link is **created and the old table dropped, not renamed**: it must be empty, so no data moves, and a rename would leave a table carrying the old names with the right ones still to come. **Corrected after the CP1 review (B-3):** the original text called Laravel's `Schema::create()` one atomic statement. It is not: it emits `create table` and then the primary key and each foreign key as separate `alter table` statements, so a run that died between them would leave a table that is present but lacks its constraints. The table is now created by ONE raw `CREATE TABLE` that carries the columns, primary key, index and both foreign keys (`createTable()` in the migration, engine, charset and collation taken from the connection configuration), so it exists complete or not at all; section 12 records the proof.

**The starting check refuses, before the first DDL statement, listing counts and structural facts only (never a record):** a populated `crm_companies`; an old Company affiliation; an old project link; an Organization with no `portal_enabled_by` (and no `created_by` to keep); a Person table with both or neither of the two columns; neither or both project link tables with the new one populated; `crm_companies` already gone while the rest of the schema is not the target (an ambiguous mixed state); and a database the ORG-1 migration has not reached. It is **schema-aware**: a database a previous run left part-converted is judged against what remains, so a column that already has its new name, or `portal_enabled_by` NULL on a row whose `created_by` is already set, is never a false refusal.

**Every stage judges itself**: it asserts its own preconditions (it never trusts that an earlier stage or the starting check ran), never renames a populated `crm_company_id` (ids of unrelated tables can coincide), never adds a key over an orphan or over a value it cannot prove is an Organization id, and refuses a column, key or table of the wrong shape with a manual-resolution message. The foreign-key halves of the postconditions are waived for a table whose engine is not InnoDB, **as a concession to the MyISAM host-parity smoke only**: MariaDB parses and silently discards a `FOREIGN KEY` clause on such an engine, so there is nothing to inspect. (The earlier text said this was "as in the earlier migrations"; those migrations contain no such waiver, they simply rely on the same server behaviour.) The application's tables are InnoDB by configuration, and `database:verify` against `database/profile/foreign-keys.json` is what proves the keys on a real database.

**`down()`** is schema-only. It refuses, changing nothing, when anything could not be reconstructed: any project link, Directory-only Organization, profile data, classification other than the default, `created_by` different from `portal_enabled_by`, partner or managed relationship, or Person affiliation. Where it is allowed it restores the historical schema **exactly** (verified by fingerprint: columns and their order, indexes, foreign keys by name and rule, CHECKs, engines, collations) and is resumable **step by step, each step verifying the WHOLE table it meets** (corrected after the CP1 review, B-1 and B-2: an earlier version treated "the table exists" and "the column is renamed back" as a finished step; section 12). ORG-2 is rolled back **before** ORG-1: ORG-1's `down()` refuses while a Directory-only Organization exists, and ORG-2's refuses first.

The migration is **not** a rehearsal candidate (the pinned baseline fixtures populate `crm_companies`, so it would refuse them) and is named in neither manifest.

## 7. Historical-schema test infrastructure

`tests/Historical/*` runs on the **primary rehearsal database** (`intechral_client_portal_rehearsal`), never the testing database. `tests/HistoricalTestCase.php` lets the normal boot guards run against the testing database, then points the application's default connection at the rehearsal target accepted by `TargetGuard`; `tests/Support/HistoricalSchema.php` builds it to "every migration before X" from the real files in `database/migrations`, and `HistoricalSchema::use(...)` is the one call a test makes. Each `use()` compares the **reuse key** (the point, a SHA-1 of the content of every migration file that builds it, and a fingerprint of the schema) with the one recorded when it was built (the content hash was added after the CP1 review, B-4): equal, and the tables are emptied and reused; different (a failing or interrupted DDL test, or another suite), and the database is **dropped and rebuilt**. Nothing is repaired in place, so no test or suite can run on a half-migrated schema. The rehearsal lock is held for the process, exactly as the rehearsal tests do.

**Why the rehearsal database and not a third.** `rehearse:provision` runs as the database root and is deliberately limited to two databases (its validator and self-tests refuse anything else); a third database would widen that, which is a separate decision. The rehearsal suites already rebuild their own baseline when the database is not at it, so sharing is safe. A build takes about 1.3 s. **Accepted by the CP1 review as the historical-test strategy.** To be exact about what is shared: the historical tests and the rehearsal tests use the **same database** (`intechral_client_portal_rehearsal`), serialized by the rehearsal lock that each Pest process holds, and neither relies on the other's contents. The shared *testing* database is never used for a historical test. A historical test leaves the rehearsal database at a historical schema, so anything that needs the rehearsal baseline rebuilds it first, as it already does.

Moved with a setup line added and no assertion changed: `PreservationMigrationsTest`, `TicketAttributionMigrationTest`, `ManagedOrganizationMigrationTest`, `OrganizationPortalStateMigrationTest`, `OrganizationAdminApprovalMigrationTest`, `OrganizationPreflightTest`. One infrastructure decision is worth stating: `use()` ends by opening one transaction, as `RefreshDatabase` does for every Feature test, because `ManagedOrganizationMigrationTest` was written against Laravel's transaction accounting (a DDL statement ends the real transaction while Laravel still counts it) and depends on it.

## 8. Integrity snapshot and preflight

`IntegritySnapshot` is now schema-conditional in both directions. On any database with the ORG-1 columns (the historical and the canonical schema alike) it states the four portal-state rules and the five Directory-only (S11) rules; while `crm_companies` exists it keeps `organizations:relationship_without_single_company`; once `organizations` has `created_by` and `classification` it adds `created_by` completeness, the Classification catalogue and the project-link and Person-affiliation references (the generic foreign-key orphan loop covers the new keys). A check that cannot run is a failure, never a pass, and the baseline and any rehearsal that stops earlier simply have none of the later checks. `organizations:preflight` runs on both schemas, reports nothing for the retired Company sections once the table is gone, and fails on an unknown classification or a missing creator.

## 9. Current-schema contracts updated at equal strictness

`PreservationKeys::liveUserKeys()` replaces `crm_companies.created_by` with **`organizations.created_by`** (NOT NULL, RESTRICT, `organizations_created_by_foreign`); the historical `USER_KEYS` stays for the pinned M2 test and the rehearsal. `PreservationSchemaTest` asserts the same 14 RESTRICT keys (13 user keys plus `project_organization.organization_id`) and lists **every** other reference to `users` or `organizations` (the Person affiliation SET NULL and the four earlier-package Organization references are named, so none can drift). `PreservationDeletionTest` proves the new creator key refuses a user delete and loses nothing, and that an Organization a project links to cannot be deleted while a Person's affiliation is cleared by SET NULL, exactly as the Company tests did. `CommittedUsers` deletes by `organizations.created_by` in place of the retired table.

## 10. Evidence

All on the isolated stack (section 2), MariaDB 11.4.13. Commands are Pest through one wrapper inside `org2_app` (`APP_ENV=testing DB_DATABASE=intechral_client_portal_testing DB_HOST=db ./vendor/bin/pest --do-not-cache-result <path>`).

**Baseline before any change** (pristine `origin/main`): `tests/Feature/Database` plus `tests/Unit/Rehearsal`, 293 passed, 1,359 assertions.

**After CP1** (final run of each suite on the final code, one suite at a time):

| Run | Result |
|---|---|
| `tests/Historical` (infrastructure 6; the ORG-2 migration 57; snapshot and preflight on both schemas 16; CP0 characterization 16 + 5 `todo`; the moved suites 83) | 178 passed, 864 assertions, 5 todos |
| `tests/Feature/Database` (current-schema contracts, `database:verify` manifest test, the two untouched migration tests) | 71 passed, 341 assertions |
| `tests/Unit/Rehearsal` (the pins unchanged, `MigrationSetTest` included) | 158 passed, 744 assertions |
| `tests/Unit/Backup` (real `mariadb-dump`, `gpg`, the restore drill into the rehearsal restore database). **Corrected after the CP1 review:** this suite ran on the historical *baseline fixtures*, NOT on the canonical schema, as originally stated here. It proves the backup tooling is unaffected by the new code; it is not a canonical-schema backup and restore verification, which remains **pending** (section 12) | 152 passed, 471 assertions, none skipped |
| `tests/Unit/Architecture` (the Organization-reader source guard) and `tests/Unit/Support` (`TestDatabaseSafety`) | 71 + 34 passed |
| Pint (`--test`) on every changed PHP file | pass |
| `git diff --check` | clean |

**CLI, fresh install** (`migrate:fresh --force`, then): `php artisan database:verify` exit 0 ("matches the supported profile"); `php artisan organizations:preflight` exit 0, no blocking finding.

**Host-parity smoke** (an extra `mariadb:11.4.13` with the Namecheap defaults: MyISAM default engine, latin1, non-strict, Eastern time; `APP_ENV=production`): `migrate --force` ran every migration to DONE including this one, `database:verify --json` passed, **0 non-InnoDB tables**, no `crm_companies` or `project_company`. (The script `scripts/ci/host-parity-smoke.sh` needs host PHP; the same steps were run inside `org2_app` against a throw-away container, which was then removed.)

**Mutation probes on the migration** (each caught by the named test): removing the populated-rename guard; making the backfill overwrite an existing `created_by`; dropping the check that stops `crm_companies` while a key references it; skipping the no-enabling-operator refusal; dropping the `down()` creator check.

**Not run, and why:** the full application suite (not authorized for CP1; it is expected to fail as described in section 1); the browser suites; a hosted CI run; `backup:create` and `backup:drill` as CLI commands, and any backup and restore round trip on the canonical schema (pending; the Backup suite above used historical baseline fixtures); `runtime:preflight`; any run against the development or staging database.

## 11. Review questions and assumptions that need real-MariaDB proof

1. **Mergeability.** CP1 leaves the application incompatible with the schema by design (section 1). Is reviewing CP1 alone, then CP2 on top, acceptable, or must CP1 and CP2 land as one pull request?
2. **Project link created, not renamed** (section 6). The owner's note listed "renaming ... the pivot table" among the recovery points; the equivalent points here are "new table created, old not dropped" and "old dropped". Is that acceptable?
3. **`down()` also refuses on a managed or partner relationship** (the previous schema required a Company for each, which cannot be reconstructed), beyond the four cases ORG-0 lists. Conservative; confirm.
4. **The historical tests share the rehearsal database** (section 7). Confirm that is preferred to a third database and a change to the provisioning scope.
5. **Source-guard exemption.** `OrganizationReadersInventoryTest` needed one exemption with its reason for the read-only `OrganizationPreflight::canonical()`; confirm it decides no portal act.
6. **Developer action before this runs anywhere real.** The development database holds Company data, so the migration will refuse it by design; it needs the owner's explicit authorization (Q6) to be cleared or rebuilt first. Developers also need `./dev rehearse:provision` only if their volume predates the rehearsal databases (nothing new is provisioned by this change).
7. **Assumptions proved here on MariaDB 11.4.13 only:** a foreign key and its index can be dropped in one ALTER; a column can be renamed and a foreign key added in a following statement; a single raw `CREATE TABLE` produces the constraint names the manifest lists (the first version used Blueprint, which is not atomic, see section 6); the ORG-1 CHECKs survive all of it. The shared-hosting server itself (the real Namecheap host) has not run this migration.
8. **Lock order.** CP1 adds no application locking (the migration runs under maintenance mode). The shared-lock rule for Project to Organization writes is CP2 and CP6, and the Person-affiliation and delete pairs of ORG-0 section 6.1 are CP6.

## 12. CP1 review closure (Opus High: ACCEPT CP1 WITH CONDITIONS)

The reviewer confirmed the forward migration, its refusal guards, the data conversion and the main recovery strategy, and accepted the historical-test isolation. Four findings and several evidence inaccuracies were corrected before any CP2 work, as follows. The original text above is kept and annotated where it said something that was wrong.

| Finding | Defect | Correction | Regression test (each fails against the defect: mutation probes, section 13) |
|---|---|---|---|
| **B-1** | An interrupted `downCompanies()` could leave an incomplete `crm_companies` that a later run accepted as restored, because it only checked that the table existed. | The historical table is created by one statement, and any existing `crm_companies` (and `project_company`) is judged by its WHOLE shape: columns in order with type and nullability, every index by name, uniqueness and columns, both foreign keys by name, parent and rules. A table of any other shape is **refused** (a message, nothing further changed; it holds no rows the rollback needs, so the operator drops it and runs again). The final `historicalProblems()` check now covers the same whole shape, so a rollback is never reported complete over an incomplete table. | `B-1:` in `OrganizationConsolidationMigrationTest` (CREATE TABLE ran and nothing after it; each missing or wrong piece by name; a complete table is finished; incomplete `project_company`) |
| **B-2** | With the Person column already renamed back to `crm_company_id` but its foreign key not restored, `downContacts()` returned as if the stage were done. | The stage now has two halves: the rename (only when `organization_id` exists) and the check that follows it, which always runs: the old column must hold only NULL (else refuse, no key added), the foreign key `crm_contacts_crm_company_id_foreign` must be present with its rules (an absent one is added; a wrong one is refused). | `B-2:` (the exact intermediate state built by raw SQL: restored; restored through `downContacts()` alone and idempotent; populated old column refused; wrong-rule key refused; both or neither column refused) |
| **B-3** | The record claimed `Schema::create()` is one atomic MariaDB statement. It emits separate `alter table` statements for the primary key and each foreign key. | See section 6: one raw `CREATE TABLE` per table the migration creates (`project_organization` going up; `crm_companies` and `project_company` coming back down). No atomicity claim is made that the SQL does not support. | `B-3:` (exactly one DDL statement and no ALTER, going up and down; the real server leaves no table when a constraint in the statement is invalid; a run interrupted inside the creation leaves no table and the next completes; an incomplete `project_organization` of the old shape is refused by each missing piece and `project_company` is not dropped on its strength) |
| **B-4** | The `HistoricalSchema` reuse fingerprint covered the schema and the recorded migration names, not the migration code, so an edited migration did not rebuild a database whose schema fingerprinted the same. | The reuse key is now `point + SHA-1 of the content of every migration file that builds it + schema fingerprint`. Names and contents only: no timestamp, owner or location, so a touched file does not rebuild and an edited, added or renamed one always does. The process-wide rehearsal lock and the `TargetGuard` checks are unchanged. | `HistoricalSchemaTest` (hash is content-only and order-independent; the real key carries the real hash; a database recorded as built from other migration code is rebuilt while an identical one is reused) |

**Evidence and documentation corrections.**
- The `tests/Unit/Backup` result in section 10 was obtained on historical *baseline fixtures*, not the canonical schema; the sentence is corrected there. **Canonical-schema backup and restore verification (the Backup suites against a canonical database, and the `backup:create` and `backup:drill` commands) remains pending** and is a required final verification.
- ORG-0 stated both that ORG-1 was merged (PR #74, `main` `0f69fd9`) and that it was not yet merged. The status line and the ORG-1 as-built status now say it merged and keep the earlier wording as superseded.
- The MyISAM waiver comment (section 6 and the migration header) now says what it is: a concession to the host-parity smoke on an engine that discards foreign keys, not a behaviour of earlier migrations.
- **A rollback can leave an Enabled Organization with no matching Company.** Before ORG-2 every Enabled Organization had a Company, and the pre-ORG-2 preflight reports an Organization without one. `down()` restores the schema and keeps every Organization row, but it creates no Company rows. A rolled-back database therefore reports a historical-preflight warning for each Enabled Organization (the single-Company section), which is expected, not corruption. The rollback is a schema rollback for an empty or nearly empty database (this release has no production data); it is not a data restore.

## 13. CP2 to CP5: the application conversion (as built)

Paths under `src/`. One Organization entity; `crm_companies` and every production abstraction that named it are gone.

### 13.1 Retired, and what replaced it

| Retired | Replaced by |
|---|---|
| `App\Models\CrmCompany`, `CrmCompanyFactory` | `App\Models\Organization` (the Directory profile is its columns: `website`, `phone`, `address`, `notes`, and the internal `classification`, `created_by`) |
| `OrganizationThroughCompanyScope` (a member saw their Organization's Company's People) | `DirectoryOperatorScope` on `CrmContact`: an operator (`portal.operator`) sees every Person, everyone else none (ruling D7) |
| `AccessibleCrmCompany` | `AccessibleOrganization` (an unknown and a hidden id fail with the same message) |
| `CrmService::{createCompany, updateCompany, deleteCompany, promoteToOrganization}` | `OrganizationDirectory::{create, update, changeClassification, delete, enablePortalAccess}` |
| `CompanyHasProjectsException`, `OrganizationRelationshipException::{COMPANY_PAIRING, COMPANY_IN_RELATIONSHIP}` | `OrganizationDirectoryException` (`has_portal_history`, `has_projects`, `has_dependants`, `still_referenced`); `OrganizationRelationshipException::ALREADY_ENABLED` |
| `Project::companies()` / `project_company`; `User::orgCompanyIds()` (no caller); `Organization::company()` | `Project::organizations()` / `project_organization`; `Organization::{people, projects, creator}`; `CrmContact::organization()` |
| The one-company assumption in `OrganizationRelationships` (`assertSingleCompany`, `companyCount`, the candidate-list filter) and its view branch | Nothing: the Organization row, locked first, is the whole record. The ORG-1 portal-state fence is unchanged |
| `OrganizationScope` (the generic direct-`organization_id` scope; its last model was the retired Company) | Deleted. Its one remaining helper, `membershipOrganizationIds()`, moved to `OrganizationMembershipScope`, its only caller |

### 13.2 Service boundaries

* **`OrganizationDirectory`** (new, `final`): create (Directory-only, `created_by` = actor, default `general`), update (missing key keeps, explicit null or blank clears a nullable field, `name` and `classification` cannot be cleared, only changed columns written, a no-op writes neither row nor audit entry, `notes` never copied into the log), `changeClassification`, `delete` (only a never-enabled Organization; refuses on a project link and on members, invitations, requests or a relationship; clears Person affiliations deliberately and records their ids; the database RESTRICT key remains the arbiter and its error 1451 becomes a controlled refusal), `enablePortalAccess` (Not enabled to Enabled only; already Enabled is a controlled `already_enabled`; Disabled is refused without clearing `disabled_at`; the enabling operator keeps the automatic `admin` membership; `portal_enabled_at`, `updated_at` and the audit entry's `created_at` are one instant). Slugs are `Str::slug(name)` or `organization`, unique, with a bounded probe (20 taken candidates, then a random suffix) and a bounded retry (5; the last attempt takes a random suffix) on a duplicate of `organizations_slug_unique` only.
* **Authorization.** `manageDirectory` (new policy ability, no new global permission, the same two capabilities as the other two) for create, update, classify and delete; `manageMembership` for initial enablement; `manageRelationships` unchanged for disable and re-enable. Each is asked of the Gate before the transaction and again from the TABLES inside it (`OrganizationAuthority::isDirectoryManager`, a method of its own, as `isMembershipManager` and `isRelationshipManager` are) under the actor's shared user-row lock. No Gate and no permission-cache read after the first lock.
* **`CrmService`** keeps only Person operations. An affiliation write takes the actor's user row (shared), then the Organization row(s) SHARED, ascending, and re-validates them under that lock, then the Person row (Organization before Person). **CP6 correction:** this includes the Organization the Person is LEAVING (and a Person's deletion locks the one it is in): the real two-connection test of an Organization's deletion against a Person moving away found a genuine InnoDB deadlock that the framework's transaction retry had hidden (section 15.4). An update that touches neither the affiliation nor its index takes no Organization lock.
* **`ProjectService`**: `create` and the new `syncOrganizations` take the user row (shared), then the affected Organization rows SHARED in ascending id order in one ordered statement, then (sync only) the PROJECT row exclusively, then the link rows under a locking read. `syncOrganizations` preserves links the actor cannot see (a scoped actor owns only the links to their own Organizations; an operator owns all), rejects a hidden id exactly as an unknown one, and restarts (bounded, 5 attempts) if the links under the project lock differ from the set it locked Organizations for. **CP6 corrections:** operator eligibility is re-derived from the TABLES inside the transaction, after the actor's row lock (F-2); every read that decides a link or a membership is a LOCKING read taken after the Organization locks (F-1); and `create` locks EVERY user row it will reference (the creator and each member, one ordered statement) before any Organization row (F-3).

### 13.3 Lock sequences implemented (for CP6 to certify)

| Operation | Sequence (`->` is order of acquisition) |
|---|---|
| Directory create | user (S) -> INSERT organization |
| Profile update, classification | user (S) -> organization (X) -> UPDATE |
| Delete (never-enabled) | user (S) -> organization (X) -> `project_organization`, members, invitations, tickets, managed rows (S, counts) -> people (X, ascending id) -> UPDATE people -> DELETE organization |
| Initial enablement | user (S) -> organization (X) -> UPDATE -> INSERT admin membership |
| Disable / re-enable (ORG-1, unchanged) | user (S) -> organization (X) -> invitations (X, ascending) |
| Person create | user (S) -> organization (S) -> INSERT person |
| Person update with affiliation; Person deletion | organization(s) (S, the current and the new one, ascending, one statement) -> person (X) -> UPDATE / DELETE (CP6; was: new organization only) |
| Project create | users (S: creator and every member, ascending, one statement) -> organizations (S, ascending, one statement) -> INSERT project, columns, members, links (CP6; was: creator only, member rows locked later by the foreign key) |
| Project link sync | user (S) -> operator eligibility (tables) -> links (plain read, to plan the lock set) -> organizations to add or remove (S, ascending, one statement) -> project (X) -> link rows (S, locking) -> membership rows (S, locking; CP6) -> DELETE / INSERT links |

The shared Organization lock on a Person write or a project link is what an Organization deletion (exclusive on the row) and an initial enablement (exclusive) serialize against, in either order. CP6 certifies each pair with two real connections, in both orders, with mutation probes (section 15), and corrected two sequences above.

### 13.4 Routes, wire names and views

The `crm.companies.*` names, URIs and `{company}` parameter are unchanged until ORG-3. Controllers type-hint `Organization $company`: route-model binding matches the parameter NAME, so an argument named `$organization` on `{company}` would be injected as an EMPTY model without error; a test pins the names and that an unknown id is a 404 with nothing created. `companies` and `linkedCompanyIds` (the project form's wire names) are kept; the React code is untouched. Every Directory action asks `manageDirectory` first (a customer holding a stray `crm.manage` no longer reads an Organization's notes or any Person: 403 for a visible Organization, 404 for a hidden or unknown one). The Directory form shows the Classification read-only and has no control to change it, and the controller no longer accepts it (CP6, F-4: the editable control belongs to ORG-3); it is changed through `OrganizationDirectory::changeClassification` only. `DashboardController`'s `companies` tile counts Organizations.

### 13.5 CP5 tests

* **New:** `OrganizationDirectoryTest` (behaviour, A4 replaced, slug retries, and the direct-service security matrix: 5 operations x 6 denied actors, a stale-permission-cache proof that the refusal comes from inside the transaction, lock sequence and no Gate or cache under a lock), `OrganizationDeletionTest` (successor of `CompanyDeletionSafetyTest`), `OrganizationClassificationTest` (every decision for all three values must be identical; classification changes move nothing), `OrganizationLeakageTest` (sentinel values in every Directory column and a Person, read in real customer, stray-`crm.manage`, partner-support, mail, invitation, export and audit responses, with a negative control), `DirectoryRouteBindingTest`, `ProjectOrganizationLinksTest` (A5 replaced, lock order), `OrganizationPreflightCanonicalTest`, two canonical snapshot negatives in `IntegritySnapshotSchemasTest`, and the architecture guards `OrganizationDeletionGuardTest` and `ClassificationIndependenceTest` (allowlisted source scan).
* **Converted, assertions kept or strengthened:** every Feature test that named a Company now uses the Organization; the ORG-1 fence, lifecycle, invitation, membership, relationship and partner-support suites run on canonical fixtures without a skip. Three assertions changed MEANING by ruling and are recorded here, not hidden: a member no longer sees their Organization's People (D7: the Person assertions in `TenantScopingTest` and `AuthorizationBaselineHardeningTest` became "none"); a customer holding a stray `crm.manage` is refused the Directory pages (`manageDirectory`); initial enablement of an already Enabled Organization is `already_enabled`, not an idempotent no-op (ruling 4).
* **Retired:** `tests/Historical/Org2BaselineCharacterizationTest.php`. It characterized the code ORG-2 rewrites and could not run once that code was gone; its facts (A4, A5, the Company deletion refusals, the legacy routes, enablement, People and Projects) are recorded in section 3, and its five `todo` controls are real tests in `OrganizationDirectoryTest` (A4), `ProjectOrganizationLinksTest` (A5, lock order), `OrganizationDeletionTest`, `OrganizationDirectoryTest` and `CrmTest` (already_enabled; Disabled needs re-enable), and `TenantScopingTest` and `OrganizationLeakageTest` (D7).
* **Historical fixtures:** the pinned historical tests no longer depend on a retired production class. `tests/Support/Historical` holds a test-only `CrmCompany` model and factories for the pre-ORG-2 shapes; `HistoricalSchema::use()` points factory discovery at them for a point before ORG-2 only, and the production factories lost their schema sniffing.

## 14. Evidence (CP1 closure and CP2 to CP5)

All on the isolated stack (section 2): MariaDB 11.4.13, `org2_db`, a throwaway `org2_net`; Pest through one wrapper inside `org2_app` (`APP_ENV=testing DB_DATABASE=intechral_client_portal_testing DB_HOST=db ./vendor/bin/pest --do-not-cache-result <path>`); the target was proven before the first write (`@@hostname` is `org2_db`'s container id, and `portal_db` does not resolve from `org2_app`). One suite at a time. The development stack, its database (3 Companies, no ORG-1 migration) and Release 0.9 staging were never touched.

### 14.1 Results (final code; Pint `--test` over `app`, `database`, `tests`: pass, 643 files; `git diff --check`: clean)

| Group | Result |
|---|---|
| `tests/Historical` (infrastructure 9; the ORG-2 migration 82, with the B-1 to B-3 cases; snapshot and preflight on both schemas 18; the moved pinned suites 84; the former CP0 `todo`s are gone) | **193 passed**, 919 assertions, 0 skipped, 0 todo |
| `tests/Feature/Database` | 71 passed, 341 assertions |
| `tests/Unit/Rehearsal` (the pins unchanged, `MigrationSetTest` included) | 158 passed, 744 assertions |
| `tests/Unit/Backup` (**historical baseline fixtures**; see 14.4 for the canonical proof) | 152 passed, 471 assertions |
| `tests/Unit/Architecture` (reader inventory, the new deletion guard and Classification scan, task-deletion authority) | 77 passed, 209 assertions |
| `tests/Feature/Crm` (ORG-1 lifecycle, invitation, membership, relationship, fence, both two-connection worker suites, and the new Directory, deletion, classification, leakage and binding tests) | 561 passed, 5,539 assertions |
| `tests/Feature/Projects` / `Tasks` / `Tickets` / `Pilot` / `Ui` / `PartnerSupport` | 875 / 318 / 588 / 237 / 102 / 186 passed |
| `tests/Feature/Console` (includes the canonical preflight and `DevSeeder`) / `Shell` / `Auth` / `Admin` | 161 / 222 / 503 / 317 passed, 20 skipped (existing pcntl and queue-worker skips in the permission-cache suites, unrelated) |
| `tests/Feature` remainder (`Accounts`, `Billing`, `Cms`, `Notifications`, `Runtime`, `Time`, the root files) | 17 + 31 + 20 + 203 + 137 + 172 + 5 + 73 + 27 + 8 + 19 + 4 + 1 + 1 + 1 + 1 passed |
| `tests/Unit` remainder (`Support`, `Configuration` incl. `BladeThemeGuardTest`, `Ci`, `Cron`, `Helpdesk`, `Permissions`, `Runtime`, `Ui`, root files) | 34 + 369 + 13 + 64 + 35 + 6 + 270 + 136 + 3 + 1 passed |

**Coverage, stated honestly.** Every Pest directory was run (above), one directory at a time on the isolated stack, so no Pest test is outside this table. It is NOT one uncontended full-suite run: that remains the later pre-commit gate. Not run: the browser suites (Playwright), the frontend lint (no file under `resources/js` changed), hosted CI, `runtime:preflight`, E2E of any kind.

**Original schema-mismatch failures.** The three untouched Feature files sampled at CP1 gave 26 failures of 39 (`crm_companies` missing, `crm_company_id` unknown). Every Feature directory now passes. During CP5 the first full `tests/Feature/Crm` run found 5 further failures, all in tests whose subject changed by ruling, and each was corrected rather than weakened: the `already_enabled` result (ruling 4), the writer inventory of `organization_members` (initial enablement moved from `CrmService` to `OrganizationDirectory`), the relationship page (a never-enabled Organization is now offered neither designation nor link; a Disabled or Directory-only Organization was already refused by the server), and the Directory list for a customer holding only `crm.manage` (403, `manageDirectory`). One pinned historical assertion group was removed with its reason recorded in the file: `ManagedOrganizationMigrationTest` also asserted the relationship service's retired Company-pairing refusal.

### 14.2 Mutation probes (each defect reintroduced in turn; the named tests fail, then the file is restored and its checksum compared)

| Probe | Failing tests |
|---|---|
| B-1: an existing `crm_companies` trusted without a shape check | 10 |
| B-2: a Person column renamed back is treated as a finished stage | 3 |
| B-3: `project_organization` created by `Schema::create` (separate ALTERs) | 1 (the single-statement test) |
| B-4: the reuse key ignores migration content | 2 |
| In-service table check removed from `create` / `update` / `delete` / `enablePortalAccess` | 2 / 4 / 2 / 2 |
| The Gate pre-check removed from `update` | 2 (the new "refuses at the Gate before any lock or transaction" test; the table check alone would otherwise mask it) |
| Initial enablement made idempotent again (`already_enabled` removed) | 1 |
| `delete` ignores portal history / ignores project links | 2 / 2 |

(The first B-3 probe did not apply: Pint had changed the quoting of its anchor, which was noticed because it reported no failure, and was re-run with the corrected anchor.)

### 14.3 Host-parity smoke on the corrected migration

A throwaway `mariadb:11.4.13` with the Namecheap defaults (MyISAM default engine, latin1, non-strict, Eastern time), `APP_ENV=production`, pinned and verified through the application before anything ran: `migrate --force` ran every migration to DONE including this one; `database:verify` passed; 0 non-InnoDB tables; `crm_companies` and `project_company` absent; `project_organization` is InnoDB with the configured collation; `migrate:rollback --step=1`, `migrate` again and `database:verify` passed. The container was removed.

### 14.4 Canonical backup and restore (previously pending)

On the isolated testing database (canonical schema; `crm_companies` absent, `project_organization` present), with committed rows: a Client Organization with a full profile, a Directory-only Organization, a Person affiliated with the first, and a Project linked to both. `php artisan backup:create` (throwaway recipient key) wrote a sealed set, exit 0 (41 tables, 74 rows); `open-backup-set.sh` with the recorded digest opened it; `backup:verify` passed; **`backup:drill` exit 0: "PASSED: the restored state equals the set and every check held"** (restored into `intechral_client_portal_rehearsal_restore`, table-by-table and row-by-row equality, every foreign-key and logical reference resolving, 60 migrations). The committed rows and every working file were removed. **Environmental finding (pre-existing, not ORG-2):** in a container with no `/run/user/<uid>`, `gpg-agent` creates its sockets inside the backup tool's temporary `GNUPGHOME`, and `BackupSet::removeTree()` treats a socket as neither a file, a link nor a directory, so `backup:create` fails with "The plaintext working files could not be removed" (and leaves a `.partial-*` directory the next run removes). The run above created `/run/user/1000` in the throwaway container. The shared-hosting host has not been checked for this.

### 14.5 What is still not proved

(Superseded for the certified pairs by section 15.) The real shared-hosting server; the browser suites and hosted CI; a single uncontended full Pest run; the development-database migration (it refuses by design: it holds 3 Companies, and needs the owner's Q6 authorization).

## 15. CP6: the review corrections and the two-connection certification

The independent review of CP2 to CP5 returned ACCEPT WITH CONDITIONS. CP6 corrects its findings (15.1), runs the lock design against a REAL second MariaDB connection for every pair the owner's matrix names, each in both orders (15.3 to 15.4), found and fixed one genuine deadlock the earlier suites could not see (15.5), and probes the harness with temporary defects (15.7). Nothing is committed, pushed or deployed; the isolated `org2_*` stack was used throughout and the development database and Release 0.9 staging were not touched.

### 15.1 Findings and dispositions

| Finding | What was wrong | Disposition |
|---|---|---|
| **F-1** stale membership authorization | `syncOrganizations` read the project's links with a plain read to size its lock set. Under REPEATABLE READ that fixed the transaction's snapshot BEFORE it waited for the Organization lock; a membership removal that committed while it waited was invisible to the plain membership read that followed. `create` had the same shape (its first consistent read is now the eligibility read, which also precedes the Organization locks). | `memberOrganizationIds()` is a LOCKING read (`lock in share mode`, ascending), taken after the Organization locks, for both operations. Every writer of a membership row holds its Organization row first, so it takes nothing against the order. Regression: `C15` (sync) and `C15c` (create), both orders, real connections. |
| **F-2** operator eligibility from the Gate | `$unrestricted` was `CustomerPilotGate::isOperator()` evaluated BEFORE the transaction: a permission-cache answer that a committed revocation had already outdated decided whose links an actor controlled. | `OrganizationAuthority::holdsOperatorCapability()` reads `portal.operator` (direct or through any role) from the TABLES, inside the transaction, after the actor's row lock. A drift test keeps it equal to the Gate for seven kinds of account. No Gate or cache read remains in either operation. What this does and does not guarantee is section 15.6. |
| **F-3** member user rows locked after Organization rows | `create` locked the creator, then the Organizations, and the `project_members` foreign key then took each extra member's user row implicitly, AFTER the Organization locks. | `create` locks every user row it will reference (creator and members, unique, ascending) in ONE `order by id asc lock in share mode` statement first. Compatible with the actor-first contracts: the actor's row is still the first statement. Evidence that the order is physical, not merely requested: `C16` (the lower id is held by the creation while it waits for the higher one, asked of a third connection). |
| **F-4** Classification selector | The Directory form had an editable control (ORG-3's). | Removed from the form; the controller no longer validates or forwards `classification`, so a forged field changes nothing; the show page keeps the read-only label; storage, enum, validation and `changeClassification` stay. The form's control counts return to the original 5. |
| **F-5** permissive 403-or-404 | Customer-refusal tests accepted either status and ran in the suite's default `legacy` mode, so they never exercised the pilot or closed surface. | Each now runs in `closed`, `pilot` and `legacy` and asserts the EXACT status: 404 in closed and pilot (the surface gate), and in legacy 403 where the route has no record to bind and 404 where the route-model binding cannot bind a record the customer's scope hides (the Organization of a Directory-only record, a Person). Every refusal has an operator control on the same route and mode. The pre-existing legacy semantics are unchanged. |
| **F-6** exhausted restart answered as a raw 409 | A bounded restart that ran out threw `ConflictHttpException`, which the framework renders as a raw 409 page. The row was missing from this table. | Closed in CP7 as **G-3** (section 16.1): the service keeps the exception, the controllers turn it into the interface's own form error. |
| **F-7** mass assignment | `Organization::$fillable` had grown to the Directory profile. | Restored to `['name', 'slug']`; `OrganizationMassAssignmentTest` pins the list, proves a fill with every other column does nothing, and scans `app/` for a mass-assigning call. The services already wrote by explicit column. |
| **F-8** cleanup | A stale `promoteToOrganization` reference; a misleading TaskQuery comment; controller 1000 vs service 5000 address ceilings; an unbounded slug probe; an ambiguous notes audit value. | All five done, behaviour-preserving except where stated: the model docblock names `OrganizationDirectory::enablePortalAccess`; the TaskQuery comment now says the relation IS read through the membership scope; the service's address ceiling is the form's 1000 (a test of the ceilings is the existing validation test); the slug probe walks at most 20 taken candidates and the last of 5 insert attempts takes a random suffix; the audit entry carries `internal_fields_changed: ["notes"]` (or `internal_fields_set` on a creation) and never a value, so a write, an edit and a clear read the same and the notes text never appears. Deferred: none. |

### 15.2 Lock sequences after CP6 (changes to section 13.3, in place there)

* **Current reads.** A locking read returns the latest COMMITTED row whatever the transaction's snapshot; a plain read returns the snapshot taken at the transaction's first plain read. Every read that DECIDES a link, a membership or a Person's affiliation is a locking read taken after the locks it depends on; the plain reads that remain (the links read to plan the lock set, the eligibility read) are re-checked under lock or explicitly bounded (15.6).
* **Table authority.** Each Directory operation asks `OrganizationAuthority` (`isDirectoryManager`, `isMembershipManager`, `isRelationshipManager`) and the Project operations ask `holdsOperatorCapability`, all from the tables inside the transaction, after the actor's row lock, never the Gate or the cache.
* **Restart and rollback.** A refusal is an exception inside the transaction: it rolls back (one rollback, one attempt). A bounded RESTART (`ProjectService::syncOrganizations`, and now `CrmService` for an affiliation change) returns `false` under its locks, COMMITS an empty transaction and begins another: attempts 2, rollbacks 0, no write. Five attempts; the fifth failure is a controlled `409`. A deadlock victim, which Laravel retries silently, would show a rollback and a moved server deadlock counter: the harness checks both, and an application restart is never offered as proof that the order is deadlock free.

### 15.3 Method and evidence standard

Two independent PHP processes are two real connections (`tests/Support/organization_invitation_worker.php`, extended with the Directory, Person and Project operations; harness `tests/Support/org2_race_support.php` on top of the ORG-1 one). The first worker FREEZES after a named statement (its locks held, its transaction open); the second announces just before its conflicting statement. Every pair asserts: two distinct MariaDB sessions (neither the test's); the second WAS waiting (still running 1.2 s after announcing, `Innodb_row_lock_current_waits` at least 1 while it waited, `Innodb_row_lock_waits` moved) or, for a pair that must not serialize, FINISHED while the first was frozen; no 1213/1205/1451/1452 and no `error:` outcome; the attempt and rollback counts expected; the server's `Innodb_deadlocks` did not move; and the exact committed final state. The application account has no PROCESS privilege, so `INNODB_TRX` and `INNODB_LOCK_WAITS` are not available; the global status counters are the instrumentation, and they need none. Synchronization is by freeze files and signals, never by sleeping alone (the 1.2 s observation window only decides "still waiting" after the signal). The physical lock order is established from the engine: a third connection with a one-second lock wait asks which rows the blocked statement already holds.

### 15.4 The matrix (CP6 final code: 67 tests, 2359 assertions; CP7 adds the rows marked CP7, giving 76 tests, 2616 assertions; sessions, waits and counters recorded per pair)

`1st/2nd` is the outcome of the worker that ran first and the one that waited; `a/r` is attempts and rollbacks. Every pair: two sessions, `Innodb_deadlocks` +0.

| Pair | 1st first, 2nd waits | 2nd first, 1st waits |
|---|---|---|
| **A1** enable vs invitation issue | ok(1/0), issue ok; invitation exists | issue refused `portal_refused`(1/1), nothing issued; enable ok |
| **A2** enable vs member add | ok; member added | add refused `portal_not_enabled`; no member; enable ok |
| **A3** enable vs delete | enable ok; delete `has_portal_history`(1/1) | delete ok; enable `http:404`(1/1), no membership, no audit |
| **A4** enable vs actor suspension | enable ok (authorized when it ran); suspension waits at the user row, then commits | suspension first: enable `unauthorized`(1/1) from the TABLE read; Not enabled, no member |
| **A5a** enable vs role revocation | revocation committed before the table read: refused `unauthorized`, nothing written (the revocation did not wait) | revocation fully committed first: refused at the Gate, no transaction |
| **A5b** enable vs role permission revocation committed AFTER the read | NOT serialized: enable completes; the revocation does not wait (documented window, 15.6) | |
| **A6** two enablements | one `ok`; the other `already_enabled`; one admin, one audit entry, in both orders | |
| **B7** delete vs Person create | delete ok; create `validation:organization_id`; no Person | create ok under the shared lock; delete clears its affiliation; Person kept |
| **B8** delete vs Person reassignment in | same: refused, Person unchanged | reassigned, then cleared by the delete; Person kept; log names it |
| **B9a** delete vs Person moving to another Organization | delete first: move restarts once (2/0) and re-affiliates | move first: delete finds nobody; Person in the other Organization |
| **B9b** delete vs affiliation cleared | restarts once (2/0) | cleared first, delete finds nobody |
| **B9c** delete vs a Person's deletion | restarts once (2/0) | Person deleted first |
| **B10** delete vs link sync | delete ok; sync `validation:companies`, no link, no FK error | sync ok; delete `has_projects`; Organization and link stand |
| **B11** delete vs Project create | `validation:companies`; no Project, column or member | Project, 5 columns, link; delete `has_projects` |
| **B12a/b** delete vs designate / assign customer | `http:404`; nothing managed | refused `portal_not_enabled`; delete ok |
| **B13** two deletions | one ok, one `http:404`; one audit entry | |
| **C14** two syncs, one Project | second restarts once (2/0) and replaces the first's set | symmetrical |
| **C15** sync vs membership removal (F-1) | removal first: sync `validation:companies`, no link, hidden link untouched, same message as an unknown id | sync first: ok, hidden link preserved; removal after |
| **C15b** sync vs operator revocation (F-2) | revocation committed before the table read: customer, hidden link preserved | committed after the read: operator, link removed (window) |
| **C15c** Project create vs membership removal (F-1) | refused whole | created |
| **C15d** (CP7, G-4) a former member's sync to empty vs removal of the membership | removal first: neither O's link nor the hidden one is removed, nothing written; hidden, unknown and just-left ids give one outcome and message | sync first: O's link removed, hidden one stands; removal after |
| **C16** create with a member vs suspending the member (F-3) | suspension first: create waits at the user statement, before any Organization lock; ok | create first; suspension waits at the member row |
| **C17** create with two Organizations vs delete of the higher | `validation:companies`; the LOWER Organization was held while waiting (ascending, from the engine) | project, both links; delete `has_projects` |
| **C18** bounded restart | succeeds on attempt 2 (2/0); succeeds on attempt 5 (5/0) | |
| **C19** exhausted restart | `409`, 5 attempts, zero writes of its own, links are exactly the five the test committed | |
| **C20 / C20b** (G-8; CP7 characterized, CP8 tightened) Project sync / create vs suspension of the ACTOR | suspension first: the write waits at the actor row, then is REFUSED (`unauthorized`), nothing written | write first: it completes; the suspension waits at the actor row and follows |
| **G2** (CP7, G-2) Person update / deletion whose affiliation moves on every attempt | 5 attempts, controlled conflict, zero Person writes, no audit, 5 transactions, 0 rollbacks, +0 deadlocks; each attempt's locks released, none stale (from the engine); the bound is five (succeeds on the fifth) | |
| **opposing writer** sync vs assign-customer, lined up behind one blocker | both ok (1/0); `Innodb_deadlocks` +0 | |
| **D20 to D23** disable / re-enable vs profile update / Classification change | each keeps the other's change and its own single audit entry; `portal_enabled_at` untouched; `disabled_at` as the lifecycle left it | both orders |
| **E24** concurrent creation, five collisions, both orders | unique non-empty slugs (`acme-co`, `acme-co-2`; `organization`, `organization-2`), two audit entries, no 500; waits on the first's uncommitted row where the pair reaches the insert together | |

### 15.5 What CP6 found: a real deadlock the earlier suites could not see

The pair "Organization deletion vs a Person moving away" recorded `Innodb_deadlocks` = 1 while both operations ended `ok`. The deletion locks the Organization, then finds its People through the `organization_id` INDEX (index record first, row after). A Person move took the PERSON row first and then rewrote that index record, meeting the deletion from the other side; InnoDB killed one, and `DB::transaction`'s retry silently re-ran it. It applied to a Person's own deletion too. It was invisible to every earlier test because a retried deadlock ends `ok`. Fix (`CrmService`): lock the Organization the Person is in NOW as well as its destination, shared and ascending, before the Person row; read the current affiliation before the transaction, re-check it under the Person lock, and restart (bounded, 5) if it moved. Proved by `B9a`, `B9b` and `B9c` in both orders, and by the mutation probe M8 (15.7), which reinstates the old order and records the deadlock. This is the kind of defect the counters exist to catch; it is why a framework retry must never be read as a pass.

### 15.6 Authority windows (stated separately from the portal-state fence)

These are NOT the ORG-1 portal-state fence (which serializes an Organization's lifecycle against invitation issue and acceptance through the Organization row and the permission-cache epoch). They concern the freshness of an ACTOR's authority:

* The role and permission writers (`AccessGrants`) lock the ACTING administrator's row and the `users.admin` permission row. They do NOT lock the rows of the accounts that HOLD the role. So a revocation does not serialize with an operation by one of its holders (measured: it does not wait, and the operation does not wait for it).
* **Guaranteed:** an authority change that has COMMITTED before the operation's table read (taken after the actor's row lock, after the Organization lock where there is one) is observed (`A5a`, `C15b`). A suspension IS serialized (it takes the actor's row exclusively; `A4`).
* **Not guaranteed, accepted by the owner:** a change that commits AFTER that read is not seen by the operation in flight (`A5b`, `C15b` window). The window is the length of one short transaction. Closing it would change the writers' protocol (locking every holder), which is not part of ORG-2, and nothing here claims it.
* The Gate answer given before the transaction is a first refusal only; it never grants.
* **What each family of operations rechecks under its own locks (CP7, G-8; stated exactly, because "serialized" is not "refused"):**
  * **Directory operations** (create, update, classify, delete, initial enablement, disable, re-enable) recheck, from the tables, that the actor is ACTIVE and holds the required capabilities (`isDirectoryManager`, `isMembershipManager`, `isRelationshipManager`). A suspension that committed first makes the operation refuse (`A4`).
  * **Project operations** (create, link synchronization) recheck (a) **that the actor is ACTIVE** (CP8: the actor's `disabled_at`, read by the same locking statement that locks the actor's row shared, before any write; a suspended actor is refused with an `AuthorizationException`; the members of a new Project are not checked), (b) whether the actor holds `portal.operator`, from the tables, which decides whose links are the actor's, and (c) the actor's Organization MEMBERSHIPS, by locking read. A suspension and a Project write by the same account **serialize** on the actor's row and the order decides (`C20`, `C20b`): **suspension first, the write is REFUSED and nothing is written; the write first, it completes and the suspension follows.** Before CP8 the writers did not read `disabled_at`, so a write authorized before the suspension and locking after it still completed; CP7 recorded that as a characterization. This is a narrow tightening, for these two operations only, of the in-flight-request rule of [account-lifecycle Info-4](../architecture/account-lifecycle.md) (a request already running when its actor is suspended finishes with the authorization it was given); it is not a general change to that rule.
  * **Role and permission changes** committed before the table read are observed; concurrent ones are not fully serialized with these transactions (the window above).

### 15.7 Mutation probes (each defect reintroduced in turn in the production file; the named tests fail; the file is restored and its sha256 compared; none is in the diff)

| Probe | Observed failure | Restored |
|---|---|---|
| M1 F-1: membership read plain (sync) | `C15` removal-first: sync `ok` instead of `validation:companies` (a link written with a revoked membership) | yes |
| M1b F-1 on create | `C15c`: creation `ok` instead of refused | yes |
| M2 shared Organization lock removed before the link write | `B10`, `B11`, `C17`: raw `database:1452` foreign-key error instead of the controlled refusal (the response is a 500, not a 422); the other order's freeze point disappears | yes |
| M3 Organization order inverted (descending) | `C17`: the lower Organization is NOT held while waiting for the higher; opposing-writer pair: the server records a deadlock (and the framework retry hides it behind `ok`: attempts 2) | yes |
| M3b same, against the real opposing writer only | `Innodb_deadlocks` +1 between the sync and the assign-customer statements | yes |
| M4 in-lock authority check removed from initial enablement | `A4` and `A5a`: enable `ok` for a suspended / revoked actor | yes |
| M5 user locks after the Organization locks (F-3) | `C16`: lock order `S organizations, S users` | yes |
| M5b only the creator locked up front (the old behaviour) | `C16`: the user statement locks one row not two; the physical-order pair does not wait | yes |
| M6 restart check removed (a link whose Organization was not locked is changed) | `C14`, `C18`, `C19`: attempts 1, not 2/5 | yes |
| M7 / M7b / M7c F-2: eligibility from the Gate before the transaction (sync / create / service-level) | `C15b`: the revoked operator removes the hidden link; `ProjectOperatorEligibilityTest` fails for both operations | yes |
| M8 Person write locks only the destination (the pre-CP6 order) | `B9a`, `B9b`, `B9c`: "the server recorded a deadlock" (the framework had retried it) | yes |
| M9 PROJECT row locked before the Organization rows | **INCONCLUSIVE experiment, EXCLUDED from the mutation-certification evidence (it is not one of the probes the harness is claimed to catch).** It **does not demonstrate a lock-order defect.** `C14`, `B10`, `B11` and the opposing-writer pair all pass (no deadlock): no production path currently opposes that order. Only the restart-protocol tests (`C18`, `C19`) fail, because their freeze point moved. Reported as a mutation that does NOT prove the order; the project-before-Organization order is a convention this record states, not one a conflicting writer enforces today. | yes |

### 15.8 Residual risks and what is not proved

* The authority windows of 15.6.
* Pair coverage is not a proof for every interleaving: three-way interleavings and statements not named in the matrix (for example Person creation against the actor's suspension, or an Organization update against an invitation acceptance) are covered only by the lock order itself and by the ORG-1 suites. The deadlock counter is a SERVER-global counter: it is meaningful because the suites run one at a time.
* `Innodb_deadlocks` cannot see a lock wait timeout (1205); that is asserted from the worker outcomes.
* Gap locks of the membership locking read are bounded by the Organization rows already held; not exercised under a hot table.
* `crm.companies.*` route names, `{company}` and the `companies`/`linkedCompanyIds` wire names remain until ORG-3.
* The Faker-name flake in `OrganizationInvitationOperatorTest` (the page HTML-escapes an apostrophe) was not ORG-2 but would have intermittently failed the mandatory full run; CP7 fixed the assertion (G-7, section 16.1).
* The CP7 verification and its residuals are section 16; the full run, the browser suites and hosted CI are recorded there, not here.

## 16. CP7: final verification and pre-commit readiness

The independent CP6 review returned **ACCEPT WITH CONDITIONS** (CP7 may begin on the same uncommitted branch, no production correction required first). CP7 closes its findings (16.1), records the confirmed three-way deadlock and its separate remediation (16.2), states the authorization guarantees exactly (16.3), and records the final verification (16.4 to 16.8). It is **not** a security acceptance: that is the independent reviewer's and the owner's. Nothing was committed, pushed, merged or deployed; the isolated `org2_*` stack was used throughout.

### 16.1 CP6 review findings and their closure

| Finding | Disposition |
|---|---|
| **G-1** confirmed three-party deadlock (Project creation, ORG-1 membership writer, a queued user-row writer) | **Documented, not fixed here, release-blocking before the first customer-facing Release 1.0 deployment.** Recorded in ORG-0 section 6 and 16.2; the remediation is specified in [the follow-up specification](./release-1.0-org-1-membership-lock-order-followup.md) and is a separate, separately authorized package. ORG-2's Project creation order stays |
| **G-2** Person restart exhaustion untested | **Closed.** `G2` (three tests, `OrganizationDirectoryConcurrencyTest`): an update and a deletion whose affiliation moves on EVERY attempt (a committed move from a second connection, between the worker's Organization lock and its Person lock, by the harness's `every` freeze) are refused after exactly five attempts with a controlled conflict, zero Person writes, no audit, 5 transactions and 0 rollbacks and 0 server deadlocks; at every freeze the engine shows the worker holding exactly the Organization(s) of THAT attempt (so the previous attempt's locks were released and no stale affiliation was reused); and a third test pins the bound at five, not four (success on the fifth attempt). Mutation probes N1a (bound 6), N1b (bound 4), N2 (the affiliation remembered across attempts) and N3 (no re-check under the Person lock) each fail the named tests |
| **G-3** conflicts as a raw 409 | **Closed.** The services keep `ConflictHttpException` (the service-level contract the concurrency tests assert). `ProjectController::syncCompanies` turns it into a validation error under `companies` (the Inertia page already renders `errors.companies`; a PUT from Inertia answers 303 back, never 409; no success flash; the message says the list was changed by someone else, nothing was changed, and to retry). `ContactController::update` and `::destroy` turn it into a redirect back with the typed input and an error under `person`, which `crm/contacts/edit.blade.php` renders as an alert. `ConflictResponseTest` (6 tests) proves the mapping with stubs that exhaust, because a real exhaustion needs a second connection; the real exhaustion and its zero-write guarantee are `C19` and `G2` |
| **G-4** former member removes an existing link | **Closed.** `C15d` (two tests, both orders): a Project linked to O (the actor's) and a hidden Organization; the actor's membership of O is removed and committed while the synchronization waits for the Organization lock; the synchronization to empty then removes NEITHER link and writes nothing; the hidden Organization, an unknown id and the Organization just left give one outcome and one message. In the other order the synchronization held O while the membership existed, so it removes O's link only and the hidden link stands. The F-1 mutant (plain membership read) fails it (probe N4) |
| **G-5** membership-read docblock | **Closed.** `memberOrganizationIds()` now says which Organization rows the callers hold (the ones just locked shared, including the Organizations of hidden links, which are locked and merely preserved) and that a link outside the locked set is asked about without its Organization row, harmlessly, because the attempt restarts before it writes |
| **G-6** conflict responses of the Inertia path | Closed with G-3 |
| **G-7** apostrophe flake | **Closed before the full run.** The assertion compares against `e($name)`. A dataset test uses an apostrophe, an ampersand, a tag-like name and an ordinary name, asserts the raw form is never emitted and the escaped one is. Nothing is skipped, weakened or constrained |
| **G-8** suspension after HTTP authorization | **Assessed in CP7 as an inherited limitation and characterized; the final review recommended, and CP8 implemented, an in-lock active-account check in the two Project writers** (15.6, 16.3, section 17). `C20`/`C20b` now assert refusal when the suspension committed first |
| **G-9** nested-transaction guard (optional) | **Not done: no supported caller needs it.** The restart protocols run only from controllers and the concurrency worker, never inside an outer transaction. (`RefreshDatabase` wraps each test in one, and the services' savepoints behave there as the tests show.) Revisit if a service ever calls them from inside a transaction |
| **M9** | Marked INCONCLUSIVE and excluded from the certification evidence (15.7) |

### 16.2 The confirmed three-way deadlock (G-1)

Full text: ORG-0 section 6 ("Confirmed three-party cycle") and [the follow-up specification](./release-1.0-org-1-membership-lock-order-followup.md). In short: Project creation holds the member's user row SHARED and waits for the Organization row; an ORG-1 membership add (or approve) holds the Organization row EXCLUSIVELY and waits for a SHARED foreign-key lock on the same user row; a user-row writer (a suspension, any `UPDATE users`) queues for EXCLUSIVE on that user row behind Project creation, and InnoDB queues the membership writer's shared request behind it. A cycle of three. A raw-SQL reproduction on the isolated MariaDB recorded 1213 and moved `Innodb_deadlocks` (14 to 15); the victim was the user-row writer. No data-integrity loss was demonstrated; the risk is liveness. Through the application the victim's fate depends on its transaction: the suspension path (`AccountAdministrators::guard`) has a single attempt, so it would surface as a database error with no partial state; the membership, Project and Person transactions have three attempts and would be retried silently and end looking successful. **The certified CP6 matrix (two connections) contains no deadlock; that is a statement about those pairs, not about the portal.** The portal is not claimed to be deadlock free. Project creation's user-rows-first order is the global order and stays (reverting it would create the symmetric cycle with the partner support grant); the minority order is the ORG-1 membership writers', whose reorder is a separate release-blocking package that needs its own concurrency review.

### 16.3 Authorization guarantees, stated exactly

The authoritative statement is 15.6; this is its summary. **Directory operations** recheck, under their locks and from the tables, that the actor is active and holds the capability. **Project operations** recheck the operator capability (from the tables), the actor's memberships (locking read) and, since CP8, that the actor is active (CP7 recorded that they did NOT; superseded: see 15.6 and section 17). **Role and permission changes** committed before the table read are observed; concurrent ones are not serialized with these transactions (owner-accepted window; `A5b`, `C15b`).

**G-8 assessment, as corrected by the final review and by CP8.** The governing contract is [account-lifecycle Info-4](../architecture/account-lifecycle.md): a request that was already running when its actor was suspended finishes with the authorization it was given, and the next request is refused (suspension revokes every session in its own transaction). CP7's first record of this finding never cited it and justified leaving the Project writers alone with an argument from "D7-B"; that argument was wrong (D7-B concerns suspended MEMBERS who may stay on a Project, not the suspended ACTOR) and is withdrawn. What CP7 observed was real: with the suspension committed first, `ProjectService::create` and `::syncOrganizations` still completed, and an unrestricted operator's synchronization still removed links (the hidden one included) after the suspension; the baseline `0f69fd9` had no active-account check either, so this was inherited, not a regression, and not a breach of Info-4, which permits an in-flight request to finish. **CP8 tightens it for these two operations only:** the actor's `disabled_at` is read from the locking statement that already locks the actor's row, a suspended actor is refused before any write, and `C20`/`C20b` assert both orders (suspension first: controlled refusal, no Project, no link change, nothing audited but the suspension; write first: it completes, the suspension follows). The serialization guarantee is exactly that: **whichever commits its lock first wins**, and no request is refused for a suspension that commits after it has read the row. It is not a statement about every other writer, which CP7 did not audit, and it leaves Info-4 standing for them. The independent role/permission-revocation window (15.6) is separate and unchanged: a revocation that commits before the table read is observed, a later one is not, and the new check does not close it.

### 16.4 Tests, counts and evidence

**Full application suite.** Command (isolated stack, unfiltered, all three Pest suites: Unit, Feature, Historical; `phpunit.xml` sets `PORTAL_CUSTOMER_SURFACE_MODE=legacy`; Pest 4.7.4, PHP 8.5.11, MariaDB 11.4.13 at `org2_db`, database `intechral_client_portal_testing`, verified before the run, empty, and no other test process running): `docker exec org2_app sh -c 'cd /var/www/app && vendor/bin/pest --colors=never --compact'`.

| Run | Tested state | Result |
|---|---|---|
| **Run 1** (2026-11-20T22:58:14Z to 2026-11-21T00:00:38Z) | `0f69fd9` plus the uncommitted tree, before the preflight-label correction below | **6484 passed, 20 skipped, 0 failed, 0 todo, 52399 assertions, 3741.92 s, exit 0.** Only the documentation moved during the run (no file under `src/` or `AGENTS.md` was newer than the start) |
| **Run 2, final** (2026-11-21T00:35:31Z to 01:22:05Z) | the exact final code state (fingerprint in 16.9) | **6485 passed, 20 skipped, 0 failed, 0 todo, 52403 assertions, 2793.17 s, exit 0** (one test and four assertions more than run 1: the preflight-label test; the same 20 Redis skips). Run 1 took longer for no recorded cause; both were uncontended |

Between the two runs exactly one production file and one test file changed (`OrganizationPreflightCommand` and `OrganizationPreflightCanonicalTest`, see 16.5) and the affected suites were rerun on their own (Console 161 passed; every file that names the command 73 passed) before run 2 was started. **The 20 skips are all one cause**: the isolated stack has no Redis server, so the Redis variants of `PermissionCacheCommitOrderTest` and `PermissionCacheEpochTest` mark themselves skipped ("No Redis server is reachable here; the file-store variant covers the race"), which was verified by running those files with `--display-skipped` (20 skipped, all with that message). Skips that did not trigger: the managed-requires-portal CHECK skip in `OrganizationPreflightCanonicalTest`, the root-user skips, and the Eastern time-zone-table skip.

**Standalone two-connection matrix** (run alone, after the full suite, nothing else on the server): `OrganizationDirectoryConcurrencyTest` + `ProjectOrganizationConcurrencyTest`: **76 passed, 2616 assertions, 314.68 s** (CP6's 67 plus G2 x3, C15d x2 and C20/C20b x4). The harness recorded 67 pair records (`O2R_EVIDENCE`): **67 of 67 on two distinct MariaDB sessions; 60 in which the second worker was observed waiting on a lock, 7 in which it must not or did not wait (A5a, A5b, C15b x2, E24 x3); server deadlocks +0 in every pair; attempts 1/1 (62) or 1/2 (5, the intentional restarts); rollbacks consistent with refusals.** The server's `Innodb_deadlocks` read 18 before and 18 after.

**What moves `Innodb_deadlocks` (so the counter is not misread; corrected after the final review).** The server-wide counter read **15** at the CP6 review, **18** after full run 1, 19 after one extra run of `TicketAttributionConcurrencyTest`, **22** after full run 2, **24** after my per-directory measurement of `Feature/Tickets`, 24 at the start of the final review and 26 at the start of CP8 (after the final review's own per-file runs). Two pre-existing tests move it, and the final review measured both alone on the idle server: `TicketAttributionConcurrencyTest` **+1**, which is **deliberate** (its T8 provokes "a REAL InnoDB deadlock inside the reference INSERT's savepoint, against a peer process"), and **`TicketReferenceIssuingTest` +1**, which is the **H8 duplicate-key deadlock** (a race on `tickets_ticket_number_unique`, a shared lock and an insert-intention lock, visible in `SHOW ENGINE INNODB STATUS`), an accidental race, not a provoked one. (An earlier version of this paragraph said the ticket-reference test measured +0; the file measured was the Unit `TicketReferenceTest`, a different file.) A full run adds **+3**: T8, H8, and **one further increment that was not conclusively isolated** (that it comes from a pre-existing file is a reasonable inference from the evidence, not an identified event; the final review's per-file runs of 18 other multi-session files, including `tests/Historical`, the Organization deletion and Directory files, partner support and the ORG-1 invitation files, found none). Measured at 0 on the idle server: the ORG-2 matrix, the four ORG-1 concurrency files, the permission-cache tests, `CleanupE2eTicketsConcurrencyTest`, `NotificationSendTimeEligibilityTest` and the mutation probes. **What is certified is the ORG-2 two-connection matrix's delta of 0 (independently verified by the reviewer); it is not a statement that the application, or a full suite run, is free of deadlocks**, and the counter is server-global and meaningful only because these suites run one at a time.

**Focused suites run on final code (CP7 additions):** `ConflictResponseTest` 6; `G2` 3; `C15d` 2; `C20`/`C20b` 4; `OrganizationInvitationOperatorTest` "Invitations card" 14 (including the four-name escaping dataset); `Feature/Console` 161; command-touching files 73.

**Mutation probes added by CP7** (each defect reintroduced in the production file; the named tests fail; the file is restored and its sha256 compared; none is in the diff):

| Probe | Defect | Observed failure | Restored |
|---|---|---|---|
| N1a | `CrmService::RESTART_ATTEMPTS` 5 to 6 | the update and the deletion exhaustion tests fail (6 freezes, not 5; the worker succeeds on the 6th); the "success on the 5th" test still passes, as it should | yes |
| N1b | `RESTART_ATTEMPTS` 5 to 4 | all three `G2` tests fail (the bound test no longer succeeds on attempt 5) | yes |
| N2 | the affiliation read once and remembered across attempts (stale state reused) | all three `G2` tests fail: the engine shows the worker holding the stale Organization, not the one the Person is in | yes |
| N3 | the affiliation not re-checked under the Person lock | all three `G2` tests fail: the write proceeds on the stale lock set (`ok`, not a conflict) | yes |
| N4 | F-1 reintroduced (plain membership read) | `C15d` removal-first fails: a former member removed O's link; the other order still passes | yes |

No probe in this table failed to demonstrate its regression. (M9 of CP6 remains the one inconclusive experiment, excluded from certification evidence, 15.7.)

**Frontend (final tree; regenerated Wayfinder routes, `node_modules` copied from the main checkout read-only because the lockfiles are byte-identical; both are gitignored and not in the diff):** `npm run typecheck` pass; `npm run lint` (`--max-warnings=0`) pass; `npm run format:check` pass; `npm run test:unit` **118 files, 2006 tests passed**; `npm run build` pass (10 s). No file under `resources/js` was changed by ORG-2 or CP7, so the wire contracts are unchanged; `tsc` passing against the freshly generated routes confirms that the legacy route names (`projects.companies.sync`, `crm.*`) and props the pages import still exist. `ProjectController::syncCompanies` still answers an Inertia PUT with a redirect, and the page already renders `errors.companies`.

**Browser suites: NOT run; deferred to hosted CI.** The Playwright suite needs a web server, a queue worker, a Mailpit sink, a database seeded by `DevSeeder` and host `php` for its global teardown, which executes `artisan e2e:cleanup-*` against the application root. No isolated stack with all of those was available, and the ordinary development stack is forbidden. Building one was judged riskier than the value it adds, because the Blade and Inertia pages ORG-2 changed are rendered and asserted by the Feature suites (`ConflictResponseTest` follows the real redirect and asserts the alert). Nothing in this record claims a browser result. The customer-pilot specification and the Directory navigation specification are required hosted-CI gates before merge.

### 16.5 Release safeguards

* **Fresh install** (`migrate:fresh --force` on the isolated database, then): `database:verify` exit 0 ("matches the supported profile"); `organizations:preflight` exit 0, no blocking finding. **A correction found here:** the text report printed `CRM companies: 0   Companies without an organization: 0` on the canonical schema, where the Company record no longer exists. The line is now printed only on the pre-ORG-2 schema (where the preflight is the gate that decides whether the migration may run) and the canonical report says `Organizations: N` and `Classification: ...` (`none` when empty); `OrganizationPreflightCanonicalTest` pins it. JSON output is unchanged.
* **The migration's contracts** (ORG-2 refusal guards, recovery after an interrupted run, the exact schema-only rollback contract, the foreign-key manifest, the Directory-only and portal-state invariants, historical-schema compatibility, the canonical integrity snapshot) are asserted by `tests/Historical`, `tests/Feature/Database`, `tests/Unit/Rehearsal` and `IntegritySnapshotSchemasTest`, all inside both full runs. No pinned migration or rehearsal manifest was edited: no existing migration is modified, `database/rehearsal/candidates.php` is unchanged, and the only manifest change is `database/profile/foreign-keys.json`, the supported-profile foreign-key list that `database:verify` reads, changed exactly by the new and retired keys.
* **Host-parity smoke** (a throwaway `mariadb:11.4.13` with the shared-host defaults, MyISAM default engine, latin1, non-strict, Eastern time, on `org2_net`, `APP_ENV=production`; the repository script needs host PHP, so its essential steps were run by hand inside `org2_app`; the container was removed): the production guard **refuses** a database that already holds a MyISAM table (exit 1, the guard's message); `migrate --force` ran every migration to DONE including this one; `database:verify` passed; **0 non-InnoDB tables of 41**; `crm_companies` and `project_company` absent, `project_organization` InnoDB; `migrate:rollback --step=1` restored `crm_companies` (the schema-only rollback), `migrate` again and `database:verify` passed.
* **`runtime:preflight`.** Under the isolated `testing` profile it fails closed, as designed (it demands the production profile). On the throwaway host-parity server with a production-shaped environment (isolated configuration cache, database cache, session and queue, a fake non-deliverable SMTP host): refuses uncached configuration; **passes** with cached configuration, `probes_ran: true`, and leaves no probe row. The cross-context proof (`--issue` / `--verify` across web, queue and scheduler processes) was **not run**; neither were its negative controls.
* **Backup and restore on the final canonical schema** (isolated database; committed rows: a Client Organization with a full profile, a Directory-only Organization, a Person, a Project linked to both): `backup:create` exit 0 (41 tables, 73 rows), the sealed set opened with its digest, `backup:verify` exit 0, **`backup:drill` exit 0: "PASSED: the restored state equals the set and every check held"** (41 tables, 73 rows, 60 migrations, restored into `intechral_client_portal_rehearsal_restore`, then removed). The committed rows and working files were removed; the database is empty. This repeats and confirms 14.4; it is not the historical `tests/Unit/Backup` suite, which still runs on baseline fixtures and proves only that the tooling is unaffected. **Operational follow-up, outside ORG-2:** in a container without `/run/user/<uid>`, `gpg-agent` puts its sockets inside the backup tool's temporary `GNUPGHOME` and `BackupSet::removeTree()` cannot remove a socket; the drill again needed `/run/user/1000` created. A successful `backup:create` under the **actual host cron environment** is required before the customer-facing Release 1.0 deployment; the real hosting environment was not accessed in CP7.

### 16.6 Change-set and scope audit

92 paths differ from `0f69fd9` (79 modified, 13 deleted, plus 28 untracked paths, several of them directories). Inspected by category: **migration** (one new migration; no existing migration, pinned manifest or rehearsal candidate edited); **database profile** (`foreign-keys.json` only); **models and services** (`Organization`, `CrmContact`, `Project`, `User`; `OrganizationDirectory`, `CrmService`, `ProjectService`, `OrganizationAuthority`, `OrganizationPreflight`, `OrganizationRelationships`); **controllers, policy, scopes, rules** (`CompanyController`, `ContactController`, `ProjectController`, `OrganizationController`, `DashboardController`, `OrganizationPolicy` with the `manageDirectory` ability on the existing permissions, `DirectoryOperatorScope`, `AccessibleOrganization`; `OrganizationScope`, `OrganizationThroughCompanyScope`, `CrmCompany` and `AccessibleCrmCompany` deleted); **routes** (unchanged: the transitional `crm.*` names stay until ORG-3); **views** (the Directory, Person and Organization pages; the Classification selector is NOT present); **frontend** (no change under `resources/js`); **tests** (new Directory, deletion, Classification, leakage, concurrency, conflict-response, eligibility, mass-assignment, source-guard and Historical files; six earlier migration and preflight tests now run from `tests/Historical` on the pinned historical schema instead of being deleted, and the seventh deleted test file, `CompanyDeletionSafetyTest`, is superseded by `OrganizationDeletionTest`); **backup and rehearsal infrastructure** (`IntegritySnapshot` only, schema-conditional in both directions); **documentation and `AGENTS.md`**.

Confirmed: no new permission, role or delegation system (the `manageDirectory` ability reuses `portal.operator` and `crm.manage`); no unrelated feature; no ORG-3 interface or selector; no mutation or debug code remains (every production file touched by a probe was restored and checksum-verified, and a scan of the changed files finds no `dd`/`dump`/`console.log`/`TODO`/`FIXME` and no new `todo`, `skip` or `markTestIncomplete`); no test was weakened (the F-5 and F-7 changes tightened assertions; the apostrophe assertion was corrected to the escaped form with a dataset, not skipped or loosened); the CP0 `todo` controls are real tests. The index is empty (nothing staged). The existing development database, Release 0.9 staging, the main checkout and every other worktree were untouched (the main checkout's `node_modules` was read, not written).

### 16.7 Residual risks, and what blocks what

**Accepted (owner-accepted or inherited), stated, not hidden:** the role/permission-writer window (15.6); (CP7 also listed "Project writers do not recheck the active account" here; CP8 closed it for the two Project writers, 15.6); pair coverage is not a proof of every interleaving; `Innodb_deadlocks` is server-global; the Person and Project 409 pages are proved by stubs at the HTTP layer and by real exhaustion at the service layer, not end to end in a browser; route and wire names stay until ORG-3; 20 Redis-variant skips on this stack.

**Release-blocking follow-ups (none is ORG-2 code):** (1) **the ORG-1 membership-writer lock-order remediation**, [separately specified](./release-1.0-org-1-membership-lock-order-followup.md), complete with its concurrency review **before the first customer-facing Release 1.0 deployment**; (2) a successful `backup:create` under the real host cron environment (the GPG-agent socket finding) before that deployment; (3) hosted CI, including the browser suites, before this branch merges; (4) the development database holds 3 Companies and has not received the ORG-1 or ORG-2 migrations: running ORG-2 there needs the owner's Q6 authorization and a preflight.

**Owner decisions still open (as of CP7; CP8 resolved the first and the owner's ruling 11 added the second to section 12 of ORG-0):** whether Project writers should recheck the active account under the actor-row lock (implemented by CP8 for the two writers); authorization of the first ORG-2 commit and pull request (after the independent review, still open).

### 16.8 Review focus recommended for the independent pre-commit review

The 16.3 assessment (is "inherited, not corrected, owner decision" the right disposition?); the `CrmService` restart protocol and its 409 mapping; the F-1 locking membership read and its gap-lock footprint under a hot table; the claim that G-1 is liveness-only and ORG-1's, not ORG-2's; the migration's refusal and recovery guards against the CP1 conditions; tenant isolation and the leakage matrix; that nothing in the diff introduces authority from Classification, an affiliation or `crm.manage` alone.

### 16.9 Tested source state

Base `0f69fd9102d66f7f69b3d621dc5ac936d1090b96`; the fingerprint of the final code (`git diff HEAD -- src AGENTS.md` and the untracked files under `src`, both as sha256) is recorded in the handback and was verified unchanged after run 2. `git diff HEAD -- src AGENTS.md`: `2c452ffdec54a8ae29deb4d5d7df8818bf9877bb71bad7087ec3e0230eede443`; untracked files under `src` (sorted `sha256sum`, hashed): `067f8e05cab2bb0367bd55cfde2e910e35ef3823014588caa19a98330a9bab5d`. Identical before and after run 2. Documentation edits after the run do not affect it.

## 17. CP8: the final pre-commit correction pass

The final independent pre-commit review returned **ACCEPT WITH CONDITIONS**. The owner authorized one narrow pass: close the documentation findings F1 to F4, strengthen the suspended-actor guard (G-8), and resolve the Disabled-Organization Project-link policy (owner ruling 11, ORG-0 section 12). No refactoring was authorized and none was done. **One production file changed (`ProjectService`), plus tests and documentation.** Nothing was committed, pushed, merged or deployed.

### 17.1 What changed in the source (`app/Services/ProjectService.php` only)

* `lockUsers()` returns, for each row its existing locking statement found, whether the account is active (`disabled_at` null). The statement is the same one (`users`, `id in (...)`, `order by id asc`, `lock in share mode`); only the selected columns differ.
* `requireActiveActor()` (new): in `create` and in every attempt of `syncOrganizations`, right after that statement and before any other read or write, a suspended or missing actor is refused with an `AuthorizationException` (the pattern `OrganizationDirectory::enablePortalAccess` uses). Only the actor's row is judged: not the members of a new Project, so a suspended member may still be added (`C16`).
* `lockLinkableOrganizations()` returns the portal state of the Organization rows its existing shared locking statement found (it selects `portal_enabled_at`, `disabled_at` as well as `id`).
* `requireLinkable()` (new): ruling 11. An unrestricted operator is never refused. Anyone else is refused a NEW association to an Organization that is not Enabled, with the one message an unknown or hidden id gets (`The selected organization is invalid.`). In `create` every submitted id is new; in `syncOrganizations` it judges `add` (the submitted ids that are not already linked), computed under the project lock from the locking read of the links, so a retained link and a removal are never judged.
* **Not changed:** `OrganizationAuthority::holdsOperatorCapability()` and its drift test, `ProjectPolicy`, the controllers, the routes, the frontend, and the permission architecture. **Lock order is unchanged**: users (S) then Organizations (S, ascending) then the project and links; the two new reads come from statements that already exist, so no lock is taken late or newly, and the existing lock-sequence pins (`ProjectOrganizationLinksTest`, `C16`, `C17`) pass.

### 17.2 Rules, and where each is proved

| Rule | Proof |
|---|---|
| Suspended actor refused before any write, create and sync, operator and customer; members not checked; a stale in-memory `User` is still refused (the locked row decides) | `ProjectLinkAuthorityTest` (7 tests) |
| Suspension first: controlled refusal (`unauthorized`, 1 attempt, 1 rollback), no Project, column, member, link or audit but the suspension's; Project write first: completes, suspension follows | `C20`, `C20b`, both orders, real connections |
| Operator may link Enabled, Disabled and Directory-only; creating and synchronizing | `ProjectLinkAuthorityTest` |
| Non-operator: NEW link only to an Enabled Organization; Disabled, Directory-only, hidden and unknown ids give one message and write nothing | `ProjectLinkAuthorityTest`, plus the HTTP form (`errors.companies`, no success) |
| Retaining an existing link to a now-Disabled Organization is not a new association; removing it is allowed; existing links survive disabling | `ProjectLinkAuthorityTest` (including disabling through `OrganizationRelationships::disable`) |
| No Project link changes portal state, membership, partner designation or visibility | `ProjectLinkAuthorityTest` (rows compared before and after, `visibleTo` unchanged) |
| Disable vs a non-operator's NEW link, sync and create, both orders: a link committed before the disabling stays; one attempted after it is refused; an operator is never refused | `C21` (six tests), real connections |
| Hidden-link and stale-membership regressions preserved | `C15`, `C15b`, `C15c`, `C15d` unchanged and passing |

### 17.3 Concurrency

Disabling takes the Organization row exclusively (actor row first); the Project writers hold it shared while they read its state, so the pair serializes on a row they already lock and the order decides. Standalone run of both concurrency files on the corrected code, alone on the server: **82 passed, 2836 assertions** (the 76 of CP7 plus `C21` x6; `C20`/`C20b` rewritten in place), **73 pair records, 73 of 73 on two distinct sessions, 66 with the second worker observed waiting, 0 deadlocks in every pair; `Innodb_deadlocks` read 26 before and 26 after.** The whole serial focused run below also left it at 26 (26 at the start of CP8). The three-way cycle of 16.2 is unaffected by CP8 and still open in ORG-1.

### 17.4 Verification of the CP8 state

The CP7 full Pest run and frontend run (section 16) are evidence for the fingerprint of 16.9 and are **not** claimed for this state; no full run was made at CP8 (optional at this checkpoint). Run against the CP8 code on the isolated stack (`org2_app`, `org2_db`, database verified before the first write, reset with `migrate:fresh`): `Feature/Projects` **922**, `Feature/Tasks` 318, `Feature/Time` 172, `Feature/Accounts` 17, `Feature/Pilot` 237, `Unit/Architecture` 80, `Historical` 193, `Feature/Console` 162, `Feature/Database` 71; `OrganizationDirectoryConcurrencyTest` 52, `OrganizationDeletionTest` 19, `OrganizationDirectoryTest` 76, `OrganizationLeakageTest` 13, `OrganizationClassificationTest` 5, `ConflictResponseTest` 6, `OrganizationInvitationOperatorTest` 67, and the three ORG-1 concurrency files 27, 5 and 6: **all passed, none failed, none skipped by this selection.** Pint passes on 650 files; `git diff --check` is clean. Not run: a full Pest run, the frontend checks (no frontend file changed), the browser suites, hosted CI, any run with Redis.

**Mutation probes added by CP8** (each defect reintroduced in `ProjectService`; the named tests fail; the file is restored and its sha256 compared; none is in the diff; the server counter did not move):

| Probe | Defect | Result |
|---|---|---|
| P1 | active check removed from `create` | 4 tests fail (`ProjectLinkAuthorityTest` x3, `C20b`) |
| P2 | active check removed from `syncOrganizations` | 4 fail (`ProjectLinkAuthorityTest` x3, `C20`) |
| P3 | the check reads the caller's in-memory `User`, not the locked row | 2 fail (the stale-model test, `C20` suspension-first): the in-flight actor loaded before the suspension is the exact case |
| P4 | ruling 11 removed from `create` | 2 fail (a non-operator's new Disabled link; `C21` create) |
| P5 | ruling 11 removed from `syncOrganizations` | 3 fail (service, HTTP, `C21` sync) |
| P6 | ruling 11 judges every submitted id, not only the new ones | 1 fails: the retained link to a now-Disabled Organization is refused |
| P7 | ruling 11 applied to operators too | 3 fail |
| P8 | a Disabled Organization let through (only Not enabled refused) | 4 fail |

### 17.5 Residual, stated

* **The Project edit page still lists a Disabled Organization to a member who belongs to it** (the options are the visible Organizations, `ProjectPresenter::companyOptions`), so a member who selects one gets the form error above. CP8 changed no frontend file; filtering the selector belongs to the unified interface (ORG-3).
* Only the two Project writers gained the active-account check; the other writers were not audited and Info-4 stands for them.
* The role/permission-revocation window (15.6) is unchanged. The ORG-1 membership lock-order follow-up (16.2, and the corrected classification in its specification) remains separate and release-blocking.
* Remaining gates: hosted CI including the browser suites and a Redis-backed run (no skips), the ORG-1 follow-up, a `backup:create` under the host cron environment and `runtime:preflight` on the host, and the owner's authorization of the commit and pull request.

### 17.6 Source state of CP8

The files changed since the CP7 final run are exactly: `app/Services/ProjectService.php`, `tests/Feature/Projects/ProjectOrganizationConcurrencyTest.php`, the new `tests/Feature/Projects/ProjectLinkAuthorityTest.php`, and four documents (this record, the ORG-0 record, ADR-011 and the ORG-1 follow-up specification). Fingerprint (`git diff HEAD -- src AGENTS.md` sha256 `c5691de5ae528208a6d8e9239c5b8782e4e613b07ca6f759d5c6230d388a8189`; untracked files under `src`, sorted `sha256sum` hashed, `94d511ad9c83f5fce59fe3c770c0fb2cfd0a1bded522fb946b9b995909fc55dc`), base `0f69fd9`.
