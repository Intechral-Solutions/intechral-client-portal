# EPIC-005: Project Management

**Status:** Implemented
**Committed:** 2026-03-25 (initial), extended 2026-03-26

---

## Goal

Provide lightweight project management with tasks, milestones, and team assignments — integrated with time tracking and billing.

---

## User Stories

### STORY-005-01: Project Creation & Configuration
**As a** platform operator,
**I want** to create projects and configure their settings,
**So that** work is organized into discrete, manageable projects.

**Acceptance Criteria:**
- [x] Project: name, description, client (CRM), start date, target date, status, budget
- [x] Projects can be `active`, `on_hold`, `completed`, or `archived`
- [x] Project members can be assigned from the user list
- [x] Project-level company linking (org-owned projects)

### STORY-005-02: Task Board (Kanban)
**As a** project member,
**I want** a Kanban-style board to visualize and manage tasks,
**So that** the team can track work in progress at a glance.

> **Current-state note (EPIC-011E D1, 2026-09):** "manage tasks" here is superseded, not retracted. Structural task mutation — create, edit, delete, move/reorder — requires Project management authority (`projects.admin`, or `projects.manage` with a `manager` membership on that project); ordinary membership alone does not grant it. Collaborative actions (commenting, toggling a checklist item) remain open to any member, following EPIC-011E's separate rules for those.

**Acceptance Criteria:**
- [x] Default columns: Backlog, To Do, In Progress, In Review, Done
- [x] Custom columns configurable per project
- [x] Drag-and-drop card movement (with keyboard fallback)
- [x] Task card: title, assignee avatar, due date, priority indicator, labels

### STORY-005-03: Task Detail
**As a** project member,
**I want** rich task detail pages,
**So that** all context for a piece of work is in one place.

**Acceptance Criteria:**
- [x] Task: title, description (rich text), assignee(s), due date, priority, labels, attachments
- [x] Sub-tasks (checklist items)
- [x] Comment thread
- [x] Time log entries linked to the task
- [x] Link tasks to other tasks (dependencies)

### STORY-005-04: Milestones & Gantt View
**As a** project manager,
**I want** milestones and a Gantt-style timeline view,
**So that** I can track high-level progress against the project schedule.

**Acceptance Criteria:**
- [x] Milestones with name, due date, and linked tasks
- [ ] Timeline (Gantt) view showing tasks and milestones on a date axis
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

## Implementation

### What Was Built

**Migrations**
- `create_projects_table` — name, description, status, budget, start/target dates, org link
- `create_project_members_table` — user ↔ project pivot
- `create_project_columns_table` — configurable Kanban columns per project
- `create_project_tasks_table` → renamed to `tasks` (2026-03-26)
- `create_project_task_checklist_items_table` → renamed to `task_checklist_items`
- `create_project_task_comments_table` → renamed to `task_comments`
- `create_project_milestones_table`
- `create_project_company_table` (2026-03-26) — project ↔ CRM company pivot
- `create_task_dependencies_table` (2026-03-26) — task-to-task dependency links
- `update_time_entries_task_id_fk` (2026-03-26) — updated FK after table rename

**Controllers**
- `ProjectController` — CRUD, `syncMembers`, `syncCompanies`
- `ProjectBoardController` — Kanban board view
- `ProjectTaskController` — create, show, update, destroy, move (column), addComment, toggleChecklistItem
- `ProjectMilestoneController` — index, store, update, destroy
- `TaskController` — unified task list (my tasks + org tasks)

**Views**
- `projects/index`, `projects/create`, `projects/edit`
- `projects/board` — Kanban board
- `projects/tasks/show` — task detail with checklist, comments, time entries
- `projects/milestones/index`
- `tasks/index` — unified task list

**Routes**
- `/projects` prefix with nested task and milestone routes
- `/tasks` — unified task list

### Known Gaps

- Gantt / timeline view not yet built
- Per-project burn-down and health dashboards not implemented
- Milestone completion percentage not confirmed
- Keyboard fallback for drag-and-drop not confirmed
- Exportable project reports not implemented

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`projects.view`, `projects.create`, `projects.manage`, `projects.admin`)
- Merged to `main` via PR
