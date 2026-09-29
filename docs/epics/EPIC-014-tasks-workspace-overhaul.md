# EPIC-014: Tasks Workspace Overhaul

**Status:** Planned (WP0 planning, 2026-09-29; no implementation has started)
**Class:** Product functionality (Product Roadmap [NEXT — Core work management → Tasks overhaul](../product/product-roadmap.md#tasks-overhaul))
**Product direction:** [Platform Product & UX Direction → Task direction](../product/platform-product-ux-direction.md#task-direction) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md)
**Prerequisites:** [EPIC-013: Direction D Application Shell and Design System Foundation](./EPIC-013-direction-d-shell-design-system.md) (Done) · Lightweight CI baseline (Done, [`docs/testing/ci.md`](../testing/ci.md))
**Related:** [EPIC-011E: Projects and Kanban Migration](./EPIC-011E-projects-kanban.md) (Verified; source of the current task architecture and of lock D3, superseded here) · [EPIC-011D: Time Tracking and Persistent Timer Migration](./EPIC-011D-time-tracking-timer.md) · [EPIC-010C: Billed Time-Entry Locking](./EPIC-010C-billed-time-entry-locking.md) · [EPIC-010D: Helpdesk Security and Integrity Hardening](./EPIC-010D-helpdesk-security-hardening.md)
**Planning baseline:** `main` @ `0b1939c` (post-PR #7), working tree clean, latest push-to-`main` CI green, verified 2026-09-29

---

## Contents

1. [Goal](#1-goal)
2. [Relationship to the Product Roadmap](#2-relationship-to-the-product-roadmap)
3. [Current-state inventory](#3-current-state-inventory)
4. [Locked owner decisions](#4-locked-owner-decisions)
5. [Scope](#5-scope)
6. [Domain invariants](#6-domain-invariants)
7. [Authorization plan](#7-authorization-plan)
8. [Complete / Reopen contract](#8-complete--reopen-contract)
9. [My Tasks / All Tasks query contract](#9-my-tasks--all-tasks-query-contract)
10. [Standalone lifecycle contract](#10-standalone-lifecycle-contract)
11. [Ticket-kind tasks: internal support versus product scope](#11-ticket-kind-tasks-internal-support-versus-product-scope)
12. [Time and billing boundary](#12-time-and-billing-boundary)
13. [Routes, controllers and DTOs](#13-routes-controllers-and-dtos)
14. [Direction D surface plan](#14-direction-d-surface-plan)
15. [Keyboard, bulk and contextual time](#15-keyboard-bulk-and-contextual-time)
16. [Test strategy](#16-test-strategy)
17. [Work packages](#17-work-packages)
18. [Exit criteria](#18-exit-criteria)
19. [Superseded prior decisions](#19-superseded-prior-decisions)
20. [Deferred and inherited items](#20-deferred-and-inherited-items)
21. [Risks and rollback](#21-risks-and-rollback)
22. [Plan-level choices open to review](#22-plan-level-choices-open-to-review)
23. [Branch and PR strategy](#23-branch-and-pr-strategy)

---

## 1. Goal

Make Tasks a **first-class workspace** rather than a by-product of project boards, on the Direction D shell, without disturbing the project-board, time-tracking and billing guarantees earlier epics hardened.

At the end of this epic:

- **My Tasks** shows the work that is mine (§9.2), including the standalone tasks I created and left unassigned, which today disappear.
- **All Tasks** exists, gated by a new `tasks.view_all` permission, and never shows a row the actor could not already open.
- Every task the actor may act on can be **explicitly Completed and Reopened** without dragging a card. Board tasks move to the project's single designated Done column through the canonical `ProjectService` move.
- The Tasks list has **server-side search, sort and filters**, held in the URL, that can only narrow the authorized set.
- **Standalone tasks** have a real lifecycle: detail, edit, Complete/Reopen and delete. The recorded-time guard applies.
- **Assignment** is available where the domain permits it, from task detail and, for authorized actors, from the list.
- The Tasks list and the task detail pages use Direction D page grammar. The S-width table clipping (A13.5) and the duplicate task-detail breadcrumb (A13.12) are gone.
- A real `TaskPolicy`, `TaskService` and task query layer own these rules, delegating to the existing project authority for board tasks instead of competing with it.

This epic is deliberately **not** a general project-management suite (§5.3).

## 2. Relationship to the Product Roadmap

The committed roadmap item ([Product Roadmap → Tasks overhaul](../product/product-roadmap.md#tasks-overhaul)):

> **Class:** Product functionality. My Tasks; All Tasks (capability-gated); project tasks included; explicit Complete/Reopen (board tasks move to the project's designated Done column through the canonical project-task service); search, sort, filter; assignment; contextual time. See Task direction.
>
> **Depends on:** shell, design system, CI baseline (all three Done — dependency satisfied). **Needs design:** designated Done column and Reopen policy.

The "needs design" items are resolved by owner decisions Q1 and Q2 (§4).

[Task direction](../product/platform-product-ux-direction.md#task-direction) adds the target the roadmap refers to:
- "useful bulk/keyboard interactions";
- likely filters;
- the rule that "the current `/tasks` behavior … is a migration artifact and **does not constrain** the future Task product".

Items EPIC-013 handed to this epic ([EPIC-013 §31](./EPIC-013-direction-d-shell-design-system.md#31-deferred-follow-on-work)):
- the D2/D9 patterns;
- `DataTable` conventions, `FilterBar`, `Chip`/`FilterChip` and `BulkBar`;
- the `J/K/X/E/T` shortcuts;
- the Tasks peek inspector;
- A13.5, the `tasks.index` table clipped at S;
- A13.12, the duplicate `Breadcrumb` landmark on `projects/tasks/show.tsx`, handed to "the Projects/Tasks product epic";
- the Home "My work" query ([EPIC-013 §21.1](./EPIC-013-direction-d-shell-design-system.md#211-what-the-data-supports-today)).

§5 classifies each of these as required, separable or deferred. Not every handed-forward idea is a blocker for this epic.

Downstream, per the roadmap's [Dependencies](../product/product-roadmap.md#dependencies):
- Timer UX improvement is "best delivered with or right after" this epic;
- Projects UX expansion "benefits from Tasks overhaul components".

## 3. Current-state inventory

Verified by reading the live code at `0b1939c` (2026-09-29 discovery). Paths are relative to `src/`.

### 3.1 Model and schema

`tasks` (`app/Models/Task.php`) holds **three kinds** in one table. `Task::kind()` derives the kind:

| Kind | Discriminator | Status authority |
|---|---|---|
| `board` | `project_id` set | the column (`project_columns.is_done_column`) when `column_id` is set; `status` when the column is null (pinned "null-column edge") |
| `ticket` | `project_id` null, `ticket_id` set | `status` |
| `standalone` | both null | `status` |

`scopeDone` / `scopeOpen` / `scopeOverdue` and `isDone` / `isOverdue` / `effectiveStatus` are the **single** source of the done/overdue rule (EPIC-011E §15). `TaskStatusPresenter` exposes `{label, done, source}`, so React never reads a raw `status`.

**Columns and foreign keys**

| Column | Null? | FK and delete behaviour |
|---|---|---|
| `project_id` | yes | projects, **cascade** |
| `ticket_id` | yes | tickets, set null |
| `column_id` | yes | project_columns, set null |
| `milestone_id` | yes | project_milestones, set null |
| `assignee_id` | yes | users, set null |
| `created_by` | **no** | users, **cascade** |
| `title` | no | string(255) |
| `description` | yes | longText, plain text |
| `due_date` | yes | date, cast to `date` |
| `priority` | no | enum `low`/`medium`/`high`/`critical`, default `medium` |
| `position` | no | unsigned int, default 0 |
| `status` | no | enum `todo`/`in_progress`/`done`, default `todo` |

**Other tables**
- `time_entries.task_id` is **RESTRICT** (EPIC-011E D4 backstop).
- `task_dependencies` exists as schema and a model relationship only. No route or UI uses it.
- Checklist items and comments hang off the task.
- There are no soft deletes, no audit trail and no organization column. Tenancy is derived from project membership or ticket policy.

**Indexes actually present** (read from the dev database with `SHOW INDEX FROM tasks`, 2026-09-29):
- `PRIMARY(id)`
- `(column_id, position)`
- `(project_id, assignee_id)`
- `due_date`
- the FK-backing single-column indexes on `assignee_id`, `created_by`, `ticket_id` and `milestone_id`

> **Correction to the discovery report.** Discovery listed `assignee_id` and `created_by` as likely missing indexes. They are not missing: MariaDB created FK-backing indexes for both. The only candidate column with no index is `status`. §9.7 plans index validation accordingly.

### 3.2 Backend

**Controllers**

`TaskController` (`/tasks`):
- `index` builds the mine/org list. "Mine" is `assignee_id = me` only. "Org" additionally ORs in company-linked projects the viewer can open and company tickets, for holders of `tasks.view_org` who have at least one organization company.
- `store` is standalone create. The assignee is limited to the actor or null (`Rule::in`). It is a raw `Task::create`.

`ProjectTaskController` (`/projects/{project}/tasks/...`):
- show, store, update, destroy and move;
- comments, and checklist add/toggle/remove.
- Structural routes authorize `ProjectPolicy::manage`; comment and toggle authorize `ProjectPolicy::view`.
- `taskRules()` holds A1–A3 (own column, own milestone, member assignee) and I8 (a departed assignee is kept when the task is saved unchanged).

**Service**

`ProjectService` owns `createTask`, `moveTask` and `deleteTask`:
- column-row locks taken in ascending id order, then the task row;
- dense `0..n-1` positions, repaired as they are touched;
- restart when the task moved while the request waited (`untilStable`);
- the D4 recorded-time guard with FK-violation (1451) mapping.

No kind-aware task service exists.

**Policy**

**There is no `TaskPolicy`.** Every task authorization goes through `ProjectPolicy` or is "authenticated" (standalone). The only task permission is `tasks.view_org`.

**Supporting code**
- `ProjectIntegrityAudit` performs read-only integrity checks: foreign column or milestone, non-member assignee, orphaned time.
- `AccessibleTimeContext` decides which task a user may log time against: the assignee, a viewer of the project, or a viewer of the ticket.
- **Navigation:** `NavigationBuilder::tasks()` emits `tasks.mine` ("My tasks") and `tasks.org` ("My organization", query `view=org`, gated on `tasks.view_org`). `projects.tasks.show` is active under the **Projects** workspace. The panel default for Tasks is Collapsed.

### 3.3 Frontend

**`pages/tasks/index.tsx`**
- Legacy `max-w-5xl` container and old `PageHeader`.
- In-page tabs ("Assigned to Me" / "My Organization") that duplicate the drawer views.
- Inline `CreateTaskForm`.
- A hand-rolled table inside `overflow-hidden`, which is A13.5.
- A hand-rolled empty state, fixed sort and pagination.

**`pages/projects/tasks/show.tsx`**
- Legacy container.
- A page-owned `<nav aria-label="Breadcrumb">` (lines 62–71; A13.12, still present).
- A local `Panel` component.
- The sidebar holds Details, Time (`TaskTimePanel` with the shared `TimerControl`) and a manager-only edit form with delete.

**Components**
- Board components in `components/projects/`: task card, Move menu, quick-add, edit form, checklist, comments, delete button, time panel, status badge.
- List components in `components/tasks/`: create form, row, title cell, context link.
- `TaskStatusBadge` already renders the canonical `Status`.

Direction D primitives available but unused by Tasks pages: `PageFrame`, `EntityHeader`, `Strata`, `Section`, `EmptyState`, `FormDialog`, `ConfirmationDialog`.

**Not built yet** (deferred to this epic by EPIC-013): `DataTable`, `FilterBar`, `Chip`/`FilterChip`, `BulkBar`.

### 3.4 Defects and limitations this epic inherits

| # | Finding | Handled in |
|---|---|---|
| F1 | **Unassigned standalone orphan.** A standalone task created as *Unassigned* is never listed to anyone, including its creator ("mine" filters on `assignee_id` only; "org" reaches rows only through a project or ticket). | Q4, WP3 |
| F2 | A plain project member assigned a board task cannot complete it (only managers can move cards). | Q1, WP2 |
| F3 | Operators have no All Tasks; an operator without an organization gets no org view at all. The drawer offers "My organization" on the permission alone, while the controller hides the tab when there is no company link. | Q3, Q5, WP3 |
| F4 | Standalone tasks are create-only (EPIC-011E D3), so the Playwright fixture cannot remove the row it creates (A9.7). | Q4, WP2, WP7 |
| F5 | The list has no search, sort or filter, and no row actions. | WP3, WP4 |
| F6 | Status editing is board-only (drag or Move menu) and manager-only. | Q1, WP2 |
| F7 | A13.5 (table clipped at S) and A13.12 (duplicate breadcrumb). | WP4, WP5 |
| F8 | "Exactly one Done column per project" holds by construction only: five default columns, one flagged, and no column-management route. There is no database constraint and no audit check. | Q2, WP1 |
| F9 | Nothing enforces that `project_id` and `ticket_id` are mutually exclusive; `kind()` simply prefers the project. | INV-13, WP1 audit |

## 4. Locked owner decisions

Locked 2026-09-29. Where later wording in this document seems to disagree, **this section wins**.

| ID | Decision |
|---|---|
| **Q1** | **Board Complete/Reopen authorization.** A board task may be explicitly Completed or Reopened by (1) an actor who can manage the project under the existing `ProjectPolicy::manage`, or (2) the task's **current assignee, provided that assignee is still a member of the project**. This grants **no** arbitrary board movement. A non-manager assignee receives only the semantic Complete/Reopen operation; column movement stays governed by `ProjectPolicy::manage`. Complete/Reopen is **server-authorized**. |
| **Q2** | **Done column and Reopen destination.** Done destination: the project's single `is_done_column`. The service requires **exactly one**. With zero or several it fails safely with a clear domain/configuration error and never silently picks one. Board completion uses the canonical `ProjectService` move/ordering logic and never writes `tasks.status` for a column-backed task. Reopen destination: **the first non-Done column by position**. No previous-column history or schema is added in this epic; a future workflow epic may introduce configurable or historical destinations. |
| **Q3** | **All Tasks.** A new permission `tasks.view_all`, granted initially to the built-in `operator` role, gates All Tasks. It is **not** an authorization bypass: a row appears only when the actor can legitimately view that task through the applicable Task/Project/Ticket rules. Other users' standalone (personal) tasks are **not** included because the actor holds `tasks.view_all`. Ticket-kind tasks are excluded from All Tasks in this epic under Q6, whatever the actor's ticket authority. |
| **Q4** | **Standalone lifecycle and My Tasks.** Standalone tasks gain detail, edit, Complete/Reopen and delete, superseding EPIC-011E D3. The authorized actor is the **creator or the current assignee**, subject to every time/billing integrity rule. Delete reuses the existing recorded-time guard and FK-safe behaviour; no bare delete path may bypass it. Standalone assignment keeps its personal character and is not broadened to organization-wide assignment. **My Tasks** = (1) tasks currently assigned to me, **plus** (2) standalone tasks I created that are currently unassigned. It does **not** include every task I once created after it was intentionally assigned elsewhere. Creating a project task confers no My Tasks visibility on its own. |
| **Q5** | **Retire "My organization"** as a first-class Tasks view. The top-level views are **My Tasks** and **All Tasks** (when `tasks.view_all` is held). Organization becomes a **filter** within the query model where applicable. The old org view is not preserved for migration compatibility. |
| **Q6** | **Ticket-task scope lock.** Ticket-task **product UX remains Future**. This epic adds no ticket-task creation, no ticket-task navigation, no new ticket-task list surface and no new customer-facing ticket-task presentation. The shared domain may understand existing `ticket`-kind rows as needed for correctness, authorization and future-proof invariants. §11 records exactly what is internal support and what is user-facing. **Consequence, made explicit (2026-09-29 review):** the Tasks workspace query (`TaskQuery`) **excludes ticket-kind tasks from every result**: My Tasks, All Tasks and every filter (§9.1). `tasks.view_all` never causes a ticket task to appear. |

## 5. Scope

### 5.1 Required (the epic is not complete without these)

| # | Outcome | Roadmap source |
|---|---|---|
| R1 | Task authorization boundary: `TaskPolicy` that understands all three kinds internally; only board and standalone are product-surfaced (§7, §11) | Precondition for every item below |
| R2 | Explicit Complete/Reopen per Q1/Q2, from task detail and from the Tasks list | "explicit Complete/Reopen" |
| R3 | My Tasks (Q4 rule) and All Tasks (Q3) as the two Tasks views; "My organization" retired (Q5) | "My Tasks; All Tasks (capability-gated); project tasks included" |
| R4 | Server-side search, sort and filters, URL-backed and validated, narrowing only (§9) | "search, sort, filter" |
| R5 | Standalone lifecycle per Q4 (§10) | Q4; F1, F4 |
| R6 | Assignment where the domain permits it, from detail and, for authorized actors, from the list (§7.3) | "assignment" |
| R7 | Tasks list on Direction D: canvas `PageFrame`, `PageHeader`, drawer-owned views, `FilterBar`, `DataTable` conventions, `Status`, `EmptyState`, D9 rows at S (fixes A13.5) | Direction D §19 step 9; EPIC-013 §31 |
| R8 | Task detail on Direction D: `EntityHeader` + `Strata`, grid `PageFrame` with a labelled aside, `Section`s; the page-owned breadcrumb removed (fixes A13.12) | EPIC-013 §31 |
| R9 | Contextual time in a meaningful form: the time panel on every task detail surface this epic delivers (board/project task detail and standalone task detail; ticket task detail is Future and is not an EPIC-014 surface), and the viewer's running timer visible on list rows (§15.3) | "contextual time" |
| R10 | Minimum keyboard and bulk behaviour satisfying the Task direction's "useful bulk/keyboard interactions": row keyboard navigation and Complete, row selection, and bulk Complete/Reopen (§15.1, §15.2) | Task direction |
| R11 | Every project-board invariant preserved (§6) | EPIC-011E |
| R12 | Every time/billing invariant preserved (§6, §12) | EPIC-010C, EPIC-011E D4 |

### 5.2 Separable (should-have; mergeable independently; **never** blocks §18)

| # | Enhancement | Why separable |
|---|---|---|
| S1 | Row-level `TimerControl` start/stop on the Tasks list (the `T` shortcut included) | R9 is met by the detail panel plus the list's running state; a start control on every row adds eligibility plumbing per row (§15.3) |
| S2 | Bulk assign | R10 is met by bulk Complete/Reopen; bulk assignment multiplies per-kind assignment rules |
| S3 | Tasks peek inspector (Direction D §9) | Direction D lists it as optional ("allowed on queue-like surfaces") |
| S4 | Home "My work" feed from the WP3 query | Home is EPIC-013's surface; the query is reusable, the surface is not required by the Tasks roadmap item |
| S5 | `?` shortcut sheet | Direction D marks it Target, not NEXT |

### 5.3 Deferred (explicitly out of EPIC-014)

- Saved views.
- The dependency UI (`task_dependencies` stays schema-only).
- Subtasks beyond the existing checklist.
- Recurrence, attachments, and an activity/audit feed.
- Ticket-task creation and any ticket-task product UX (Q6).
- Column management, or configurable and historical Reopen destinations (Q2).
- Timer UX NEXT work: Stop all, the long-running warning, quick-start search, "Today N logged".
- Customer-facing Tasks presentation or a customer shell.
- A Complete affordance on board *cards*: the board belongs to Projects, and Projects UX expansion owns it. Complete is reachable for every board task from task detail and the Tasks list.
- Changing a task's project, or converting between kinds (forbidden by INV-9).
- Plex Sans 600 M2 cold-load recentering.
- Generic CI and test-infrastructure debt: the `tests/Browser` lint/typecheck gap, jsdom load sensitivity, the vacuous Pest guards (A13.13). A9.7 is the one exception; it closes naturally in WP7 (§20).

## 6. Domain invariants

Each invariant is **preserved**. The epic adds none that weakens an existing guarantee. "Evidence" names the test that pins it today; new pins are in §16.

| ID | Invariant | Enforced by | Evidence today |
|---|---|---|---|
| INV-1 | Board task completion is **column-authoritative**: a column-backed task is done iff its column is the Done column | `Task::scopeDone`/`isDone` | `ProjectPinnedBehaviorTest`, `ProjectIntegrityTest` |
| INV-2 | Board movement, including Complete/Reopen of a board task, **never writes `tasks.status`** | `ProjectService::writeOrder` touches only `column_id`/`position`/`updated_at` | `ProjectPinnedBehaviorTest` ("a board move never touches tasks.status"), `ProjectIntegrityTest` |
| INV-3 | Board positions are **dense** `0..n-1` per column after every write | `ProjectService::writeOrder` | `ProjectIntegrityTest` |
| INV-4 | **Lock ordering:** column rows ascending by id, then the task row; restart-on-race; bounded deadlock retries | `ProjectService::lockColumns`/`untilStable` | `ProjectMoveConcurrencyTest` + `tests/Support/project_task_worker.php` |
| INV-5 | A task's column and milestone belong to its own project (A1, A2) | controller validation + `ProjectIntegrityAudit` | `ProjectIntegrityTest`, `ProjectIntegrityAuditTest` |
| INV-6 | A **new** board assignment names a current project member (A3) | `ProjectTaskController::taskRules` | `ProjectIntegrityTest` |
| INV-7 | A departed assignee is **kept**, not silently unassigned, when the task is saved unchanged (I8); detail shows "no longer a project member" | `taskRules` closure; `ProjectTaskController::show` | `ProjectIntegrityTest`, `ProjectTaskDetailInertiaTest` |
| INV-8 | Exactly one designated Done column is **required** for Complete/Reopen; zero or several fail safely, never with a guess (Q2) | **new** `TaskService` resolver (WP1/WP2) + new audit check | new (§16.2) |
| INV-9 | A task's **project identity never changes** after creation, and a task never changes kind (standalone ↔ project ↔ ticket). No endpoint accepts `project_id` or `ticket_id` on update | route/validation surface | `ProjectIntegrityTest` (update rules); new explicit pin (§16.2) |
| INV-10 | **Project time attribution cannot be silently changed**: time on a task is attributed through `task.project_id`, so INV-9 protects every report and invoice | INV-9 | follows from INV-9 |
| INV-11 | **Hard delete is prohibited once any time entry references the task** (billed, invoiced, stopped or running), for every kind | `ProjectService::deleteTask` guard today; lifted into a shared guard (WP1) | `ProjectDeletionGuardTest` |
| INV-12 | **Database RESTRICT on `time_entries.task_id` stays authoritative**; an FK violation from a race maps to the same validation message | migration `2026_09_21_120000`; `isRestrictViolation` | `ProjectDeletionGuardTest` |
| INV-13 | `project_id` and `ticket_id` are not both set. Not enforced today (F9). The epic adds an audit check and refuses to create such rows, but does not add a DB constraint | new audit check (WP1) | new |
| INV-14 | **Billed time stays immutable**: no task operation mutates a time entry | `TimeEntry::isLockedForBilling` + `TimeEntryService` | EPIC-010C suite, `ProjectDeletionGuardTest` |
| INV-15 | **Complete never stops, alters or reassigns any timer**, the actor's own included (stopping one's own is Timer UX scope) | `TaskService` touches no `time_entries` row | new pin (§16.2) |
| INV-16 | **Filters, search and sort only narrow** the authorized visible set; no request parameter widens it | `TaskQuery` composition order (§9.1) | `ProjectVisibilityTest` (org tab); new pins |
| INV-17 | **DTOs leak nothing unauthorized**: no email, no raw model attributes, no ids of rows the actor cannot open, no link that would 403 | presenters | `TaskListInertiaTest`, `ProjectTaskDetailInertiaTest`, `ProjectVisibilityTest` |
| INV-18 | Navigation visibility is presentation, never authorization: every new route carries its own server authorization | route + policy | `NavigationBuilderTest`, `ShellContractTest` |
| INV-19 | Task vocabulary (priorities, statuses, kinds) comes from the server, never hard-coded labels in React | presenters / props | `TaskListInertiaTest` |

## 7. Authorization plan

### 7.1 Permission catalogue

- **Add** `tasks.view_all` (`PermissionCatalogue::TASKS_VIEW_ALL`) to `all()`. `RoleSeeder` syncs `operator` to `Permission::all()`, so the operator grant needs no seeder logic change. It is **not** added to `userDefaults()`.
- **`tasks.view_org`** loses its only consumer when the org view retires (Q5). Following the EPIC-011E C2 precedent for `projects.view_org`, it **stays in the catalogue and in the role defaults, inert**, and is recorded as permission debt. It is not deleted in this epic (§22 P1).
- Before WP3's permission lands, existing development databases need `PermissionSeeder` + `RoleSeeder` re-run. Development data is disposable ([Roadmap principle 8](../product/product-roadmap.md#roadmap-principles)); CI seeds from scratch.
- [`rbac-design.md`](../architecture/rbac-design.md) is updated in WP3, when the permission exists, not in WP0.

### 7.2 `TaskPolicy` abilities by kind

Registered for `App\Models\Task`. For board tasks the policy **delegates to `ProjectPolicy`** and adds only Q1's assignee arm. It never re-derives project authority. It invents no organization ownership, because tasks have no organization column.

"Member-assignee" below means `task.assignee_id === actor.id` **and** `project.hasMember(actor)`, evaluated at request time.

| Ability | Board task | Standalone task | Ticket task (Q6; §11) |
|---|---|---|---|
| `view` | `ProjectPolicy::view(project)` | creator **or** current assignee | may delegate to `TicketPolicy::view(ticket)` for existing/internal code only; grants **no** Tasks-workspace exposure (§9.1) |
| `update` (fields) | `ProjectPolicy::manage(project)` | creator or current assignee | **deny** (no surface) |
| `complete` | `manage(project)` **or** member-assignee (Q1) | creator or current assignee | **deny** |
| `reopen` | `manage(project)` **or** member-assignee (Q1) | creator or current assignee | **deny** |
| `delete` | `manage(project)` | creator or current assignee | **deny** |
| `assign` | `manage(project)`; the target must satisfy INV-6/INV-7 | creator or current assignee; the target set is §7.3 | **deny** |
| `move` (arbitrary column/position) | `manage(project)`: unchanged, **not** granted to member-assignees | n/a | n/a |
| `viewAny` / list | any authenticated user (My Tasks) | same | same |
| `viewAll` (class-level) | `tasks.view_all` | same | same |

Rules:
- A board task whose assignee has left the project is **not** viewable by that former assignee. This keeps today's matrix row `assignee → DENY` on project task routes. The row still appears in their My Tasks, unlinked and with no actions, exactly as today (EPIC-011E §5).
- `viewAll` gates the **surface** only. The rows inside it come from `view` (Q3), restricted to the kinds the workspace surfaces: board and standalone (§9.1). `TaskPolicy` understanding a ticket-kind task internally is authorization support, never product exposure (Q6).
- Comment and checklist abilities stay on `ProjectPolicy` in `ProjectTaskController` and are **unchanged**.
- `ProjectTaskController`'s existing structural routes keep `ProjectPolicy::manage`. Those controllers are not refactored to call `TaskPolicy` unless a WP needs it; if one does, the result must be identical, proven by `ProjectAuthorizationMatrixTest`.

### 7.3 Assignment

| Kind | Allowed assignee values | Who may set them |
|---|---|---|
| Board | a current project member, or null. Re-saving an unchanged departed assignee is allowed (I8) | `manage(project)` only: **unchanged** |
| Standalone | the actor, or null | creator or current assignee |

The standalone set means the creator can take or release their own task, and an assignee (legacy or factory data) can release it back, which returns it to the creator's My Tasks under the Q4 rule. **No cross-person standalone assignment exists** (Q4). This epic changes no ticket assignment (Q6).

The board assignee-validation closure in `taskRules()` is **extracted and shared**, not copied, so the list's narrow assign endpoint (§13) and the existing update route run the same rule.

### 7.4 Actor matrix (built-in roles)

| Actor | My Tasks | All Tasks | Complete/Reopen board task | Edit/assign/delete board task | Standalone lifecycle |
|---|---|---|---|---|---|
| `operator` (all permissions, incl. `projects.admin`) | yes | yes: every board task (all projects are visible to `projects.admin`) and only **own** standalone tasks; never ticket tasks (Q6) | yes | yes | own only |
| `user`, project manager (`projects.manage` + manager role) | yes | no | own projects: yes | own projects: yes | own only |
| `user`, plain member | yes | no | only as member-assignee | no | own only |
| `user`, former member still assigned | row listed, unlinked | no | no | no | own only |
| no role | yes | no | no board visibility | no | own only |

"Own" standalone means creator or current assignee.

## 8. Complete / Reopen contract

`TaskService::complete(Task, User)` and `TaskService::reopen(Task, User)`. The controller authorizes `TaskPolicy::complete`/`reopen` before calling the service. The service performs no authorization of its own and trusts nothing from the client except the task id.

| Kind | Complete | Reopen |
|---|---|---|
| Board, column-backed | Resolve the Done column (below). If the task is already in it: **no-op success**, no move and no reorder. Otherwise `ProjectService::moveTask(task, doneColumnId, PHP_INT_MAX)`, which appends at the tail (clamped). `status` is **not** written (INV-2). | Resolve the first non-Done column by `position` (ties broken by `id`). If the task is not done: **no-op success**. Otherwise `moveTask(task, firstOpenColumnId, PHP_INT_MAX)` to the tail. `status` is not written. |
| Board, null column (the column was deleted; pinned edge, unreachable while no column-management route exists) | Placed into the Done column through the same `moveTask` (the service already handles a null source). It rejoins the board rather than gaining a status-only completion. | n/a: a null-column board task is done only if `status = done`, and Complete never produces that state. If one exists (legacy), Reopen places it in the first open column through `moveTask`. |
| Standalone | `status = done`. Idempotent. | `status = todo`. Idempotent. |
| Ticket | **Refused.** The service recognizes the kind and throws a domain exception without changing anything (§11). No route reaches it. | **Refused**, same as Complete. |

**Done-column resolution (Q2, INV-8)**
- Query `project_columns where project_id = ? and is_done_column = true`. Exactly one row → proceed.
- Zero or several → throw a dedicated domain exception (working name `DoneColumnConfigurationException`, carrying the project id and the count). The controller maps it to a **validation-style error on the `complete` key** with a clear message (e.g. "This project's board has no single Done column, so tasks cannot be completed from here. A project administrator needs to correct the board."). **Never a 500, never a guess.**
- Reopen with **no** non-Done column raises the same exception.
- `ProjectIntegrityAudit` gains a read-only check listing projects whose Done-column count is not exactly one.

**Other rules**
- **Concurrency:** Complete/Reopen inherit `moveTask`'s locking and restart protocol unchanged (INV-4). Resolution happens before the move; it is safe because the column set is static (no column management), and `moveTask` re-validates that the target belongs to the task's project under its lock.
- **Response:** redirect-back with a flash, the same Inertia contract as `move`, so partial reloads reconcile. The bulk variant is in §15.2.
- **Timers:** unaffected (INV-15). A task completed while a timer runs on it keeps the timer. The time picker's existing `open()` filter stops offering done tasks for *new* timers, as today.

## 9. My Tasks / All Tasks query contract

### 9.1 Composition order (INV-16)

One query layer (working name `App\Queries\TaskQuery`) builds every Tasks-workspace list, in a fixed order:

1. **Visibility set** for the chosen view (§9.2 or §9.3). This is the maximum; nothing later may OR into it.
2. **Filters**, each an `AND`-ed `where`/`whereHas`.
3. **Search**, `AND`-ed.
4. **Sort**, then **pagination** (30 per page, `withQueryString`, as today).

**Surfaced kinds (Q6).** Step 1 always begins with the *surfaced-kinds predicate*, `project_id IS NOT NULL OR ticket_id IS NULL` (board and standalone). `TaskQuery` therefore returns **no ticket-kind task in any Tasks-workspace result**: not in My Tasks, not in All Tasks, not under any filter, and not because the actor holds `tasks.view_all`. This is a deliberate narrowing of today's `/tasks`, which can list ticket rows (assigned to the viewer, or reached through the org view). Ticket-task product UX remains Future, and the queries, filter options and DTOs model only the two surfaced kinds. EPIC-014 adds **no** `Ticket::scopeVisibleTo`; if a later package finds an independent concrete need for a canonical Ticket visibility scope, it is proposed and characterized then.

The view's visibility set is wrapped in a single parenthesised `where(fn …)` group, so a filter can never escape it through operator precedence. A test pins that every filter combination returns a subset of the unfiltered view (§16.2).

### 9.2 My Tasks (Q4)

```
surfaced kinds (project_id IS NOT NULL OR ticket_id IS NULL)
AND (
      assignee_id = me                       -- board or standalone tasks assigned to me
   OR (project_id IS NULL AND ticket_id IS NULL AND created_by = me AND assignee_id IS NULL)
)
```

- Board/project tasks currently assigned to me; standalone tasks currently assigned to me; unassigned standalone tasks I created (Q4). **No ticket tasks.**
- No company or organization widening. Being the creator of a project task grants nothing.
- Rows the viewer cannot open (a former member still assigned) are listed **unlinked and without actions** (INV-17), as today.

### 9.3 All Tasks (Q3)

Available only with `tasks.view_all`. The set is the union of the surfaced-kind rows the actor can `view` (§7.2):

```
surfaced kinds (project_id IS NOT NULL OR ticket_id IS NULL)
AND (
      project_id IN Project::visibleTo(actor)                       -- board tasks the actor may view
   OR (project_id IS NULL AND ticket_id IS NULL
       AND (created_by = me OR assignee_id = me))                   -- the actor's own standalone tasks
   OR assignee_id = me                                              -- keeps All ⊇ Mine
)
```

- `Project::visibleTo` already exists and equals `ProjectPolicy::view` (pinned by `ProjectVisibilityTest`).
- **Ticket tasks are excluded** (Q6). No ticket authorization query is part of this epic, so no `Ticket::scopeVisibleTo` is planned.
- Other users' standalone tasks never appear.

### 9.4 View state

- `?view=mine|all`.
- Anything else is **clamped to `mine`**, including the retired `view=org` and `view=all` without the permission. This mirrors today's clamp for a navigational parameter. No migration shim for `org` (Q5).

### 9.5 Filters

Every filter is optional, URL-backed, validated and clamped (an unknown value is dropped, not a validation redirect), and `AND`-ed.

| Filter | Values | Query | Notes |
|---|---|---|---|
| Completion | `open` (default) · `done` · `any` | `scopeOpen` / `scopeDone` | The single done rule (INV-1). The default is open, so a completed row leaves the default view (Direction D §14.2, "row completed → focus next row") |
| Priority | subset of `Task::PRIORITIES` | `whereIn('priority')` | |
| Due | `overdue` · `today` · `next7` · `none` | `scopeOverdue`; date comparisons in the application timezone; `whereNull` | Presets only; no free date range in this epic |
| Kind | `project` · `standalone` | `whereNotNull('project_id')` / standalone predicate | `ticket` is **not offered** and is not a value of the filter DTO (Q6). Ticket tasks are not in the result set at all (§9.1) |
| Project | a project id | `where('project_id')` | Options: projects in `Project::visibleTo(actor)` that have at least one row in the current view |
| Milestone | a milestone id | `where('milestone_id')` | **Offered only when exactly one project is selected**; the server ignores a milestone that does not belong to it. Milestones are per project, and a cross-project milestone filter has no clean meaning |
| Assignee | a user id, or `none` | `where('assignee_id')` / `whereNull` | **All Tasks only**; My Tasks is already scoped to the viewer. Options: distinct assignees of rows in the current visible set, names only (INV-17) |
| Organization | a CRM company id (Directory "Organizations") | board: `project.companies` contains it; standalone: never matches | Replaces the retired org view (Q5). A company link is **metadata that narrows, never grants** (EPIC-011E D2). Options: companies linked to projects the actor can view |

Filter option lists are computed from already-authorized sets, so they cannot enumerate unauthorized records (INV-17). Each option query is bounded and counted in the query budget.

### 9.6 Search and sort

**Search**
- `q`: trimmed and length-clamped (e.g. 100 characters), matched against **`title` only** with an escaped `LIKE`. Special characters are escaped so `%` and `_` match literally.
- Not description, comments or checklist items. This keeps search inside the task's own visible fields and avoids the H3-style oracle class (EPIC-010D).

**Sort**
- `sort`: `due` (default: nulls last, then `due_date`, then newest), `priority` (critical → low by enum order), `title`, `updated`, `created`.
- `dir`: `asc|desc`, clamped.
- A deterministic `id` tiebreak is always appended.
- Sorting by status is not offered, because it is column-derived per project.

### 9.7 Query budget and indexes

- `ProjectQueryBudgetTest` already pins that `/tasks` does not grow with row count. WP3 extends it to filtered, searched and All Tasks requests, including the option lists. The count is constant per request shape and independent of page size.
- **Indexes (not reflexive).** Per §3.1, `assignee_id`, `created_by` and `due_date` are already indexed; `status` is not. WP3 runs `EXPLAIN` on the final My Tasks, All Tasks and filtered query shapes against a realistically sized fixture. It adds an index **only** where the plan shows a scan the index removes (a composite such as `(created_by, assignee_id)` for the standalone arm is the most plausible). Each index is justified by the plan in the WP3 amendment. A low-cardinality `status` index is not expected to help and is not added without evidence.

### 9.8 Navigation contract update (planned; implemented in WP3)

This supersedes the Tasks row of [EPIC-013 §11.1](./EPIC-013-direction-d-shell-design-system.md#111-current--target-matrix) for the Tasks workspace:

| Item key | Label | href | Gate | Query match |
|---|---|---|---|---|
| `tasks.mine` | My tasks | `tasks.index` | authenticated | none (mirrors the clamp) |
| `tasks.all` | All tasks | `tasks.index?view=all` | `tasks.view_all` | `view=all` |
| ~~`tasks.org`~~ | *retired (Q5)* | | | |

- Both items list `tasks.index` and the new `tasks.show` (§13) as active routes, so standalone detail keeps the Tasks workspace active.
- `projects.tasks.show` stays under **Projects**.
- Panel default stays **Collapsed** (Direction D §5.3: "the table is the product; views stay reachable from the breadcrumb view switcher").
- `NavigationBuilder` still issues no query: gating is permission-only, so the controller's data-dependent tab suppression (F3) disappears.

## 10. Standalone lifecycle contract

| Operation | Route (§13) | Authorization | Rules |
|---|---|---|---|
| Create | `POST /tasks` (existing) | authenticated | Unchanged: title, description, priority, due date, status; assignee ∈ {actor, null}. `created_by = actor`. Moves into `TaskService::createStandalone` |
| Detail | `GET /tasks/{task}` | `TaskPolicy::view` | Standalone only. A board task **redirects** to its canonical `projects.tasks.show`; a ticket task is **404** (Q6: no ticket-task navigation) |
| Edit | `PUT /tasks/{task}` | `TaskPolicy::update` | Title, description, priority, due date, status. **No** `project_id`/`ticket_id` (INV-9). Assignee through the same request or the narrow assign endpoint, per §7.3 |
| Complete / Reopen | `PUT /tasks/{task}/complete`, `/reopen` | `TaskPolicy::complete`/`reopen` | §8 |
| Delete | `DELETE /tasks/{task}` | `TaskPolicy::delete` | Through the **shared recorded-time guard** (§12.1): refused with the existing `delete`-key message when any time entry references the task, and FK 1451 is mapped to the same message. Redirects to `tasks.index`, never `back()` (the detail page no longer exists: the EPIC-011E §11 precedent) |

Standalone tasks have **no** checklist, comments, milestone or board position in this epic. Their detail page does not pretend those sections exist (§14.2).

## 11. Ticket-kind tasks: internal support versus product scope

Nothing in application code creates ticket tasks; only factories do (EPIC-011E §3.1). Q6 applies: **ticket-task product UX remains Future.** EPIC-014 lists no ticket task, offers none in My Tasks or All Tasks, filters by no ticket kind, adds no ticket-task detail surface and no ticket-task mutation UX.

| Concern | Internal domain support (in scope) | User-facing (out of scope) |
|---|---|---|
| Kind detection (`Task::kind()`), done/overdue rules | Unchanged: `status`-based | n/a |
| `TaskPolicy::view` | May delegate to `TicketPolicy::view` where existing or internal code needs an answer | Grants **no** Tasks-workspace exposure: `TaskQuery` excludes ticket tasks (§9.1) |
| `TaskPolicy` mutations (`update`, `complete`, `reopen`, `delete`, `assign`) | All **deny** | No Complete/Edit/Delete/Assign control or route for a ticket task |
| `TaskService` | Recognizes a ticket-kind task sufficiently to **fail safely**: every EPIC-014 operation refuses it with a domain exception and changes nothing | No ticket-task mutation behaviour |
| Delete guard | Generic (INV-11): it keys on `time_entries.task_id`, whatever the kind | No delete route for a ticket task |
| Tasks list, My Tasks, All Tasks | **Excluded** (§9.1) | No ticket rows, no `TICKET` source tag in the workspace, no ticket kind in the filter DTO |
| Task detail | `GET /tasks/{task}` → 404 for a ticket task | No ticket-task detail page; contextual time does not apply to a surface that does not exist |
| Filters | `kind` offers `project` and `standalone` only | |

## 12. Time and billing boundary

### 12.1 Shared recorded-time guard

`ProjectService::deleteTask` currently holds the D4 check, the FK-violation mapping and the message. WP1 **lifts** the check, mapping and message into one shared unit (working name `RecordedTimeGuard`) used by:
- `ProjectService::deleteTask`, still inside its column locks;
- `ProjectService::deleteProject`;
- the new standalone delete, inside a transaction that locks the task row.

There is **no second copy** and no bare `$task->delete()` anywhere on a request path. A static test asserts that `Task::delete`/`destroy` is called only through the guarded services (§16.2).

### 12.2 High-risk interactions

| Interaction | Risk | Plan |
|---|---|---|
| Task delete | Silently nulled or lost history | INV-11/INV-12; guard shared (§12.1); `ProjectDeletionGuardTest` extended to standalone rows (the guard keys on `time_entries.task_id`, so it is kind-agnostic) |
| Changing project or kind | Re-attributes historical and billed time to another project | Forbidden (INV-9). No endpoint accepts `project_id`/`ticket_id` on update; pinned |
| Complete while a timer runs | Stopping someone else's timer breaks timer ownership | INV-15: no time entry is touched |
| Standalone reassignment (actor ↔ null) | `AccessibleTimeContext::canUseTask` admits the assignee, so releasing a task removes the releaser's eligibility for **new** entries; an existing unbilled entry re-validated on edit could then fail | **Characterize first (WP1):** pin what `TimeEntryService::update` does when an entry's task is no longer eligible. Billed/invoiced entries remain locked regardless (INV-14). If the characterization shows an edit of an unrelated field is rejected, WP2 records it and the owner decides; it is **not** silently "fixed" in the time domain |
| Unassigned standalone time | The creator of an unassigned standalone task can view it (Q4) but is not eligible to log time on it: `AccessibleTimeContext` is unchanged | Kept as-is (§22 P5): the time panel shows start only when the server says the actor is eligible; the creator can assign the task to themself. No time-domain rule changes in this epic |
| Rename | Reports/CSV read `task.title` live, so historical report text follows renames | Existing behaviour, unchanged. Invoices do not reference tasks (their link is `time_entries.invoice_id`). Delete is still blocked while time exists |
| `tasks.created_by` cascade | Deleting a user would cascade-delete their tasks, blocked by RESTRICT where time exists | Latent and out of scope: no user-deletion route exists (EPIC-011E §27). Recorded, not changed |

## 13. Routes, controllers and DTOs

### 13.1 Routes

| Method | URI | Name | Authorization | WP |
|---|---|---|---|---|
| GET | `/tasks` | `tasks.index` | authenticated; `view=all` needs `tasks.view_all` (clamped) | WP3 |
| POST | `/tasks` | `tasks.store` | authenticated (existing) | WP2 (moves into the service) |
| GET | `/tasks/{task}` | `tasks.show` | `TaskPolicy::view`; board → redirect, ticket → 404 | WP2 (backend), WP5 (page) |
| PUT | `/tasks/{task}` | `tasks.update` | `TaskPolicy::update`; standalone only (board → 404: board edits stay on `projects.tasks.update`) | WP2 |
| DELETE | `/tasks/{task}` | `tasks.destroy` | `TaskPolicy::delete`; standalone only (board → 404: board delete stays on `projects.tasks.destroy`) | WP2 |
| PUT | `/tasks/{task}/complete` | `tasks.complete` | `TaskPolicy::complete`; board and standalone (ticket denied) | WP2 |
| PUT | `/tasks/{task}/reopen` | `tasks.reopen` | `TaskPolicy::reopen`; board and standalone (ticket denied) | WP2 |
| PUT | `/tasks/{task}/assignee` | `tasks.assignee.update` | `TaskPolicy::assign`; §7.3 target rules | WP2 |
| POST | `/tasks/bulk` | `tasks.bulk` | per task, each through `TaskPolicy` (§15.2) | WP2 |

Every existing `projects.tasks.*` route is **unchanged**. The `{task}` bindings are plain integer ids, as today. Unauthorized access returns 403, a mismatched kind 404, mirroring the existing `abort_unless` pattern.

### 13.2 Controller shape

`TaskController` stays the Tasks-workspace controller: index, store, show, update, destroy, complete, reopen, assignee, bulk. The actions are thin: authorize, validate, call `TaskService` / `TaskQuery`, present, redirect-back. `ProjectTaskController` is unchanged except where the WP1 extraction of `taskRules`' assignee closure and the delete guard touch it, with identical behaviour.

### 13.3 DTOs

**`TaskRow`** (the list) keeps every current field: id, title, priority, status DTO, dueDate, overdue, assignee `{id,name}`, context `{kind,label,url}`, url. It gains:
- `kind` (`board|standalone`; the only kinds the workspace surfaces, Q6), which the source tag needs;
- `abilities: {complete, reopen, assign}`, computed in **batch** (one membership lookup per page, as `openableProjects` already does; never a policy query per row);
- `url` for standalone rows (`tasks.show`) when viewable.

**Index props**
- `view`, `filters` (the normalized, clamped echo of the request), `filterOptions` (§9.5), `sort`, `canViewAll`, `createOptions`.
- `canViewOrg` is removed.

**Task detail**
- One shared shape for the header and the common fields, with **kind-scoped** sections.
- Board detail keeps its current props (checklist, comments, options, abilities, timeSummary) and adds `abilities.complete`/`reopen`.
- Standalone detail provides `task`, `abilities {update, complete, reopen, delete, assign}`, `options {priorities, statuses, assignees: [me, none]}` and `timeSummary`.

All DTOs keep INV-17: no email, no raw attributes, no unrelated ids.

## 14. Direction D surface plan

### 14.1 Tasks index (`pages/tasks/index.tsx`), WP4

```
PageFrame width="canvas"
  PageHeader  title = view label ("My tasks" / "All tasks"), primary action "New task" → FormDialog
  FilterBar   search · completion · priority · due · kind · project (+ milestone) · assignee (All) · organization
              active filters as FilterChips; "Clear filters"
  DataTable   columns: Complete ring · Task (title + source tag) · Status · Priority · Context · Assignee · Due
              [+ selection column for BulkBar]
  BulkBar     (on selection) Complete · Reopen
  EmptyState  truly empty vs filtered empty (Direction D §15.2)
  Pagination
```

- **Views** come from the shell drawer and breadcrumb (`tasks.mine`/`tasks.all`). The in-page tabs are **deleted** when WP3's views land, because the shell owns navigation.
- **Status and priority** follow [Direction D §10.2/§10.3](../design/direction-d-design-system.md#102-tasks):
  - an Open task shows a hollow ring, which *is* the Complete control;
  - Done shows a ring with a check (`success-glyph`) and muted row text;
  - Overdue shows a clock glyph plus the due date in `danger` at weight 500; "Today" is plain;
  - a Running row uses `live-soft`;
  - the source tag is mono (`BOARD`/`STANDALONE`; `TICKET` is a Direction D kind this epic does not surface, Q6);
  - a board status shows the column name;
  - priority uses the three-bar glyph.
- **S width (D9):** rows reflow to two-line rows (title and complete on the first line; status, due and context on the second). There is no document-level horizontal scroll, which closes A13.5. The clipping `overflow-hidden` wrapper goes.
- **`DataTable`, `FilterBar`, `Chip`/`FilterChip` and `BulkBar`** are built as shared components (`components/ui/` or `components/data-table/`) with Tasks as their first consumer. They must be presentation-only: no task knowledge, no data fetching. That lets Projects UX expansion and Helpdesk reuse them. They are not over-generalized beyond what Tasks needs.
- **Create:** `FormDialog`, replacing the inline collapsible form, with the same fields and the server-enforced assignee set.

### 14.2 Task detail, WP5

One shared grammar for board and standalone:

```
PageFrame width="grid" header={EntityHeader} aside={…} asideLabel="Task details"
  EntityHeader  overline = "Task" (+ project name for board) · title · status (Status) · actions (Complete/Reopen, Edit, Delete per abilities)
  Strata
  main:  Section "Description"
         [board] Section "Checklist" · Section "Comments"
  aside: Details (assignee incl. departed-member note, due, priority, [board] milestone, [board] column)
         Time (TaskTimePanel)
```

- **Board detail** (`pages/projects/tasks/show.tsx`) keeps its route and its Projects-workspace ownership. It **removes the page-owned `<nav aria-label="Breadcrumb">`** (A13.12). The shell breadcrumb receives the project/task trail through the page-supplied trail prop that [EPIC-013 §13.2](./EPIC-013-direction-d-shell-design-system.md#132-component-responsibilities) defines for `Breadcrumb` (no page supplies one yet; `components/shell/breadcrumb.tsx` notes the seam), and the WP includes whatever minimal shell prop that segment needs. The sidebar edit form becomes an Edit `FormDialog` (or an inline-edit Section) over the same `TaskEditForm` fields.
- **Standalone detail** (a new page `pages/tasks/show.tsx`) uses the same grammar with only the sections it has: Description, Details, Time. It shows no empty board sections.
- A shared task-form field set is extracted from `TaskEditForm` and `CreateTaskForm`, so the two forms stop diverging. Board-only fields (milestone, member assignee) are rendered only when their options are present.

### 14.3 Out of surface scope

The board page and its cards (Projects-owned; §5.3), the Home dashboard (S4), and the peek inspector (S3).

## 15. Keyboard, bulk and contextual time

### 15.1 Keyboard (required minimum, R10)

- Every row action is reachable with standard Tab/Enter. The Complete ring is a real `button` with an accessible name ("Complete *title*" / "Reopen *title*"), and the title is a link.
- **Row shortcuts**, active **only while focus is inside the Tasks table and not in a text field**:
  - `J`/`K`: next/previous row;
  - `Enter`: open;
  - `E`: Complete/Reopen the focused row;
  - `X`: select the row.
- Scoping the shortcuts to the widget's focus satisfies WCAG 2.1.4 without a preference setting (Direction D §14.3).
- After Complete, focus moves to the next row (Direction D §14.2).
- `T` (timer) belongs to S1, and `?` (shortcut sheet) to S5.

### 15.2 Bulk (required minimum, R10)

- Selection via a checkbox column and `X`. A `BulkBar` offers **Complete** and **Reopen** on the selected rows (at most one page, ≤ 30).
- `POST /tasks/bulk {action: complete|reopen, ids[]}`:
  - authorizes and executes **each task independently** through `TaskService`, in its **own transaction**, so the lock ordering of INV-4 is never widened to many columns at once;
  - returns a per-task result summary (succeeded / not permitted / configuration error);
  - never does all-or-nothing across unrelated projects;
  - caps and deduplicates `ids`.
- Bulk assign is S2.

### 15.3 Contextual time (required minimum, R9)

- **Every task detail surface EPIC-014 delivers, board/project task detail and standalone task detail:** `TaskTimePanel` with the shared `TimerControl`, exactly as EPIC-011D/013 built it. Start is offered only when the server-provided eligibility (`time.log` + `AccessibleTimeContext`) allows it.
- **Tasks list:** a row whose task has the **viewer's** running timer shows the Direction D running state (`live-soft` row, time in `live-text`). This comes from the existing `TimerProvider` client state: no new server query and no second timer state.
- Row-level start/stop (S1) is separable. Timer UX NEXT items are out (§5.3).

## 16. Test strategy

### 16.1 Existing coverage to preserve (must stay green, unmodified unless named in §16.3)

| Area | Suites |
|---|---|
| Board domain and ordering | `ProjectIntegrityTest`, `ProjectMoveConcurrencyTest` (+ `tests/Support/project_task_worker.php`) |
| Time/deletion integrity | `ProjectDeletionGuardTest`, `TimeEntryForeignKeyMigrationTest`, `ProjectIntegrityAuditTest` |
| Authorization | `ProjectAuthorizationMatrixTest`, `ProjectPinnedBehaviorTest` (except the D3 pin), `ProjectVisibilityTest` (except the org-tab cases) |
| Inertia DTOs | `TaskListInertiaTest`, `ProjectTaskDetailInertiaTest`, `ProjectBoardInertiaTest` |
| Budget | `ProjectQueryBudgetTest` |
| Navigation | `NavigationBuilderTest`, `ShellContractTest` (the tasks.org cases are rewritten, not deleted) |
| Time | `Time/TimeTrackingTest`, the timer-context-by-kind case in `ProjectIntegrityTest`, the EPIC-010C billed-lock suite |
| Vitest | `pages/tasks/index.test.tsx`, `pages/projects/tasks/show.test.tsx`, `components/tasks/*.test.tsx`, `components/projects/task-*.test.tsx`, `move-task-menu`, `quick-add-task`, `timer-control` |
| Playwright | `tests/Browser/tasks-migration.spec.ts`, `tests/Browser/task-detail-migration.spec.ts`, the shell specs |

### 16.2 New coverage

| Coverage | Level | WP |
|---|---|---|
| `TaskPolicy` matrix: every ability × each kind × the `ProjectAuthorizationMatrixTest` actors (+ standalone creator, standalone assignee, stranger, `tasks.view_all` holder) | Pest | WP1 |
| Manager vs member-assignee Complete/Reopen; former member assignee denied; member-assignee **cannot** `move` | Pest | WP1/WP2 |
| Done-column invariant: zero, one and two Done columns → error vs success; no guess; no 500; audit check lists misconfigured projects | Pest | WP1/WP2 |
| Complete → Done column tail via `moveTask`; already done is a no-op; `status` untouched (INV-2); dense positions after; concurrency worker still passes | Pest | WP2 |
| Reopen → first non-Done column by position (Backlog with default columns); no-op when open | Pest | WP2 |
| Standalone lifecycle by creator and by assignee; stranger denied; no cross-person assignment | Pest | WP2 |
| **Unassigned standalone stays discoverable to its creator** in My Tasks, and not to anyone else | Pest (+ Playwright) | WP1 (characterize F1) / WP3 |
| Standalone delete with recorded time (billed, invoiced, stopped, running) is refused, with entries untouched; the FK race maps to the same message | Pest | WP2 |
| Guard uniqueness: no request-path `Task` delete outside the guarded services | Pest (static/architectural) | WP1 |
| INV-9: update rejects/ignores `project_id`, `ticket_id` on every task update route | Pest | WP1 |
| INV-15: Complete leaves every time entry, running ones included, byte-identical | Pest | WP2 |
| Ticket task: absent from My Tasks and All Tasks for every actor (including a `tasks.view_all` holder and the ticket's owner) and from every filter combination; every mutation route denied; `tasks.show` 404; `TaskService` refuses it without changing anything; `TaskPolicy` `view` delegation and mutation denials pinned | Pest | WP1/WP2/WP3 |
| All Tasks: gated by `tasks.view_all`; clamps without it; excludes others' standalone; equals the union of `view`-able rows | Pest | WP3 |
| **Filters never widen**: property-style, every filter/search/sort combination ⊆ unfiltered view, for several actors | Pest | WP3 |
| Search escaping; sort determinism; filter clamping; `view=org` clamps to mine | Pest | WP3 |
| Query budget constant across filters, search, All Tasks and option lists | Pest | WP3 |
| Navigation: `tasks.mine`/`tasks.all` gating and active state; `tasks.org` gone; `tasks.show` active under Tasks | Pest | WP3 |
| Bulk: per-task authorization, partial results, independent transactions, cap and dedupe | Pest | WP2 |
| DataTable/FilterBar/BulkBar/Chip components; Complete ring states; shortcuts inactive in text fields; focus to next row | Vitest | WP4 |
| Tasks list at S: no document horizontal scroll, two-line rows (A13.5) | Playwright | WP4 |
| Explicit Complete/Reopen browser flows: list and detail; manager and member-assignee; board task lands in Done | Playwright | WP4/WP5 |
| **Duplicate breadcrumb retired**: exactly one `navigation` landmark named Breadcrumb on task detail | Vitest + Playwright | WP5 |
| Standalone detail: edit, complete, delete; delete blocked when time exists | Playwright | WP5 |

### 16.3 Pins that change, rewritten to the new rule and never simply deleted

| Existing pin | New assertion |
|---|---|
| `ProjectPinnedBehaviorTest` "PINNED (D3): standalone tasks have no route beyond tasks.index and tasks.store" | The standalone route surface is exactly §13.1's set, each with its `TaskPolicy` ability (re-pinned under Q4) |
| `ProjectIntegrityTest` "accepts a standalone assignee only when it is the actor or none" / "keeps standalone validation unchanged" | Kept on create, and extended to update/assign per §7.3 |
| `ProjectVisibilityTest` org-tab cases ("limits the org tab…", "gives an admin the org tab…", "shows no org rows to a user without tasks.view_org…") | Rewritten to the organization **filter** (narrows only, grants nothing) and to All Tasks visibility |
| `TaskListInertiaTest` ticket-row cases ("derives a ticket row's status from its raw status field…", the ticket arm of "reports context.kind…") and `ProjectVisibilityTest` "renders no ticket link that TicketPolicy would deny" | Rewritten to the Q6 rule: a ticket-kind task is absent from My Tasks and All Tasks (WP3). Ticket-kind status semantics stay pinned at the model/presenter level (`ProjectPinnedBehaviorTest`), which the workspace does not exercise. Nothing is deleted without its replacement assertion |
| `ProjectVisibilityTest` "keeps standalone tasks unlinked and the mine tab scoped to the assignee" | Standalone rows link to `tasks.show` when viewable; My Tasks follows the Q4 rule |
| `NavigationBuilderTest` "tasks org view" | Replaced by `tasks.all` (`view=all`) active-state cases |
| `ProjectAuthorizationMatrixTest` `tasks.index`/`tasks.store` rows | Extended with every new `tasks.*` route |
| `tasks-migration.spec.ts` "…the standalone row offers no mutation controls (D3)" | The standalone row offers exactly the controls its abilities allow, and the fixture is removed through `DELETE /tasks/{task}` (A9.7) |

### 16.4 Gates

- **Every WP:** `./dev check` green (CLI self-tests, `git diff --check`, Pint, `npm run check`, full Pest against MariaDB), plus PR CI green.
- **WPs that touch UI or routes (WP4, WP5, WP6, WP7):** `./dev test:e2e` green as well, with the suite's 3-worker cap. `./dev check` and `./dev test:e2e` are run one after the other, never at once ([`docs/testing/ci.md`](../testing/ci.md)).
- **WP1 and WP2** also re-run `ProjectMoveConcurrencyTest` explicitly and record its result.

## 17. Work packages

Each WP is one reviewable PR to `main`, leaves the product shippable, and ends with an amendment to this document (results, deviations, gate evidence).

The discovery structure is kept with one boundary change: **bulk Complete/Reopen and the narrow assign endpoint move into WP2.** They are server semantics of Complete and assignment, and putting them there lets WP4 be UI-only.

### WP0: Epic and decisions (this document)

- **Objective:** make EPIC-014 the authoritative Planned epic; lock Q1–Q6; record the D3 supersession.
- **In scope:** this file; `docs/epics/README.md`; the Product Roadmap link/status; forward-pointer notes in EPIC-011E (D3) and EPIC-013 (§11.1 Tasks row, §31 items).
- **Out of scope:** any source, test or migration change.
- **Gates:** `git diff --check`; relative links and anchors verified; index/roadmap consistency.
- **Exit:** reviewed and merged to `main`.

### WP1: Characterization and domain/authorization foundation

- **Objective:** pin current behaviour, then introduce `TaskPolicy`, the `TaskService` skeleton, the Done-column resolver, the shared recorded-time guard and the new audit checks, with **no user-visible change**.
- **In scope:**
  - characterization tests for F1 (the orphan, pinned as current behaviour and marked to flip in WP3), F9, the reassignment/time-edit behaviour (§12.2) and INV-9;
  - `app/Policies/TaskPolicy.php` (registered; used by no route yet except as tests exercise it);
  - `app/Services/TaskService.php` (kind dispatch; board paths delegate to `ProjectService`);
  - the Done-column resolver and its exception;
  - extracting `RecordedTimeGuard` from `ProjectService` (identical behaviour);
  - extracting the board assignee-validation closure;
  - `ProjectIntegrityAudit` checks for Done-column count ≠ 1 and for `project_id`+`ticket_id` both set.
- **Out of scope:** new routes, UI, permission changes, the My Tasks rule change.
- **Code areas:** `app/Policies`, `app/Services/{TaskService,ProjectService,ProjectIntegrityAudit}.php`, a shared guard/rule class, `AppServiceProvider` (`Gate::policy(Task::class, TaskPolicy::class)`, beside the existing Ticket/Project/Invoice registrations), `tests/Feature/Tasks/*` (new), `tests/Feature/Projects/*`.
- **Invariants:** INV-1–INV-14, INV-17; every §16.1 suite unchanged and green.
- **Test-first:** characterization lands before any extraction. Extraction commits are behaviour-neutral, proven by the unchanged `ProjectDeletionGuardTest`, `ProjectAuthorizationMatrixTest` and `ProjectMoveConcurrencyTest`.
- **Exit:** the `TaskPolicy` matrix is complete and green; the guard is shared, with no duplicate; the audit checks exist; `./dev check` and CI are green; no route, prop or UI diff.
- **Depends on:** WP0.

### WP2: Complete/Reopen, standalone lifecycle and assignment (backend)

- **Objective:** ship the §13.1 mutation routes: complete, reopen, bulk, assignee, standalone show (redirect/404 rules), update, destroy, with store moved into `TaskService`.
- **In scope:** the routes, the `TaskController` actions, the `TaskService` operations, `DoneColumnConfigurationException` mapping, bulk semantics (§15.2), minimal DTO additions (`abilities`) needed by later UI, and rewriting the D3 pin.
- **Out of scope:** list query/filter changes, navigation, React.
  - A deliberately minimal standalone detail **page** may be registered so `GET /tasks/{task}` renders. The Direction D page itself is WP5. If a bare page would be misleading, the route may ship in WP5 instead; the WP2 amendment records which.
- **Invariants:** INV-2–INV-4, INV-8–INV-12, INV-14, INV-15, INV-17.
- **Test-first:** Done-column error cases and INV-15 pins are written before the service methods.
- **Focused tests:** §16.2 rows for WP2; the concurrency worker re-run.
- **Exit:**
  - a manager and a member-assignee can Complete/Reopen board tasks through the API, and a member-assignee cannot `move`;
  - misconfigured projects fail safely;
  - the standalone lifecycle works for creator and assignee;
  - a standalone task with time cannot be deleted;
  - `./dev check` and CI green.
- **Depends on:** WP1.

### WP3: Task query, My/All Tasks, filters, indexes and navigation

- **Objective:** implement §9 in full, the `tasks.view_all` permission and the §9.8 navigation contract.
- **In scope:**
  - `TaskQuery` (with the surfaced-kinds predicate, §9.1);
  - `TaskController::index` props (`filters`, `filterOptions`, `sort`, `canViewAll`; remove `canViewOrg`);
  - `PermissionCatalogue` + seeder impact; `NavigationBuilder` (`tasks.all`, retire `tasks.org`);
  - `EXPLAIN`-justified indexes only (a migration only if justified);
  - the `rbac-design.md` update.
- **Transitional UI:** the existing page receives the new props with the minimum change needed to stay correct: the in-page tabs switch to mine/all or are removed; a bare search box is optional. Full UI is WP4.
- **Out of scope:** the Direction D list UI.
- **Invariants:** INV-16, INV-17, INV-18, INV-19.
- **Test-first:** a "filters never widen" property test and the All Tasks visibility union are written first.
- **Exit:**
  - F1 fixed (the WP1 characterization flipped);
  - All Tasks gated and visibility-correct;
  - every filter narrows;
  - the query budget holds;
  - navigation updated;
  - `./dev check`, CI and `./dev test:e2e` green (navigation changes are browser-visible).
- **Depends on:** WP1 (policy/visibility); independent of WP2 except for `abilities` in the DTO (WP3 merges after WP2).

### WP4: Tasks list UI (Direction D)

- **Objective:** §14.1 and §15.1–§15.3 on the list.
- **In scope:** canvas frame, `PageHeader`, `FilterBar`/`FilterChip`, `DataTable`, `BulkBar`, `EmptyState`, the Complete ring, row shortcuts, bulk Complete/Reopen, the running-timer row state, D9 rows at S, the create `FormDialog`, and in-page tab removal.
- **Out of scope:** S1–S5; task detail.
- **Code areas:** `resources/js/pages/tasks/index.tsx`, new shared data-table/filter/bulk components, `components/tasks/*`, `types/tasks.ts`, the Vitest suites, `tests/Browser/tasks-migration.spec.ts`.
- **Invariants:** INV-17, INV-19; the server remains the only authority (a hidden control never substitutes for a 403).
- **Test-first:** Vitest for the shared components before the page adopts them.
- **Exit:**
  - A13.5 closed: no horizontal document scroll at S, measured in Playwright;
  - Complete/Reopen and bulk work in the browser;
  - truly empty vs filtered empty is correct;
  - `./dev check`, `./dev test:e2e` and CI green.
- **Depends on:** WP2, WP3.

### WP5: Task detail and edit (Direction D)

- **Objective:** §14.2 for board and standalone detail.
- **In scope:**
  - board detail migrated to `EntityHeader` + `Strata` + grid `PageFrame` + aside;
  - page breadcrumb removed (A13.12) and the shell's page-supplied trail segment wired;
  - Complete/Reopen and Delete actions per abilities;
  - the standalone detail page;
  - shared task form fields;
  - Edit as a `FormDialog` or inline Section.
- **Out of scope:** checklist/comments behaviour changes; board page changes.
- **Invariants:** INV-6, INV-7 (departed-assignee note stays), INV-15, INV-17.
- **Exit:**
  - exactly one Breadcrumb landmark on every task detail page;
  - standalone detail is fully usable, with delete blocked when time exists;
  - member-assignee Complete works in the browser;
  - `./dev check`, `./dev test:e2e` and CI green.
- **Depends on:** WP2. WP4 is not strictly required (WP5 may merge before or after WP4), but the shared form fields must not fork.

### WP6: Approved separable enhancements (optional)

- **Objective:** any of S1–S5 the owner chooses, **each as its own small PR**.
- **Rule:** WP6 is **not** required for §18. The epic may reach Verified with WP6 empty, and unshipped items return to §20 as deferred.
- **Depends on:** WP4 (S1–S3, S5); WP3 (S4).

### WP7: Hardening and closeout

- **Objective:** verification against §18; close A9.7; documentation reconciliation.
- **In scope:**
  - Playwright end-to-end Complete/Reopen/standalone flows across personas;
  - `E2eCleanup` tracks standalone task ids and deletes them through `DELETE /tasks/{task}` (**no raw SQL**), with the `tests/Browser/support/e2e-fixtures.ts` header note and the `tasks-migration.spec.ts` AA10 note rewritten;
  - the §16.3 rewrites confirmed;
  - the doc sweep: roadmap status, epic index, EPIC-011E/EPIC-013 forward notes, `docs/testing/e2e-browser-suite.md` if the fixture budget changes;
  - an accessibility pass on the new widgets (keyboard, focus, names); exhaustive AT, device and browser matrices stay with FINAL HARDENING.
- **Exit:** every §18 criterion checked with evidence; status → Verified; → Done after the merge lifecycle.
- **Depends on:** WP2–WP5 (and whichever WP6 PRs merged).

**Dependency graph:** WP0 → WP1 → WP2 → WP3 → WP4 → WP7. WP5 depends on WP2 and runs in parallel with WP3/WP4. WP6 is independent and optional.

## 18. Exit criteria

EPIC-014 is **Verified** when all of these hold. S1–S5 (WP6) are **not** criteria.

1. `TaskPolicy` exists and governs every `tasks.*` route; its matrix test covers every ability × kind × actor; board abilities delegate to `ProjectPolicy`; `ProjectAuthorizationMatrixTest` is unchanged for every `projects.*` route.
2. Complete/Reopen work per §8 for board and standalone tasks, from the list and from detail, for managers and member-assignees; member-assignees cannot move; ticket tasks are not surfaced and cannot be mutated.
3. A project with zero or several Done columns fails Complete/Reopen with the clear configuration error, never a 500 or a guess, and is listed by `ProjectIntegrityAudit`.
4. My Tasks follows Q4 exactly (F1 fixed); All Tasks is gated by `tasks.view_all`, equals the union of `view`-able rows, and excludes others' standalone tasks; "My organization" is gone as a view.
5. Search, sort and every §9.5 filter work server-side from URL state, are validated/clamped, and are proven never to widen visibility; the query budget holds; any added index is justified by `EXPLAIN`.
6. Standalone detail, edit, Complete/Reopen and delete work for creator and assignee only; delete is refused when time exists, through the shared guard.
7. Assignment works per §7.3 from detail and from the list for authorized actors.
8. The Tasks list uses the §14.1 grammar; A13.5 is closed; truly empty and filtered empty states are distinct.
9. Task detail uses the §14.2 grammar for board and standalone tasks; A13.12 is closed (exactly one Breadcrumb landmark).
10. R9 contextual time and R10 keyboard/bulk minimums are present and tested.
11. INV-1 to INV-19 hold, each with a named test.
12. A9.7 is closed: the browser suite leaves no standalone-task residue, cleaned through the supported delete route.
13. `./dev check`, `./dev test:e2e` and PR CI are green on the final package.

## 19. Superseded prior decisions

### 19.1 EPIC-011E D3: standalone tasks are create/list only

- **Original decision** ([EPIC-011E Amendment 1](./EPIC-011E-projects-kanban.md#amendment-1-locked-decisions-and-clarifications-2026-09-21), locked 2026-09-21): "Standalone tasks keep their current create/list capability; no edit, delete, or complete." It was pinned by `ProjectPinnedBehaviorTest` ("standalone tasks have no route beyond tasks.index and tasks.store") and produced the accepted test debt AA10/A9.7.
- **Why EPIC-014 supersedes it:** D3 was a *migration* lock. EPIC-011E moved the Blade page to React without adding capability. The Product/UX direction ([Task direction](../product/platform-product-ux-direction.md#task-direction)) later declared the current `/tasks` behaviour a migration artifact that "does not constrain" the Task product. D3 also leaves unassigned standalone tasks unreachable (F1).
- **New rule:** Q4 (§4, §10). Standalone tasks have detail, edit, Complete/Reopen and delete for the creator or current assignee, under the shared recorded-time guard.
- **When it changes:** decided 2026-09-29 (EPIC-014 WP0). Behaviour changes in **WP2** (backend) and **WP5** (UI). The D3 pin is rewritten in WP2. A9.7 closes in WP7.
- EPIC-011E's text is **not** rewritten; a forward-pointer note is added beside its decision table.

### 19.2 EPIC-013 §11.1 Tasks views ("My tasks, Organisation tasks")

- **Original:** EPIC-013 promoted the existing in-page tabs to drawer views `tasks.mine` / `tasks.org` (`view=org`, gated on `tasks.view_org`) and listed All Tasks as future.
- **Why:** Q5. Since EPIC-011E D2 a company link grants nothing, so the org view is mostly inert (it widens only through company-linked projects the viewer could already open), and operators without an organization never see it (F3).
- **New rule:** §9.8. My tasks + All tasks (`tasks.view_all`); organization becomes a filter.
- **When:** decided 2026-09-29; implemented in **WP3**. EPIC-013 is Done and is not rewritten; a forward-pointer note is added.

### 19.3 Tasks list "mine" semantics and ticket rows (EPIC-011E WP8)

- **Original:** "mine" = `assignee_id = me`, and the list could show ticket-kind rows (assigned to the viewer, or reached through the org view) with a context link.
- **New:** Q4's two-arm rule over the surfaced kinds (§9.2). Ticket-kind tasks leave the workspace list under Q6 (§9.1) until ticket-task UX is designed; no ticket data is changed.
- **When:** WP3.

### 19.4 Discovery-report correction (not a prior decision)

The discovery report's "missing indexes on `assignee_id`/`created_by`" was wrong; see §3.1 and §9.7.

## 20. Deferred and inherited items

| Item | Disposition |
|---|---|
| A13.5 `tasks.index` clipped at S | **Closed by WP4** |
| A13.12 duplicate task-detail Breadcrumb | **Closed by WP5** |
| A9.7 standalone-task E2E residue | **Closed by WP7** through the supported delete route (no separate cleanup project, no raw SQL). Leftover rows from earlier runs are development data, disposable under roadmap principle 8; they may be removed through the new route or a normal reseed |
| `tasks.view_org` inert after Q5 | Permission debt, recorded; not deleted in this epic (§22 P1) |
| `time.view_own` unenforced (EPIC-011E C5) | Unchanged |
| `task_dependencies` schema-only | Unchanged (§5.3) |
| `tasks.created_by` user-delete cascade | Unchanged (§12.2) |
| Plex Sans 600 M2, `tests/Browser` lint/typecheck gap, jsdom load sensitivity, vacuous Pest guards (A13.13), operator-persona timer isolation (A11.15), lockfile caret (A11.2) | Not absorbed; they stay with their existing owners ([EPIC-013 §31](./EPIC-013-direction-d-shell-design-system.md#31-deferred-follow-on-work), [`docs/testing/ci.md`](../testing/ci.md#known-limitations-and-deferred-items)) |
| S1–S5 not shipped in WP6 | Return here as deferred at closeout |

## 21. Risks and rollback

| # | Risk | Likelihood | Impact | Mitigation | Rollback |
|---|---|---|---|---|---|
| R1 | A second source of task-authorization truth diverges from `ProjectPolicy` | Medium | High | Board abilities delegate; `ProjectAuthorizationMatrixTest` unchanged; `TaskPolicy` matrix shares its actors | Revert the WP; no data change |
| R2 | Complete via `moveTask` regresses board ordering or concurrency | Low | High | No new ordering code; concurrency worker re-run in WP1/WP2 | Revert WP2's routes; the board is untouched |
| R3 | A filter or All Tasks leaks rows | Medium | High | Fixed composition order and surfaced-kinds predicate (§9.1); property test | Revert WP3; the list falls back to the prior query |
| R4 | Standalone delete bypasses the time guard | Low | High | One shared guard; static test; RESTRICT backstop | Revert WP2 |
| R5 | Shared `DataTable`/`FilterBar` over-generalized, slowing WP4 | Medium | Medium | Build only what Tasks uses; presentation-only contract | Keep them in `components/tasks/` and promote later |
| R6 | Standalone reassignment affects time-entry edits (§12.2) | Low | Medium | WP1 characterization first; owner decision if a real regression shows | Keep the pre-existing assignment rule |
| R7 | Retiring `view=org` surprises users of the org tab | Low | Low | Q5 accepted; the organization filter replaces it | n/a (deliberate) |
| R8 | The optional WP6 delays closeout | Medium | Low | §18 excludes S1–S5 | Ship without them |

## 22. Plan-level choices open to review

None blocks WP1. Each is the plan's default, derived from the locked decisions, and the owner may override any of them when reviewing this document.

| # | Choice | Rationale |
|---|---|---|
| P1 | `tasks.view_org` stays in the catalogue, inert | EPIC-011E C2 precedent for `projects.view_org`; removal needs permission-row cleanup for no product gain |
| P2 | The default completion filter is **open** | Complete removes the row from the working view (Direction D §14.2); `done`/`any` remain one filter away |
| P3 | Complete/Reopen place the board task at the destination column's **tail** | Requires no new ordering rule; `moveTask` clamps |
| P4 | A null-column board task completes by joining the Done column through `moveTask` | Keeps board tasks column-authoritative rather than writing `status` |
| P5 | `AccessibleTimeContext` is unchanged; the creator of an unassigned standalone task must take it to log time | No time-domain rule change in this epic |
| P6 | `GET /tasks/{task}` redirects board tasks to `projects.tasks.show` and 404s ticket tasks | One canonical board detail; Q6 |
| P7 | Search matches title only | Keeps search inside visible fields; avoids oracle-class issues |
| P8 | No Complete control on board cards | Board belongs to Projects UX expansion; Complete is reachable from detail and the list |

## 23. Branch and PR strategy

- **WP0:** branch `docs/epic-014-tasks-overhaul`, a single documentation PR to `main`, not auto-merged.
- **Implementation:** after WP0 merges, `feature/epic-014-tasks-overhaul` is cut from current `main`.
  - Each WP is a **package-sized PR to `main`**. Where parallel work needs it, sub-branches are cut from the epic branch.
  - After each package merges, the epic branch is **synced to current `main`** (merge, not rebase of published history) before the next package starts. **No force pushes.**
- **CI:** the PR gate (`pull_request` → `main`) is the merge gate for every package. `./dev check` (and `./dev test:e2e` for UI/route packages) runs locally before opening each PR.
- **Record:** each package's results are recorded as an amendment to this document in the same PR.
