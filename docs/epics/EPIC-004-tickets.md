# EPIC-004: Ticket Management

**Status:** Implemented
**Committed:** 2026-03-25

---

## Goal

Provide a full-featured ticket (support/issue) management system allowing users to submit requests and operators to triage, assign, and resolve them.

---

## User Stories

### STORY-004-01: Ticket Submission
**As a** portal user,
**I want** to submit a support ticket with a title, description, priority, and attachments,
**So that** I can request help or report an issue.

**Acceptance Criteria:**
- [x] Ticket form with: title, description (rich text), category, priority (low/medium/high/critical)
- [x] File attachments (up to 10 files, 20 MB each)
- [x] Ticket assigned a human-readable ID (e.g., `TKT-0001`)
- [ ] Confirmation email sent to submitter on creation
- [x] User can view all their submitted tickets

### STORY-004-02: Ticket Triage & Assignment
**As a** platform operator,
**I want** to view, filter, and assign incoming tickets,
**So that** every ticket is handled by the right person.

**Acceptance Criteria:**
- [x] Operator queue with filters: status, priority, assignee, category, date range
- [x] Bulk assign / bulk close / bulk change status
- [x] Assign ticket to any operator user
- [ ] SLA due-date calculated based on priority and business hours config
- [ ] Overdue tickets highlighted in the queue

### STORY-004-03: Ticket Replies & Internal Notes
**As an** operator or user,
**I want** to reply to a ticket or add an internal note,
**So that** communication is tracked in a single thread.

**Acceptance Criteria:**
- [x] Threaded reply interface with rich text and attachments
- [x] Internal notes visible only to operators (visually distinct)
- [ ] Email notifications on new replies
- [ ] Reply via email (inbound mail integration — optional, configurable)

### STORY-004-04: Ticket Status Workflow
**As an** operator,
**I want** tickets to move through a defined status workflow,
**So that** the lifecycle of every request is trackable.

**Acceptance Criteria:**
- [x] Statuses: `open` → `in_progress` → `pending_user` → `resolved` → `closed`
- [ ] Status transitions validated (no jumping to invalid states)
- [ ] Auto-close resolved tickets after configurable idle period
- [x] Audit trail records every status change with actor and timestamp

### STORY-004-05: Ticket Search & Reporting
**As an** operator,
**I want** to search tickets and view summary reports,
**So that** I can track team performance and identify patterns.

**Acceptance Criteria:**
- [ ] Full-text search across title, description, and replies
- [x] Report: ticket volume by date, category, priority
- [ ] Report: average resolution time by assignee / category
- [x] Exportable to CSV

---

## Implementation

### What Was Built

**Migrations**
- `create_tickets_table` — human-readable ID (TKT-XXXX), title, description, category, priority, status, assignee_id, company_id
- `create_ticket_replies_table` — body, is_internal flag, author
- `create_ticket_attachments_table` — path, filename, size, mime type
- `create_ticket_status_histories_table` — old_status, new_status, actor, timestamps
- `add_company_id_to_tickets` — links tickets to CRM company

**Controllers**
- `TicketController` — user-facing: index, create, store, show, downloadAttachment
- `Operator\TicketController` — operator queue: index, show, updateStatus, assign
- `Operator\TicketReplyController` — store (public replies + internal notes)
- `Operator\TicketBulkController` — bulk assign / close / status change
- `Operator\TicketReportController` — index (report view), export (CSV)

**Views**
- `tickets/index`, `tickets/create`, `tickets/show`
- `tickets/_priority_badge`, `tickets/_status_badge` (reusable partials)
- `operator/tickets/index` — operator queue with filters
- `operator/tickets/show` — assign, status change, internal notes
- `operator/tickets/reports` — summary + CSV export

**Routes**
- `/tickets` (user-facing, `can:tickets.view` / `can:tickets.create`)
- `/attachments/{attachment}/download` (auth, policy checked in controller)
- `/operator/tickets` (operator queue, `can:tickets.assign`)

### Known Gaps

- Confirmation / reply notification emails — queue jobs not verified end-to-end
- SLA due-date calculation not implemented
- Status transition validation (guard against invalid state jumps) not confirmed
- Auto-close after idle period not implemented
- Full-text search not implemented

---

## Definition of Done

- All acceptance criteria above are checked
- TDD: all business logic tested, form submissions tested with feature tests
- Permissions wired (`tickets.view`, `tickets.create`, `tickets.assign`, `tickets.admin`)
- Merged to `main` via PR
