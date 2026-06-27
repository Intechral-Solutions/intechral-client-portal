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
| `tests/TestCase.php` | Added `refreshApplication()` safety guard that rejects non-testing database names before `RefreshDatabase` fires |
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

### Remaining gaps (Epic 10B candidates)

- `OrganizationScope` reads `$user->organization_id` which does not exist on the `users` table — org data filtering silently does nothing for non-operators
- `TimeEntryService::update()` does not enforce the `billed` lock on time entries
- Invoice and ticket number generation race condition under concurrency
- `autoCloseResolved()` and `markOverdue()` are not registered as scheduled commands
- Time-to-invoice export workflow is incomplete (`time_entries.invoice_id` is never populated)
- No GitHub Actions CI pipeline

<!-- Add future milestones below -->
