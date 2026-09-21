# EPIC-007: Time Tracking

**Status:** Implemented
**Committed:** 2026-03-25 (initial), extended 2026-03-26 and 2026-03-27

---

## Goal

Allow team members to log time against projects and tasks, with reports and billing integration.

---

## User Stories

### STORY-007-01: Manual Time Entry
**As a** project member,
**I want** to log time entries against a project or task,
**So that** billable and non-billable hours are tracked accurately.

**Acceptance Criteria:**
- [x] Time entry: project, task (optional), date, duration (HH:MM), description, billable flag
- [ ] Entries can be edited or deleted by the logger (within a configurable lock window)
- [x] Billed or invoice-linked entries are locked from ordinary mutation
- [x] Daily/weekly timesheet view for self

### STORY-007-02: Timer (Start/Stop)
**As a** project member,
**I want** to start and stop a live timer while working,
**So that** I don't need to calculate durations manually.

**Acceptance Criteria:**
- [x] Start timer button creates an in-progress entry in the DB
- [x] Timer state persisted server-side (survives page reload)
- [x] Stop timer converts in-progress entry to a completed time entry
- [x] Multiple simultaneous timers are supported by the block allocation system
- [x] Timer displays in the navigation bar while running

### STORY-007-03: Operator Time Oversight
**As a** platform operator,
**I want** to view and manage all team members' time entries,
**So that** I can approve, correct, or report on logged hours.

**Acceptance Criteria:**
- [x] Operator view: filter by user, project, date range, billable status
- [ ] Bulk approve entries (marks them as reviewed)
- [ ] Edit or delete any entry (with audit trail)

### STORY-007-04: Time Reports & Billing Export
**As a** platform operator,
**I want** time reports that can be pushed to billing,
**So that** invoices are generated from actual tracked hours.

**Acceptance Criteria:**
- [x] Report: hours by user / project / task for a date range
- [ ] One-click export of billable hours to a new invoice draft
- [ ] Exported entries marked as `invoiced` (locked from further editing)
- [x] Exportable to CSV

---

## Implementation

### What Was Built

**Migrations**
- `create_time_entries_table` — user, project, task (optional), ticket (optional), date, duration, description, billable flag, invoiced flag, stopped_at (for live timers)
- `add_ticket_id_and_stopped_at_to_time_entries` (2026-03-26) — links entries to tickets
- `create_time_entry_blocks_table` (2026-03-27) — block-based allocation for multi-timer sessions; stores block start, end, and allocation weights
- `widen_time_entry_duration_minutes` (2026-06-30) — changes duration storage from unsigned smallint to unsigned integer so abandoned long-running timers can be finalized safely

**Controllers**
- `TimeEntryController` — full user-facing time tracking:
  - `index` — timesheet view
  - `store` / `update` / `destroy` — manual entry CRUD
  - `timerStart` / `timerStop` — live timer
  - `updateTimerDescription` — patch description while running
  - `activeTimersJson` — JSON for global timer overlay
  - `contextOptions` — cascading project → task options for timer form
  - `allocationView` — block allocation chart
  - `updateBlockAllocation` — patch block weights from drag
- `Operator\TimeReportController` — `index` (all-user filtered view), `export` (CSV)

**Views**
- `time/index` — timesheet: entry list, manual entry form, active timers
- `time/allocation` — block allocation chart
- `operator/time/index` — operator view with all-user filters
- `layouts/partials/timer-overlay` — global nav overlay when timer is running
- `components/time-tracker` — timer start/stop component

**Routes** (`/time` prefix, `can:time.log`; `/operator/time`, `can:time.view_all`)

### Multi-Timer / Block Allocation (2026-03-27)

Users can run multiple concurrent timer blocks within a session. The `time_entry_blocks` table stores discrete blocks; a drag interface on the allocation view lets users adjust the proportional split of time across blocks before committing entries.

#### Allocation semantics (2026-09-21)

`time_entry_blocks.allocation_pct` is a finalized timer entry's share of one 15-minute UTC slot. For one user, date, and slot the shares sum to exactly 100%, whether the timers overlapped in wall-clock time or ran one after another inside the slot. Timer finalization and `updateBlockAllocation()` maintain this with one shared redistribution rule; billing-locked and manually overridden blocks are frozen and the rest share the remainder, weighted by seconds in the slot. The percentage is presentation and attribution metadata only: reports, the dashboard, CSV export, and billing use `duration_minutes`. See [EPIC-011D](./EPIC-011D-time-tracking-timer.md#allocation-semantics-and-invariant) for the evidence, the locked-sibling rule, and locking.

### Canonical Timer State (2026-06-30)

| State | `timer_started_at` | `stopped_at` | Notes |
|---|---|---|---|
| Running | Non-null | Null | Returned by `TimeEntry::running()` and the active-timers endpoint |
| Stopped | Null | Non-null | Duration is persisted and allocation blocks are finalized |
| Manual historical entry | Null | Usually null | No live timer interval exists |
| Billed/invoiced | Null | Either stopped or manual | Must never remain running |

`TimeEntry::isRunning()` and the `running` scope are the canonical definition. Model guards reject mixed running/stopped and billed/running states during normal Eloquent writes. `stopTimer()` locks the row, is idempotent, clears `timer_started_at`, preserves or sets `stopped_at`, and safely normalizes partially stopped legacy rows.

### Stuck Timer Incident and Repair (2026-06-30)

Local entry 10 had been running since 2026-03-27. Its elapsed duration exceeded the old unsigned-smallint maximum of 65,535 minutes, so MariaDB rejected every stop update while the frontend incorrectly treated the failed HTTP response as success. The column is now an unsigned integer, long block finalization uses batched upserts, and both timer UIs check `response.ok` before removing a timer.

The local record was preserved and stopped through the application service after migrating:

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan tinker --execute='$entry = App\Models\TimeEntry::findOrFail(10); app(App\Services\TimeEntryService::class)->stopTimer($entry);'
```

Result: entry 10 has `timer_started_at = NULL`, `stopped_at = 2026-06-30 20:46:58`, `duration_minutes = 136824`, and 9,123 finalized allocation blocks. No local timers remain active.

Regression coverage reproduces the long-running overflow before stop, verifies it disappears from the active endpoint afterward, normalizes partially stopped rows idempotently, rejects impossible model states, and confirms concurrent block rebalancing. Final verification: **237/237 Pest tests passing (586 assertions)** and **180/180 files passing Pint**.

### Billing Lock (2026-06-30)

A time entry is locked when `billed` is true or `invoice_id` is non-null. `TimeEntry::isLockedForBilling()` centralizes this definition, and transactional service methods enforce it for update, delete, timer description, timer stop, and allocation changes. Allocation rejects the whole affected slot if a target or sibling entry is locked, preventing indirect mutation. Locked entries remain available in owner timesheets and operator reports. See [EPIC-010C](./EPIC-010C-billed-time-entry-locking.md) for the lock matrix and regression coverage.

### Known Gaps

- Configurable lock window for entry edits not confirmed
- Bulk approve in operator view not implemented
- Time-to-invoice export not yet implemented (see EPIC-006 gap)

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`time.log`, `time.view_own`, `time.view_all`, `time.manage`)
- Merged to `main` via PR
