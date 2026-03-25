# EPIC-005: Project Management

**Status:** Pending
**Branch:** `epic/005-projects`
**Goal:** Provide lightweight project management with tasks, milestones, and team assignments — integrated with time tracking and billing.

---

## User Stories

### STORY-005-01: Project Creation & Configuration
**As a** platform operator,
**I want** to create projects and configure their settings,
**So that** work is organized into discrete, manageable projects.

**Acceptance Criteria:**
- [ ] Project: name, description, client (CRM), start date, target date, status, budget
- [ ] Projects can be `active`, `on_hold`, `completed`, or `archived`
- [ ] Project members can be assigned from the user list
- [ ] Project-level permissions override global role permissions (future-proof)

### STORY-005-02: Task Board (Kanban)
**As a** project member,
**I want** a Kanban-style board to visualize and manage tasks,
**So that** the team can track work in progress at a glance.

**Acceptance Criteria:**
- [ ] Default columns: Backlog, To Do, In Progress, In Review, Done
- [ ] Custom columns configurable per project
- [ ] Drag-and-drop card movement (with keyboard fallback)
- [ ] Task card: title, assignee avatar, due date, priority indicator, labels

### STORY-005-03: Task Detail
**As a** project member,
**I want** rich task detail pages,
**So that** all context for a piece of work is in one place.

**Acceptance Criteria:**
- [ ] Task: title, description (rich text), assignee(s), due date, priority, labels, attachments
- [ ] Sub-tasks (checklist items)
- [ ] Comment thread (same component as ticket replies)
- [ ] Time log entries linked to the task
- [ ] Link tasks to tickets or other tasks

### STORY-005-04: Milestones & Gantt View
**As a** project manager,
**I want** milestones and a Gantt-style timeline view,
**So that** I can track high-level progress against the project schedule.

**Acceptance Criteria:**
- [ ] Milestones with name, due date, and linked tasks
- [ ] Timeline view showing tasks and milestones on a date axis
- [ ] Milestone completion percentage based on linked task completion

### STORY-005-05: Project Dashboard & Reporting
**As a** platform operator,
**I want** a project overview dashboard,
**So that** I can see the health of all active projects at a glance.

**Acceptance Criteria:**
- [ ] Per-project dashboard: burn-down, open tasks by status, overdue tasks
- [ ] Cross-project dashboard: all active projects with health indicators
- [ ] Exportable report (CSV / PDF)

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`projects.view`, `projects.create`, `projects.manage`, `projects.admin`)
- Merged to `main` via PR
