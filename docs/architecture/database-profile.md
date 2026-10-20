# Database Profile: InnoDB, UTC and utf8mb4 (Release 0.9, WP3)

**Authority:** the Release 0.9 shared-hosting runtime gate and the WP3-0A host probe of 2026-10-19. This document records what the
application now states about its own database session and tables, how that is verified, what a production migration refuses to do,
and what is still unproven. **Companions:** [Role Governance](./role-governance.md#permission-cache-security-on-the-database-store-r09-wp2)
(the epoch fence needs transactional InnoDB tables), [System Overview](./system-overview.md#cpanel-deployment).

## 1. Why the application states its own profile

The Namecheap Stellar Plus MariaDB (`11.4.13-MariaDB-cll-lve-log`, reached over a local socket) reported these server defaults to the
owner's probe (Block C, 2026-10-19). Development and CI run the same MariaDB version with friendlier defaults.

| Setting | Namecheap host (reported) | Development and CI | Consequence if the application trusted the server |
|---|---|---|---|
| `default_storage_engine` | **MyISAM** | InnoDB | the very first migration fails (below); had it not, no transactions and no foreign keys |
| `character_set_server` / `collation_server` | latin1 / `latin1_swedish_ci` | utf8mb4 / `utf8mb4_unicode_ci` | tools outside Laravel read and write latin1 |
| server `sql_mode` | `NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` (not strict) | strict | restores and imports truncate silently |
| `time_zone` | `SYSTEM`, which is **Eastern time** (`system_time_zone` EDT) | `SYSTEM`, UTC | timestamps are interpreted in Eastern time (below) |
| isolation, `innodb_lock_wait_timeout` | `REPEATABLE-READ`, 50 | same | none; the epoch fence and its tests assume them |

Laravel already overrides two of these per connection (`strict => true` sets a strict `sql_mode`; `charset`/`collation` run `SET NAMES`
and appear on every `CREATE TABLE`). Nothing overrode the storage engine or the time zone.

### What was reproduced (disposable MariaDB 11.4.13 with the host's defaults)

Evidence is kept on the development machine in `backups/dev/r09-wp3-db-profile/evidence/` (gitignored). The server was a throwaway container on a
private network, configured with exactly the values above and the container time zone `America/New_York`; no development volume or
database was touched. The unchanged code (`engine => null`, no `timezone`) was run against it:

- **`php artisan migrate` fails at the first migration**: `SQLSTATE 1071 Specified key was too long; max key length is 1000 bytes` creating
  `password_reset_tokens` (a `varchar(255)` utf8mb4 primary key is 1020 bytes and MyISAM allows 1000). It leaves a MyISAM `users` table and a
  MyISAM `migrations` table with **no** `migrations` row, so a naive retry then fails with `Table 'users' already exists`. The failure is loud,
  not silent, but it is a hard deployment blocker, and the "obvious" ways around it (shorter keys, `ALTER DATABASE`, a server change) would have
  produced a database without transactions or enforced foreign keys.
- **Timestamps are written and read in Eastern time.** The application sends UTC wall-clock strings; the session interprets them as Eastern. With
  strict mode (which Laravel sets) a UTC value in the **hour that does not exist in Eastern time on the spring-forward date** (2026-03-08
  02:00 to 02:59 UTC) is **rejected** (`SQLSTATE 22007 / 1292`): in that hour, any request that writes a `created_at` fails. Every other
  value round-trips as the same string but is **stored as the wrong instant** (4 or 5 hours off when read by anything in UTC). A database-side
  default (`failed_jobs.failed_at` uses `CURRENT_TIMESTAMP`) is Eastern wall-clock time in a UTC application.

## 2. What the application states

`config/database.php`, on both the `mysql` and `mariadb` connections, as **literals** (no environment variable can configure them away):

- `'engine' => 'InnoDB'`: every `CREATE TABLE` the application issues says `engine = InnoDB`. With `NO_ENGINE_SUBSTITUTION` (part of Laravel's
  strict mode) an unavailable InnoDB is an error, not a silent substitution.
- `'timezone' => '+00:00'`: every connection runs `SET time_zone = '+00:00'`. An offset, not a named zone, so it needs no time zone tables.
- unchanged: `strict => true`, `charset => utf8mb4`, `collation => utf8mb4_unicode_ci`.

Development and CI already run InnoDB and a UTC system time zone, so the DDL they produce and the instants they store do not change. The migration
rehearsal harness copies the `mysql` connection, so it inherits both.

## 3. Verification (read-only, fails closed)

`php artisan database:verify [--json]` checks the connection it runs on and writes nothing. It reports every violation and exits non-zero if
there is any, or if it could not look:

| Check | Violation |
|---|---|
| configuration | `engine` or `timezone` on `mysql`/`mariadb` is not the literal above |
| session (what the **Laravel connection** is actually running, not the server defaults) | `time_zone` is not `+00:00`; `sql_mode` is not strict or lacks `NO_ENGINE_SUBSTITUTION`; connection character set/collation is not `utf8mb4`/`utf8mb4_unicode_ci`; isolation is not `REPEATABLE-READ` |
| tables | any base table that is not InnoDB or not `utf8mb4_unicode_ci`; any text column not in `utf8mb4`, or not `utf8mb4_unicode_ci` (the one accepted exception is `utf8mb4_bin` on a **JSON** column: MariaDB stores JSON as `longtext` with a `json_valid` check and forces that collation, as on `activity_log.properties`) |
| foreign keys | the live set differs from `database/profile/foreign-keys.json`: missing, unexpected, or a different ON DELETE/ON UPDATE rule |
| pending migrations | the foreign-key manifest describes the schema after **every** migration, so with migrations pending (or the migration state unreadable) the foreign keys are reported as **not verified**, which is a failure |

It uses the same foreign-key reader as the migration rehearsal ([`IntegritySnapshot::foreignKeys()`](../../src/app/Support/Rehearsal/IntegritySnapshot.php),
extracted without changing its output), so there is one definition of "a foreign key" in the repository. It is deliberately not a general schema
auditor: indexes, columns and row data stay the rehearsal's business. A check that throws is reported as a violation carrying only the
exception class and SQLSTATE (a query exception's message carries the host, database and SQL).

**Maintaining the manifest.** A migration that adds, drops or changes a foreign key must update `database/profile/foreign-keys.json`. The test
`finds the migrated schema ... equal to the committed foreign-key manifest` fails until it does; run it once with
`UPDATE_DATABASE_PROFILE_MANIFEST=1` and review the diff. The manifest was generated from the disposable host-parity database after migrating
with the new configuration (59 foreign keys), and it equals the development database's schema.

## 4. Migration-time guard (production only)

`App\Support\Database\MigrationProfileGuard` listens to the migrator's `MigrationsStarted` and `MigrationsEnded` for an **`up`** run, on the connection
being migrated, and only when `APP_ENV=production`. It throws `DatabaseProfileViolation` (the migration command exits non-zero) when the
configuration, the session, or any existing table breaks the profile. Development, CI, the disposable rehearsal databases and rollbacks are unaffected.
`--pretend` is covered by the `MigrationsStarted` check (the answer is the same), but it is **not** free of side effects: Laravel creates its `migrations`
repository table before the first event, so `migrate --pretend` may still create that table (as InnoDB under the profile). The guard does not check foreign
keys (they need every migration to have run).

What a refusal means depends on which event raised it:

- **`MigrationsStarted`** refuses before any migration runs. Nothing from this run has been applied.
- **`MigrationsEnded`** refuses **after** the pending migrations have already been applied and recorded. The exit code is non-zero, but the schema change
  is done and the `migrations` table lists it. Do not treat a failed run as "nothing happened"; inspect the database.
- **A rerun with no pending migrations does not exercise the guard.** `migrate` finds nothing to run, so neither the started nor the ended check proves
  anything about the current schema. After any refusal, after a restore, and as the release gate, run `php artisan database:verify`: it is the
  **authoritative post-migration check** (session, every table's engine and collation, and the foreign keys).

Limit, shown by the negative controls: Laravel creates its `migrations` repository table **before** the first event, so with the engine
configuration missing the one table that exists at refusal is a MyISAM `migrations` table. The refusal is loud and the next `migrate` with the
configuration restored is refused again until that empty table is dropped (it holds no rows); the runbook says so.

The guard runs only when `APP_ENV=production`. **The isolated Namecheap staging rehearsal must therefore run with `APP_ENV=production`**, or it does not
exercise the guard the production release will meet. Staging is not production: it needs its **own database and credentials, its own storage, its own
domain** and safe outbound settings (mail to a sink or an owner-controlled mailbox, no live payment or other integration keys), and no production data or
external effect without the owner's explicit authorization.

## 5. Deployment order

1. Deploy code; `APP_ENV=production`, `.env` mode 600.
2. **Pre-migration checks are the guard's, not the verifier's.** `php artisan migrate --force` starts with the `MigrationsStarted` check (configuration, the
   Laravel session, and every table that already exists). There is no separate command to pass first. `php artisan database:verify` run **before** migrating
   on a database with pending migrations exits non-zero by design (it reports the pending migrations and the foreign keys as not verified); do not use it as
   a pre-migration gate, and never treat a non-zero result of any run of it as ignorable.
3. `php artisan migrate --force`. A non-zero exit stops the release; see section 4 for what each refusal means.
4. **Mandatory post-migration gate:** `php artisan database:verify` must exit 0 with nothing pending. This is the authoritative schema check; a failure
   aborts the release, including after a `MigrationsEnded` refusal or a `migrate` that found nothing to run.
5. `php artisan config:cache`, then `php artisan runtime:preflight` (must exit 0): the production runtime shape, [Runtime Preflight](./runtime-preflight.md).
6. Then `roles:sync` and the rest of the release steps.

## 6. Safe database creation, import and restoration (utf8mb4)

Do not `ALTER` an existing database to change its character set. Create a **new** database instead:

- **Create:** `CREATE DATABASE name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` (cPanel's database wizard creates it with the server default, latin1;
  the application's own `CREATE TABLE` statements name utf8mb4 explicitly, so tables are right either way, but a new table created by hand inherits the
  database default). `database:verify` reports any table or column that is not utf8mb4.
- **Dump:** `mariadb-dump --default-character-set=utf8mb4 --single-transaction`, never with `--compatible=...`; keep the default `--tz-utc` so
  TIMESTAMP values are written in UTC.
- **Restore:** `mariadb --default-character-set=utf8mb4 --init-command="SET time_zone='+00:00'; SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"`
  into a database created as above. A dump taken from this application names `ENGINE=InnoDB` on each table; a restore tool or editor that strips it would
  create MyISAM tables on this host.
- **Always finish with `php artisan database:verify`.** A restore is not accepted until it passes.
- phpMyAdmin and cPanel import tools use their own connection settings; prefer the command line for restores.

## 7. Evidence that the fix works on the host's defaults

The same disposable server, with the new code (`backups/dev/r09-wp3-db-profile/evidence/`):

- `migrate` completes (48 migrations) with 41 InnoDB tables and 59 foreign keys, and `database:verify` passes. The Pest file
  `tests/Feature/Database/DatabaseProfileTest.php` (22 tests) passes on it, including `migrate:fresh` of the suite's own database.
- The whole Pest suite (CI's command, 3,609 tests) ran on that server too: 3,588 passed, 20 skipped, and one failure that was **test code**, not the application: a
  raw `create table ... foreign key` in `SchemaSnapshotTest` named no engine, so the MyISAM default created a table that ignores foreign keys. It now says
  `engine = InnoDB`. The same suite passes on the friendly server (3,589 passed, 20 skipped). A test that creates a table by raw SQL must name its engine.
- The DST probe (UTC strings at 2026-03-08 01:59:59, 02:00:00, 02:15:00, 02:45:00, 03:00:00 and 2026-11-01 00:59:59, 01:00:00, 01:30:00, 02:00:00)
  reads back identically with an epoch error of **0** for all nine; the database default `now()` equals UTC.

Negative controls (each restored afterwards):

| Control | Result |
|---|---|
| engine configuration removed, `APP_ENV=production` | `migrate` refused: `config ... engine is not 'InnoDB'`; `database:verify` exits 1 |
| the same with the guard bypassed (`APP_ENV=local`) | the 1071 failure of §1 |
| `timezone` removed, `APP_ENV=production` | `migrate` refused (`config ... timezone`, `session time_zone is 'SYSTEM'`); the DST probe then rejects the 02:00 to 02:59 writes and is 18000 s off |
| a MyISAM table already present, profile intact | `migrate` refused: `table legacy_probe uses engine MyISAM` |
| after a clean migrate, an expected foreign key dropped | `database:verify` exits 1: `foreign key missing: tickets.assignee_id->users.id` |
| after a clean migrate, a stray MyISAM latin1 table | `database:verify` exits 1 with engine, collation and character set |
| in the Pest file, on a friendly server: engine or `timezone` configuration removed; the guard listener unregistered | the configuration, session, schema, command and guard tests fail (engine removed: 4 tests; `timezone` removed: 5; guard listener unregistered: 3) |

## 8. What this does not prove

- It does **not** change Namecheap's server settings, and says nothing about other schemas on that server.
- The host's behaviour is established from the owner's read-only probe and the reproduction above, not from running the migrations on Namecheap.
  The first host `migrate` and `database:verify` belong to isolated staging.
- **Replication topology is not proven** (`SHOW REPLICA STATUS` was denied to the account); the epoch fence still assumes no read replica.
- The guard is production-only and the manifest covers foreign keys only; indexes and data are the rehearsal's job.
