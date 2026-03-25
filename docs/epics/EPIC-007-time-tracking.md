# EPIC-007: Time Tracking

**Status:** Pending
**Branch:** `epic/007-time-tracking`
**Goal:** Allow team members to log time against projects and tasks, with reports and billing integration.

---

## User Stories

### STORY-007-01: Manual Time Entry
**As a** project member,
**I want** to log time entries against a project or task,
**So that** billable and non-billable hours are tracked accurately.

**Acceptance Criteria:**
- [ ] Time entry: project, task (optional), date, duration (HH:MM), description, billable flag
- [ ] Entries can be edited or deleted by the logger (within a configurable lock window)
- [ ] Entries locked after invoicing (cannot be edited)
- [ ] Daily/weekly timesheet view for self

### STORY-007-02: Timer (Start/Stop)
**As a** project member,
**I want** to start and stop a live timer while working,
**So that** I don't need to calculate durations manually.

**Acceptance Criteria:**
- [ ] Start timer button creates an in-progress entry in the DB
- [ ] Timer state persisted server-side (survives page reload)
- [ ] Stop timer converts in-progress entry to a completed time entry
- [ ] Only one active timer per user at a time
- [ ] Timer displays in the navigation bar while running

### STORY-007-03: Operator Time Oversight
**As a** platform operator,
**I want** to view and manage all team members' time entries,
**So that** I can approve, correct, or report on logged hours.

**Acceptance Criteria:**
- [ ] Operator view: filter by user, project, date range, billable status
- [ ] Bulk approve entries (marks them as reviewed)
- [ ] Edit or delete any entry (with audit trail)

### STORY-007-04: Time Reports & Billing Export
**As a** platform operator,
**I want** time reports that can be pushed to billing,
**So that** invoices are generated from actual tracked hours.

**Acceptance Criteria:**
- [ ] Report: hours by user / project / task for a date range
- [ ] One-click export of billable hours to a new invoice draft
- [ ] Exported entries marked as `invoiced` (locked from further editing)
- [ ] Exportable to CSV

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`time.log`, `time.view_own`, `time.view_all`, `time.manage`)
- Merged to `main` via PR
