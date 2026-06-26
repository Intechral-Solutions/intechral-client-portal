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
- [ ] Entries locked after invoicing (cannot be edited)
- [x] Daily/weekly timesheet view for self

### STORY-007-02: Timer (Start/Stop)
**As a** project member,
**I want** to start and stop a live timer while working,
**So that** I don't need to calculate durations manually.

**Acceptance Criteria:**
- [x] Start timer button creates an in-progress entry in the DB
- [x] Timer state persisted server-side (survives page reload)
- [x] Stop timer converts in-progress entry to a completed time entry
- [x] Only one active timer per user at a time (multi-timer block system added 2026-03-27)
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

### Known Gaps

- Configurable lock window for entry edits not confirmed
- Entries locked after invoicing — `invoiced` flag exists, enforcement not confirmed
- Bulk approve in operator view not implemented
- Time-to-invoice export not yet implemented (see EPIC-006 gap)

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`time.log`, `time.view_own`, `time.view_all`, `time.manage`)
- Merged to `main` via PR
