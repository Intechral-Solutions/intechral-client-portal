# Migration rehearsal harness (R0.9-1A)

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

Checked immediately **before every destructive step** (reset, clean, restore, migrate), not once at start-up. A check that fails **before the run starts** refuses with exit 2 and changes nothing. The same guards also run before each later step, so a refusal or error **after a rehearsal has begun** (for example a candidate migration that throws, or a guard failing on a later capture) can leave the rehearsal databases and files partly changed. It still exits non-zero and only ever touches the rehearsal databases and files; run `./dev rehearse:clean` or the next `./dev rehearse` to reset them. (The command currently prints exit 2 and "Nothing was changed" for some of these; see [Review follow-ups](#review-follow-ups), J-3.)

1. **Environment.** The runtime environment *and* the configured `app.env` must each be `local` or `testing` (`--env` changes only the first). `production` and anything unrecognised is refused.
2. **Identity by configuration.** MySQL/MariaDB; host exactly `db`; port 3306; database exactly the reserved name for its role; not `portal`, not the testing database, not a system schema, not the database the application is configured to use; no URL, socket or read/write split.
3. **Identity on the wire.** The live connection must report that database. The account must not be `root` and must hold no global privilege (`SHOW GRANTS`).
4. **Ownership.** The database must be empty or carry the harness's marker table (signature and role). A database with tables and no marker is "unidentified" and is never touched.
5. **Files.** A directory is emptied or deleted only if it is empty/absent or carries the harness's marker file for that exact kind, is inside `storage/rehearsal`, and is not (or behind) a symbolic link.
6. **One rehearsal at a time.** An exclusive lock (`storage/rehearsal/.lock`) refuses a second run, which would reset the same databases underneath the first.

`./dev rehearse` adds a layer in front: it resolves the normal development target and refuses unless that is `local`/`portal`/`db` (so nothing is redirected), pins `APP_ENV=local DB_HOST=db`, and passes only the allow-listed options. `./dev` cannot be asked to rehearse against `portal`, the testing database, or production: the target is not an argument.

## Baseline and fixtures

The baseline is every migration in `database/migrations` **except** those named in `database/rehearsal/candidates.php`. Fixtures (`BaselineFixtures`) are deterministic: fixed ids, timestamps and values, inserted directly (no events, mail, queue or activity-log side effects), written against the schema that exists **before** a candidate (C5: `users.disabled_at` is never seeded before its migration). Nothing in them is a real person or credential (`.test` addresses, inert password hashes).

Coverage today: 203 rows in 33 tables and 4 real attachment files: users (operators, customers, an SSO-only user with no password, a custom role), invitations in each state, tickets in every status with replies, internal notes, status history and attachments, projects with customer members, task kinds, comments, checklists, dependencies, milestones, time entries (running, stopped, billed-locked) with blocks, retained invoices with items and payments, organizations and CRM metadata, and activity-log rows.

## Integrity snapshot

`IntegritySnapshot` captures, from a database and its private files:

- **schema** (tables, columns, primary and unique keys, foreign keys with their rules);
- per-table **row counts** and a **digest of every row**, pinned to the baseline's columns so a candidate that adds a column does not change old rows' digests;
- ~60 **checks that must be zero**: foreign-key orphans (declared and logical), attachment rows without files and files without rows, ticket numbers, tasks, milestones, dependencies, time entries and blocks, invoice arithmetic, the EPIC-010D preflight gates, the project audit. A check that **cannot run counts as a failure**: a snapshot never passes by being unable to look;
- **facts** that must not move (preflight figures, next ticket id, role grants, counts by status);
- **files** (size and SHA-256);
- the migrations recorded as run.

`SnapshotComparator` reports every difference that was not declared: lost, added or altered rows, lost or changed files, schema changes, changed foreign-key rules, failing checks, unexpected migrations. A declared change that did **not** happen is also a difference.

## Candidate migrations

A candidate is selected **by name** (`--candidate=2026_10_12_000001_example`), never by path, wildcard or `all`. It must be in `database/rehearsal/candidates.php` (the review record: summary, SHA-256, declared `schema` changes in the manifest notation, declared `data` tables), present in `database/migrations`, and its file must still hash to the recorded SHA-256. Duplicates, stale manifest entries and anything else are refused with exit 2 before the database is touched. The manifest is **empty at R0.9-1A**.

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
- **Cleanup failures are visible**: a backup that cannot be removed is reported and fails the run (exit 1); `rehearse:clean` exits non-zero if it did not complete.
- **The rehearsal databases and their `primary`/`restore` file roots are not removed after a run.** They *are* the rehearsal environment: the next run resets them, and they hold fixtures only. `rehearse:clean` empties them.
- `rehearse:clean` acts only on the two rehearsal databases and on directories carrying the harness marker beneath `storage/rehearsal`; it never reaches `portal`, the testing database, `storage/app/private`, or anything unmarked.

## Tests

- `./dev test:php --filter=Rehearsal` runs `tests/Unit/Rehearsal/*` (needs the rehearsal databases; run `./dev rehearse:provision` if missing). They take storage and candidate migrations from throw-away directories (the real `storage/rehearsal` and the committed manifest are untouched) but **use the two real rehearsal databases**. Do not run them while `./dev rehearse` runs, and do not use `--parallel`.
- `./dev check` runs the CLI self-tests (`scripts/dev/tests/run.sh`), which cover the `rehearse*` commands against a stubbed Docker, so they cannot reach a database.

## Known limitations

- Fixtures only. Production-derived data in rehearsal is undecided (H8).
- The backup is a **PHP logical dump** (the app container has no MariaDB client): base tables only; no views, triggers or routines (the schema has none). Not `mariadb-dump`.
- `APP_KEY` and `.env` are not in the set; two-factor secrets in the database cannot be read back without the original key (H9, open).
- The ticket-number high-water mark (§7.3) is not exercised.
- Hosted CI is **not yet verified**. This package adds no workflow, but the existing hosted Pest job starts the Compose `db` service, whose init SQL provisions the rehearsal databases, so the rehearsal tests are expected to run there once this is pushed. They have not been run there yet; confirm the first hosted run (see [Review follow-ups](#review-follow-ups)).
- A migration that removes something a snapshot check reads makes that check "unavailable", which fails the run until the harness is updated alongside the migration. That is intended.
- The shared account's `ALL` on `portal` and the testing database is an existing property of the dev stack; the harness avoids them by guard, not by privilege.

## Not production tooling

Rehearsal-only: the reserved databases, fixtures, the PHP dump and restore, the marker-based cleanup, `./dev rehearse*`. A production backup, restore and deployment package is a separate piece of work that must **rehearse its own tooling** with this harness, not reuse the dump.

## Review follow-ups

The independent review (Opus 5.5) of R0.9-1A concluded **SAFE TO COMMIT**, with no Critical, High or Medium findings. Its findings are numbered J-1 to J-12. J-6 and J-7 (a code comment and this document's wording) were corrected with the commit. The rest are **open obligations**, deliberately not implemented in R0.9-1A:

| When | Id | Obligation |
|------|----|------------|
| **Before R0.9-1B** | J-1 | Pin the existing migration baseline (for example a recorded list or hash of the baseline migration names) and reject any new migration file that is not declared as an approved candidate. Today an undeclared new migration silently joins the baseline, runs before the fixtures are seeded, and is never tested against populated data while the run still passes. |
| **Before R0.9-1B** | J-2 | Include foreign-key `ON UPDATE` rules, relevant non-unique indexes and collation in the schema snapshot. They are not captured today, so a migration that changes only those passes undetected. (No current foreign key uses a non-default `ON UPDATE` rule.) |
| Recommended during R0.9-1B preparation | J-3 | Distinguish an unsafe **pre-flight refusal** from an exception **after** destructive rehearsal work has started. Both exit 2 and print "Nothing was changed" today, which is wrong for the second. |
| Recommended during R0.9-1B preparation | J-4 | Use a lock shared by Pest and CLI rehearsal runs (for example a database-level `GET_LOCK`). The file lock is under `storage_path()`, and the tests use a throw-away storage path, so Pest and `./dev rehearse` do not exclude each other. Do this before relying on simultaneous or hosted-CI executions. |
| **Before data-transforming migrations** | J-5 | Make a `data` declaration unable to hide unexpected row disappearance: today it skips both the row-count and the per-row check for that table. Not needed for the foreign-key tightening in R0.9-1B. |

Informational findings, accepted and not expanding this package:

- **J-8.** The provisioning-SQL validator (`rehearsal_sql_violations`) strips any line starting with `--`, which is looser than the MariaDB client's comment rule. On MariaDB 10.11 such a line is not executed by the client, so it is not exploitable; keep the SQL file simple.
- **J-9.** A restore runs the dump's lines as the shared `portal` account, which can reach the development and testing databases. The checksum sits beside the dump, so it is an integrity check, not a security boundary. The dump is only ever the set this run just wrote, in a 0700 directory.
- **J-10.** Two same-user windows, with no cross-user path: restored files are briefly 0644 inside a 0700 tree, and `BackupRestore::discard` checks a path and then deletes it without closing a symlink-swap window.
- **J-11.** Row digests and snapshot hashes call `json_encode` without `JSON_THROW_ON_ERROR`, so invalid UTF-8 would not fail loudly. There are no binary or floating-point columns today; revisit if one is added.
- **J-12.** Pre-existing, outside this package: `.docker/mysql/init-testing.sql` grants on `intechral_client_portal_testing` with unescaped underscores (a one-character wildcard). The rehearsal SQL escapes them correctly.

**Hosted CI obligations.** The rehearsal Pest tests (`tests/Unit/Rehearsal/*`, about four minutes) are expected to run in the existing hosted Pest job once pushed, because the Compose `db` service mounts `init-rehearsal.sql`. Before relying on that: confirm the first hosted run provisions the two rehearsal databases and passes; confirm the hosted job does not run Pest in parallel or alongside another rehearsal (J-4); and note the run adds that time to the job. Hosted validation is not yet complete.

## How R0.9-1B uses it

Each R0.9-1B migration (H1 foreign-key changes, `users.disabled_at`, H2) is added to `candidates.php` in the same change: summary, SHA-256 and the exact `schema` tokens it should produce (for example `fk~:tickets.user_id->users.id:CASCADE>RESTRICT`). `./dev rehearse --candidate=<name>` must pass with no undeclared difference before the migration is accepted. Fixtures stay on the pre-migration schema; once a migration lands, fixtures for the *next* candidate may use its columns, and the baseline snapshot hash changes in the same commit. Adding the manifest entry moves the migration out of the baseline automatically.
