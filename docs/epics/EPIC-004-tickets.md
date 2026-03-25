# EPIC-004: Ticket Management

**Status:** Pending
**Branch:** `epic/004-tickets`
**Goal:** Provide a full-featured ticket (support/issue) management system allowing users to submit requests and operators to triage, assign, and resolve them.

---

## User Stories

### STORY-004-01: Ticket Submission
**As a** portal user,
**I want** to submit a support ticket with a title, description, priority, and attachments,
**So that** I can request help or report an issue.

**Acceptance Criteria:**
- [ ] Ticket form with: title, description (rich text), category, priority (low/medium/high/critical)
- [ ] File attachments (up to 10 files, 20 MB each)
- [ ] Ticket assigned a human-readable ID (e.g., `TKT-0001`)
- [ ] Confirmation email sent to submitter on creation
- [ ] User can view all their submitted tickets

### STORY-004-02: Ticket Triage & Assignment
**As a** platform operator,
**I want** to view, filter, and assign incoming tickets,
**So that** every ticket is handled by the right person.

**Acceptance Criteria:**
- [ ] Operator queue with filters: status, priority, assignee, category, date range
- [ ] Bulk assign / bulk close / bulk change status
- [ ] Assign ticket to any operator user
- [ ] SLA due-date calculated based on priority and business hours config
- [ ] Overdue tickets highlighted in the queue

### STORY-004-03: Ticket Replies & Internal Notes
**As an** operator or user,
**I want** to reply to a ticket or add an internal note,
**So that** communication is tracked in a single thread.

**Acceptance Criteria:**
- [ ] Threaded reply interface with rich text and attachments
- [ ] Internal notes visible only to operators (visually distinct)
- [ ] Email notifications on new replies (user receives public replies; operator receives all)
- [ ] Reply via email (inbound mail integration — optional, configurable)

### STORY-004-04: Ticket Status Workflow
**As an** operator,
**I want** tickets to move through a defined status workflow,
**So that** the lifecycle of every request is trackable.

**Acceptance Criteria:**
- [ ] Statuses: `open` → `in_progress` → `pending_user` → `resolved` → `closed`
- [ ] Status transitions validated (no jumping to invalid states)
- [ ] Auto-close resolved tickets after configurable idle period
- [ ] Audit trail records every status change with actor and timestamp

### STORY-004-05: Ticket Search & Reporting
**As an** operator,
**I want** to search tickets and view summary reports,
**So that** I can track team performance and identify patterns.

**Acceptance Criteria:**
- [ ] Full-text search across title, description, and replies
- [ ] Report: ticket volume by date, category, priority
- [ ] Report: average resolution time by assignee / category
- [ ] Exportable to CSV

---

## Definition of Done

- All acceptance criteria above are checked
- TDD: all business logic tested, form submissions tested with feature tests
- Permissions wired (`tickets.view`, `tickets.create`, `tickets.assign`, `tickets.admin`)
- Merged to `main` via PR
