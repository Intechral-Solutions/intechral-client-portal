# EPIC-015: Projects UX Expansion

**Status:** Planned (WP0 planning, 2026-10-02). No implementation has started.
**Class:** Product functionality (Product Roadmap [NEXT — Core work management → Projects UX expansion](../product/product-roadmap.md#projects-ux-expansion))
**Product direction:** [Platform Product & UX Direction → Project direction](../product/platform-product-ux-direction.md#project-direction) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md) (D3 artboard: **not in the repository**, see [§15](#15-design-reference-gate-d3))
**Prerequisites:** [EPIC-013: Direction D Application Shell and Design System Foundation](./EPIC-013-direction-d-shell-design-system.md) (Done) · [EPIC-014: Tasks Workspace Overhaul](./EPIC-014-tasks-workspace-overhaul.md) (Done) · Lightweight CI baseline (Done, [`docs/testing/ci.md`](../testing/ci.md))
**Related:** [EPIC-011E: Projects and Kanban Migration](./EPIC-011E-projects-kanban.md) (source of the current project, board and milestone architecture, and of the A9 rule) · [EPIC-011D: Time Tracking and Persistent Timer Migration](./EPIC-011D-time-tracking-timer.md) · [EPIC-010C: Billed Time-Entry Locking](./EPIC-010C-billed-time-entry-locking.md)
**Planning baseline:** `main` @ `76aa3c9` (`docs: close EPIC-014`), equal to `origin/main`, working tree clean, verified 2026-10-02

---

## Contents

1. [Goal](#1-goal)
2. [Relationship to the Product Roadmap](#2-relationship-to-the-product-roadmap)
3. [Current-state inventory](#3-current-state-inventory)
4. [Locked owner decisions](#4-locked-owner-decisions)
5. [Scope](#5-scope)
6. [Domain invariants](#6-domain-invariants)
7. [Authorization and customer-safety contract](#7-authorization-and-customer-safety-contract)
8. [Health contract](#8-health-contract)
9. [Milestone lifecycle contract](#9-milestone-lifecycle-contract)
10. [Project-time contract](#10-project-time-contract)
11. [Project workspace IA, routes and navigation](#11-project-workspace-ia-routes-and-navigation)
12. [Overview and Monitoring V1 contract](#12-overview-and-monitoring-v1-contract)
13. [Project Tasks contract](#13-project-tasks-contract)
14. [Direction D surface plan, StagePath and progress](#14-direction-d-surface-plan-stagepath-and-progress)
15. [Design reference gate (D3)](#15-design-reference-gate-d3)
16. [Test strategy](#16-test-strategy)
17. [Performance](#17-performance)
18. [Work packages](#18-work-packages)
19. [Exit criteria](#19-exit-criteria)
20. [Non-goals](#20-non-goals)
21. [Deferred and inherited items](#21-deferred-and-inherited-items)
22. [Customer-product roadmap gap](#22-customer-product-roadmap-gap)
23. [Risks and rollback](#23-risks-and-rollback)
24. [Plan-level choices open to review](#24-plan-level-choices-open-to-review)
25. [Branch and PR strategy](#25-branch-and-pr-strategy)

---

## 1. Goal

Turn a project from "a board with a settings page" into a **project workspace**, using only data that exists or is cheap to add, on the Direction D shell, without weakening the board, task, time or billing guarantees earlier epics hardened.

At the end of this epic:

- **`/projects/{project}` is the project's home**: an Overview that answers "how is this going?" with a derived, explained health signal, task progress, milestones, open and overdue work, the people involved and correctly attributed time.
- A project has four tabs: **Overview · Board · Tasks · Milestones**. Settings stays a header action.
- The **Tasks** tab is a project-scoped list built on EPIC-014's canonical `TaskQuery`, `DataTable` and row abilities, not a second task architecture.
- **Milestones are real checkpoints**: they are explicitly completed and reopened, and they drive a reusable `StagePath`.
- **Project time means one thing everywhere**: direct project time plus time on the project's tasks, each entry counted in at most one project. The Overview and the Time workspace agree.
- The remaining legacy project surfaces (index, milestones, create/edit) use Direction D grammar.
- Project creation is atomic, and a departed board assignee can no longer log new time on the task they were stored against.
- Every new surface is **safe for the customer members** who already reach project routes today: the Overview discloses nothing they cannot already see (no member roster, no budget, no other users' time).

This epic is deliberately **not** Advanced Projects ([§20](#20-non-goals)).

## 2. Relationship to the Product Roadmap

The committed roadmap item ([Product Roadmap → Projects UX expansion](../product/product-roadmap.md#projects-ux-expansion)):

> **Class:** Product functionality. Redesign the project workspace around Planning / Execution / Monitoring using data that exists or is cheap to add: milestones, tasks, members, time, budget. Add list view alongside the board; a first project-health/monitoring summary; stakeholder-friendly presentation. Outcomes, SOW linkage, baselines, and change control are Advanced Projects.
>
> **Depends on:** shell, design system; benefits from Tasks overhaul components.

The dependencies are satisfied: EPIC-013 and EPIC-014 are Done.

**How this epic reads the item:**

| Roadmap phrase | EPIC-015 interpretation |
|---|---|
| Planning / Execution / Monitoring | **IA concepts, not tabs.** Planning = Milestones (plus dates on the Overview); Execution = Board + Tasks; Monitoring = the Overview's health and progress ([§11](#11-project-workspace-ia-routes-and-navigation)) |
| List view alongside the board | The project **Tasks** tab. Direction D §5.3 names it the "Tasks tab"; "List" is not a committed label |
| First project-health/monitoring summary | Derived health with reasons, plus Monitoring V1 on the Overview ([§8](#8-health-contract), [§12](#12-overview-and-monitoring-v1-contract)) |
| Stakeholder-friendly presentation | **Deferred** (Q4). This epic makes the normal workspace customer-safe; it does not build a stakeholder view ([§22](#22-customer-product-roadmap-gap)) |
| Budget | Metadata only, shown only to actors with effective Settings/Edit access (Q3) |

**Overlap with Advanced Projects.** The roadmap lists "Multiple views (list, board, timeline)" and "Monitoring: … health" under Advanced Projects too. This epic takes **list + board + derived health from existing data**. Advanced Projects keeps timeline, estimated-vs-actual, burn/variance and anything that needs new planning models.

**Items handed to this epic by earlier epics:**
- **EPIC-013 [§31](./EPIC-013-direction-d-shell-design-system.md#31-deferred-follow-on-work):** "Projects expansion (D3), `StagePath`, `Priority`, project health, list view, Monitoring". `Priority` was already delivered by EPIC-014; the others are owned here.
- **EPIC-013 [A13.13](./EPIC-013-direction-d-shell-design-system.md#a1313-test-suite-audit):** the vacuous `ProjectIntegrityTest` guard. The `BrowserAuthContractTest` guard stays with its existing owner.
- **EPIC-014 [§5.3](./EPIC-014-tasks-workspace-overhaul.md#53-deferred-explicitly-out-of-epic-014) and §22 P8:** the Complete affordance on board cards. Optional here ([§5.2](#52-optional--separable-never-blocks-19)).
- **EPIC-014 [A1.3.1](./EPIC-014-tasks-workspace-overhaul.md#a131-deferred-follow-ups-non-blocking-not-assigned-to-a-wp):** stale board-assignee time eligibility (Q8) and non-atomic `ProjectService::create`. Both are required here.

## 3. Current-state inventory

Verified by reading the live code at `76aa3c9` (2026-10-02 discovery). Paths are relative to `src/`. No tests were run for this inventory.

### 3.1 Domain

| Area | Current |
|---|---|
| Project | `status ∈ {active, on_hold, completed, archived}` (manual; not tied to task completion), `start_date`/`target_date` nullable, `budget decimal(12,2)` nullable, `client_id` unused (`app/Models/Project.php`) |
| Board | Five default columns, exactly one `is_done_column`. No column-management route. Board-task completion is column-authoritative (EPIC-014 INV-1/2) |
| Milestone | `name`, `due_date` (required), `description`. **No completion state.** "Completion" is derived (done linked tasks / linked tasks) and "overdue" is `due < today ∧ completion < 100` (`ProjectMilestone::isOverdueAt`). **Quirk:** a milestone with no tasks is 0% complete, so it is overdue forever once its date passes |
| Progress | `Project::percentage(done, total)`, every task weighted equally. `scopeWithTaskStats` gives task, done, overdue and member counts |
| Budget | Labelled "Budget ($)" in `project-details-fields.tsx`. No currency column, no defined unit or meaning, no rate or cost model anywhere. Visible only on the edit page |
| Money linked to a project | `invoices.project_id` (optional). That is invoiced revenue, not consumption, and belongs to Finance |
| Creation | `ProjectService::create` inserts the project, five columns and the creator's membership with **no transaction**. `ProjectController::store` then calls `syncMembers` and `companies()->sync` separately |

### 3.2 Authorization

- `ProjectPolicy::view` = `projects.admin` or membership. `manage` = `projects.admin`, or `projects.manage` plus the manager pivot role. `manageMembers` = `projects.admin` only.
- **A9 (accepted, EPIC-011E):** management routes also carry `can:projects.manage` middleware, which is stricter than `ProjectPolicy::manage` for a `projects.admin` holder without `projects.manage`. Pages therefore offer management links from both (`openSettings`, the milestones `manage` ability).
- **Customers** (the `user` role) hold `projects.view` and `time.log`. They see the projects they are members of through the same routes and the same Operational shell (`shell.presentation` is always `operational`).
- `TaskPolicy` (EPIC-014) delegates board tasks to `ProjectPolicy`.

### 3.3 Time

- `TimeEntryController::contextRules` makes `project_id`, `task_id` and `ticket_id` mutually exclusive. **A task-attributed entry therefore has `project_id = NULL`.**
- `RecordedTimeGuard::deleteProject` uses `project_id = P OR task_id IN tasks(P)`. It is the existing predicate that includes both direct and task references, which is correct for **conservative delete safety**, but it is **not** the canonical reporting attribution rule: a malformed row could match two projects under it ([§10.2](#102-canonical-implementation-wp1)). `ProjectIntegrityAudit::projects_blocked_from_delete_by_time` mirrors the same conservative semantics.
- **Undercounting consumers** (they use `project_id` only, so they exclude task time):
  - `TimeEntryService::summaryByProject` and `applyFilters` (operator time report: by-project rows, total and CSV under a project filter);
  - `Operator\TimeReportController::index` entry list under a project filter, and its `projectName` column (null for task time);
  - the `/time` page project filter (`TimeEntryController::index`);
  - `TimeEntry::scopeForProject`, which has no caller.
- **No billing path selects time entries.** `InvoiceService` has no time-entry query, so correcting attribution changes reports and filters only, never an invoice.
- `AccessibleTimeContext::canUseTask` admits the stored assignee for **every** kind before any project check, so a departed board assignee can still start a timer or log new time (pinned as OBSERVED in `TaskCurrentBehaviorCharacterizationTest`).
- `timerStop` does not re-validate context. Editing an entry that keeps its unchanged task skips the task rule (EPIC-014 decision (c), `TimeEntryController::keepsTask`).

### 3.4 Routes and surfaces

| Route | Surface | Direction D? |
|---|---|---|
| `projects.index` | Card grid, `max-w-7xl`, legacy `PageHeader` | No |
| `projects.show` | **302 to `projects.board`** | n/a |
| `projects.board` | `PageFrame canvas` + `EntityHeader`; Milestones/Settings as header links | Yes |
| `projects.milestones.index` | `max-w-4xl`, cards with `Progress`, `MilestoneFormDialog` | No |
| `projects.create` / `projects.edit` | `max-w-5xl`, `SectionPanel` | No |
| `projects.tasks.show` | EPIC-014 shared task detail; trail via the shell breadcrumb | Yes |

- Every generic "go to project" link targets the Board: `project-card.tsx`, `TaskListPresenter` context, `TimeEntryController` context, the task-detail trail, and the milestones/edit back links. `store` also redirects to the Board.
- `GET /projects/{project}/tasks` is free (only `POST` is registered at that URI).
- The Projects drawer has one workspace-wide `PanelDefault::Open`. Direction D's per-surface default (Board collapsed) is not implemented.
- There is **no `Tabs` component** and **no `StagePath`**. `Progress` is a `progressbar` only; no `meter`, expected marker or overage (EPIC-013 A6.9 notes).

### 3.5 Tests that exist and stay authoritative

`ProjectAuthorizationMatrixTest`, `ProjectVisibilityTest`, `ProjectIntegrityTest`, `ProjectIntegrityAuditTest`, `ProjectQueryBudgetTest`, `ProjectMoveConcurrencyTest`, `ProjectDeletionGuardTest`, `ProjectPinnedBehaviorTest`, `ProjectMilestoneTest`/`InertiaTest`, `ProjectTaskDetailInertiaTest`, the `tests/Feature/Tasks` suite (`TaskPolicyMatrixTest`, `TaskQueryTest`, `TaskListPageTest`, `TaskCurrentBehaviorCharacterizationTest`), `tests/Feature/Time` (`TimeTrackingTest`, `TimeEntryTaskAttributionTest`, `BilledTimeEntryLockingTest`), `NavigationBuilderTest`, `ShellContractTest`, and the Playwright `projects-`, `board-`, `milestones-`, `task-detail-*` and `time-migration` specs.

## 4. Locked owner decisions

Locked 2026-10-02. Where later wording in this document seems to disagree, **this section wins**.

| ID | Decision |
|---|---|
| **Q1** | **Project health is derived, with no manual override.** Lifecycle rules first: `completed` → Complete; `on_hold`/`archived` → no derived health (the lifecycle status carries the meaning); `active` with a future `start_date` → Not started; `active` with no tasks and no milestones (and no future start) → a neutral "Not enough data" non-health state. For an active project with tracked work: **Off track** if `target_date` is past and open tasks remain, **or** any incomplete milestone is overdue; **At risk** if at least one open task is overdue; **On track** otherwise. The DTO carries `state`, `label` and `reasons[]`. No percentage or schedule thresholds. No schema. Full contract: [§8](#8-health-contract) |
| **Q2** | **Milestone completion is explicit stored state**: `completed_at` (nullable timestamp) and `completed_by` (nullable user FK). Complete/Reopen is an explicit action under the existing project-management/A9 rule. Task-derived milestone progress (done linked tasks / linked tasks) stays separate, informational data and is **never** the milestone's source of truth. A milestone is overdue when `due_date < today AND completed_at IS NULL`, so zero-task milestones are legitimate manual checkpoints. Full contract: [§9](#9-milestone-lifecycle-contract) |
| **Q3** | **Budget is metadata only.** No burn, consumption, forecast, hours-to-money conversion or invoice-derived consumption; no rate/cost model exists. On the Overview, the budget is sent only to actors who currently hold **effective Settings/Edit access** (the existing A9 conjunction, [§7](#7-authorization-and-customer-safety-contract)); every other viewer, customer members included, never receives it in their props. Money analysis belongs to Finance |
| **Q4** | **Dedicated stakeholder/customer presentation is deferred.** EPIC-015 builds the normal Projects workspace. Because customer members already reach these routes, every new surface must be safe for them (budget gated to effective Settings/Edit access as defined by Q3 and P6, never to `ProjectPolicy::manage` alone; the Overview names-and-roles roster gated the same way; other people's time gated to `time.view_all`; no internal-only field exposed merely because the Overview exists). The customer shell and customer product UX remain separate future work; the missing roadmap item is recorded as a planning gap ([§22](#22-customer-product-roadmap-gap)) |
| **Q5** | **`projects.show` is the Project Overview and the canonical project entity route.** The Board stays at `/projects/{project}/board`. Generic "open project" links target `projects.show`; explicit Board links and actions keep targeting the Board. Project creation lands on the Overview |
| **Q6** | **Project Tasks reuse the canonical Task architecture.** No separate project-task query. A project-scoped entry point, conceptually `TaskQuery::forProject(actor, project)`, whose base set is "task belongs to the project AND the actor may view it". It does **not** use the global `mine|all` view semantics. `TaskRow` is extended only where project scope needs it (`milestone {id, name}`); an optional board-order sort is project-scope-only. Full contract: [§13](#13-project-tasks-contract) |
| **Q7** | **The D3 artboard is not in the repository.** EPIC-015 may be planned and WP1 implemented without it. The owner exports or provides the D3 light and dark artboards **before WP2's visual implementation**, or explicitly waives that. Until then the committed Direction D contract is authoritative, and agents must not invent missing visual composition ([§15](#15-design-reference-gate-d3)) |
| **Q8** | **Fix stale board-assignee time eligibility in WP1.** For a board task, time eligibility requires current `ProjectPolicy::view(project)`; a stored `assignee_id` alone grants no new-time access once the user has left the project. Standalone and ticket rules are unchanged. A departed assignee cannot start a timer or create manual time on the task, and the task leaves their eligible time-context options. Stopping an already-running timer stays allowed; editing an entry whose task attribution is unchanged stays allowed under EPIC-014 decision (c); billed entries stay locked. Complete/Reopen never stops timers |
| **PT** | **Project time** = entries whose task belongs to the project, **plus** entries with no task whose `project_id` is the project (a valid entry has exactly one of the two); each entry counts in **at most one** project. For a malformed entry that has both, `task.project_id` wins; if that task is standalone or ticket-linked (`task.project_id IS NULL`) the entry has **no** project attribution, even with `project_id` set (grouping is `CASE WHEN task_id IS NOT NULL THEN task.project_id ELSE project_id END`, never `COALESCE`). WP1 owns one canonical reusable query/scope, migrates the existing `TimeEntryService` project summary/filter consumers, fixes the `/time` project filter, and the Overview uses the same definition. The Overview and the Time workspace never use different definitions. Legacy/invalid dual-context rows are characterized, flagged by the integrity audit, and never counted under two projects. Full contract: [§10](#10-project-time-contract) |

## 5. Scope

### 5.1 Required (the epic is not complete without these)

| # | Outcome | Source |
|---|---|---|
| R1 | Atomic project creation: project, default columns, creator membership, extra members and company links in one service-owned transaction | A1.3.1(2); WP1 |
| R2 | The A13.13 `ProjectIntegrityTest` vacuous guard fixed and mutation-checked | EPIC-013 A13.13 |
| R3 | Stale board-assignee time eligibility fixed (Q8) | A1.3.1(1); Q8 |
| R4 | Canonical project-time scope; operator report, CSV and `/time` filter consumers migrated; same definition on the Overview (PT) | §3.3; PT |
| R5 | Explicit milestone Complete/Reopen: schema, domain, routes, DTO (Q2) | Q2 |
| R6 | Derived health (Q1) available to the Overview and the projects index from aggregates | Roadmap "health"; Q1 |
| R7 | Project workspace frame: final tabs Overview · Board · Tasks · Milestones, delivered incrementally (WP2 Overview + existing destinations, WP3 Tasks, WP4 Board/Milestones integration, [§11.3.1](#1131-transitional-behaviour-between-packages)); `projects.show` → Overview, link retargeting, breadcrumbs, active state (Q5) | Q5; Direction D §6 |
| R8 | Project Overview with Monitoring V1 ([§12](#12-overview-and-monitoring-v1-contract)) | Roadmap; Q1–Q4 |
| R9 | Project Tasks tab on `TaskQuery::forProject` (Q6) | Roadmap "list view" |
| R10 | Reusable `StagePath` primitive with milestones as its first consumer | EPIC-013 §31 |
| R11 | Direction D migration of the projects index, milestones page and create/edit; Board integrated into the tab/header grammar | Direction D §19 step 9 |
| R12 | Customer-safety: capability-filtered props on every new or changed project surface, proven by DTO-minimality tests | Q3, Q4 |
| R13 | Every invariant in [§6](#6-domain-invariants) preserved and pinned | — |

### 5.2 Optional / separable (never blocks §19)

| # | Enhancement | Why separable |
|---|---|---|
| S1 | Complete affordance on board cards (EPIC-014 §5.3 / P8 handoff) | Complete is already reachable from task detail, the global Tasks list and the new Tasks tab |
| S2 | Per-surface drawer defaults (Board/Tasks collapsed, Direction D §5.3) | The workspace-wide Open default works; this is shell polish |
| S3 | Project Time tab | Monitoring V1 already shows attributed time; a full tab needs its own design |
| S4 | Home "My work" and any other EPIC-014 WP6 item | Not Projects scope; stays with its existing deferral |

Each is independently mergeable. None is promoted to required scope without an owner decision recorded as an amendment.

### 5.3 Deferred (explicitly out of EPIC-015)

See [§20 Non-goals](#20-non-goals) and [§21](#21-deferred-and-inherited-items).

## 6. Domain invariants

Each invariant is **preserved** (or, where marked new, introduced). "Authority" names what enforces it; "Test" names the existing pin or the planned one ([§16](#16-test-strategy)).

| ID | Invariant | Authority | Test |
|---|---|---|---|
| INV-P1 | The board column stays authoritative for board-task completion; nothing in this epic writes `tasks.status` for a column-backed task (EPIC-014 INV-1/2) | `Task::scopeDone`/`isDone`; `ProjectService::writeOrder` | `ProjectPinnedBehaviorTest`, `ProjectIntegrityTest` (existing) |
| INV-P2 | A project task never migrates between projects, and no new endpoint accepts `project_id` on a task (EPIC-014 INV-9/10) | Route/validation surface | Existing INV-9 pin; WP3 route-surface pin for `projects.tasks.index` |
| INV-P3 | The recorded-time delete guard stays authoritative for task and project deletes (EPIC-014 INV-11/12). It keeps its conservative any-reference semantics and is **not** migrated to task-first project-time attribution; neither is `ProjectIntegrityAudit::projects_blocked_from_delete_by_time`, which mirrors it ([§10.2](#102-canonical-implementation-wp1)) | `RecordedTimeGuard`; FK RESTRICT | `ProjectDeletionGuardTest`, `TaskDeletionAuthorityTest` (existing) |
| INV-P4 | **New.** Project time = task entries attributed to `task.project_id`, plus task-less entries attributed to `project_id`; every entry counts in at most one project (a malformed dual-context row counts only under its task's project), through one canonical scope used by every project-time consumer | Canonical scope (WP1) | `ProjectTimeAttributionTest` (new): task time included, no double count, **a malformed row with a conflicting `project_id` is counted under exactly one project**, audit flags it, consumer parity |
| INV-P5 | Stale assignment never grants board-project visibility (EPIC-014 §7.2) **or new-time access** (new, Q8) | `TaskPolicy`/`ProjectPolicy`; `AccessibleTimeContext` | `TaskPolicyMatrixTest` (existing); flipped `TaskCurrentBehaviorCharacterizationTest` case; new eligibility matrix |
| INV-P6 | Stopping an existing timer never requires current time-context eligibility; editing an entry with an unchanged task attribution stays allowed (EPIC-014 decision (c)) | `TimeEntryService::stopTimer`; `TimeEntryController::keepsTask` | Existing decision-(c) pins; new departed-assignee stop and unchanged-edit cases |
| INV-P7 | Billed time stays immutable (EPIC-010C) and Complete/Reopen touches no time entry (EPIC-014 INV-14/15) | `TimeEntry::isLockedForBilling`; `TaskService` | `BilledTimeEntryLockingTest`, existing INV-15 pin |
| INV-P8 | **New.** An actor without effective Settings/Edit access never receives the **budget** or the Overview **names-and-roles roster** in any Overview prop, and no one receives other users' time without `time.view_all`; sensitive fields are filtered **before** props are built, never hidden in React. This does not change existing assignment-candidate name lists (`{id,name}` of current members for actors `TaskPolicy::assign` admits, including the A9 actor), which stay governed by `TaskPolicy::assign` exactly as today | Presenters, capability-filtered through the shared Settings-access resolver ([§7](#7-authorization-and-customer-safety-contract)) | DTO-minimality tests per actor shape (WP1 presenter, WP2 page) |
| INV-P9 | **New.** Milestone completion is explicit and independent of task progress: completing or reopening a task never changes `completed_at`, and completing a milestone never moves a task | Milestone domain service | Milestone lifecycle tests (WP1) |
| INV-P10 | **New.** Project creation is atomic: a failure anywhere in create leaves no project, column, membership or company-link row | `ProjectService::create` transaction | Injected-failure rollback test (WP1) |
| INV-P11 | Exactly one Done column remains required for Complete/Reopen (EPIC-014 INV-8); atomic create guarantees every new project has it | `ProjectService::doneColumn`; audit | `DoneColumnResolverTest`, `ProjectIntegrityAuditTest` (existing) |
| INV-P12 | Filters, scopes and search never widen task visibility; the project-scoped entry point is a subset of `TaskPolicy::view` (EPIC-014 INV-16) | `TaskQuery` composition order | `TaskQueryTest` parity extended to `forProject` (WP3) |
| INV-P13 | **New.** Health is a pure function of server facts; React never derives it | Health derivation (WP1); presenters | Health truth-table test; Vitest renders server state only |
| INV-P14 | Navigation visibility is presentation, never authorization: every new route authorizes itself (EPIC-014 INV-18) | Route + policy | `ProjectAuthorizationMatrixTest` extended; `NavigationBuilderTest` |
| INV-P15 | DTOs leak no email, no raw model, no id of an unopenable row, no link that would 403 (EPIC-014 INV-17) | Presenters | Inertia page tests per surface |
| INV-P16 | **New.** Overview budget and member-roster visibility equal effective Settings/Edit access: an actor can open `projects.edit` **if and only if** the Overview DTO carries the `budget` key (even when null) and the roster | One shared resolver of the existing A9 conjunction (WP1), never a copy in a presenter | Parity test over the actor matrix against the real `projects.edit` route (WP1, WP2) |

## 7. Authorization and customer-safety contract

Existing `ProjectPolicy` semantics are **preserved**. No permission is added or renamed.

| Surface or datum | Rule |
|---|---|
| Overview page (`projects.show`) | `ProjectPolicy::view` |
| Board, Milestones page | `ProjectPolicy::view` (unchanged) |
| Tasks tab (`projects.tasks.index`) | `ProjectPolicy::view`; each row's actions from `TaskRowAbilities` (`TaskPolicy` parity) |
| Budget on the Overview | **Effective Settings/Edit access** (below). Prop **absent** otherwise |
| All-user project time | `time.view_all` (and `view` on the project) |
| Own project time ("your time") | `time.log`; settled entries of the viewer only |
| Neither time permission | No time prop at all |
| Milestone create/update/delete/**complete/reopen** | Existing A9 rule: `can:projects.manage` route middleware **and** `ProjectPolicy::manage`; the page's `manage` ability mirrors both (unchanged pattern) |
| Settings action (`projects.edit`) | Existing `openSettings` = `manage` **and** `projects.manage` |
| Names-and-roles roster on the Overview (project members with their project roles) | **Effective Settings/Edit access** (below): today's only names-**and-roles** roster disclosure is the `projects.edit` page's `members` prop. Name and role only, never email. For every other viewer the People section is **omitted** and no roster prop is sent. No partial or customer roster is invented |
| Assignment-candidate name lists (existing) | **Unchanged**, governed by `TaskPolicy::assign` as today: the task-detail `options.members` ([ProjectTaskController](../../src/app/Http/Controllers/ProjectTaskController.php)) and the Tasks-list `TaskAssigneeOptions` send current members' `{id, name}` (no role, no email) to actors who may assign, **including the A9 actor** (`projects.admin` without `projects.manage`). EPIC-015 does not widen, narrow or reuse this as the Overview roster, and the Project Tasks page keeps using it unchanged |
| Milestone `completedBy {id, name}` | `view` (see [§9.4](#94-dto-additions)): accepted provenance metadata |

**Effective Settings/Edit access (the one seam).** The accepted A9 contract means that an actor can open `projects.edit` only when **both** `ProjectPolicy::manage(project)` and `can('projects.manage')` hold (route middleware plus `authorize('manage')`). That conjunction is also what the Board's `openSettings` and the Milestones page's `manage` ability compute today, each inline in its controller; the repository has no shared function for it. EPIC-015 does not redesign it:
- WP1 extracts **one** shared resolver (working name `ProjectSettingsAccess`) that returns exactly this conjunction, with no behaviour change, and the Board, Milestones and Overview presenters all call it. No presenter restates the conjunction.
- Overview budget visibility and Overview roster visibility both equal this resolver's answer ([INV-P16](#6-domain-invariants)). A planned parity test asserts, over the actor matrix (operator with and without `projects.manage`, `projects.admin` without `projects.manage`, manager, plain member, customer member, non-member), that an actor receives the budget and the roster in the Overview DTO **if and only if** a real request to `projects.edit` succeeds for them.
- `manageMembers` (the user directory with emails, `projects.admin` only) is a separate, stricter seam and is **not** used for roster visibility.

**Customer safety.** Customer members reach every `view`-gated project route today. Therefore:
- every presenter builds its props from the actor's capabilities, so a forbidden value is never serialized;
- each new or changed surface gets a DTO-minimality test for at least these actor shapes: operator (`projects.admin`), project manager, plain member, **customer member** (`user` role), member without any `time.*` permission, and an actor holding `projects.admin` without `projects.manage` (the A9 case);
- no field is added to a page "for later"; React never hides a value it received;
- **key presence, not value equality, is what the tests assert.** An authorized actor's Overview DTO contains the `budget` key **even when its value is null**; an unauthorized actor's DTO does **not contain the key at all**. The same omission principle applies to the roster (no `members`/People key), to all-user `time` and to the Settings ability. A test that only compares values (a null budget equals a hidden budget) does not pin the rule;
- the customer-member DTO test also asserts, deliberately, that milestone `completedBy {id, name}` is present (provenance metadata, [§9.4](#94-dto-additions)) and that nothing else reveals a member name on the Overview.

**Consequence recorded, not a new rule:** the Overview preserves today's disclosure boundary for the names-and-roles roster and the budget. A customer or plain member sees the project's Overview without the budget and without the People section; board and task assignee names, assignment-candidate lists and the existing aggregate member counts stay exactly as today. Anyone who can already open Settings sees the roster and budget there and now also on the Overview.

**A9 is not redesigned.** The `ProjectPolicy::manage` versus `projects.manage` middleware mismatch stays the accepted contract. A WP that finds a required surface cannot honour it stops and records an amendment rather than silently changing it.

## 8. Health contract

### 8.1 Facts (server-side, from aggregates)

| Fact | Definition |
|---|---|
| `status` | `projects.status` |
| `startsInFuture` | `start_date` not null and `start_date > today` (application timezone) |
| `targetPassed` | `target_date` not null and `target_date < today` (due today is not past, matching the overdue rule) |
| `taskCount`, `openTaskCount` | Project tasks; open = `Task::scopeOpen` (kind-aware, the single done rule) |
| `overdueTaskCount` | `Task::scopeOverdue` (due before today and not done) |
| `milestoneCount` | Project milestones |
| `overdueMilestoneCount` | Milestones with `due_date < today AND completed_at IS NULL` (Q2) |

### 8.2 Derivation (evaluated in this order; first match wins)

| # | Condition | `state` | `label` |
|---|---|---|---|
| 1 | `status = completed` | `complete` | Complete |
| 2 | `status ∈ {on_hold, archived}` | **no health** (`health: null`) | — |
| 3 | `status = active` and `startsInFuture` | `not_started` | Not started |
| 4 | `status = active`, `taskCount = 0` and `milestoneCount = 0` | `insufficient_data` (neutral, not a health colour) | Not enough data |
| 5 | (`targetPassed` and `openTaskCount > 0`) **or** `overdueMilestoneCount > 0` | `off_track` | Off track |
| 6 | `overdueTaskCount > 0` | `at_risk` | At risk |
| 7 | otherwise | `on_track` | On track |

- **`reasons[]`** is a list of **structured items** `{code, …data}` so tests assert codes and data, not prose; the UI renders the label from them. Every signal that fired is listed, in this **fixed order**:
  1. `target_passed`: `{openTaskCount}` (rule 5, first clause);
  2. `milestones_overdue`: `{count, earliest: {id, name, dueDate} | null}` (rule 5, second clause). `earliest` is the first overdue milestone ordered by `due_date`, then `id`. **One summary item, never one item per milestone**, so the list is bounded (at most three items);
  3. `tasks_overdue`: `{count}` (rule 6; **only when rule 6 or a higher rule fired and `overdueTaskCount > 0`**, so an Off track project with overdue tasks lists it as the last item).

  Rule 3 gives `starts_in_future {date}`; rule 4 gives `no_tracked_work`; rules 1 and 7 give `[]`. Reasons never include data the viewer could not otherwise see (a milestone name is `view`-level data).
- **Index versus Overview.** The Projects index carries only `state`, `label` and **count-oriented** reason data (`target_passed.openTaskCount`, `milestones_overdue.count`, `tasks_overdue.count`), all from the same aggregates as the derivation; `earliest` is `null` there. It never fetches named milestone detail per project. The Overview carries the richer `earliest` milestone, from one bounded query for the single project.
- **Lifecycle precedence is deliberate:** a project that has not started yet, or is completed, shows that state even if it has overdue tasks.
- **No thresholds, no elapsed-calendar percentage, no forecast**, and no manual override (Q1).
- Rendering uses the existing `Status` glyph vocabulary (Direction D §10.1): On track filled circle, At risk triangle, Off track square, Not started hollow circle, Complete check. "Not enough data" renders as muted text, not a health glyph.
- **No schema.** Derivation is a pure function of the facts ([INV-P13](#6-domain-invariants)), unit-testable as a truth table and fed by aggregates so it costs constant queries per page.

### 8.3 Known limits (accepted)

- One forgotten overdue task makes a project At risk (a false positive).
- A project whose tasks and milestones have no dates can never be At risk or Off track (a false negative).
- Both are honest consequences of the data, shown with reasons, and are not tuned in this epic.

### 8.4 Invalid dual-linked tasks (WP1 characterization requirement)

An invalid legacy row can carry **both** `project_id` and `ticket_id` (EPIC-014 OBSERVED F9; `Task::kind()` classifies it as a board task). Today the authorities disagree:
- `TaskQuery` **excludes** it (`tasks.ticket_id IS NULL`, [§13.1](#131-query-q6)), so it never appears in a task list;
- `Project::scopeWithTaskStats` and `withCount('tasks')` may still **count** it as a project task (task count, done count, overdue count, progress and therefore health inputs);
- time-context eligibility for it is classification-dependent (`AccessibleTimeContext::canUseTask` tests the assignee, then `task.project`, then `task.ticket`).

**WP0 does not choose the rule.** WP1 must first **characterize** (pin as OBSERVED, as EPIC-014 did) the current behaviour for such a row across: project task count and progress, health inputs, Project Tasks visibility (via `TaskQuery::forProject`), and time-context eligibility (including under the Q8 change). **Intended end state:** the Overview counts and the Project Tasks list must not present contradictory user-visible results because of invalid dual-linked rows. The implementation fix (aligning the aggregates, the query, or neither) is specified in the WP1 amendment only after the characterization proves the current authorities and the safest rule. The integrity audit keeps flagging the invalid row. This is WP1 entry and exit evidence ([§18](#18-work-packages)).

## 9. Milestone lifecycle contract

### 9.1 Schema (WP1)

`project_milestones` gains:
- `completed_at` nullable timestamp;
- `completed_by` nullable FK to `users`, `nullOnDelete`.

There is no backfill decision to make: development data is disposable ([roadmap principle 8](../product/product-roadmap.md#roadmap-principles)) and every existing milestone starts incomplete.

### 9.2 Operations

| Operation | Route | Authorization | Rule |
|---|---|---|---|
| Complete | `PUT /projects/{project}/milestones/{milestone}/complete` → `projects.milestones.complete` | `can:projects.manage` + `ProjectPolicy::manage`; milestone must belong to the project (404 otherwise) | Sets `completed_at = now()`, `completed_by = actor`. Idempotent: an already-complete milestone is a no-op success that keeps the original values |
| Reopen | `PUT …/reopen` → `projects.milestones.reopen` | Same | Clears both fields. Idempotent |
| Update | Existing `projects.milestones.update` | Unchanged | Never touches completion fields |
| Delete | Existing `projects.milestones.destroy` | Unchanged | Allowed whether complete or not (tasks keep `milestone_id → null` via the existing FK) |

Responses follow the existing milestone contract (redirect with flash).

### 9.3 Semantics

- **Source of truth:** `completed_at`. Task-derived progress (`doneCount / taskCount`) is shown beside it as information only ([INV-P9](#6-domain-invariants)).
- **Overdue:** `due_date < today AND completed_at IS NULL`. This replaces `ProjectMilestone::isOverdueAt` and fixes the zero-task quirk (§3.1).
- **Zero-task milestones** are legitimate manual checkpoints (kickoff, go-live, acceptance).
- **A reopened task under a completed milestone** leaves the milestone complete. The milestone DTO exposes `openTaskCount` so the UI can show "1 open task" against a completed milestone.
- **No automatic completion**, no completion suggestion, and no coupling to project status.

### 9.4 DTO additions

`ProjectMilestonePresenter::item` gains `completedAt` (ISO date-time or null), `completedBy {id, name}` or null (name only), `openTaskCount`, and a recomputed `overdue`. Existing `taskCount`, `doneCount` and `completion` keep their meaning (task progress).

**`completedBy` disclosure (accepted).** `completedBy {id, name}` is visible to ordinary project viewers, **including customer project members**. It is accepted as provenance metadata, consistent with the identity already disclosed on task comments and board assignees. It is **not** a member roster: it names only the one user who completed that milestone, and it is deliberately included in the customer DTO-minimality tests ([§7](#7-authorization-and-customer-safety-contract), [§16.2](#162-new-coverage)) so the disclosure is intentional. It must be loaded without a per-milestone query (eager-load or a join), covered by the milestones-page query budget.

## 10. Project-time contract

### 10.1 Definition (PT)

> **Project time** for project P is the set of time entries that either (a) have a `task_id` referencing a task whose `project_id = P`, or (b) have **no** `task_id` and `project_id = P`. Each entry counts in **at most one** project.

| Entry | Attributed to |
|---|---|
| Direct project entry (`project_id` set, no task) | `project_id` |
| Task entry (`task_id` set, normally `project_id = NULL`) | `task.project_id` |
| **Malformed** entry with both `task_id` and `project_id` | `task.project_id`; a conflicting stored `project_id` is ignored for attribution |

The task is the authoritative contextual relationship, because task-attributed time normally has `project_id = NULL`. The malformed rule is **damage containment for legacy or invalid rows, not a valid domain state**: the audit flags every such row (§10.4) and no current create path can produce one.

### 10.2 Canonical implementation (WP1)

- **One** reusable query/scope on `TimeEntry` (working name `scopeAttributedToProject(P)`) with the task-first predicate `(task_id IS NOT NULL AND task_id IN tasks(P)) OR (task_id IS NULL AND project_id = P)`. A plain `project_id = P OR task_id IN tasks(P)` is **not** acceptable: a malformed row whose `project_id` names one project and whose task belongs to another would match both projects.
- **One** grouping expression for per-project summaries (working name "attributed project id"), conceptually:

  ```
  CASE
      WHEN task_id IS NOT NULL THEN task.project_id
      ELSE project_id
  END
  ```

  Grouping by it puts each entry in exactly one group, consistent with the scope. It must **not** be implemented as `COALESCE(tasks.project_id, time_entries.project_id)`: for a malformed row whose task is standalone or ticket-linked (`task.project_id IS NULL`) while `project_id` is also set, `COALESCE` would fall back to `project_id` and attribute the row to a project that the scope excludes.

  | Entry | Attributed project |
  |---|---|
  | Board-task entry | `task.project_id` |
  | Standalone-task entry | none (`NULL`) |
  | Ticket-task entry | none (`NULL`) |
  | Direct project entry (no task) | `project_id` |
  | Malformed: board task + `project_id` | `task.project_id` |
  | Malformed: standalone or ticket task + `project_id` | **none**, even though `project_id` is populated |
  | Malformed: `ticket_id` + `project_id`, no task | follows the rule as written (no `task_id`, so `project_id`); characterized in WP1 and flagged by the audit |

- **Parity is required** between the canonical filter/scope, the by-project grouping and the Overview project time: for any project, the grouped total equals the filtered total equals the Overview total.
- `ProjectIntegrityAudit::projects_blocked_from_delete_by_time` follows the conservative `RecordedTimeGuard` semantics and is **not** migrated either; it answers "may this project be deleted?", not "how much time is on it?".
- The unused `TimeEntry::scopeForProject` is replaced or redirected to the canonical scope, never left as a second definition.
- `RecordedTimeGuard::deleteProject` is **not** migrated. It answers a different question (may this project be deleted?) and must stay conservative: any entry referencing the project or one of its tasks blocks the delete, malformed or not. Its behaviour must not change.

### 10.3 Consumers migrated in WP1

| Consumer | Change |
|---|---|
| `TimeEntryService::applyFilters` (`project_id` filter) → `summaryByProject`, `summaryByUser`, `totalMinutes`, `exportCsv` | Filter uses the canonical scope; `summaryByProject` groups by the attributed project id |
| `Operator\TimeReportController::index` entry list | Project filter uses the canonical scope; the row's project name is the attributed project's name |
| `TimeEntryService::exportCsv` project column | The attributed project's name |
| `/time` page project filter (`TimeEntryController::index`) | The canonical scope |
| Overview time ([§12](#12-overview-and-monitoring-v1-contract)) | The canonical scope, settled entries only (`timer_started_at IS NULL`), matching every existing "settled" query |

A parity test asserts that the Overview total, the operator report's by-project row and total, and the `/time` filtered total agree for the same project and actor scope.

### 10.4 Dual-context rows

- **Characterize first.** WP1 pins what exists today for rows with more than one context key set (EPIC-014 A2.5 called them "legacy dual-context entries"), including a row whose `project_id` conflicts with its task's project. No current path creates them.
- **Counting rule:** `task.project_id` wins. Such a row counts under its task's project only, never under the stored `project_id`'s project, in every query and every group. Its stored values are not rewritten.
- **Required tests:**
  1. A single malformed row (`project_id = A`, task in **board project** `B`) is counted under exactly one project: it appears in B's scope and total, never in A's, and the sum over all projects counts it once. It is also asserted for the report's by-project rows, the `/time` filter and the Overview.
  2. A malformed row (`project_id = A`, `task_id` = a **standalone** task, and the same for a **ticket** task) counts under **no project**: absent from A's filtered total **and** from the grouped by-project totals (it has no project group), and absent from the Overview. This pins the `CASE` grouping against a `COALESCE` implementation.
  3. The integrity audit flags both malformed shapes.
- `ProjectIntegrityAudit` gains a read-only check that flags **every** time entry with both `task_id` and `project_id` set (and, separately, any with `ticket_id` plus another context key), so each such row is visible rather than silently attributed.

### 10.5 What does not change

- No time entry is written or rewritten by this correction.
- Billing is unaffected: no billing path selects time entries (§3.3), and billed entries stay locked.
- Reports **will** show more time under each project than before. That is the fix, and it is recorded in the WP1 amendment.

## 11. Project workspace IA, routes and navigation

### 11.1 Surfaces

The four V1 tabs, in this order: **Overview · Board · Tasks · Milestones**. Settings is a header action, not a tab. **Monitoring is an IA concern represented on the Overview, not a tab.** There are no Activity or Time tabs in required scope (Activity has no data source; Time is optional S3).

| Surface | Route | Purpose | Frame | Major components | Source data | Authorization | Narrow widths |
|---|---|---|---|---|---|---|---|
| Projects index | `/projects` (`projects.index`) | Portfolio scan | `PageFrame canvas` | `PageHeader`, `FilterBar` (lifecycle status), `DataTable`: name, health (state + label, count-only reasons), progress, target, next milestone, **member count (existing aggregate; no member names)** | `Project::visibleTo` + aggregates + one next-milestone query per page | `projects.view`; rows `visibleTo` | D9 two-line rows |
| Overview | `/projects/{project}` (`projects.show`) | "How is this going?" | `PageFrame grid` (main + context rail) | `EntityHeader` (health, lifecycle, dates), tabs (those that exist, §11.3.1), sections per §12 | `ProjectOverviewPresenter` | `view`; field gates per §7 | Context rail drops below main |
| Board | `/projects/{project}/board` (`projects.board`) | Execution | `PageFrame canvas` | Existing `Board`, now under the shared header + tabs | Unchanged | Unchanged | Unchanged |
| Tasks | `/projects/{project}/tasks` (`projects.tasks.index`) | Execution list | `PageFrame canvas` | `DataTable`, `FilterBar`, `BulkBar`, Complete ring, assignment menu | `TaskQuery::forProject` | `view` + row abilities | D9 rows |
| Milestones | `/projects/{project}/milestones` (`projects.milestones.index`) | Planning | `PageFrame` per D3 (default `grid`) | `StagePath` (full), milestone list, `FormDialog`, Complete/Reopen | Milestones with counts | `view`; mutations A9 | Stacked list; StagePath compact |
| Settings | `/projects/{project}/edit` (`projects.edit`) | Edit the record | `PageFrame reading` (`forms`) | `PageHeader`, `Section`s, existing fields, member and company editors | Unchanged | Unchanged (A9) | Reading width |

### 11.2 Routes

| Method | URI | Name | Authorization | WP |
|---|---|---|---|---|
| GET | `/projects/{project}` | `projects.show` | `view`; **renders the Overview** instead of redirecting | WP2 |
| GET | `/projects/{project}/tasks` | `projects.tasks.index` | `view` | WP3 |
| PUT | `/projects/{project}/milestones/{milestone}/complete` | `projects.milestones.complete` | `can:projects.manage` + `manage` | WP1 |
| PUT | `/projects/{project}/milestones/{milestone}/reopen` | `projects.milestones.reopen` | `can:projects.manage` + `manage` | WP1 |

Every existing `projects.*` route keeps its URI, name and authorization. `projects.store` redirects to `projects.show` (Q5, WP2). `ProjectTaskController::destroy` keeps redirecting to the Board (it is an explicit board action).

### 11.3 Landing, links, breadcrumbs and active state (Q5)

- **Generic project links retarget to `projects.show`** (WP2; the Overview's own task-count links are handled in [§11.3.1](#1131-transitional-behaviour-between-packages)): `project-card.tsx` (or its WP4 table successor), `TaskListPresenter` context link, `TimeEntryController` entry context links, the task-detail trail's project segment, and the milestones/edit back links. Explicit Board links and actions keep `projects.board`.
- **Breadcrumb (shell trail, no page-owned breadcrumb):** `Projects › {project}` on the Overview; `Projects › {project} › Board | Tasks | Milestones | Settings` on the others; task detail stays `Projects › {project} › {task}` with the project segment linking to the Overview.
- **Active state:** `projects.all` keeps owning every project route; WP3 adds `projects.tasks.index` (and any other new GET route, in the package that creates it) to its active-route list. `ShellContractTest`'s single-active-item guarantee extends to the new routes.
- **Backwards compatibility:** `/projects/{id}` links keep working (Overview instead of a redirect); all other URIs are unchanged.

### 11.3.1 Transitional behaviour between packages

The final navigation is **Overview · Board · Tasks · Milestones**, but a package renders only destinations that exist when it merges:

| After | Tabs rendered | Overview open/overdue task links |
|---|---|---|
| WP2 | Only tabs whose routes exist: **Overview** and **Board** (and Milestones where its page already renders inside the shared frame). `projects.tasks.index` does not exist yet, so **no Tasks tab** | Go to the **existing Board** (`projects.board`) |
| WP3 | Adds **Tasks** (`projects.tasks.index`) | **Retarget** to `projects.tasks.index` with the matching filter (`completion=open`, `due=overdue`) |
| WP4 | Board and Milestones are integrated into the shared project tab/header grammar; all four tabs render on every project page | Unchanged |

No package ships a link or tab that answers 404/405 (today `GET /projects/{project}/tasks` is unregistered). The §19 criterion requiring the full four-surface navigation is an **epic**-level check, satisfied when WP4 merges.

### 11.4 Tabs

A route-link tab strip (working name `PageTabs`) shared by the project pages:
- each tab is an ordinary link (standard link navigation, Direction D §14) inside a labelled `nav`, with `aria-current="page"` on the active tab and the Direction D ink underline (§8);
- it sits directly on the `EntityHeader` strata, per §6;
- it shows only tabs that exist ([§11.3.1](#1131-transitional-behaviour-between-packages)) and that the viewer may open (all four final tabs are `view`-gated, so once all exist every viewer sees all four);
- at narrow widths it scrolls horizontally rather than wrapping.

This deliberately uses link semantics, not `role="tablist"`, because each tab is a separate page ([§24](#24-plan-level-choices-open-to-review) P3). **Recorded deviation from Direction D:** [Direction D §8](../design/direction-d-design-system.md#8-navigation-and-selection-rules) specifies `role="tab"` / `aria-selected` for page tabs. EPIC-015 uses `nav` + ordinary links + `aria-current="page"` instead, because each item changes route; the visual treatment (ink underline on the strata) is unchanged. WP6 adds a forward note to Direction D rather than rewriting it ([§18](#18-work-packages) WP6).

## 12. Overview and Monitoring V1 contract

### 12.1 Content

**Required**

| Section | Content | Source |
|---|---|---|
| Identity header | Overline "Project"; name (the one `h1`); lifecycle status; derived health with reasons; start → target dates; Settings action when `openSettings` | `ProjectOverviewPresenter`, §8 |
| Task progress | Done / total with `Progress` (`progressbar`, mono number) | Task aggregates |
| Open and overdue tasks | Counts, each linking to the Tasks tab with the matching filter (`completion=open`, `due=overdue`). **Until WP3 exists the links go to the Board** ([§11.3.1](#1131-transitional-behaviour-between-packages)) | Task aggregates |
| Milestones | Compact `StagePath` plus the next upcoming and any overdue milestones (name, due date, state) | Milestones (Q2) |
| People | Supported and rendered **where authorized**: the **names-and-roles roster** (project member names and roles, owner first, no email), only for actors with effective Settings/Edit access ([§7](#7-authorization-and-customer-safety-contract)). For every other viewer the section is omitted and no roster key is sent. Existing aggregate member counts and assignment-candidate lists are separate and unchanged | `ProjectPresenter::members`, behind the shared Settings-access resolver |

**Useful, authorization-gated**

| Section | Gate | Content |
|---|---|---|
| Project time, all users | `time.view_all` | Total settled minutes, attributed per PT |
| Your time | `time.log` without `time.view_all` | The viewer's own settled minutes on the project, attributed per PT |
| Budget | Effective Settings/Edit access (same resolver as People) | The stored value as metadata ("Budget"), with no derived figure. The `budget` key is present (possibly `null`) for authorized actors and **absent** for everyone else |
| Description | `view` | Plain text |
| My open tasks here | `view` | Up to a small fixed number of the viewer's open tasks in this project, linking to the Tasks tab filtered to them (to the Board until WP3, [§11.3.1](#1131-transitional-behaviour-between-packages)) |

**Not on the Overview:** linked companies, invoices, activity, risks, estimates, burn, charts, portfolio comparisons. The Overview is a summary of one project, not a dashboard.

### 12.2 Monitoring V1

Monitoring is the honest set of derived signals the Overview shows: health with reasons, task completion, overdue tasks, milestone completion and overdue state, target-date status (days remaining or days past), and correctly attributed logged time.

**Not available, and not implied:** monetary burn, estimate variance, baseline variance, activity history, a risk register, or any forecast inferred from elapsed calendar percentage.

### 12.3 `ProjectOverviewPresenter` (WP1)

- One presenter builds the Overview props from aggregates, with a constant query count independent of task, milestone, member and time-entry volume.
- It takes the actor and decides every gated field **before** building the array (INV-P8): `budget`, the names-and-roles roster, `time` (`scope: all | own`) and the Settings ability are **absent as keys** when not permitted (an authorized actor's `budget` key exists even when null). Budget, roster and the Settings ability are all derived from the one shared Settings-access resolver, never from a second copy of the A9 conjunction.
- The roster is the only member-name data it adds; it never reuses assignment-candidate lists, and sends no email.
- Health and milestone states come from the §8 and §9 rules; the presenter does not restate them. It includes the Overview-level `earliest` overdue milestone for `milestones_overdue` ([§8.2](#82-derivation-evaluated-in-this-order-first-match-wins)) from one bounded query; the index presenter uses counts only.

## 13. Project Tasks contract

### 13.1 Query (Q6)

- `TaskQuery::forProject(actor, project)` composes, in EPIC-014's fixed order: (1) the existing maximum authorized surfaced set (`TaskQuery::authorizedFor`), (2) `tasks.project_id = project`, (3) filters, (4) search, (5) sort and pagination. Steps 1–2 stay one parenthesised group (INV-P12).
- The controller authorizes `ProjectPolicy::view(project)` first (403 otherwise). For an authorized viewer the base set is every non-ticket-linked task of the project.
- There is **no** `view=mine|all` parameter in project scope; a `view` parameter is ignored.

### 13.2 Filters, search and sort

| Parameter | Behaviour in project scope |
|---|---|
| `completion` | As EPIC-014 (`open` default, `done`, `any`) |
| `priority`, `due`, `q` | As EPIC-014 |
| `milestone` | Always offered; options are the project's milestones; a foreign id is ignored |
| `assignee` | Offered to every viewer; options are distinct assignees of the project's visible tasks (names only), plus `none` |
| `project`, `kind`, `organization`, `view` | Not offered; ignored if sent |
| `sort` | EPIC-014 keys plus a project-scope-only `board` key (column position, then task position, then id) |

All parameters are validated and clamped as in EPIC-014 §9.5 (an unknown value is dropped, not a validation redirect).

### 13.3 Rows and actions

- `TaskRow` gains `milestone {id, name} | null` **in project scope only**, so the global Tasks list's DTO and query budget are unchanged ([§24](#24-plan-level-choices-open-to-review) P4). The project context column is omitted in project scope.
- Row `url` is `projects.tasks.show`.
- `TaskRowAbilities` (one membership query per page), Complete/Reopen, the assignment menu, `BulkBar` bulk Complete/Reopen and the existing `POST /tasks/bulk` endpoint are reused unchanged.
- Creating a task from the Tasks tab is **not required** (the Board's quick-add remains the create path).

## 14. Direction D surface plan, StagePath and progress

### 14.1 Surfaces

- **Projects index (WP4):** `PageFrame canvas`, `PageHeader` with New project (existing `abilities.create`), lifecycle-status `FilterBar`, `DataTable` per §11.1, empty states. Health and progress come from aggregates (§17). Pagination stays 20 per page.
- **Overview (WP2):** `PageFrame grid`, `EntityHeader` + strata + `PageTabs`, sections per §12. Composition and order follow D3 ([§15](#15-design-reference-gate-d3)).
- **Board (WP4):** gains the shared header and tabs; the Milestones header link becomes a tab; `Board` behaviour is untouched.
- **Milestones (WP4):** full `StagePath`, milestone list with progress, completion state and Complete/Reopen (only when the page's `manage` ability, the Settings-access resolver's answer, is true), `FormDialog` create/edit, `ConfirmationDialog` delete.
- **Create/edit (WP4):** `PageFrame reading` (`forms`), `Section`s replacing `SectionPanel`, existing field, member and company editors restyled, no behaviour change.

Primitives are reused from EPIC-013/014 (`PageFrame`, `PageHeader`, `EntityHeader`, `Strata`, `Section`, `DataTable`, `FilterBar`, `Chip`, `BulkBar`, `FormDialog`, `ConfirmationDialog`, `EmptyState`, `Status`, `Priority`, `Progress`). No local design-system fork is created. New shared primitives: `PageTabs` and `StagePath` only.

### 14.2 `StagePath` (WP2)

A generic `components/ui` primitive (Direction D §11.2).

| Prop | Meaning |
|---|---|
| `stages: {key, label, state: 'done' \| 'current' \| 'planned' \| 'blocked', meta?}[]` | Ordered stages; `meta` is a plain string such as "Done · 12 Sep" or "Planned · 3 Oct" |
| `variant: 'full' \| 'compact'` | Full shows names and meta; compact shows segments only (14px max height) |
| `label` | Required for `compact` (for example "Milestones: 3 of 5 complete") |
| `hiddenBefore?`, `hiddenAfter?` | Optional caller-written summary strings for stages outside the >7 window (see below) |

- **Semantics:** `role="list"`, each stage a `listitem`, `aria-current="step"` on the current stage, state announced in text and never by colour alone.
- **Geometry:** N equal columns with 4px gap; heights 6, 9, 12 … +3px per step, capped at 24 (the strata rising); `progress-fill` done, `live` current, dashed `stage-future` planned. Never cyan for anything but current.
- `blocked` is supported by the primitive but **produced by nothing in EPIC-015**.

**Milestone mapping:** stages are milestones ordered by `due_date`, then `id`. `done` = `completed_at` set; `current` = the **first** incomplete milestone; every other incomplete milestone is `planned`. A completed milestone may follow the current one (out-of-order completion is shown as it is). An overdue current milestone keeps the `current` state, with "Overdue · {date}" in its meta.

**Window rule for more than 7 stages (owned by the generic `StagePath`, by stage index and state only; no milestone logic).** Show **at most 7** visible stage items, in stable original (index) order:
1. the **current** stage is always visible (if there is none, anchor on the first stage, or on the last when every stage is `done`);
2. include the stages **after** the anchor, in order, up to the limit;
3. if fewer than 7 would then render, **backfill earlier stages** (nearest preceding first, completed ones in practice) until 7 are shown or none remain;
4. hidden stages are not rendered as segments. The caller supplies the summary copy for them through props (for example `hiddenBefore: "5 completed"`, `hiddenAfter: "3 later"`), so the primitive carries no milestone wording;
5. the compact `label` is caller-supplied and states the full count ("Milestones: 3 of 12 complete").

Example (12 stages, current is #11): show #6 to #12 (current + 1 later + 5 backfilled earlier), `hiddenBefore` covers #1 to #5. The milestone caller maps its milestones to stages and writes the summary text; `StagePath` has no Projects-specific business logic.

### 14.3 Progress

The existing `Progress` (`progressbar`, 4/6px, mono number) is sufficient for task and milestone progress. The `meter` role, expected marker, overage and over-100% treatments are **not** built; they wait for a Finance or Advanced Projects consumer.

## 15. Design reference gate (D3)

- **Status:** the D3 Project artboard exists only in the external Claude Design canvas. The repository has no export, screenshot or textual description of it.
- **What Direction D already fixes:** entity-header grammar (§6), page tabs on the strata (§6, §8), grid width for Project Overview (§9), drawer defaults per surface (§5.3), health glyphs (§10.1), StagePath and bar specifications (§11), widths and density (§5, §9).
- **What remains open without D3:** Overview section order and what sits in the context rail; tab presentation details; projects index columns and density; milestones page layout; empty-state composition.
- **Gate:** WP0 and WP1 proceed without D3. **WP2 must not begin visual implementation** until the owner exports D3 (light and dark) into `docs/design/` or explicitly waives the requirement; the waiver or the files are recorded in the WP2 amendment. This is a **WP2 entry gate, not an epic blocker**. The gate applies to WP2's new visual Overview composition and to any WP4 surface whose visual design depends on the D3 reference (the index and milestones layouts). **WP3 (Project Tasks) is not blocked by D3**: it reuses the already-established Direction D `DataTable`/`FilterBar` Tasks grammar and adds no new visual composition (its tab strip comes from WP2's `PageTabs`).
- **Until then:** the Direction D contract is authoritative, and no agent invents composition the contract does not specify. The acceptance criteria in this epic are functional and do not depend on D3.

## 16. Test strategy

### 16.1 Existing coverage to preserve

Every suite in §3.5 stays green and unmodified, except the pins named in §16.3.

### 16.2 New coverage

**Pest**
- **Health:** a truth table over lifecycle × start/target × task/overdue counts × milestone/overdue-milestone counts, including precedence (Not started beats overdue; Complete beats open tasks), no-health for `on_hold`/`archived`, and **structured `reasons[]` asserted by code and data, in the fixed §8.2 order** (target passed, then the single milestone summary with `earliest` by `due_date, id`, then overdue tasks; several overdue milestones produce one item; multiple signals list in order). The index presenter returns count-only reasons with `earliest: null`; the Overview returns the named earliest milestone.
- **Milestone lifecycle:** complete/reopen authorization (A9 actor matrix), idempotency, `completed_by` provenance, foreign-milestone 404, zero-task milestone completion, overdue rule, independence from task completion and reopen (INV-P9), user-delete nulls `completed_by`.
- **Project time:** task time included; direct time included; ticket time excluded; no double count; dual-context rows (characterized; a malformed board-task row with a conflicting `project_id` counts under exactly one project, the task's; a malformed **standalone- or ticket-task row with a `project_id` counts under no project**, in both filtered and grouped totals; both audited); the grouping is `CASE WHEN task_id IS NOT NULL THEN task.project_id ELSE project_id END`, never `COALESCE`; parity of canonical scope, by-project grouping, Overview, report total, CSV and `/time` filter. `RecordedTimeGuard::deleteProject` and `projects_blocked_from_delete_by_time` keep conservative any-reference behaviour (existing `ProjectDeletionGuardTest`, plus a malformed-row block case).
- **Q8:** departed assignee denied timer start, manual store and context option; current member and `projects.admin` still allowed; standalone and ticket rules unchanged; departed assignee can still stop a running timer and edit an unchanged-attribution entry; billed entries still locked.
- **Atomic create:** a failure injected after the project insert (columns, membership, members or companies) leaves no partial rows; a successful create has exactly one Done column.
- **A13.13 guard:** rewritten so any one forbidden label fails it; mutation-checked by adding one done task to the context options.
- **Overview authorization and DTO minimality:** for each §7 actor shape, including the customer member and the `projects.admin`-without-`projects.manage` actor, asserting **key presence**: the `budget` key exists (even when null) only for effective Settings/Edit actors and is **absent** for all others; likewise the names-and-roles roster, all-user `time` and the Settings ability; no email anywhere. The customer-member case also asserts milestone `completedBy {id, name}` is present (deliberate provenance) and that no other member name appears on the Overview. Assignment-candidate lists are asserted unchanged (`TaskPolicy::assign`), not removed.
- **Invalid dual-linked project+ticket tasks (§8.4):** characterization pins for project task count/progress, health inputs, Project Tasks visibility and time-context eligibility, written before any fix.
- **Settings-access parity (INV-P16):** over the same actor matrix, receiving the budget and the roster in the Overview DTO equals successfully opening `projects.edit`; the Board `openSettings` and Milestones `manage` abilities equal the same resolver.
- **Project Tasks:** `forProject` equals the `TaskPolicy::view`-able tasks of the project; every filter combination narrows; foreign milestone ignored; non-viewer 403; abilities parity with `TaskPolicy`.
- **Routes and navigation:** `projects.show` renders, `store` lands on it, new routes in `ProjectAuthorizationMatrixTest`, `projects.all` active on them, one active item.
- **Query budgets:** §17.

**Vitest**
- `PageTabs` (link semantics, `aria-current`, horizontal scroll), `StagePath` (roles, `aria-current="step"`, compact label, the >7 window with current always visible, backfill of earlier stages, stable index order and caller-supplied summary copy), health rendering from structured reasons and the neutral state, Overview composition with and without gated props (absent keys), milestones page (completion controls only when the page's `manage` ability, the Settings-access resolver's answer, is true), project Tasks page, responsive layouts.

**Playwright** (existing personas and fixture architecture; no new login budget; `workers: 3` unchanged)
- Project creation lands on the Overview.
- Overview → Board / Tasks / Milestones tabs and back, per package (WP2 asserts only existing destinations and that the Overview task links reach the Board; WP3 adds the Tasks tab and the retargeted links; WP4 the full four-tab strip); breadcrumb correct; exactly one Breadcrumb landmark and one `h1` per page.
- Tasks tab: filter, Complete/Reopen, assignment, bulk.
- Milestone Complete/Reopen as a manager; read-only for a member.
- Customer-safe Overview (no budget, no People section, own time only).
- Narrow layouts (390px and the S/M boundaries) without horizontal page scroll.
- Cleanup through `E2eCleanup` and the guarded delete paths; product-data counts unchanged after a run.

### 16.3 Pins that change, rewritten to the new rule and never simply deleted

| Pin | New rule |
|---|---|
| `TaskCurrentBehaviorCharacterizationTest` "OBSERVED / DEFERRED TIME-DOMAIN FOLLOW-UP: a board assignee who left the project can still start NEW time…" | Inverted (Q8): denied for timer start and manual store |
| `TaskCurrentBehaviorCharacterizationTest` "OBSERVED (§12.2): an existing entry … stays editable after its assignee leaves…" | Assertions unchanged; its comment's rationale changes from "stale assignment grants eligibility" to decision (c) |
| `ProjectIntegrityTest.php:323` multi-needle `not->toContain` | Rewritten so each forbidden label is asserted (R2) |
| Any test asserting `projects.show` redirects to the Board, or `store` redirects to the Board | Renders the Overview / redirects to `projects.show` (Q5) |
| Milestone `overdue` expectations based on task completion | `due < today ∧ completed_at IS NULL` (Q2) |
| Operator report / `/time` filter expectations that exclude task time | Include task time per PT |

### 16.4 Gates

Per package: focused suites, then `./dev check`; `./dev test:e2e` for UI and route packages; PR CI as the merge gate. The full suites are not run concurrently (the jsdom load sensitivity recorded in `docs/testing/ci.md`).

## 17. Performance

- **Constant-growth query-budget tests** (the `ProjectQueryBudgetTest` growth-world pattern) are required for: the Overview, the projects index with health/progress/next milestone, the project Tasks tab (each filter shape), and the milestones page. Each must show the same query count as the world grows.
- **Health and progress on the index** come from `withCount` aggregates (open, overdue, milestones, overdue milestones) plus one bounded next-milestone query for the page's project ids; never a query per row. The index fetches **no named reason detail** (no per-project earliest-overdue-milestone lookup); its reasons are count-only. The Overview adds one bounded query for the single project's earliest overdue milestone, and the milestones page loads `completedBy` without a per-milestone query.
- **Project time** uses the canonical single-`WHERE` predicate; the Overview runs a constant number of sums.
- **No index is added speculatively.** `EXPLAIN` is run only where a real query shape shows a scan worth removing, and any index is justified in that WP's amendment, as EPIC-014 §9.7 required.

## 18. Work packages

Each WP ends with an amendment to this document (results, deviations, gate evidence). Packages after WP0 are PRs from the implementation branch ([§25](#25-branch-and-pr-strategy)).

### WP0: Decisions and epic (this document)

- **Objective:** lock Q1–Q8 and PT; make EPIC-015 the Planned implementation contract; record the D3 gate and the customer-product gap.
- **In scope:** this file; `docs/epics/README.md`; the roadmap's Projects UX vehicle line; forward notes in EPIC-013 §31 and EPIC-014 A1.3.1.
- **Out of scope:** any source, test, migration or dependency change.
- **Gates:** `git diff --check`; links and anchors verified; historical sections untouched.
- **Exit:** owner review; committed to `main`.

### WP1: Domain foundation (backend only)

- **Objective:** every domain, authorization and integrity change, with no required UI.
- **In scope:**
  - characterization pins first (milestone overdue today, project-time undercount, dual-context time rows including the standalone/ticket-task malformed shapes, **invalid dual-linked project+ticket tasks across counts, health inputs, Project Tasks visibility and time eligibility ([§8.4](#84-invalid-dual-linked-tasks-wp1-characterization-requirement))**, Q8 current behaviour, create partial failure);
  - atomic project creation (R1), moving extra members and company links into the service-owned transaction;
  - the A13.13 guard fix (R2);
  - Q8 in `AccessibleTimeContext` (R3);
  - the canonical project-time scope and consumer migration, plus the dual-context audit check (R4, §10);
  - milestone completion schema, service, routes and DTO (R5, §9);
  - health derivation (R6, §8);
  - the shared Settings-access resolver (§7): a behaviour-neutral extraction of the existing A9 conjunction, adopted by the Board, Milestones and Overview, with the INV-P16 parity test against `projects.edit`;
  - `ProjectOverviewPresenter` (§12.3), tested directly;
  - authorization matrix and query-budget coverage for all of the above.
- **Out of scope:** React pages, routes that render new pages, navigation changes.
- **Invariants:** INV-P1–P13 and INV-P16.
- **Exit:** all §16.2 Pest items for WP1 green; §16.3 pins rewritten; the §8.4 characterization recorded in the WP1 amendment with the chosen (or explicitly deferred) rule; `./dev check` and CI green.
- **Delivery / review note (not a new package, not a dependency change).** WP1 may land as **two PRs on the same EPIC-015 implementation branch** to keep review sizes sane; there is still one WP1, one amendment and one branch:
  - **PR A: time and integrity:** atomic project create (R1); the vacuous guard fix (R2); Q8 stale-assignee time (R3); canonical project-time attribution and the report/filter migration (R4); the invalid-row characterization (dual-context time rows and §8.4 dual-linked tasks).
  - **PR B: project domain:** the Settings-access resolver; milestone completion (R5); health (R6); `ProjectOverviewPresenter`; the related query budgets.
  - PR B does not depend on PR A's behaviour; the split is for risk and reviewability (A changes report totals and time permissions; B is new domain code).

### WP2: Workspace frame and Overview

- **Entry gate:** D3 provided or explicitly waived ([§15](#15-design-reference-gate-d3)).
- **In scope:** `PageTabs`; `StagePath`; health rendering; `projects.show` → Overview; `store` → Overview; link retargeting and breadcrumbs (§11.3); active state; the Overview with Monitoring V1 (§12).
- **Transitional behaviour ([§11.3.1](#1131-transitional-behaviour-between-packages)):** the Overview and the shared project header render **only tabs whose routes exist** (Overview, Board, and Milestones where available). There is **no Tasks tab and no `projects.tasks.index` yet**; the Overview's open/overdue task links go to the **existing Board**. Board and Milestones need not yet adopt the full tab-strip integration (WP4).
- **Exit:** R8 and R10 met; the R7 elements WP2 owns (Overview as `projects.show`, creation lands on it, generic-link retargeting, breadcrumbs and active state, tabs for existing routes) met; no tab or link answers 404/405; Vitest and Playwright for the Overview; customer-safe Overview proven in the browser. WP2 does **not** require the Tasks tab or the Board/Milestones tab integration.

### WP3: Project Tasks

- **In scope:** `TaskQuery::forProject`, the project-scope `TaskRow` milestone, `projects.tasks.index`, the Tasks page reusing `DataTable`/`FilterBar`/`BulkBar`/row abilities, project-scope query budgets; **adding Tasks to the project navigation** and **retargeting the Overview's open/overdue task-count links** from the Board to the filtered `projects.tasks.index` ([§11.3.1](#1131-transitional-behaviour-between-packages)).
- **Depends on:** WP1. May run in parallel with WP2 on the backend; its page uses WP2's `PageTabs`. **Not blocked by D3** ([§15](#15-design-reference-gate-d3)): it reuses the established Tasks/`DataTable` grammar.
- **Exit:** R9 met; INV-P12 pinned; the Tasks tab and the retargeted Overview links work.

### WP4: Remaining Direction D migration

- **In scope:** projects index (§14.1), milestones page with StagePath and Complete/Reopen UI, create/edit on reading/forms, **completing the Board and Milestones integration into the shared project tab/header grammar** (so all four tabs render on every project page), responsive behaviour.
- **Depends on:** WP2 (tabs, StagePath), WP3 (Tasks tab), WP1 (milestone API, health). Surfaces whose visual design depends on D3 are gated per [§15](#15-design-reference-gate-d3).
- **Exit:** R11 met; the complete **Overview · Board · Tasks · Milestones** navigation works on every project page; no legacy `max-w-*` container left on a project page.

### WP5: Optional / separable

S1–S4 ([§5.2](#52-optional--separable-never-blocks-19)), each its own small PR, each independently mergeable. Skipping all of them does not affect §19.

### WP6: Hardening and closeout

- **In scope:** the §19 acceptance matrix; an accessibility pass on the new widgets (keyboard, focus, names, one `h1`, one Breadcrumb landmark); full Playwright; the documentation sweep (README, roadmap, `rbac-design.md` if a rule changed, `docs/testing/ci.md` A13.13 note, forward notes, and a **forward note in the Direction D design system** recording that EPIC-015's project page navigation uses `nav` + links + `aria-current="page"` instead of §8's `role="tab"`/`aria-selected` tab semantics (a forward note, not a rewrite of the historical text)); query/performance verification; full gates; the Verified and Done transitions.

**Dependency graph:** WP0 → WP1 → (WP2 ∥ WP3 backend) → WP4 → WP6. WP5 is independent and optional.

## 19. Exit criteria

EPIC-015 is **Verified** when all of these hold. S1–S4 (WP5) are **not** criteria. It moves to **In Progress** when WP1 merges, and to **Done** when the final package merges with green `main` CI, following the repository lifecycle.

1. Project creation is atomic (INV-P10), proven by an injected-failure test; every new project has exactly one Done column.
2. The A13.13 `ProjectIntegrityTest` guard fails on any single forbidden label (mutation-checked).
3. Q8 holds: a departed board assignee cannot start a timer, log manual time or see the task as a time-context option; stop and unchanged-attribution edit still work; standalone and ticket rules are unchanged.
4. One canonical project-time scope exists; the operator report (summary, total, CSV, entry list) and the `/time` filter use it; the Overview agrees with them (scope, `CASE` grouping and Overview parity); task time is included and no entry, including a malformed dual-context row, counts under more than one project; a malformed standalone/ticket-task row with a `project_id` counts under none; the delete guard keeps its conservative semantics.
5. Milestones complete and reopen explicitly under the A9 rule; overdue follows Q2; zero-task milestones work; task progress stays separate.
6. Health follows §8 exactly, with structured reasons in the fixed order (bounded, index count-only), from aggregates, with no schema and no override. The invalid dual-linked project+ticket task row is characterized, and Overview counts and the Project Tasks list do not contradict each other because of it (§8.4).
7. `/projects/{project}` renders the Overview with the §12.1 required content; creation lands there; generic links target it; the Board keeps its URI.
8. The four tabs (Overview · Board · Tasks · Milestones) work on every project page once WP4 merges, with correct breadcrumbs, active state, one `h1` and one Breadcrumb landmark, and no tab or link ever answers 404/405 at any package boundary.
9. The project Tasks tab works per §13 and never widens visibility.
10. `StagePath` exists as a shared primitive with tested semantics and the §14.2 window rule.
11. The projects index, milestones page and create/edit use Direction D grammar; the Board is integrated into the tab/header grammar.
12. Customer safety (INV-P8, INV-P16) is proven by DTO-minimality tests for every new or changed surface, including the customer-member actor, asserting **key absence** (not value equality) for budget, roster, all-user time and Settings ability, deliberate presence of milestone `completedBy`, and the Overview budget/roster parity with `projects.edit` (including the `projects.admin`-without-`projects.manage` actor).
13. The §17 query budgets hold for the Overview, index, Tasks tab and milestones page.
14. INV-P1 to INV-P16 hold, each with a named test.
15. **Responsive and accessibility (WP4/WP6):** at 390px and the S/M width boundaries every redesigned project page has no document-level horizontal overflow; the project navigation has an accessible name and `aria-current="page"` semantics; `StagePath` accessibility (list semantics, `aria-current="step"`, state in text) is tested; and a keyboard/focus pass of the redesigned project workspace passes. Manual screen-reader testing (for example NVDA) is **not** required by this epic.
16. `./dev check`, `./dev test:e2e` and PR CI are green on the final package.

## 20. Non-goals

These belong to Advanced Projects, Finance, customer product work or other future roadmap items:

- outcomes and objectives;
- SOW linkage;
- estimates and planned effort;
- baselines;
- tolerance and change control;
- approvals;
- monetary budget burn or consumption;
- invoice or revenue analysis;
- timeline / Gantt;
- board column management;
- configurable Done/Reopen destinations;
- task dependencies UI;
- ticket-task UX;
- the customer shell;
- stakeholder-specific presentation;
- an activity/audit feed;
- a risk / issue register;
- a manual health override;
- portfolio dashboards;
- notifications;
- saved views;
- Timer UX NEXT items;
- a membership-model redesign;
- `client_id` adoption.

## 21. Deferred and inherited items

| Item | Disposition |
|---|---|
| EPIC-014 A1.3.1(1) stale board-assignee time eligibility | **Required here** (Q8, WP1) |
| EPIC-014 A1.3.1(2) non-atomic `ProjectService::create` | **Required here** (R1, WP1) |
| EPIC-013 A13.13 `ProjectIntegrityTest` vacuous guard | **Required here** (R2, WP1). `BrowserAuthContractTest:84` stays with its existing owner |
| EPIC-014 §5.3 / P8 board-card Complete | Optional (S1) |
| Direction D §5.3 per-surface drawer defaults | Optional (S2) |
| EPIC-014 WP6 items (row timer, bulk assign, peek, Home "My work", `?` sheet) | Unchanged; stay deferred (S4 only if promoted) |
| EPIC-014 A2.4(3) decision (c) is task-only | Unchanged |
| A9 `ProjectPolicy::manage` vs `projects.manage` middleware | Preserved, not redesigned (§7) |
| `projects.view_org`, `tasks.view_org` inert; `time.view_own` unenforced | Unchanged permission debt |
| `client_id` unused | Unchanged (non-goal) |
| `tests/Browser` lint/typecheck gap, jsdom load sensitivity, A11.15, A11.2 | Stay with their existing owners |

## 22. Customer-product roadmap gap

Direction D [§19](../design/direction-d-design-system.md#19-implementation-order) step 9 and EPIC-013 [§31](./EPIC-013-direction-d-shell-design-system.md#31-deferred-follow-on-work) expect separate **customer product work**: adopting the customer shell (D4, Direction D [§7](../design/direction-d-design-system.md#7-customer-shell-anatomy)), the customer routing topology (Direction D [§20](../design/direction-d-design-system.md#20-open-questions) Q1), the multi-organization switcher and, per the [IA](../product/information-architecture.md#operator-vs-customer-surfaces), a customer stakeholder view of projects. **The Product Roadmap has no explicit item or bucket for it.**

EPIC-015 does not solve this and does not absorb it (Q4). It is flagged here for later roadmap placement by the owner. Until then, customers keep using the capability-filtered Operational shell, and EPIC-015 keeps every project surface safe for them (§7).

## 23. Risks and rollback

| # | Risk | Likelihood | Impact | Mitigation | Rollback |
|---|---|---|---|---|---|
| R1 | The project-time correction surprises report users (totals rise) | High (by design) | Low | Recorded in the WP1 amendment; parity test proves correctness | Revert WP1's consumer migration; no data was written |
| R2 | Q8 blocks a legitimate workflow (a departed member finishing their time) | Low | Medium | Stop and unchanged-attribution edits still work; re-adding the member restores access | Revert the `AccessibleTimeContext` change |
| R3 | A gated Overview field (budget, roster, other users' time) leaks to customer members | Medium | High | Props built from capabilities through one Settings-access resolver; DTO-minimality tests and the INV-P16 parity test per actor | Remove the field; revert the WP |
| R4 | Health reads as misleading (one stale task → At risk) | Medium | Low | Reasons always shown; no thresholds; documented limits (§8.3) | Hide health on the index only; the derivation stays |
| R5 | Visual work starts without D3 and invents composition | Medium | Medium | WP2 entry gate (§15) | Rework in WP2 before merge |
| R6 | `TaskQuery::forProject` diverges from `TaskPolicy` | Low | High | Same step-1 set; parity tests | Revert WP3 |
| R7 | Retargeting links breaks a deep link | Low | Low | URIs unchanged; only targets change | Point links back at the Board |
| R8 | Optional WP5 delays closeout | Medium | Low | §19 excludes S1–S4 | Ship without them |

## 24. Plan-level choices open to review

None blocks WP1. Each is the plan's default, derived from the locked decisions, and the owner may override any of them when reviewing this document.

| # | Choice | Rationale |
|---|---|---|
| P1 | **Confirmed by the owner.** A malformed entry with both `task_id` and `project_id` is attributed to the task's project (`task.project_id` wins), counts in at most one project, and every such row is audited (§10.2, §10.4) | Task-attributed time normally has `project_id = NULL`, so the task is the authoritative relationship; this is containment, not a valid state |
| P2 | **Owner override.** The Overview People section follows effective Settings/Edit access; viewers without it get no roster and the section is omitted (§7, §12.1) | Preserves today's disclosure boundary; no partial or customer roster model is invented |
| P3 | Project tabs use link semantics (`nav` + `aria-current="page"`), not ARIA `tablist` | Each tab is a separate page; Direction D §8's `role="tab"` wording fits in-page tabs. A deliberate, recorded deviation from Direction D §8 ([§11.4](#114-tabs)), with a forward note added to Direction D in WP6 |
| P4 | `TaskRow.milestone` is added in project scope only | Keeps the global Tasks list DTO and query budget unchanged |
| P5 | The projects index status filter defaults to **all** statuses | Matches today's index; nothing is hidden by default |
| P6 | **Owner override.** Overview budget visibility equals effective Settings/Edit access, through one shared resolver of the existing A9 conjunction, with an iff parity test against `projects.edit` (§7, INV-P16) | EPIC-015 preserves A9; it must not widen what it exposes beyond the Settings seam |
| P7 | `ProjectTaskController::destroy` keeps redirecting to the Board | It is an explicit board action, not a generic project link |
| P8 | Creating a task from the Tasks tab is not required | The Board's quick-add is the existing create path |

## 25. Branch and PR strategy

- **WP0:** documentation only, reviewed uncommitted, then committed directly to `main`. **No branch** is created for it.
- **Implementation:** after WP0 is on `main`, **one** branch, `feature/epic-015-projects-ux`, is cut from current `main`.
  - Package-sized PRs (WP1, WP2, WP3, WP4, WP6, and any WP5 item) go from it to `main`.
  - After each merge the branch is synced to current `main` (merge, not rebase of published history). No force pushes.
  - No branch is created for each small documentation or incidental fix; those go directly to `main` where safe.
- **CI:** the PR gate is the merge gate for every package.
- **Record:** each package's results are recorded as an amendment to this document in the same PR.
