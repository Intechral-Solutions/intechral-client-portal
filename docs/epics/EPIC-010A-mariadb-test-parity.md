# EPIC-010A: MariaDB Test Parity

**Status:** Implemented
**Branch:** `hardening/epic-10a-mariadb-test-parity`
**Completed:** 2026-06-26

---

## Goal

Replace SQLite in-memory with a dedicated MariaDB test database so the full Pest feature suite can run against the same database engine used in production.

---

## Background

The test suite was originally configured to use SQLite (`:memory:`) via `phpunit.xml`. This was blocked entirely by migration `2026_03_26_120001_rename_project_tasks_to_tasks`, which calls `$table->dropForeign('constraint_name')` with a string argument — an operation SQLite does not support. As a result, 218 of 226 tests failed before executing, because `RefreshDatabase` could not complete the migration run.

The production (and local dev) database is MariaDB 10.11. Testing against a different engine creates a false safety net even when tests do pass. Switching to MariaDB eliminates the entire SQLite compatibility class of problems.

---

## What Was Changed

### `phpunit.xml`
- Removed: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`
- Added: `DB_CONNECTION=mysql`, `DB_HOST=db`, `DB_PORT=3306`, `DB_DATABASE=intechral_client_portal_testing`, `DB_USERNAME=portal`, `DB_PASSWORD=portal`
- All other test drivers remain: `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`

### `tests/TestCase.php`
- Added `refreshApplication()` override containing a **test database safety guard**.
- The guard runs after the app boots (so `config()` is available) but before `RefreshDatabase::setUpTraits()` fires.
- If the active database name does not contain `"testing"`, it throws a `RuntimeException` with a clear message rather than allowing migrations to wipe the wrong database.

### `.docker/mysql/init-testing.sql` (new)
- Init SQL script that creates `intechral_client_portal_testing` and grants `portal@%` full access.
- Mounted as `/docker-entrypoint-initdb.d/init-testing.sql` — runs automatically on a fresh Docker volume.

### `docker-compose.yml`
- Added volume mount for `init-testing.sql` into the `db` container's `docker-entrypoint-initdb.d/`.

### `tests/Feature/Time/TimeTrackingTest.php`
- Renamed and rewrote the stale test `"it starting a second timer stops the first"` → `"it allows multiple concurrent timers per user"`.
- The original test reflected the old single-timer behavior. `TimeEntryService::startTimer()` was changed on 2026-03-27 to allow concurrent timers as part of the multi-timer block system. The test assumption was wrong relative to the current code, not relative to MariaDB.

---

## Test Result Summary

| State | Unit | Feature | Total | Assertions |
|---|---|---|---|---|
| Before (SQLite) | 8 passed | 0 passed / 218 failed | 8 / 226 | — |
| After (MariaDB) | 8 passed | 218 passed | **226 / 226** | 532 |

Duration: ~55 seconds (inside the `app` Docker container).

---

## Acceptance Criteria

- [x] Feature tests no longer use SQLite in-memory
- [x] Dedicated MariaDB test database `intechral_client_portal_testing` exists and is documented
- [x] All 40 migrations run successfully under MariaDB 10.11
- [x] The `rename_project_tasks_to_tasks` migration that crashed SQLite succeeds on MariaDB
- [x] The full Pest feature suite executes (226/226 passing)
- [x] Safety guard prevents `RefreshDatabase` from running against a non-testing database
- [x] Runtime dev database (`portal`) is untouched
- [x] Documentation covers the local test workflow

---

## Known Remaining Gaps (deferred to Epic 10B and beyond)

These were not addressed in this epic as they are product/hardening issues, not test-environment issues:

- `OrganizationScope` reads `$user->organization_id`, which is not a column on `users` — org-scoped filtering silently does nothing for non-operators
- `TimeEntryService::update()` does not check the `billed` flag before allowing edits to invoiced entries
- Invoice and ticket number generation has a race condition under concurrent requests
- `TicketService::autoCloseResolved()` and `InvoiceService::markOverdue()` are not wired to a scheduled command
- `time_entries.invoice_id` is never populated — the time-to-invoice export workflow is incomplete
- No GitHub Actions CI pipeline
- Spatie Media Library is declared as a dependency but appears unused

---

## Definition of Done

- [x] All acceptance criteria above are checked
- [x] 226/226 Pest tests passing under MariaDB
- [x] Branch merged to `main`
