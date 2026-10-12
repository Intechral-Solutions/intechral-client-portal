# Migration rehearsal harness (R0.9-1A, extended in R0.9-1B)

The Release 0.9 data contract ([§8 of the scope document](../product/release-0.9-scope-and-architecture.md#8-migration-rehearsal-contract)) says a risky migration is proven on populated data in a disposable database before it is trusted. This harness is that proof. It builds a known populated baseline, snapshots it, applies an **approved candidate migration**, snapshots again, reports any difference nobody announced, and proves a backup of the result restores to an identical snapshot.

It is a **rehearsal tool**. It is not a production backup, restore or deployment tool (see [Not production tooling](#not-production-tooling)).

## Workflow

```bash
./dev rehearse:provision          # once per Docker volume (a fresh volume does it automatically)
./dev rehearse                    # baseline + snapshot + backup/restore round trip. No candidate.
./dev rehearse --candidate=<name> # also apply one approved candidate migration and compare
./dev rehearse:clean              # empty both rehearsal databases, delete rehearsal files and any kept backups
```

| Option | Meaning |
|--------|---------|
| `--candidate=<name>` (repeatable) | Apply an approved candidate migration after the baseline snapshot. Names only. |
| `--no-restore` | Skip the backup/restore round trip. |
| `--keep-backup` | Keep this run's backup set even if the run passes. |
| `--json` | Print the report as JSON. |

Anything else is refused by `./dev` before Docker is touched.

**Exit codes** (`rehearsal:run`, and so `./dev rehearse`): `0` every invariant held; `1` the rehearsal ran and found a difference, a broken invariant, a failed round trip, or could not remove its own temporary backup; `2` it **refused to start** (unsafe target, unapproved candidate, another rehearsal running) and changed nothing.

A run with no candidate proves the baseline, the snapshot and the round trip, and says in its report that it proves **nothing about any future migration**.

## What it uses

| Resource | Name |
|----------|------|
| Primary database | `intechral_client_portal_rehearsal` (baseline is built, migrated, snapshotted here) |
| Restore database | `intechral_client_portal_rehearsal_restore` (a backup of the primary is restored here) |
| Private files | `src/storage/rehearsal/{primary,restore}/files/`, backup sets in `src/storage/rehearsal/backups/` (git-ignored, mode 0700) |

Both databases live on the Compose `db` service. The names are **constants in code**, not configuration. The connection is built at run time from the application's own credentials, and is deliberately **not** an entry in `config/database.php`, so `php artisan migrate --database=...` cannot reach it. Nothing the harness does reads `DB_DATABASE`, `DB_HOST` or `DB_URL`.

### Database authority

`.docker/mysql/init-rehearsal.sql` creates the two databases and grants the application account `portal` `ALL` on **those two only** (underscores escaped, because an unescaped `_` in a `GRANT` database name is a one-character wildcard). It adds no global privilege. It runs automatically on a fresh volume (mounted into `docker-entrypoint-initdb.d`, so hosted CI gets it too) and by hand with `./dev rehearse:provision`, the only step that uses the database root login. `./dev rehearse:provision` first checks that the SQL file contains nothing but `CREATE DATABASE IF NOT EXISTS` and `GRANT ALL` for exactly those two databases and `FLUSH PRIVILEGES`, and refuses otherwise.

The shared `portal` account **also** holds `ALL` on `portal` and the Pest testing database. The harness therefore cannot rely on the grants to keep it away from them; the guards below do that.

## Safety guards

Checked immediately **before every destructive step** (reset, clean, restore, migrate), not once at start-up. Failures are classified by *when* they happen, because the two promise different things:

- **A refusal, before anything is changed** (an unapproved, undeclared or drifted migration, an unsafe target, another rehearsal holding the lock): exit **2**, and the command prints "Nothing was changed". For `rehearse:clean` every target is checked before the first one is touched, so a refusal there changes nothing either.
- **A failure after the rehearsal began** (a candidate that throws, a guard failing on a later capture, a failed restore): exit **1**. The report names the step ("Rehearsal stopped during ...") and says the rehearsal databases and files **may be partly changed**; it never says nothing changed. They hold fixtures only and only the rehearsal resources are ever touched, so `./dev rehearse:clean` or the next `./dev rehearse` resets them. A `rehearse:clean` that stops part-way exits 1 and names the step that failed and what was already cleaned.

1. **Environment.** The runtime environment *and* the configured `app.env` must each be `local` or `testing` (`--env` changes only the first). `production` and anything unrecognised is refused.
2. **Identity by configuration.** MySQL/MariaDB; host exactly `db`; port 3306; database exactly the reserved name for its role; not `portal`, not the testing database, not a system schema, not the database the application is configured to use; no URL, socket or read/write split.
3. **Identity on the wire.** The live connection must report that database. The account must not be `root` and must hold no global privilege (`SHOW GRANTS`).
4. **Ownership.** The database must be empty or carry the harness's marker table (signature and role). A database with tables and no marker is "unidentified" and is never touched.
5. **Files.** A directory is emptied or deleted only if it is empty/absent or carries the harness's marker file for that exact kind, is inside `storage/rehearsal`, and is not (or behind) a symbolic link.
6. **One rehearsal at a time, from any path.** A database lock (`GET_LOCK` on the rehearsal database server, name `intechral_client_portal_rehearsal`, held on its own session) refuses a second run immediately, and names the session holding it. It is shared by `./dev rehearse`, `rehearse:clean` and the Pest tests, which a file under `storage/` could not be (the tests move `storage_path()`). It is bound to the holder's database session, so a crashed or killed process releases it on the server and a stale lock cannot outlive its holder; it is re-verified on every re-entry and a lost lock stops the run. The Pest tests hold it from their first rehearsal test until the process ends, so a concurrent `./dev rehearse`, a second Pest process or a `--parallel` worker is refused rather than resetting the databases underneath them.

`./dev rehearse` adds a layer in front: it resolves the normal development target and refuses unless that is `local`/`portal`/`db` (so nothing is redirected), pins `APP_ENV=local DB_HOST=db`, and passes only the allow-listed options. `./dev` cannot be asked to rehearse against `portal`, the testing database, or production: the target is not an argument.

## Baseline and fixtures

The baseline is **pinned**: `database/rehearsal/baseline.php` lists, in migration order, every migration the rehearsal builds its starting schema from, each with the SHA-256 of the file as it shipped. `database/migrations` must be exactly that list plus the approved candidates in `candidates.php`, and anything else is refused with exit 2 before the database is touched, naming the precise problem:

- an **undeclared migration file** (in neither list): it does not silently join the baseline, where it would be installed on an empty schema and never tested against populated data;
- a **missing** or **edited** baseline file (drift);
- a baseline out of order, a name in both lists, or a **candidate that sorts before the last baseline migration** (it must run after the fixtures, as a real upgrade would).

The run then applies only the pinned baseline, checks the `migrations` table records exactly those names, seeds the fixtures, and only afterwards applies the selected candidates; it fails if the fixtures wrote no rows or a candidate was already applied. The report says `applied_after_fixtures` and how many rows existed first. A run with no candidate lists the approved candidates it did *not* exercise. When a candidate has shipped and a later package needs its columns in the fixtures, its entry moves from `candidates.php` to the end of `baseline.php` in the same reviewed change. Fixtures (`BaselineFixtures`) are deterministic: fixed ids, timestamps and values, inserted directly (no events, mail, queue or activity-log side effects), written against the schema that exists **before** a candidate (C5: `users.disabled_at` is never seeded before its migration). Nothing in them is a real person or credential (`.test` addresses, inert password hashes).

Coverage today: 203 rows in 33 tables and 4 real attachment files: users (operators, customers, an SSO-only user with no password, a custom role), invitations in each state, tickets in every status with replies, internal notes, status history and attachments, projects with customer members, task kinds, comments, checklists, dependencies, milestones, time entries (running, stopped, billed-locked) with blocks, retained invoices with items and payments, organizations and CRM metadata, and activity-log rows.

## Integrity snapshot

`IntegritySnapshot` captures, from a database and its private files:

- **schema** (tables, columns, column and table collations, primary and unique keys, non-unique indexes by table and column list, foreign keys with both their `ON DELETE` and `ON UPDATE` rules);
- per-table **row counts** and a **digest of every row**, pinned to the baseline's columns so a candidate that adds a column does not change old rows' digests;
- ~60 **checks that must be zero**: foreign-key orphans (declared and logical), attachment rows without files and files without rows, ticket numbers, tasks, milestones, dependencies, time entries and blocks, invoice arithmetic, the EPIC-010D preflight gates, the project audit. A check that **cannot run counts as a failure**: a snapshot never passes by being unable to look;
- **facts** that must not move (preflight figures, next ticket id, role grants, counts by status);
- **files** (size and SHA-256);
- the migrations recorded as run.

`SnapshotComparator` reports every difference that was not declared: lost, added or altered rows, lost or changed files, schema changes, changed foreign-key rules (delete or update), changed indexes or collations, failing checks, unexpected migrations. A declared change that did **not** happen is also a difference.

## Candidate migrations

A candidate is selected **by name** (`--candidate=2026_10_12_000001_example`), never by path, wildcard or `all`. It must be in `database/rehearsal/candidates.php` (the review record: summary, SHA-256, declared `schema` changes in the manifest notation, declared `data` tables), present in `database/migrations`, sort after the pinned baseline, and its file must still hash to the recorded SHA-256. Duplicates, stale manifest entries and anything else are refused with exit 2 before the database is touched.

The manifest holds the three **R0.9-1B** candidates (see [Release 0.9 preservation migrations](#release-09-preservation-migrations-r09-1b)). `./dev rehearse` with no `--candidate` exercises none of them and says so.

Schema notation: `table+`/`table-`, `column+`/`column-`/`column~` (type, nullability), `collation~:t.c`, `tablecollation~:t`, `primary~`, `unique+`/`unique-`, `index+:t(a,b)`/`index-:t(a,b)`, `fk+`/`fk-:t.c->p.c:DELETE/UPDATE` (an added or removed key states both rules), `fk~:t.c->p.c:OLD>NEW` (ON DELETE) and `fku~:t.c->p.c:OLD>NEW` (ON UPDATE). A rename of an index is not a change.

The comparison demands an *exact* match: the observed schema delta must equal the declared one, and existing rows may change only in declared `data` tables.

## Backup and restore verification

After the comparison the harness backs up the primary (database and files), restores into the restore database, snapshots both and requires them to be identical (the same SHA-256 and no differences).

- The database is dumped in one `START TRANSACTION WITH CONSISTENT SNAPSHOT`; files are copied **after** it, so every attachment row in the dump has its file. This is sufficient only while committed attachment files are never deleted or overwritten (§7.1).
- Before anything is restored the set is verified: manifest, dump checksum, completion marker, every file against its recorded size and checksum, **and permissions**. An incomplete, truncated, altered or non-private set is refused and the restore database is untouched. The set must lie inside `storage/rehearsal/backups`.
- Row counts are checked after loading, and the restored files must equal the inventory.

## Resource lifecycle and permissions

- **Permissions.** Every backup artifact is created under `umask 0077`, then set explicitly to **0700 directories / 0600 files** and *verified*; a set that is group- or world-accessible fails the backup. The umask is restored afterwards, even on failure. Fixture and restored attachment files are 0600 too, and so is the marker. Messages name paths only, never contents.
- **A passing run** removes the backup set it made. Only that set: removal requires a direct child of the marked backups root with the exact generated name, not a link, and a manifest that names that very set.
- **A failing run** (difference, broken invariant, failed round trip) **retains** its backup set, private, for diagnosis, and the report says so. `./dev rehearse:clean` removes it. `--keep-backup` retains a passing run's set the same way. A partially written set is removed immediately.
- **Cleanup failures are visible**: a backup that cannot be removed is reported and fails the run (exit 1); `rehearse:clean` exits non-zero if it did not complete. A directory that cannot be fully removed (usually a permission) is an error, not a silent success, and its marker is deleted **last**, so a partly removed directory is still provably the harness's own and the cleanup can simply be run again once the cause is fixed.
- **The rehearsal databases and their `primary`/`restore` file roots are not removed after a run.** They *are* the rehearsal environment: the next run resets them, and they hold fixtures only. `rehearse:clean` empties them.
- `rehearse:clean` acts only on the two rehearsal databases and on directories carrying the harness marker beneath `storage/rehearsal`; it never reaches `portal`, the testing database, `storage/app/private`, or anything unmarked.

## Tests

- `./dev test:php --filter=Rehearsal` runs `tests/Unit/Rehearsal/*` (needs the rehearsal databases; run `./dev rehearse:provision` if missing). They take storage and candidate migrations from throw-away directories (the real `storage/rehearsal` and the committed manifests are untouched) but **use the two real rehearsal databases**, and they hold the rehearsal lock for the life of the test process, so a concurrent `./dev rehearse` or a second Pest process is refused. Do not use `--parallel`. Files: target safety, migration-set pinning, schema-snapshot coverage, the lock (including a real child process killed with SIGKILL), backup and restore, baseline integrity, the whole run and its failure classification, and the real R0.9-1B candidates on populated data (`PreservationRehearsalTest`).
- `tests/Feature/Database/*` runs against the testing database and covers the schema the R0.9-1B migrations leave behind, the migrations themselves (down, up, preflight, resume, never unprotected) and what the database now does when a referenced user or company is deleted.
- `./dev check` runs the CLI self-tests (`scripts/dev/tests/run.sh`), which cover the `rehearse*` commands against a stubbed Docker, so they cannot reach a database.

## Known limitations

- Fixtures only. Production-derived data in rehearsal is undecided (H8).
- The backup is a **PHP logical dump** (the app container has no MariaDB client): base tables only; no views, triggers or routines (the schema has none). Not `mariadb-dump`.
- `APP_KEY` and `.env` are not in the set; two-factor secrets in the database cannot be read back without the original key (H9, open).
- The ticket-number high-water mark (§7.3) is not exercised.
- Hosted CI is **not yet verified**. This package adds no workflow, but the existing hosted Pest job starts the Compose `db` service, whose init SQL provisions the rehearsal databases, so the rehearsal tests are expected to run there once this is pushed. They have not been run there yet; confirm the first hosted run (see [Review follow-ups](#review-follow-ups)).
- A migration that removes something a snapshot check reads makes that check "unavailable", which fails the run until the harness is updated alongside the migration. That is intended.
- The shared account's `ALL` on `portal` and the testing database is an existing property of the dev stack; the harness avoids them by guard, not by privilege.
- Foreign keys and indexes are compared by their columns, not their names: a rename, a duplicate index on the same columns, or an index changing only its type (for example BTREE to FULLTEXT) is not detected (L3). Migrations that depend on names verify them themselves, as M2 and M3 do.

## Not production tooling

Rehearsal-only: the reserved databases, fixtures, the PHP dump and restore, the marker-based cleanup, `./dev rehearse*`. A production backup, restore and deployment package is a separate piece of work that must **rehearse its own tooling** with this harness, not reuse the dump.

## Review follow-ups

The independent review (Opus 5.5) of R0.9-1A concluded **SAFE TO COMMIT**, with no Critical, High or Medium findings. Its findings are numbered J-1 to J-12. J-6 and J-7 (a code comment and this document's wording) were corrected with that commit. R0.9-1B completed the obligations that were due before it:

| Id | Status | What changed |
|----|--------|--------------|
| J-1 | **Done in R0.9-1B** | The baseline is pinned (`baseline.php`, SHA-256 per file, ordered). An undeclared, missing or edited migration file, a misordered baseline, and a candidate that sorts before the baseline are refused with exit 2 and the precise drift. Candidates run only after the fixtures, with evidence in the report. |
| J-2 | **Done in R0.9-1B** | The snapshot includes foreign-key `ON UPDATE` rules, non-unique indexes and column and table collations, each with its own declarable token, alongside the existing `ON DELETE` detection. |
| J-3 | **Done in R0.9-1B** | A refusal before anything changes (exit 2, "Nothing was changed") is distinguished from a failure after the rehearsal began (exit 1, the step named, resources "may be partly changed"). A cleanup that stops part-way names the step and what was already cleaned. |
| J-4 | **Done in R0.9-1B** | One database-server lock shared by `./dev rehearse`, `rehearse:clean` and the Pest tests, released by the server when the holder dies. |

Still open:

| When | Id | Obligation |
|------|----|------------|
| **Before data-transforming migrations** | J-5 | Make a `data` declaration unable to hide unexpected row disappearance: today it skips both the row-count and the per-row check for that table. Not needed for the tightening migrations of R0.9-1B (they declare no `data` tables). |
| Rehearsal harness (from the R0.9-1B review) | L3 | Detect foreign-key and index **name** changes and a duplicate index on the same columns. The snapshot compares foreign keys and indexes by their columns only. For M2 and M3, their own post-migration `verify()` and `PreservationSchemaTest` check the names. |

The independent review (Opus 5.5) of R0.9-1B concluded **SAFE TO COMMIT**, with no Critical, High or Medium findings. Its two application findings are carried by the Release 0.9 plan, not by the harness: **L1** (an attempted Eloquent user deletion strips roles and permissions before the database refuses it; R0.9-1C) and **L2** (a CRM company delete with linked projects ends in HTTP 500; addressed in R0.9-1D, which is implemented and passed independent review, SAFE TO COMMIT). See the [Release 0.9 status](../product/release-0.9-scope-and-architecture.md#11-work-packages-and-parallel-hosting-discovery).

Informational findings, accepted and not expanding this package:

- **J-8.** The provisioning-SQL validator (`rehearsal_sql_violations`) strips any line starting with `--`, which is looser than the MariaDB client's comment rule. On MariaDB 10.11 such a line is not executed by the client, so it is not exploitable; keep the SQL file simple.
- **J-9.** A restore runs the dump's lines as the shared `portal` account, which can reach the development and testing databases. The checksum sits beside the dump, so it is an integrity check, not a security boundary. The dump is only ever the set this run just wrote, in a 0700 directory.
- **J-10.** Two same-user windows, with no cross-user path: restored files are briefly 0644 inside a 0700 tree, and `BackupRestore::discard` checks a path and then deletes it without closing a symlink-swap window.
- **J-11.** Row digests and snapshot hashes call `json_encode` without `JSON_THROW_ON_ERROR`, so invalid UTF-8 would not fail loudly. There are no binary or floating-point columns today; revisit if one is added.
- **J-12.** Pre-existing, outside this package: `.docker/mysql/init-testing.sql` grants on `intechral_client_portal_testing` with unescaped underscores (a one-character wildcard). The rehearsal SQL escapes them correctly.

**Hosted CI obligations.** The rehearsal Pest tests (`tests/Unit/Rehearsal/*`, several minutes) and the new `tests/Feature/Database/*` are expected to run in the existing hosted Pest job once pushed, because the Compose `db` service mounts `init-rehearsal.sql`. Before relying on that: confirm the first hosted run provisions the two rehearsal databases and passes (it now also needs `GET_LOCK`, which every MariaDB account has); and note the run adds that time to the job. The rehearsal lock makes a hosted run that overlapped another rehearsal fail loudly instead of corrupting it. Hosted validation is not yet complete.

## Release 0.9 preservation migrations (R0.9-1B)

Three candidate migrations implement the schema decisions of the [Release 0.9 gate](../product/release-0.9-scope-and-architecture.md): H1 and H2 in full, and the schema portion of H3. Each is in `candidates.php` with its SHA-256 and the exact structural change it declares.

| Migration | Decision | Change |
|-----------|----------|--------|
| `2026_10_12_000001_add_disabled_at_to_users_table` | H3 (schema only) | `users.disabled_at`, a nullable timestamp, appended as the last column. No backfill, index, default or enforcement; suspension itself belongs to R0.9-1C. |
| `2026_10_12_000002_restrict_user_business_record_deletes` | H1 | 13 foreign keys to `users` change `ON DELETE CASCADE` to `RESTRICT`: `tickets.user_id`, `ticket_replies.user_id`, `ticket_attachments.user_id`, `time_entries.user_id`, `task_comments.user_id`, `tasks.created_by`, `projects.created_by`, `organizations.owner_id`, `crm_companies.created_by`, `crm_contacts.created_by`, `cms_pages.created_by`, `invoices.client_id`, `invoices.created_by`. |
| `2026_10_12_000003_restrict_company_project_association_deletes` | H2 | `project_company.crm_company_id` changes `ON DELETE CASCADE` to `RESTRICT`. |

**Method.** MariaDB DDL is not transactional, and a foreign key cannot be dropped and re-added under the same name in one statement (error 121, verified on 10.11). So each key is replaced in two single-statement `ALTER`s, each atomic (a failing `ALTER` leaves the old constraint in place): first the `CASCADE` key is dropped and a `RESTRICT` key added under a temporary name, then that is swapped back to the original name. The column has exactly one foreign key at every moment, constraint names are preserved (including the two tables renamed after their constraints were created: `project_tasks_created_by_foreign`, `project_task_comments_user_id_foreign`), and a run interrupted between the steps leaves a correct `RESTRICT` key that a re-run finishes. Foreign-key checks are never disabled. Before any change the migration checks every key against the approved inventory (live name, parent, rule, `ON UPDATE RESTRICT`, `NOT NULL`) and every child column for orphans, and stops with a list and changes nothing if anything differs. `ON UPDATE` is left unspecified, which preserves the existing `RESTRICT`.

**Class: tightening.** No row is read, moved or rewritten, and none can become invalid; `RESTRICT` only refuses a *later* parent delete. The previous release's code has no path that deletes a user, so it cannot violate the new rule. Per R0.9-0 section 6, run them under maintenance mode after a verified backup, and rehearse first: `./dev rehearse --candidate=<name>` for each, or all three together.

**Consequences for the application, left deliberately to later packages:**

- No application path deletes a user, so M2 changes no user-facing behaviour. A future offboarding path must suspend (H3, R0.9-1C), not delete, and must not call `$user->delete()` on a user who owns records: the permission library's `deleting` hook detaches the user's roles and permissions *before* the `DELETE` that the database then refuses.
- The CRM company delete route (`DELETE /crm/companies/{company}`) has no object-level guard. After M3 the database refuses to delete a company that projects still reference, so that route fails with a database error instead of destroying the associations. R0.9-1D adds the friendly refusal for this route (HTTP 409 for JSON, back with an error for a browser; [Role Governance](../architecture/role-governance.md#crm-company-deletion-safeguard)). It is only the refusal; the broader H6 project and company delete policy is not ratified.
- Tests that committed users and relied on the cascade to clean up now delete what they created first, through `Tests\Support\CommittedUsers`, which is scoped to the given user ids and fails loudly on a leftover rather than skipping it.

## How a later package uses it

A new migration is added to `candidates.php` in the same change: summary, SHA-256 and the exact `schema` tokens it should produce (for example `fk~:tickets.user_id->users.id:CASCADE>RESTRICT`). `./dev rehearse --candidate=<name>` must pass with no undeclared difference before the migration is accepted. Fixtures stay on the pre-migration schema; once a candidate has shipped, its entry moves to the end of `baseline.php` and fixtures for the *next* candidate may use its columns, and the baseline snapshot hash changes in the same commit.
