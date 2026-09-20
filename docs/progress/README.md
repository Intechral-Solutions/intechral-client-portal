# Milestones

This file records major project milestones. Detailed epic-level implementation notes and known gaps live in [docs/epics/](../epics/).

---

## Milestone 1 — All Epics Implemented

**Date:** 2026-03-27
**Commit range:** `faa6d38` → `e735f39`

All 9 planned epics reached **Implemented** status. The full platform — auth, RBAC, tickets, projects, billing, time tracking, CRM, and CMS — has controllers, migrations, and views committed to `main`.

### Summary

| Epic | Title | Implemented |
|------|-------|-------------|
| EPIC-001 | Project Foundation & Dev Environment | 2026-03-24 |
| EPIC-002 | Authentication & User Onboarding | 2026-03-25 |
| EPIC-003 | Roles, Permissions & Authorization | 2026-03-25 |
| EPIC-004 | Ticket Management | 2026-03-25 |
| EPIC-005 | Project Management | 2026-03-25 |
| EPIC-006 | Billing & Invoicing | 2026-03-25 |
| EPIC-007 | Time Tracking | 2026-03-25 |
| EPIC-008 | CRM & Organizations | 2026-03-25 |
| EPIC-009 | CMS & Documentation | 2026-03-25 |

### What's next

- Formal acceptance criteria verification pass across all epics
- Test coverage (Pest feature + unit tests per module)
- Close known gaps documented in each epic file
- GitHub Actions CI pipeline
- First staging/production deployment

---

---

## Milestone 2 — MariaDB Test Parity (Epic 10A)

**Date:** 2026-06-26
**Branch:** `hardening/epic-10a-mariadb-test-parity`

The Pest feature test suite was entirely blocked (218/226 failing) because the SQLite in-memory test driver cannot execute `dropForeign()` with a string constraint name, which is required by migration `2026_03_26_120001_rename_project_tasks_to_tasks`. Switching to a dedicated MariaDB test database (`intechral_client_portal_testing`) resolved the blocker and restored full suite execution.

### What changed

| File | Change |
|---|---|
| `phpunit.xml` | Replaced `DB_CONNECTION=sqlite / DB_DATABASE=:memory:` with MariaDB test DB credentials |
| `AppServiceProvider` + `tests/TestCase.php` | Added exact environment/database safety checks at application boot and immediately before `RefreshDatabase` fires |
| `.docker/mysql/init-testing.sql` | New init SQL that creates the test DB on fresh Docker volumes |
| `docker-compose.yml` | Mounts `init-testing.sql` into MariaDB's `entrypoint-initdb.d` |
| `tests/Feature/Time/TimeTrackingTest.php` | Updated one stale test that reflected old single-timer behavior (superseded by multi-timer block system in EPIC-007, 2026-03-27) |
| `docs/architecture/docker-setup.md` | Added "Running the Test Suite" section with test DB setup commands |
| `docs/epics/EPIC-010A-mariadb-test-parity.md` | New epic doc |

### Test result summary

| | Before | After |
|---|---|---|
| Passing | 8 (unit only) | **226** |
| Failing | 218 | **0** |
| Assertions | — | 532 |
| Duration | ~16s (all failing) | ~55s |

### Why SQLite was replaced

SQLite is not a viable test database for this project. The schema uses MariaDB-specific DDL operations (dropping foreign keys by constraint name during table renames) that have no SQLite equivalent. Testing against a different engine family provides false confidence: tests could pass on SQLite while failing on the production engine, or — as here — fail on SQLite while the production DB has no issue.

### Validated commands

```bash
# Create test DB on existing setup
docker compose exec db mariadb -u root -proot -e "
  CREATE DATABASE IF NOT EXISTS \`intechral_client_portal_testing\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON \`intechral_client_portal_testing\`.* TO 'portal'@'%';
  FLUSH PRIVILEGES;
"

# Run full test suite
docker compose exec app ./vendor/bin/pest
```

### Remaining hardening gaps

- Invoice and ticket number generation race condition under concurrency
- `autoCloseResolved()` and `markOverdue()` are not registered as scheduled commands
- Time-to-invoice export workflow is incomplete (`time_entries.invoice_id` is never populated)
- No GitHub Actions CI pipeline

---

## Milestone 3 — Tenant Scoping Correctness (Epic 10B)

**Date:** 2026-06-30

Tenant isolation now follows the implemented many-to-many organization membership schema. CRM companies use direct membership scoping, contacts scope through their company, and organizations scope through the membership pivot. Operators retain cross-tenant access, multi-org users see all joined organizations, and users without memberships receive an empty tenant view.

The milestone also closes request-validation bypasses that allowed guessed company IDs to be submitted when creating contacts, tickets, or linking projects. MariaDB-backed regression coverage exercises model queries, route binding, member management, and adjacent ticket/project/billing behavior.

Final result: **233/233 tests passing, 561 assertions** against `intechral_client_portal_testing`.

See [EPIC-010B](../epics/EPIC-010B-tenant-scoping.md) for the tenant-scoping matrix and verification details.

---

## Milestone 4 — Stuck Timer Integrity Preflight

**Date:** 2026-06-30

An abandoned local timer from 2026-03-27 could not be stopped because its elapsed duration exceeded the `UNSIGNED SMALLINT` capacity of `time_entries.duration_minutes`. MariaDB rejected the update, while both timer interfaces ignored the unsuccessful HTTP status and temporarily hid the unchanged timer.

Timer state is now canonicalized as running only when `timer_started_at` is non-null and `stopped_at` is null. Duration storage is widened, stopping is transactional and idempotent, partially stopped legacy rows normalize safely, long allocation runs use batched upserts, and model guards reject impossible stopped/running or billed/running writes. Regression coverage includes the 95-day overflow case, partial legacy state, invalid-state guards, and overlapping concurrent allocation.

Local entry 10 was stopped through `TimeEntryService` without deletion: 136,824 minutes were retained and 9,123 allocation blocks finalized. The canonical active-timer count is now zero. See [EPIC-007](../epics/EPIC-007-time-tracking.md#canonical-timer-state-2026-06-30) for invariants and the repair command.

Final result: **237/237 Pest tests passing (586 assertions)** and **180/180 files passing Pint**.

---

## Milestone 5 — Billed Time-Entry Locking (Epic 10C)

**Date:** 2026-06-30
**Branch:** `hardening/epic-10c-billed-time-entry-locking`

Time entries are now immutable when either the legacy `billed` flag is true or `invoice_id` is populated. The lock is centralized on the model and enforced by transactional service methods that reload and row-lock current database state, preventing stale-model bypasses.

Update, deletion, timer description, timer stop, and allocation changes all share the same boundary. Allocation also protects locked sibling entries from indirect mutation during slot redistribution. Locked entries remain visible to their owners and in operator reports, while operators receive no ordinary mutation bypass.

Regression coverage exercises billed and invoice-linked states, visibility, legacy inconsistent timers, allocation targets and siblings, operator routes, and persisted-state concurrency protection. See [EPIC-010C](../epics/EPIC-010C-billed-time-entry-locking.md) for the full lock and mutation matrices.

Final result: **247/247 Pest tests passing (636 assertions)** and **181/181 files passing Pint**.

<!-- Add future milestones below -->
