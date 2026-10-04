# EPIC-015: Projects UX Expansion

**Status:** In Progress (2026-10-04). WP0 is complete (committed `58c58f1`). **WP1 is complete:** PR A (time and integrity, PR #15) merged to `main` (`5a92f74`) and PR B (project domain, PR #16) merged to `main` (merge commit `66d60a2`, parents `5a92f74` and `0803dd7`), each after independent review, with the merge-triggered `main` CI green ([Amendment 1](#amendment-1-wp1-implementation)). **WP2 is complete:** PR #17 merged to `main` (merge commit `e33056d`, implementation `6d3dfe8`) after independent review ([Amendment 2](#amendment-2-wp2-workspace-frame-and-overview)); the owner **waived** the D3 design-reference entry gate ([§15](#15-design-reference-gate-d3), [A2.1](#a21-owner-decision-the-d3-artboard-gate-is-waived)). **WP3 (Project Tasks) is implemented and awaiting independent review** ([Amendment 3](#amendment-3-wp3-project-tasks)). The epic is not Verified or Done.
**Class:** Product functionality (Product Roadmap [NEXT — Core work management → Projects UX expansion](../product/product-roadmap.md#projects-ux-expansion))
**Product direction:** [Platform Product & UX Direction → Project direction](../product/platform-product-ux-direction.md#project-direction) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md) (D3 artboard: **not in the repository**, see [§15](#15-design-reference-gate-d3); the gate was **waived by the owner** for all remaining EPIC-015 visual work, including WP4, [A2.1](#a21-owner-decision-the-d3-artboard-gate-is-waived))
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

---

## Amendment 1: WP1 implementation

> **Status (2026-10-04): WP1 is complete and the epic is In Progress.** PR A (PR #15) was implemented, independently reviewed (A1.1.10) and merged (`5a92f74`). PR B (PR #16) was implemented, independently reviewed (verdict: safe to commit, no defects), verified by hosted CI and merged (`66d60a2`); the merge-triggered `main` CI was green. WP2 has not started and the D3 entry gate still applies. A1.1 records PR A; A1.2 records PR B (its text below describes the work as it stood at review time).

### A1.1 WP1 PR A: Time and integrity foundation

**Starting point.** Branch `feature/epic-015-projects-ux` at `58c58f1` (`docs: plan EPIC-015 Projects UX expansion`), equal to `main` and `origin/main`, working tree clean. No migration, dependency, CI or frontend file changed.

**Method.** Characterize first, then change. Each new test file was written against `58c58f1` code and run green; the behaviour tests were then flipped in place to the committed rule (labelled `FLIPPED IN WP1`, each keeping what it asserted before) and shown **red against the old production code** before the fix landed (time consumers: 12 of 15 red with the old consumers restored; create atomicity: 8 of 10 red with the old service restored; Q8: 5 of 17 red before the `AccessibleTimeContext` change). The two risky rules were mutation-checked: the A13.13 guard (below) and `CASE` versus `COALESCE` grouping (3 tests fail under `COALESCE`).

#### Characterization findings

| Area | Finding at `58c58f1` | Class |
|---|---|---|
| Create | A project row, then columns, then the creator row, then (controller) `syncMembers`, then `companies()->sync`, with **no transaction**. Proven with injected failures: a failure on the 3rd column left a committed project with 3 columns and no Done column; a failure on the creator insert left a project and 5 columns; a failure in the extra-member sync left a project whose creator had been **detached** by `sync()` (only the extra member remained); a failure linking companies left the project, members and the link. | KNOWN DEFECT, flipped |
| Project time | Every consumer filtered and grouped on `time_entries.project_id` alone. A board-task entry has `project_id = NULL`, so task time was invisible to every project filter and by-project row; a malformed row was counted under its stored `project_id` (A: 10 + 160 + 320 = 490, B: 0 in the fixture; correct is A 30, B 160). The CSV and report rows named no project for task time. | KNOWN DEFECT, flipped |
| Stale assignee | A departed board assignee could start a timer and log manual time (pinned since EPIC-014). | KNOWN DEFECT, flipped |
| Preserved | Stopping a running timer after eligibility is lost; an unchanged board-task attribution stays editable (decision (c)); billed entries stay locked; standalone and ticket eligibility; a current member who is not the assignee may log time on any project task (`ProjectPolicy::view`); `time.log` is not widened; a departed assignee cannot open the task detail (so `logTime` is never reached). | PRESERVED |
| Dual-linked project+ticket task | See [A1.1.5](#a115-dual-linked-projectticket-tasks-characterization-and-the-owner-ruling-resolved). | OBSERVED |

#### A1.1.1 Atomic project creation (R1, INV-P10)

`ProjectService::create` is now one `DB::transaction` covering the project row, the five columns (exactly one Done), the creator membership, the extra-member sync and the company sync. `$data['members']` and `$data['companies']` are optional keys, so every existing caller (`makeProject`, tests, seeders) is unchanged. `ProjectController::store` keeps authorization (`manageMembers` for extra members) and validation (`AccessibleCrmCompany`) and now makes **one** service call. No lock is taken (nothing is read that a concurrent request can change). No external side effect runs inside the transaction: the model has no observers or events, and nothing queues, mails or writes a file in this path.

Failure injection throws from a `DB::listen` callback **after** the Nth INSERT into a named table has executed, so the failing row itself must be undone by the transaction. Tests: failures on the 1st and 3rd column, the creator row, the extra-member sync and the company link all leave **zero** rows in `projects`, `project_columns`, `project_members` and `project_company`; the service is also atomic without the controller; another project is undisturbed and a retry succeeds; the integrity audit reports no project without a Done column after a failed create. Preserved: a successful create (5 columns, 1 Done, creator manager, extras, companies) and the rule that an extra-member entry for the creator never demotes them.

*Test-environment note.* The suite runs inside `RefreshDatabase`'s outer transaction, so these tests exercise a savepoint rollback; production uses a real transaction with the same semantics.

#### A1.1.2 A13.13 guard (R2)

`ProjectIntegrityTest` "offers timer context by kind and never a Done-column board task" used `->not->toContain('Done board task', 'Standalone done', 'Ticket done')`, which passes unless **all three** are present. **Mutation proof (before the fix):** production was temporarily changed to offer Done board tasks; the old assertion still passed. The assertion is now `array_intersect($labels, [...])` equals `[]`. **After the fix, the same mutation fails it** (`'Done board task'` reported); the mutation was reverted and the test passes on real code. Product behaviour was not changed to satisfy it. The review then found three more multi-argument `not->toContain` assertions: `TaskQueryTest` (the INV-13 "never surfaces a ticket-kind or dual-linked row" guard) and `TaskListPageTest` (the page-props leak guard) were **fully vacuous** (the second needle was a diagnostic string that is never present, so neither could fail), and `TaskAssigneeOptionsTest` was partly vacuous. All three were repaired in PR A ([A1.1.10](#a1110-independent-review-remediation)).

#### A1.1.3 Canonical project-time attribution (R4, INV-P4)

One seam, three faces, all on `TimeEntry`:

| Face | Use |
|---|---|
| `scopeAttributedToProject($id)` | filtering: `(task_id IS NULL AND project_id = P) OR task_id IN (tasks of P with no ticket)` |
| `scopeJoinedToTask()` + `attributedProjectIdSql()` | grouping: `CAST(CASE WHEN task_id IS NULL THEN time_entries.project_id WHEN tasks.ticket_id IS NULL THEN tasks.project_id ELSE NULL END AS UNSIGNED)` over a LEFT JOIN (the `CAST` keeps the id an integer; an `ELSE NULL` branch otherwise comes back from the driver as a string) |
| `attributedProject()` | the per-entry project (report row name, CSV); null for a missing, standalone, ticket-linked or malformed task. Callers eager load `task:id,project_id,ticket_id` and `task.project` |

`scopeForProject` (no callers) was **removed**, not left as a second definition. The grouping is a `CASE`, never `COALESCE`; mutation-checked. The first implementation of the grouping used a correlated subquery and **failed on MariaDB** (`ONLY_FULL_GROUP_BY`, error 1055), which is why it is a LEFT JOIN on plain columns; `tasks.id` is unique, so the join cannot multiply rows. `applyFilters`' columns are now table-qualified because the grouped query joins `tasks`.

| Entry | Attributed project |
|---|---|
| Direct project entry | `project_id` |
| Board-task entry | `task.project_id` |
| Standalone-task entry | none |
| Ticket-task entry | none |
| Malformed: board task in B + `project_id` = A | **B** (`project_id` ignored) |
| Malformed: standalone or ticket task + `project_id` = A | **none** |
| Task **linked to both a project and a ticket** (owner ruling), whatever `project_id` says | **none** |

**Consumers migrated:** `TimeEntryService::applyFilters` (hence `summaryByProject`, `summaryByUser`, `totalMinutes`, `exportCsv`); `summaryByProject` grouping; the CSV Project column; `Operator\TimeReportController::index` (entry list filter and each row's project name); the `/time` page filter, for both the list and the total. The `/time` page stays user-scoped. **Not changed:** the Dashboard time queries and the other user-only queries (no project dimension), and no write path. **The Overview consumer arrives in PR B** and joins the parity test then.

Tests (`Time/ProjectTimeAttributionTest`, 15): the fixture has six entries with distinct power-of-two durations so a total identifies its members. Asserted: filter totals (A = 30, B = 160); the by-project groups (A 30, B 160, none 440, summing to every settled entry once); per-entry membership in at most one project; the null group for malformed standalone/ticket rows (including a ticket-task variant); filter = group = scope parity; operator page, `/time` page and service totals agree; the CSV rows; composition with the user/billable filters; running timers still excluded; and the guard distinction below.

#### A1.1.4 Guard and audit stay conservative; the new audit checks

`RecordedTimeGuard::deleteProject` and `ProjectIntegrityAudit::projects_blocked_from_delete_by_time` are **unchanged in behaviour**: any direct or task reference blocks the delete. Maintainer comments now say they must not be normalized to the reporting scope, and tests pin the distinction: a malformed row (task in D, `project_id` = C) is **reported under D only** but **blocks deleting both C and D**. The audit gains two read-only checks, `time_entries_with_task_and_project` and `time_entries_with_ticket_and_other_context`. **They may overlap**: one row carrying a ticket, a task and a project is counted by both, because each key reports a different violation; this is intended, documented in the audit and pinned by a test. No migration and no constraint was added, and the audit still writes nothing (its fingerprint test is extended).

#### A1.1.5 Dual-linked project+ticket tasks: characterization and the owner ruling (resolved)

`Tasks/DualLinkedTaskCharacterizationTest` was first written as seven OBSERVED tests recording what each authority did with a task carrying **both** `project_id` and `ticket_id` at `58c58f1`. They disagreed:

| Authority | Before the ruling |
|---|---|
| `Task::kind()` | board task |
| `TaskPolicy` (`kindOf`) | malformed: every ability denied for everyone |
| `TaskQuery::authorizedFor` | excluded |
| Project aggregates (`withTaskStats`, helpers, milestone counts) | counted (total, done, overdue, progress) |
| Board page, task detail and the other project-task routes | shown / reachable to a project member or manager |
| New-time eligibility (`AccessibleTimeContext`) | admitted (member, departed assignee, ticket owner) |
| Project time attribution | to `tasks.project_id` ("project wins") |
| Integrity audit | flagged |

**Owner ruling (final).** Such a row has **no valid product kind**: it is neither a project task nor a ticket task, and neither "project wins" nor "ticket wins". It is refused and unsurfaced; it is **not** auto-deleted or auto-migrated; the audit keeps flagging it. Applied in PR A:

| Seam | Result |
|---|---|
| `Task::isMalformedKind()` and `Task::scopeOfValidKind()` | the one classifier and the one scope (`project_id IS NULL OR ticket_id IS NULL`). `Task::kind()` is deliberately unchanged. `TaskPolicy`, `TaskService` and `TaskQuery` already refuse the row and keep their own equivalent checks |
| `Project::scopeWithTaskStats`, `completionPercentage`, `overdueTasks`; `ProjectMilestone::scopeWithTaskCounts`, `completionPercentage` | count valid kinds only, via the shared scope: the row is in no total, no progress and no overdue/health input, and helper and aggregate cannot disagree |
| `ProjectBoardController` | the board's task collection excludes it |
| `ProjectTaskController` | one private `ensureProjectTask()` backstop for **every** bound-task route (show, update, destroy, move, comment, the three checklist routes): 404 unless the task belongs to the route's project **and** is not malformed. Called after the project authorization, so 403 still precedes 404. The review found these routes authorized on the project alone, so the row was reachable by URL even once the board hid it |
| `AccessibleTimeContext::canUseTask` | denied for **new** time, classified before any other rule (see A1.1.6) |
| Time attribution | none (A1.1.3) |
| Future Project Tasks (WP3) | already excluded by `TaskQuery`; nothing to add |

**Preserved.** An unchanged historical attribution on such a task stays editable (decision (c)); an already-running timer on it can be stopped; a billed entry on it stays locked; `RecordedTimeGuard` and `projects_blocked_from_delete_by_time` still treat the physical reference as a delete blocker.

The characterization file keeps the **before** values in comments. Its tests are now: classifier and scope; TaskPolicy, TaskQuery and the audit (PRESERVED, plus "neither deleted nor migrated"); aggregates, helpers and milestone counts; the board; all eight project-task routes returning 404 for a manager and an operator (and a valid task still working, a member still getting 403 on a structural route, a foreign-project task still 404); new-time denial for a member, a departed assignee, a ticket owner, an operator and a manager through the rule **and** the real timer, manual-entry and picker routes; decision (c), timer stop and the billed lock; and the cross-surface check that the board, the project count and `/tasks` all show one task.

#### A1.1.6 Q8: stale board-assignee new-time eligibility (R3, INV-P5)

`AccessibleTimeContext::canUseTask` (the single authority: timer start, manual store, an entry changed to another task, and the context-options picker all go through it) now requires current `ProjectPolicy::view` for a board task (`project_id` set, `ticket_id` null). The stored `assignee_id` is untouched and grants nothing alone. The kind is classified in a fixed order so no later rule can rescue a refused row: missing task, denied; **malformed project+ticket, denied** (before the assignee shortcut, project and ticket handling); board task, current `ProjectPolicy::view` only; ticket task, assignee or ticket view (unchanged); standalone, assignee (unchanged). `ProjectPolicy` and every route are unchanged. The task-detail `logTime` ability needed no change: a departed member cannot open the page (403), so it is never reached.

`Time/StaleAssigneeTimeEligibilityTest` (17): **flipped** (departed assignee denied a timer, manual time, the picker, a changed or added task); **preserved** (current member + assignee; current member not the assignee may log on any project task though the picker lists only assigned tasks; operator without membership; outsider denied; `time.log` not widened; standalone and ticket rules; re-adding the member restores access; unchanged-attribution edit after leaving, decision (c); running timer still stops after leaving; billed entry still locked). The dual-linked row is **denied** (A1.1.5). The EPIC-014 characterization "OBSERVED / DEFERRED TIME-DOMAIN FOLLOW-UP" was flipped in place (`FLIPPED IN EPIC-015 WP1`), and the §12.2 test's comment now says the unchanged edit survives under decision (c), no longer because a stale assignment grants eligibility.

#### A1.1.7 Performance and billing

- `QUERY BUDGET`: the by-project summary, the operator report, the `/time` page and the CSV cost the **same number of queries** after 12 more tasks, 36 task entries and 12 direct entries on the project (warm-up request excluded). Report rows load `task.project` by eager load, so a page of entries adds no per-row query.
- No index was added and `EXPLAIN` was not needed: the filter is a semi-join on `tasks.project_id` and the grouping joins on `tasks.id`.
- **Billing is unaffected.** After the change, no billing code references `TimeEntry` or `time_entries` (`InvoiceService` and the Billing controllers were re-read), and the only users of the new seam are the time report, the CSV and the `/time` page. Billing lock semantics are untouched and re-pinned.

#### A1.1.8 Deviations and findings

1. **Report totals rise.** Per-project totals in the operator report, CSV and `/time` filter now include task time (§23 R1); that is the fix.
2. The grouping is a LEFT JOIN (not the correlated subquery first tried: MariaDB `ONLY_FULL_GROUP_BY`) and is wrapped in `CAST ... AS UNSIGNED` (see A1.1.3).
3. The dual-linked rule is resolved by the owner ruling (A1.1.5); nothing in PR A is awaiting an owner decision.
4. The three vacuous multi-argument `not->toContain` assertions are repaired in PR A (A1.1.10).
5. No migration was needed.

#### A1.1.9 Evidence

| Gate | Result |
|---|---|
| New test files | `Projects/ProjectCreateAtomicityTest` (10), `Time/ProjectTimeAttributionTest` (15), `Time/StaleAssigneeTimeEligibilityTest` (17), `Tasks/DualLinkedTaskCharacterizationTest` (15, after the ruling) |
| Extended / flipped | `ProjectIntegrityAuditTest` (now 6 tests: extended fixture and keys, the guard-distinction test, the overlap test), `ProjectIntegrityTest` (A13.13 guard), `TaskCurrentBehaviorCharacterizationTest` (Q8 flip, rationale comment), and the three repaired Tasks guards (A1.1.10) |
| Baseline before PR A | `Projects` + `Time` + `Tasks/TaskCurrentBehaviorCharacterizationTest`: 664 passed (2623 assertions) |
| Focused Pest (after remediation) | `tests/Feature/Projects`, `Time`, `Tasks`, `tests/Unit`, `NavigationBuilderTest`, `ShellContractTest`, `DashboardInertiaTest`: **1143 passed (6807 assertions)**, 310 s. (First pass, before the review: 1134 / 6718.) |
| Query-budget test, run alone | 1 passed (7 assertions) |
| Focused Playwright | `time-migration`, `projects-migration`, `task-detail-wp5`: **25 passed** (1.6 min); product-data counts before and after identical (projects 0, tasks 0, time_entries 0) |
| `./dev check` (alone) | CLI self-tests 196 assertions; `git diff --check` pass; Pint pass; frontend `npm run check` pass (typecheck, ESLint `--max-warnings=0`, Prettier, **Vitest 92 files / 1052 tests**, `vite build` 413 modules); full Pest **1518 passed (8478 assertions)**. (First pass: 1509 / 8389.) |
| Pint on changed files | 24 files, pass |
| Scope | No migration, dependency, CI, `resources/js`, config or `database/` change; no milestone completion, health derivation, `ProjectSettingsAccess`, `ProjectOverviewPresenter`, tabs, Project Tasks or `StagePath` code |

**Files changed (PR A).** Production: `Models/TimeEntry`, `Models/Task`, `Models/Project`, `Models/ProjectMilestone`, `Services/TimeEntryService`, `Services/ProjectService`, `Services/ProjectIntegrityAudit`, `Services/RecordedTimeGuard` (comment only), `Rules/AccessibleTimeContext`, `Http/Controllers/ProjectController`, `ProjectBoardController`, `ProjectTaskController`, `TimeEntryController`, `Operator/TimeReportController`. Tests: the four new files plus the extended and repaired ones above. Docs: this amendment and the status line.

**Open for the owner.** Nothing. The EPIC status stays **Planned** until WP1 merges.

#### A1.1.10 Independent-review remediation

The first independent review of PR A returned **"WP1 PR A NEEDS SMALL REMEDIATION"**, with the findings below (the dual-linked owner ruling was supplied alongside it). All were applied here. The short independent re-review of the remediated work then returned **"WP1 PR A SAFE TO COMMIT"**; it found all of F1 to F9 closed and recorded two documentation-only corrections, which are made in this amendment.

| Finding | Resolution |
|---|---|
| F1 dual-linked ruling | Applied (A1.1.5): classifier and scope on `Task`, aggregates, board, new-time rule |
| F2 direct routes | The review found every `ProjectTaskController` bound-task route authorized on the project alone, so a malformed row stayed reachable by URL after the board hid it. One `ensureProjectTask()` backstop now serves all eight routes (404), with a test per route for a manager and an operator |
| F3 time attribution | Malformed dual-linked task time attributes to **no project**, consistently in the scope, the SQL grouping and `attributedProject()` |
| F4, F5 vacuous guards | `TaskQueryTest` (INV-13 never-surfaces guard) and `TaskListPageTest` (page-props leak guard) were fully vacuous; `TaskAssigneeOptionsTest` partly. Repaired with one needle per assertion (or an empty-intersection check) and the diagnostic as the message. **Mutation-checked, one prohibited value each:** dropping `whereNull('tasks.ticket_id')` from `TaskQuery` fails the INV-13 guard; adding one `created_by` key to a task row fails the leak guard; adding one `options` key fails the assignee-options guard. All three were green on real code first, so no existing leak was masked or found |
| F6 running timer | The running fixtures now store non-zero durations (999 and 777) so including a running row would change the total; the test asserts the filter, the group, the scope and the filtered summary |
| F7 parity | The COALESCE guard now groups the **unfiltered** fixture. A three-way parity test asserts, per project, filtered total = grouped total = sum of settled entries whose `attributedProject()` is that project, plus the unattributed group and the partition of all settled time. The fixture now also has a ticket task + `project_id`, a dual-linked task with matching and with conflicting `project_id`, and an entry with no context. A `COALESCE` grouping fails 5 tests; dropping the ticket exclusion from the scope fails 5 |
| F8 amendment | This amendment: the ruling is recorded, no "owner decision requested" remains |
| F9 audit overlap | Documented and pinned by a test (A1.1.4) |

Atomic create, the delete-guard distinction and the Q8 matrix were accepted by the review and were not changed; their tests stay green.

### A1.2 WP1 PR B: Project domain foundation

**Starting point.** Branch `feature/epic-015-projects-ux` at `5a92f74` (PR A merged), equal to `main` and `origin/main`, working tree clean. PR B is backend only: one migration, no `resources/js`, dependency, CI or config change, no Overview page, tabs, `StagePath`, Project Tasks or index redesign.

**Method.** Characterize first. The Settings-access matrix (`projects.edit`, the Board `openSettings`, the Milestones `manage` ability, for ten actor shapes) was run against `5a92f74` with every PR B production change set aside: **10 of 10 green**, so the extraction had a pinned baseline. After the change the same tests are green and the new resolver/Overview assertions join them. The two flipped overdue pins were proven **red against the old rule** by restoring only the old `due < today AND task completion < 100` expression (both failed on the overdue assertion, not on a missing class), then restored.

#### A1.2.1 `ProjectSettingsAccess` (§7, INV-P16): behaviour-neutral extraction

`App\Policies\ProjectSettingsAccess::allows(User, Project)` returns exactly the A9 conjunction, `Gate::forUser($user)->allows('manage', $project) && $user->can('projects.manage')` (the actor it is given, not the authenticated user; pinned). Consumers: the Board `openSettings`, the Milestones page `manage` ability, and every gated Overview field. `projects.edit` (and the other Settings routes) keep enforcing it themselves through `can:projects.manage` middleware plus `authorize('manage')`; they were deliberately **not** rewritten to call the resolver, so route enforcement is byte-for-byte unchanged, and the parity test pins the resolver to a real `projects.edit` request. `ProjectController::index`'s `create` ability is a different conjunction (`create` policy, not `manage`) and is not part of this seam. A9 is not redesigned.

Parity matrix (`Projects/ProjectSettingsAccessTest`, 42 tests): for outsider, customer member, member with no permission, member with `time.view_all`, member holding `projects.manage` with the `member` pivot role, manager pivot without `projects.manage`, project manager, operator, `projects.admin` alone, and `projects.admin` alone with the manager pivot: **`projects.edit` succeeds ⇔ resolver ⇔ Board `openSettings` ⇔ Milestones `manage` ⇔ Overview carries `budget` (key, value null) and `members` and `abilities.openSettings`**. Only the project manager and the operator are allowed. No inconsistency between the surfaces was found.

#### A1.2.2 Milestone completion (R5, §9, Q2, INV-P9)

- **Schema** (`2026_10_03_120000_add_completion_to_project_milestones`): `completed_at` nullable timestamp; `completed_by` nullable `foreignId` → `users`, `nullOnDelete` (as §9.1 specifies), indexed by the FK. No backfill: every existing milestone starts incomplete. Reversible (`dropConstrainedForeignId`). No other index.
- **Domain:** `App\Services\ProjectMilestoneService::complete/reopen`, the only writer. Each is one conditional `UPDATE` (`WHERE completed_at IS NULL` / `IS NOT NULL`), so both are idempotent without a lock and a repeated Complete keeps the original `completed_at`/`completed_by`. The completion columns are **not** fillable, so the create/update routes cannot touch them even when the payload names them (pinned). Nothing touches a task, a task status, a column, a position or a timer (fingerprint-pinned, including a running timer on a linked task).
- **Model:** `ProjectMilestone::isOverdue()` (`due_date < today AND completed_at IS NULL`) and its SQL twin `scopeOverdue`, `isCompleted()`/`scopeCompleted`, `completer()` (`completed_by` → `User`). `isOverdueAt(int $completion)` was **removed** (its only caller was the presenter), not left as a second rule.
- **Routes:** `PUT /projects/{project}/milestones/{milestone}/complete|reopen` → `projects.milestones.complete|reopen`, inside the existing `can:projects.manage` group, with `authorize('manage')` and the existing foreign-milestone 404 (authorization first). Redirect to `projects.milestones.index` with a flash, like the other milestone mutations. Both added to `ProjectAuthorizationMatrixTest` as `MATRIX_MANAGE_ROUTE`.
- **DTO** (`ProjectMilestonePresenter::item`, §9.4): adds `openTaskCount` (`taskCount − doneCount`), `completedAt` (ISO 8601 or null), `completedBy {id, name}` or null, and the recomputed `overdue`. `taskCount`, `doneCount` and `completion` keep their task-progress meaning (valid kinds only, PR A). `completedBy` is eager loaded (`completer:id,name`); no email. Milestones with the same due date are ordered by id for deterministic presentation.

`Projects/ProjectMilestoneLifecycleTest` (24): schema and FK rule, user delete nulls `completed_by` and keeps the milestone complete, authorized Complete with time and actor, idempotent Complete (a later Complete by another actor keeps the first values), Reopen and idempotent Reopen, zero-task completion, the A9 actor matrix (allowed: project manager, operator; 403 with no state change: outsider, customer member, manager pivot without `projects.manage`, `projects.admin` alone), guest to login, foreign 404 and outsider 403 first, update never touches completion, delete while complete, INV-P9 both directions (all tasks done does not complete; reopening a task leaves the milestone complete and shows `openTaskCount = 1`), the flipped overdue rule (SQL scope = per-row rule), the DTO, the customer member receiving `completedBy` deliberately with no email, and the malformed dual-linked task excluded from milestone progress.

#### A1.2.3 Health (R6, §8, Q1, INV-P13)

One authority, `App\Queries\ProjectHealth`, with a plain value `App\Queries\ProjectHealthFacts`:

| Entry point | Use | Queries |
|---|---|---|
| `withFacts(Builder)` | adds `Project::withTaskStats()` (valid kinds, column-authoritative done) and the new `Project::withMilestoneStats()` (`milestones_count`, `completed_milestones_count`, `overdue_milestones_count`, explicit completion) to any project query | 0 extra: subselects in the same query |
| `forIndex(Project)` | count-only reasons, `earliest: null` (WP4 index) | none |
| `forOverview(Project)` | adds `milestones_overdue.earliest {id, name, dueDate}` by (`due_date`, `id`) | one bounded query, only when an overdue milestone exists |
| `derive(ProjectHealthFacts)` | the §8.2 table, pure | none |

Output is `null` for `on_hold`/`archived` (any non-active, non-completed status), otherwise `{state, label, reasons}` with structured reasons in the fixed order `target_passed {openTaskCount}`, `milestones_overdue {count, earliest}`, `tasks_overdue {count}` (one milestone item however many are overdue; at most three items); `starts_in_future {date}` for rule 3, `no_tracked_work` for rule 4, `[]` for rules 1 and 7. No schema, no override, no threshold, no calendar percentage. Nothing in a controller, model accessor or React derives it.

`Projects/ProjectHealthTest` (32): a 20-row truth table (completed with overdue work, archived/on_hold with overdue work, future start beats overdue work, no dates/no work, no work with a past target, no tasks with an open milestone, overdue zero-task milestone, open overdue task, past target with and without open work, past target with no open task but an overdue milestone, overdue milestone + overdue task, every signal in order, past target + overdue tasks, nothing wrong, all done); bounded reasons; then real data: lifecycle states, the date edges (due/target today not past, start today has started), the board column over `tasks.status`, explicit milestone completion (an overdue zero-task milestone is Off track until completed; finished tasks do not rescue it), earliest by `due_date` then `id` on the Overview with `null` on the index, the **malformed dual-linked task excluded from every input** (a project whose only task is malformed is Not enough data), per-project isolation, a page of projects in **one query** equal to each project alone, and the milestone aggregates equal to the per-row rules.

#### A1.2.4 `ProjectOverviewPresenter` (§12.3, INV-P8, INV-P15)

`App\Http\Presenters\ProjectOverviewPresenter::overview(Project, User)`, called only after `view` is authorized. No route renders it yet (WP2). Keys:

| Key | Content | Present for |
|---|---|---|
| `project` | `id, name, description, status, startDate, targetDate` | every viewer |
| `health` | `ProjectHealth::forOverview` (null for on_hold/archived; the key stays) | every viewer |
| `tasks` | `total, done, open, overdue, completion` from `withTaskStats` | every viewer |
| `milestones` | `total, completed, overdue` (aggregates), `currentId` (first incomplete by `due_date, id`), `nextId` (first incomplete and not overdue), `items` (the shared `ProjectMilestonePresenter::item`, including `completedBy`) | every viewer |
| `abilities` | `{openSettings: true}`; **empty** otherwise | key `openSettings` only with Settings access |
| `budget` | stored decimal string or null; metadata only, no derived figure | Settings access only |
| `members` | `ProjectPresenter::members` (owner first, `{id, name, role, isOwner}`, no email) | Settings access only |
| `time` | `{scope: all, totalMinutes}` with `time.view_all`; `{scope: own, totalMinutes}` with `time.log` alone | omitted with neither |

Time is `TimeEntryService::totalMinutes(['project_id' => P] (+ 'user_id' for own))`, the operator report's own total over the PR A canonical scope, settled entries only, so the Overview cannot disagree with the report. Assignment-candidate lists are untouched and not reused. `milestones.items` carries everything the WP2 `StagePath` mapping needs (`completedAt`, `overdue`, order) without UI vocabulary in the domain.

`Projects/ProjectOverviewPresenterTest` (24): the exact key list and values for a full-permission actor; roster order, roles, no email; health null for on_hold with the key kept; Overview health equal to `ProjectHealth`; the **key-presence matrix** for nine actor shapes (budget/members/openSettings present iff Settings access; time key and scope by permission); a customer member gets no budget, no roster, only their own time, `completedBy` present, and no other member's name anywhere; no raw model/internal column; **project time**: direct + valid board-task + own entries counted, a standalone task with a stale `project_id`, a ticket task with a stale `project_id` and a malformed dual-linked task contribute nothing, a running timer is excluded, a board task in B carrying `project_id = A` counts under B only; Overview total = `totalMinutes` = `summaryByProject` group = canonical scope; own-scope parity with the `/time` filter; `time.view_all` without Settings gets all time but no budget/roster; no time key without either permission; no time entry written. **Progress:** column-authoritative done, `tasks.status` ignored, due today not overdue, malformed rows excluded, equal to `completionPercentage()`, `completionFromCounts()` and `overdueTasks()`. **Milestones:** order by `due_date, id`, out-of-order completion shown as it is, an overdue current keeps `currentId`, `nextId` skips overdue ones, all complete gives null/null.

#### A1.2.5 Query budgets (§17)

Growth-world tests in `ProjectQueryBudgetTest`, measured on **fresh** `Project`/`User` instances per call after a warm-up, with assertions that the world really grew (milestones +27, tasks +54, roster +27, all-user minutes +27 × 90):

| Shape | 3 steps | 30 steps |
|---|---|---|
| Overview, operator (Settings + all time) | 10 | 10 |
| Overview, project manager (Settings + own time) | 11 | 11 |
| Overview, customer member (own time) | 9 | 9 |
| Overview, member with `time.view_all` | 9 | 9 |
| Milestones page, 3 → 30 completed milestones, each by a different user | 5 | 5 |
| Index-shaped health, 3 → 30 projects (paginated 20) | constant (pinned, tolerance 1) | |

Each step adds a member, a milestone (alternately overdue, every third completed by a different user), an open overdue task, a done task, a malformed dual-linked task and three time entries. **Mutation check:** removing the `completer` eager load from the Overview fails all four Overview cases (operator 10 → 19). `ProjectHealthTest` also pins a page of projects with health at exactly one query. No index was added and `EXPLAIN` was not needed.

#### A1.2.6 Pins changed (§16.3)

- `ProjectMilestoneInertiaTest`: the item key list gains `openTaskCount`, `completedAt`, `completedBy`; the overdue test is **FLIPPED IN EPIC-015 WP1 PR B** ("Done late", every task done but never completed, is now overdue; a completed late milestone is not) and keeps the old rule in its comment.
- `ProjectAuthorizationMatrixTest`: `projects.milestones.complete|reopen` added.
- `ProjectTestHelpers`: gains the shared §7 actor shapes (`SETTINGS_ACCESS_ACTORS`, `settingsAccessActor`); existing helpers unchanged.

#### A1.2.7 Deviations and findings

1. **Transitional milestone display (owner attention).** The current (pre-WP4) milestones page renders `overdue` from the DTO. After PR B a past-due milestone is overdue until someone completes it, but the Complete/Reopen **UI** arrives only in WP4 (§14.1); until then completion is reachable only through the new `PUT` routes. A past-due milestone whose tasks are all done therefore shows Overdue with no button to clear it. This is the committed Q2/§16.3 behaviour placed in WP1 and is not changed here; the owner may prefer to land WP4's Complete/Reopen controls early or accept the interval (development data is disposable).
2. `abilities` on the Overview is an empty PHP array for an actor without Settings access, so it serializes as JSON `[]`, not `{}`. Harmless for key-presence (`openSettings` is absent either way); WP2 may type it as `Partial<{openSettings: true}>`.
3. "My open tasks here" (§12.1, useful/optional) and a server-computed days-to-target figure (§12.2) are **not** in the PR B presenter: no WP1 test or §12.3 bullet requires them, and adding fields "for later" is forbidden by §7. WP2 adds them with their page and tests if the composition keeps them.
4. The settings routes were not rewritten to call the resolver (A1.2.1); the parity test pins the equality instead.
5. No TypeScript type was changed: the new milestone DTO keys are additive and unused by the current page, so the build does not need them. WP2/WP4 add the types with their consumers.
6. The development database was migrated with `./dev db:migrate` (backup `backups/dev/portal-2026-10-03_061946.sql`) to run the focused Playwright specs; the testing database migrates per run.

#### A1.2.8 Evidence

| Gate | Result |
|---|---|
| New test files | `Projects/ProjectSettingsAccessTest` (42), `Projects/ProjectMilestoneLifecycleTest` (24), `Projects/ProjectHealthTest` (32), `Projects/ProjectOverviewPresenterTest` (24) |
| Extended / flipped | `ProjectQueryBudgetTest` (+6: four Overview shapes, milestone provenance, index health), `ProjectMilestoneInertiaTest` (keys, flipped overdue), `ProjectAuthorizationMatrixTest` (+16 route × actor cases) |
| Against `5a92f74` | Settings-access characterization 10/10 green; flipped overdue pins red under the old rule (2/2) |
| PR-B suites | 144 passed (940 assertions); query budgets 17 passed (122); authorization matrix 275 passed (434) |
| Focused Pest | `tests/Feature/Projects`, `Time`, `Tasks`, `tests/Unit`, `NavigationBuilderTest`, `ShellContractTest`, `DashboardInertiaTest`: **1287 passed (7701 assertions)**, 315 s (PR A's set: 1143 / 6807) |
| Focused Playwright | `milestones-migration`, `board-migration`, `projects-migration`: **14 passed**; product-data counts before and after identical (projects 0, tasks 0, time_entries 0) |
| Pint on changed files | 20 files; 2 style fixes applied |
| `./dev check` (alone) | **All checks passed**: CLI self-tests 196 assertions; `git diff --check` pass; Pint pass; frontend `npm run check` pass (typecheck, ESLint, Prettier, **Vitest 92 files / 1052 tests**, `vite build` 413 modules); full Pest **1662 passed (9372 assertions)**, 390 s (PR A: 1518 / 8478) |

**Files changed (PR B).** Production: `Policies/ProjectSettingsAccess` (new), `Services/ProjectMilestoneService` (new), `Queries/ProjectHealth` and `Queries/ProjectHealthFacts` (new), `Http/Presenters/ProjectOverviewPresenter` (new), `Http/Presenters/ProjectMilestonePresenter`, `Models/ProjectMilestone`, `Models/Project`, `Http/Controllers/ProjectMilestoneController`, `Http/Controllers/ProjectBoardController`, `routes/web.php`, the migration. Tests: the four new files, the three extended ones, `ProjectTestHelpers`. Docs: this amendment and the status lines.

**Open for the owner.** Finding 1 (transitional milestone display) only; it did not block review or merge. PR B passed independent review and merged as PR #16 (`66d60a2`), completing WP1.

**Review findings carried forward (none implemented in WP1):**
- **WP2:** `nextId` may equal `currentId`; the UI must not render the same milestone as both Current and Next.
- **WP4:** review milestone copy where task progress reads "100% complete" while explicit milestone completion is still open or overdue.
- **Optional test hygiene:** the static fixture step in `ProjectQueryBudgetTest`; two extra denied actor shapes (`projects.manage` with the `member` pivot role, and a member with no permissions) in `ProjectMilestoneLifecycleTest`.
- Milestones with the same due date are ordered by id (A1.2.2).

---

## Amendment 2: WP2 Workspace Frame and Overview

> **Status (2026-10-04): WP2 complete.** Independent review passed (safe to commit, no blocking, high or medium findings); merged as PR #17 (merge commit `e33056d`, implementation `6d3dfe8`). The epic stays **In Progress**. Nothing in Amendment 1 is rewritten; §15's statement that the D3 gate existed is historical and stays as written.

### A2.1 Owner decision: the D3 artboard gate is waived

Recorded **before** any WP2 visual implementation began.

- **Decision.** The owner explicitly **waived** the historical D3 Projects-artboard entry gate ([§15](#15-design-reference-gate-d3), Q7). The D3 light and dark artboards are **not required** to start or to complete WP2. This is a later owner decision; it does not rewrite WP0, which correctly recorded that the gate existed.
- **Visual authority for WP2, in order:**
  1. the committed Direction D design system ([`direction-d-design-system.md`](../design/direction-d-design-system.md));
  2. the application's established current theme and shared primitives (semantic tokens, typography, spacing, rules, surfaces, focus treatment, dark mode, and the established green-accent control language);
  3. EPIC-015's committed IA, content and authorization contracts (§7, §8, §9, §11, §12, §14);
  4. existing polished Direction D surfaces, especially Tasks (EPIC-014) and the existing project-detail surfaces (Board, project task detail).
- **Composition** (section order, what sits in the context rail, empty-state composition) may be adjusted case by case during implementation and review within that authority.
- **No old artboard is required, and none is reconstructed.** If the historical D3 artboard is recovered later it is **optional reference material only** and does not override committed product or design decisions.
- **No new visual theme** is invented: no new palette, no hard-coded replacement colours; shared primitives and tokens only.
- **Scope of the waiver.** The owner's final ruling: the waiver applies to **all remaining EPIC-015 visual work, including WP4** (Projects index, Milestones layout, Board and shared-frame integration, create/edit visual migration). The D3 artboard is not required for any of them; the visual authority above applies throughout. No further owner decision is needed.


### A2.2 Starting point and method

Branch `feature/epic-015-projects-ux` at `a47e593` (`docs: close EPIC-015 WP1`), equal to `main` and `origin/main`, working tree clean; WP1 recorded complete, WP2 not started. No migration, dependency, CI or config change. Small route/controller plumbing only: the WP1 `ProjectOverviewPresenter` is served as is and no domain rule (health, milestone completion, project time, Settings access) is restated anywhere in WP2. No material contradiction between the WP2 brief and this epic was found; the two small differences are recorded as deviations (A2.12).

### A2.3 Routing and landing (Q5, §11.2)

- `projects.show` (`GET /projects/{project}`) now **renders** the Inertia component `projects/show` with exactly `ProjectOverviewPresenter::overview($project, $actor)` after `authorize('view')`. It no longer redirects to the Board. URI, name and middleware are unchanged; `/projects/{id}` links keep working.
- `projects.board` stays `/projects/{project}/board`; Milestones keeps its route. **No `projects.tasks.index` route exists** (WP3).
- `projects.store` redirects to `projects.show` (an ordinary redirect, so an Inertia request follows it as a visit). Validation, authorization (`create`, `manageMembers` for extra members) and the WP1 atomic create are untouched.
- Active state: `projects.show` was already in `projects.all`'s active-route list (`NavigationBuilder`); pinned again through the real route (one active view).

### A2.4 Project workspace navigation (§11.3.1, §11.4)

- **`PageTabs`** (`components/page-tabs.tsx`): a new shared, domain-free primitive. A `nav` with an accessible name, a list of ordinary Inertia links, `aria-current="page"` on the current page, the Direction D §8 ink underline and 600 weight, horizontal scroll rather than wrap at narrow widths. **No** `role="tablist"`, `role="tab"` or `aria-selected` (P3; the forward note to Direction D §8 remains a WP6 item).
- **`EntityHeader`** gains an optional `navigation` slot rendered directly on the strata (Direction D §6: "page tabs over the strata"). Existing consumers are unchanged (the slot is optional and the strata is still drawn once).
- **`ProjectWorkspaceNav`** (`components/projects/project-workspace-nav.tsx`): the one project-level navigation, accessible name **"Project"**, rendering **Overview · Board · Milestones** (all three routes exist and are `view`-gated like the page). **No Tasks tab** (WP3 adds it here). It takes `current: 'overview' | 'board' | 'milestones'`, so WP4 reuses it unchanged on the Board and Milestones pages.
- **Transitional rule applied:** only the Overview renders the navigation in WP2. Board and Milestones do **not** adopt the shared frame/tabs (WP4). The Board gains only a shell breadcrumb trail (A2.9).

### A2.5 Overview composition

Visual authority per A2.1; existing primitives only (`PageFrame grid`, `EntityHeader`, `Strata`, `Section`, `Status`, `Progress`, `Tag`, `EmptyState`, `buttonVariants`); no new colour, no hard-coded value, no local replacement for a shared component.

| Region | Content |
|---|---|
| Entity header (spans) | Overline "Project"; the name as the one `h1`; **lifecycle** status (`ProjectStatusBadge`) beside it; start and target dates (only those set; none, no line); Settings only from `abilities.openSettings`; `ProjectWorkspaceNav` on the strata |
| Main column | **Health** (state + reasons) → **Tasks** (progress, open/overdue figures) → **Milestones** (summary line, compact `StagePath`, key milestones) |
| Context rail (`aside`, "Project details") | **About** (description, plain text) → **Details** (time, budget; each only when its key is present) → **People** (only when `members` is present). Absent entirely when none applies, so the frame has no empty split. Stacks below the main column under XL, in reading order |

Composition decisions (case by case, A2.1): health is a **labelled section**, not part of the header status line §12.1 sketches, so lifecycle ("Active") and derived health ("Off track") are never read as one signal; the open/overdue counts reuse Home's figure-row grammar (rules, not cards); empty states are the shared `EmptyState` with tighter padding so a new project shows two short bands rather than large empty cards. Not on the page: linked companies, invoices, charts, burn, forecasts, days-to-target (deferred, A1.2.7 #3), "My open tasks here" (optional §12.1 item, not in the WP1 presenter, not added).

### A2.6 Health, progress and task summary

- **Health** renders the server's `{state, label, reasons}` only (INV-P13). Glyphs per Direction D §10.1 through `Status`: On track filled circle (success), At risk triangle (warning), Off track square (danger), Not started hollow circle (muted), Complete check (success). **Not enough data** is muted text with no glyph. `health: null` (on hold, archived) renders **no** health section; nothing is invented.
- **Reasons** are mapped from structured codes in server order (`target_passed`, `milestones_overdue` with the named `earliest`, `tasks_overdue`, `starts_in_future`, `no_tracked_work`); each list item carries `data-reason={code}` so tests assert codes; an unknown code is skipped, never shown raw. The list is compact plain text, no alarm styling beyond the status mark.
- **Progress:** "N of M tasks complete" with the server's `completion` as the mono figure and a named `progressbar` ("Task progress", `aria-valuenow = done`, `aria-valuemax = total`, `aria-valuetext` "N of M tasks complete, P%"). Nothing is recomputed from task status; copy says tasks, never effort.
- **Task summary:** open and overdue counts. Until WP3 every task link targets **`projects.board`** and says so ("On the board", accessible names "N open tasks: open the board", section action "Open board"); nothing links to `/projects/{id}/tasks` or is labelled a task list. WP3 retargets them (§11.3.1).

### A2.7 Milestones and `StagePath` (R10, §14.2)

- **`StagePath`** (`components/ui/stage-path.tsx`): generic, domain-free. Props per §14.2 (`stages {key, label, state: done|current|planned|blocked, meta?}`, `variant: full|compact`, `label`, `hiddenBefore?`, `hiddenAfter?`). `label` is required for both variants. The primitive owns the state word ("Done", "Current", "Planned", "Blocked"), visible in `full` and visually hidden in `compact`, and renders the caller's `meta` after it, so state is always text. `role="list"` (restoring list semantics after the Tailwind reset), `listitem`s, `aria-current="step"` on the current stage, decorative segments `aria-hidden`. Geometry: equal columns, 4px gap, heights 6 + 3px per step capped at 24 (`full`) / 14 (`compact`), measured in Chromium as 6/9/12/14/14; tokens `progress-fill` (done), `live` (current, the only cyan), dashed `stage-future` (planned), `warning-glyph` (blocked; produced by nothing in EPIC-015). The `full` variant stacks at phone widths and becomes columns from S/M up; `compact` is always one row.
- **Window rule** (`stagePathWindow`, exported): at most 7 visible, stable index order, the current stage always visible (else the first, or the last when all are done), later stages next, earlier stages backfill. The caller supplies `hiddenBefore`/`hiddenAfter` copy; the primitive adds none.
- **Milestone mapping (Overview):** stages in server order (`due_date`, `id`); `done` = `completedAt` set; `current` = `milestones.currentId`; every other incomplete one `planned`; meta "Due {date}" or "Overdue · due {date}". Task progress never sets `done` (a milestone with every task done but no `completedAt` is `planned`/`current`). All complete: an all-done path with no current stage and "All milestones complete". No milestones: an `EmptyState`, never an empty path.
- **Key milestones:** the current, the next upcoming and any other overdue milestones, in server order, **each once**. **`nextId === currentId` renders one row, tagged Current** (Amendment 1 carried finding, closed for the Overview). Overdue rows beyond five are summarised with a link to the Milestones page. Each row words task progress as "N of M linked tasks done" / "No linked tasks", never "complete" or a percentage; overdue is a clock glyph plus "Overdue · {date}" in danger text.
- The **Milestones page** is unchanged apart from two link targets (A2.9); its 100% task-progress wording stays a WP4 item.

### A2.8 Gated fields and customer-safe rendering (INV-P8, INV-P16, §7)

The page renders a gated block only when its **key** is present and never hides a value it received:

| Key | Rendered as | Absent |
|---|---|---|
| `members` | People: name + project role ("Owner · Manager", "Member"), server order, no email | No People section, no placeholder |
| `budget` | Details › Budget: the stored decimal string with thousands separators in the edit form's unit ("$48,500.00"), never parsed to a number; an authorized `null` is "Not set" | No Budget row, no placeholder |
| `time` (`all`) | Details › **Time logged** "3h 20m", "All team members" | — |
| `time` (`own`) | Details › **Your time**, "Your entries only" (never presented as the project total) | No time row |
| `abilities.openSettings` | Header **Settings** link to `projects.edit` | No Settings action |

`abilities` is typed `Partial<{ openSettings: true }>`; the PHP empty array serializes as `[]` (A1.2.7 #2), which the page reads as no ability. No backend normalization was needed. Time is the presenter's total, not computed or fetched in React. Membership is never reconstructed from another list.

**Customer member (proven over HTTP and in the browser):** receives and renders identity, lifecycle, health, progress, milestones and milestone `completedBy {id, name}` (accepted provenance), and **their own** time; receives **no** `budget` key, **no** `members` key, **no** `openSettings`, no other user's time, no colleague's name and no email in the page props. No customer shell was built.

### A2.9 Generic link retargeting (§11.3)

| Link | Before | After | Class |
|---|---|---|---|
| `ProjectController::store` redirect | Board | **Overview** | Landing (Q5) |
| `projects.show` | 302 → Board | **renders Overview** | Canonical route |
| Projects index card title (`project-card.tsx`) | Board | **Overview** | Generic "open project" |
| `TaskListPresenter` project context (`/tasks` rows) | Board | **Overview** | Generic project context |
| `TimeEntryController` project context (time entries and the running-timer payload) | Board | **Overview** | Generic project context |
| Project task detail trail, project segment | Board | **Overview** | Breadcrumb |
| Milestones page project-name link and back link ("Back to board" → "Back to project") | Board | **Overview** | Generic back links (§11.3) |
| Edit page back link ("Back to board" → "Back to project") | Board | **Overview** | Generic back link (§11.3) |
| Board page | no trail | shell trail **Projects › All projects › {project} › Board**, project → Overview | Breadcrumb (§11.3), the Board's way back before WP4 tabs |
| Board header "Milestones" link | Milestones | unchanged | Explicit |
| `ProjectTaskController::destroy` redirect | Board | **unchanged** (P7) | Explicit board action |
| Overview task counts and "Open board" | — | Board, labelled as the board | Explicit, transitional (§11.3.1) |

The Overview trail is the shell's one breadcrumb, `Projects › All projects › {project}` (the shell's view segment is part of every project trail, as on task detail); no page draws a breadcrumb. Milestones and Settings pages get their trails with WP4's header integration (the Milestones page still draws its own pre-Direction D "{project} / Milestones" line, which WP4 replaces). Pins: `ProjectOverviewPageTest` (server links, timer payload, explicit destroy), Vitest per page, and the Playwright specs.

### A2.10 Responsive, accessibility and visual review

**Automated (Vitest and Playwright):** exactly one `h1` and one `Breadcrumb` landmark (Overview, Board, task detail); `navigation "Project"` with `aria-current="page"` on the current link and no tab roles; no Tasks link; StagePath list semantics, `aria-current="step"`, state in text; health reasons as a named list with readable text; the named `progressbar`; the Settings link by name; keyboard order header action → Overview tab with a visible focus outline; at **390px, 768px (S/M boundary) and 1440px, light and dark**: no document-level horizontal overflow, the three project links, Settings, health, the StagePath and the key milestones all visible. The rail stacks below the main column under XL and splits at XL (1440 shown).

**Visual review** (Chromium screenshots at 390, 1024 and 1440, light and dark, a realistic project and a brand-new one; taken by a temporary spec that was deleted afterwards):
- Hierarchy reads identity → health → tasks → milestones, with the rail for context. Sections are rules, not cards; the only bordered band is the figure row and the empty states.
- Lifecycle ("● Active") and health ("■ Off track") are visibly distinct and never adjacent.
- Controls use the established ink/secondary button and the teal `live` stage only; **no blue accent** was introduced. Dark mode is token-driven with no light-only assumption.
- At 390px the three tabs fit without scrolling, Settings wraps under the title, milestone rows wrap their date under the name, nothing overflows.
- Findings (not defects): the compact StagePath's rise is subtle by specification (6→14px cap); an overdue figure of 0 still links to the board (consistent target, harmless). A brand-new project shows two short empty bands; judged acceptable rather than collapsing them.
- Manual NVDA testing was not performed (not required, §19.15).

### A2.11 Evidence

| Gate | Result |
|---|---|
| New test files | Pest `Projects/ProjectOverviewPageTest` (38); Vitest `pages/projects/show.test.tsx` (39), `components/ui/stage-path.test.tsx` (16), `components/page-tabs.test.tsx` (5), `components/projects/project-workspace-nav.test.tsx` (5); Playwright `project-overview.spec.ts` (5) |
| Extended / flipped | `ProjectInertiaPagesTest` (**FLIPPED IN EPIC-015 WP2**: store → Overview, Inertia and plain; `projects.show` renders), `ProjectQueryBudgetTest` (+2: the real `projects.show` page, operator and customer member), `TaskListPageTest` and `ProjectVisibilityTest` (project context → Overview); Vitest `board`, `edit`, `index`, `milestones/index`, `tasks/show`, `entity-header` and three milestone fixtures (new `MilestoneItem` keys); Playwright `projects-migration`, `board-migration`, `milestones-migration`, `board-drag`, `task-detail-migration`, `task-detail-wp5`, `tasks-migration`, `shell` (create now lands on the Overview; generic links and trails now open it; one `exact` selector because the board's trail names the project) |
| Mutation checks | Spreading an extra `budget` into the `show` props fails 16 page tests (parity and key-presence); pointing the `/tasks` project context back at the Board fails its pin. Both reverted |
| Query budget | `projects.show` page: **13 queries** at 3 and 30 growth steps for both the operator (presenter 10 + 3 request) and the customer member (presenter 9 + 4); the presenter budgets of A1.2.5 are unchanged. One presenter call per request; no client-side fetch. No index added |
| Focused Pest | `tests/Feature/Projects`, `Tasks`, `Time`, `NavigationBuilderTest`, `ShellContractTest`: **1202 passed (6701 assertions)**, 504 s |
| Focused Vitest | 12 files (new + touched): **149 passed** |
| Focused Playwright | `project-overview`, `projects-migration`, `board-migration`, `milestones-migration`: **19 passed**; affected specs `shell`, `board-drag`, `task-detail-migration`, `tasks-migration`, `task-detail-wp5`: 66 passed with 2 trail expectations to update, then `task-detail-migration` + `task-detail-wp5` **11 passed**. Product-data counts before and after every run: projects 0, tasks 0, time_entries 0 |
| Pint on changed PHP | 8 files; 1 style fix (the new test) |
| `./dev check` (alone) | **All checks passed**: CLI self-tests 196 assertions; `git diff --check` pass; Pint pass; frontend `npm run check` pass (typecheck, ESLint, Prettier, **Vitest 96 files / 1120 tests**, `vite build`); full Pest **1702 passed (9560 assertions)**, 485 s (WP1 close: Vitest 92 / 1052, Pest 1662 / 9372) |

### A2.12 Findings and owner decisions

**Owner decision recorded:** the D3 gate is waived for all remaining EPIC-015 visual work, including WP4 (A2.1). Nothing is open for the owner.

**Independent review (passed, safe to commit)** findings, carried forward and not implemented:
- **WP3:** when the Tasks link is added to `ProjectWorkspaceNav`, add a component-level nav overflow/in-viewport regression, not only document-level overflow checks.
- **Future/shared `StagePath`:** exhaustive typing of unknown health reason codes is optional hardening; the Direction D blocked-stage triangle is deferred until a real blocked-stage consumer exists.
- **Optional cleanup (no separate work):** strict `dl` markup on the Overview task figures; stale `/projects/1/board` fixture strings in `timer-context-link.test.tsx`; a possible stale-asset warning in the local E2E launcher.

**Deviations (recorded, not defects):**
1. **Health placement.** §12.1 sketches health in the identity header's status line; WP2 renders it as the first labelled section of the main column so lifecycle and derived health cannot be read as one signal (the brief's §12, composition authority per A2.1).
2. **Milestones tab in WP2.** §11.3.1's WP2 row says "Milestones where its page already renders inside the shared frame"; the brief asks for Overview · Board · Milestones wherever the route exists. The Milestones route exists and is `view`-gated, so the tab is rendered (no 404/405); the Milestones page itself is not yet in the shared frame (WP4).
3. **Board breadcrumb trail.** §11.3's per-page trails are delivered for the Overview, the Board and task detail in WP2; Milestones and Settings get theirs with WP4's header integration.
4. **"Back to board" → "Back to project"** on the Milestones and Edit pages: §11.3 lists them as generic back links; the label changed with the target so a link to the Overview is never called "board".
5. **Process finding (test environment):** the first Playwright attempt ran against a **stale `public/build`** (3 Oct) without the new page, so every create stayed on `/projects/create`; the failures then restarted workers into the login throttle (HTTP 429). Those runs left **10 `E2E …` fixture projects** (no tasks, no time; created before their ids were tracked); they were removed through `ProjectService::deleteProject` and the counts returned to 0. After `npm run build` every run was clean. `./dev test:e2e` does not rebuild assets; a stale build fails loudly rather than silently, but leaves fixtures behind.

**Carried forward:**
- **WP2 (closed here):** `nextId === currentId` is de-duplicated in the Overview (one row, tagged Current), pinned in Vitest.
- **WP3:** add Tasks to `ProjectWorkspaceNav`; retarget the Overview's open/overdue links and "Open board" to `projects.tasks.index` with `completion=open` / `due=overdue`.
- **WP4:** milestones page copy where 100% task progress coexists with an open or overdue milestone; Board and Milestones adopt `ProjectWorkspaceNav` and the shared header; Milestones and Settings shell trails; the Milestones page's own "{project} / Milestones" line; full `StagePath` on the Milestones page; the A1.2.7 #1 Complete/Reopen UI.
- **Optional test hygiene (unchanged from A1.2):** the static fixture step in `ProjectQueryBudgetTest`; two extra denied actor shapes in `ProjectMilestoneLifecycleTest`.
- **Minor debt:** the minutes formatter now exists three times as local helpers (task time panel, `/time`, Overview); a shared formatter is a candidate cleanup outside EPIC-015's required scope.
- **Design-system debt, explicitly OUT OF EPIC-015 scope:** controls in **Helpdesk, Directory, Finance and System** use a blue accent instead of the established green-accent control language. WP2 touched none of those modules and no shared primitive change affected them; this needs its own bounded normalization pass.

### A2.13 Files changed

**Production:** `Http/Controllers/ProjectController` (`show` renders the Overview; `store` lands on it), `Http/Controllers/TimeEntryController` (two project context links), `Http/Presenters/TaskListPresenter` (project context link). Frontend new: `pages/projects/show.tsx`, `components/ui/stage-path.tsx`, `components/page-tabs.tsx`, `components/projects/project-workspace-nav.tsx`, `components/projects/project-health.tsx`. Frontend changed: `components/entity-header.tsx` (optional `navigation` slot), `types/projects.ts` (Overview/health types; `MilestoneItem` gains the WP1 keys), `components/projects/project-card.tsx`, `pages/projects/board.tsx` (trail), `pages/projects/tasks/show.tsx` (trail), `pages/projects/milestones/index.tsx` and `pages/projects/edit.tsx` (link targets only).
**Tests:** as listed in A2.11. **Docs:** this amendment and the status and design-contract lines.
**Not changed:** routes file, migrations, models, policies, `ProjectOverviewPresenter`, `ProjectHealth`, any Helpdesk, Directory, Finance or System file, dependencies, CI, config.

---

## Amendment 3: WP3 Project Tasks

> **Status (2026-10-04): WP3 implemented and independently reviewed (verdict: needs small remediation; no product, security or architecture defect); the test-only remediation is applied (A3.12 #6). Uncommitted and unmerged.** The epic stays **In Progress**; WP4 is not started. Amendments 1 and 2 are not rewritten (only their status lines now record that WP2 merged).

### A3.1 Starting point and method

Branch `feature/epic-015-projects-ux` at `e33056d` (merge of PR #17, WP2), equal to `main` and `origin/main`, working tree clean; `6d3dfe8` (reviewed WP2 implementation) is an ancestor. No migration, dependency, CI or config change. The D3 gate is waived (A2.1); visual authority is the established Tasks workspace and the WP2 Overview. No material contradiction between the WP3 brief and §13 was found; decisions the brief left open are recorded in A3.12.

### A3.2 One task query: `TaskQuery::forProject` (§13.1, Q6, INV-P12)

Project scope is the EPIC-014 pipeline with a different step 2, not a second query class:

| Step | Global (`new TaskQuery($actor, mine\|all)`) | Project (`TaskQuery::forProject($actor, $project)`) |
|---|---|---|
| 1 | `authorized()`: `ticket_id IS NULL`, board tasks of `Project::visibleTo`, own standalone | **same** |
| 2 | Mine (assignee / own unassigned standalone) or All (nothing) | `tasks.project_id = project`, **inside the same parenthesised group** |
| 3–4 | `results()`: completion (column-authoritative `open()`/`done()`), priority, due, kind, project, milestone, assignee, organization, search | **same method**; kind/project/organization cannot be set in project state |
| 5 | `sorted()` allowlist + `id` tiebreak | same, plus the project-only `board` key |
| page | `paginate()` in one snapshot transaction | `paginateProject()`: same transaction, eager loads `milestone:id,name` instead of `project:id,name`, and sets `project` from the instance in hand |

- `TaskQuery::VIEW_PROJECT` is **not** in `VIEWS`, so `resolveView` can never return it; the constructor accepts it only with a project (and a project only with it).
- The malformed project+ticket row is excluded by step 1's existing `ticket_id IS NULL` (A1.1.5 "nothing to add"); no predicate was duplicated. Standalone, ticket and other projects' rows cannot pass step 2.
- No PHP-side filtering; every filter is an AND-ed `where` after the group. Mutation check: deleting the step-2 predicate fails **24** tests.
- `TaskListState::fromProjectInput` normalizes with the same private normalizers; `projectFilters()` echoes only `completion, priority, due, milestone, assignee, q`; `PROJECT_SORTS = SORTS + board`. A directly constructed project state carrying `kind`, `project` or `organization` throws, and the global vocabulary rejects `board`.
- `TaskQuery::projectFilterOptions()`: the project's milestones (`due_date, id`) and the distinct assignees of the project's step-1 rows (names only). Nothing is looked up by a requested id.
- Shared labels moved from `TaskController::labelled` to `TaskListPresenter::vocabulary()` (byte-identical global output; `TaskListPageTest` unchanged and green).

### A3.3 Route and authorization (§7, §11.2)

- `GET /projects/{project}/tasks` → `projects.tasks.index` → `ProjectTaskListController::index` (one action). `authorize('view', $project)` first; no new permission; `tasks.view_all` is not involved. The other `projects/{project}/tasks*` routes are unchanged (route-surface pin).
- Props: `project {id, name, status}`, `tasks` (paginator of project rows), `filters`, `filterOptions {completion, priorities, due, sorts, milestones, assignees}`, `sort`, `projectHasTasks`, `assigneeOptions` (unchanged `TaskAssigneeOptions`), `abilities` (`{openSettings: true}` through `ProjectSettingsAccess`, else `[]`).
- `projectHasTasks` runs one `exists()` on the unfiltered project set **only when the page is empty**, so "no tasks yet" and "nothing matches" never conflate.
- `NavigationBuilder`: `projects.tasks.index` joins `projects.all`'s active routes (not the Tasks workspace's).
- Actor audit (Pest, HTTP): outsider 403, stale non-member assignee 403, guest → login; member, manager pivot, project manager, operator and `projects.admin` alone (A9) 200; matrix row `MATRIX_VIEW` across all 8 actor shapes. A customer member sees exactly the owner's rows; no email, other project, malformed, standalone or ticket row appears in the page props; `openSettings` present iff `projects.edit` admits the actor over all 10 `SETTINGS_ACCESS_ACTORS`.

### A3.4 Project navigation (§11.3.1, §11.4)

`ProjectWorkspaceNav` renders the final **Overview · Board · Tasks · Milestones**; `current` is `'overview' | 'board' | 'tasks' | 'milestones'`. Ordinary links in `nav "Project"`, `aria-current="page"`, no `tablist`/`tab`/`aria-selected`. The component was not redesigned. Board and Milestones still do not render it (WP4).

**Carried WP2 finding closed (component-level overflow).** `project-tasks.spec.ts` asserts on the strip itself at 390 and 1440, light and dark: `overflow-x: auto` with a `nowrap` list (scroll, not wrap), the nav box inside the viewport, each of the four links reachable with `toBeInViewport` after `scrollIntoViewIfNeeded`, the last link fully in view whenever `scrollWidth > clientWidth`, and the focused Tasks link visible with a non-`none` outline whose offset is ≤ 0 (drawn inside the link, so the strip's overflow cannot clip it). Observed: at 390px all four links fit without scrolling, so that branch is not reached by the natural widths. The independent review therefore asked for a forced case (A3.12 #6): the navigation journey narrows the strip to 180px (a fixture, not a breakpoint contract) and asserts `scrollWidth > clientWidth`, that the last link starts clipped, scrolls fully into view (`scrollLeft > 0`), takes keyboard focus with a visible inset outline, and that the document never overflows.

### A3.5 Filters, search and sort (§13.2)

| Parameter | Project scope |
|---|---|
| `completion` | `open` (default) / `done` / `any`, column-authoritative (a Done-column task with `status=todo` is done; the reverse is open, pinned) |
| `priority`, `due`, `q` | as EPIC-014: presets in the application timezone; `%`, `_` and `\` literal; 100-character cap |
| `milestone` | kept **only** if it is one of the project's milestones; a foreign, nonexistent or malformed (`abc`, `-1`, `1.5`, `0`, array) id is **dropped** (ignored, not zero rows), never labelled. A project milestone with no tasks is kept and yields zero rows |
| `assignee` | well-formed id or `none` kept as a narrowing predicate (EPIC-014 owner decision B): a forged id yields zero rows and gets the type-only chip "Assignee filter", never a name |
| `view`, `project`, `kind`, `organization`, junk | dropped; never widen or retarget (pinned through HTTP with a hand-built query string) |
| `sort` | `due` (undated last both ways), `priority` (rank), `title`, `updated`, `created`, **`board`** (column position, task position, id) |

A combinatorial test (3 completions × 2 milestones × 3 assignees × 3 due × 3 searches) asserts every result is a subset of the project set. Pagination links carry only `state->query()` (junk, `view` and unknown priorities shed). Back/forward restore state from the URL alone (Playwright). Client: `ProjectTaskFilterBar` composes the same `FilterBar`/`FilterField`/`FilterSearch`/`FilterToggleGroup`/`FilterChip` primitives with `TaskFilterBar`'s exported helpers; `projectTaskListQuery`/`projectTaskListClearedQuery` share the sort serializer. The milestone control is omitted when a project has no milestones (A3.12 #2).

### A3.6 Milestone row contract (§13.3, P4)

`TaskListPresenter::projectRow` = the canonical `row()` plus `milestone: {id, name} | null`. Nothing else is added (no lifecycle, provenance or due date). The global `row()` and `/tasks` DTO are unchanged: pinned that a global page loads no `milestone` relation, sends no `milestone` key and issues **no** `project_milestones` query (default, All, filtered shapes). `ProjectTaskRow = TaskRow & { milestone }` in TypeScript.

### A3.7 Table, actions and focus

- `TaskTable` gains `scope="project"`: the Milestone column takes Context's place **and its flexible second-band slot at S** (two bands kept), and the "Board" source tag is omitted (every row is a board task). The project name is never repeated per row.
- Row abilities: unchanged `TaskRowAbilities` (one membership query), parity with `TaskPolicy` complete/reopen/assign asserted per row for five actor shapes. Complete/Reopen, the assignment menu and `BulkBar` Complete/Reopen call the unchanged `tasks.*` endpoints (`back()` returns to the filtered tab, pinned). No project-specific mutation logic.
- **Shared, not copied:** the Tasks page's selection/in-flight/focus-repair/bulk logic moved verbatim into `components/tasks/task-list-actions.tsx` (`useTaskListActions`, `TaskActionAlerts`, `TaskBulkBar`). The page owns the table/empty-region refs (React compiler rule). The global page's 30+ existing focus and action tests pass unchanged on the refactor.
- Timer state: `TimerProvider` via the shared hook; no server timer query.
- No create action anywhere on the page (P8). Delete is not surfaced; `ProjectTaskController::destroy` still redirects to the Board, re-pinned with a Tasks-tab referer (P7).
- Empty states: no tasks yet ("Tasks are added and moved on the project board", link to Board); filtered ("No tasks match these filters", Clear filters); open default with only done tasks ("Every task in this project is done", Show all tasks → `completion=any`). The focusable empty region receives focus when the last row leaves (Vitest).

### A3.8 Overview link retargeting (§11.3.1)

Open figure → `projects.tasks.index?completion=open`; overdue figure → `?completion=open&due=overdue`; section action "Open board" → **"View tasks"** (plain list). Captions "On the board" → "View in Tasks"; accessible names "N open tasks: view in Tasks". The empty Tasks section's "Open board" stays on the Board (creation path). Parity pinned in Pest: the filtered list's totals equal the Overview's `open`, `overdue` and `total` for an operator and a customer member, with a malformed row present (§8.4 agreement). StagePath and the rest of the Overview are untouched.

### A3.9 Query budgets (§17)

Growth world per step: a new assigned member, a milestone, open/overdue/done tasks on it, an unassigned task, a malformed row and a task in another project; 3 → 30 steps, with assertions that milestone and assignee options grew by 27.

| Shape | 3 steps | 30 steps |
|---|---|---|
| customer member, default | 16 | 16 |
| project manager, default (assignment candidates) | 18 | 18 |
| operator, `completion=any` | 16 | 16 |
| customer, milestone | 16 | 16 |
| customer, assignee id | 16 | 16 |
| customer, `assignee=none` | 14 | 14 |
| project manager, priority + overdue | 13 | 13 |
| customer, search + board sort desc | 16 | 16 |
| customer, no match (adds the `exists()`) | 12 | 12 |

Mutation: removing the `milestone:id,name` eager load fails 4 shapes (e.g. 22 → 45). The global `/tasks` budgets (EPIC-014 WP3 shapes) are unchanged and green. No index added; `EXPLAIN` not needed (the board sort is a correlated scalar subselect on the `project_columns` primary key).

### A3.10 Responsive, accessibility and visual review

- **Automated:** one `h1` (the project name) and one Breadcrumb landmark; shell trail `Projects › All projects › {project} › Tasks`, project segment → Overview, no page-owned breadcrumb; a visually hidden `h2 "Tasks"`; the table named "Tasks"; at 390px a strict two-band row with the milestone on the second band, ring and checkbox reachable, no document overflow; `table-row` at 1440; light and dark.
- **Visual review** (Chromium, 390 and 1440, light and dark; temporary screenshot test, removed): same Direction D language as `/tasks` and the Overview (ink/secondary buttons, teal `live` only for state, no blue control); the four tabs fit at 390; milestone truncates at S and wraps at desktop like Context; no project column; flat rules, no cards; focus ring visible on the Tasks tab. Finding (not a defect, unchanged from `/tasks`): at 390px the filter controls take most of the first screen.
- Manual NVDA testing not performed (not required, §19.15).

### A3.11 Evidence

| Gate | Result |
|---|---|
| New Pest | `Tasks/ProjectTaskQueryTest` (63 tests, 283 assertions), `Projects/ProjectTasksPageTest` (47, 140) |
| Extended Pest | `ProjectQueryBudgetTest` (+10: nine project shapes, global milestone-free pin), `ProjectAuthorizationMatrixTest` (+8 `projects.tasks.index` cases), `NavigationBuilderTest` (active state) |
| Focused Pest | `tests/Feature/Projects`, `Tasks`, `Time`, `NavigationBuilderTest`, `ShellContractTest`: **1330 passed (7237 assertions)**, 474 s |
| New Vitest | `pages/projects/tasks/index.test.tsx` (24); `task-list-query.test.ts` +5 |
| Flipped Vitest | `project-workspace-nav.test.tsx` (**FLIPPED IN EPIC-015 WP3**: four links, Tasks current, no tab roles), `pages/projects/show.test.tsx` (nav and task links, **FLIPPED IN EPIC-015 WP3**) |
| Focused Vitest | `pages/projects/tasks`, `pages/tasks`, `components/tasks`, `pages/projects/show`, `project-workspace-nav`, `page-tabs`, `ui/data-table`, `ui/filter-bar`, `ui/bulk-bar`: **23 files, 346 tests** passed |
| Playwright | new `project-tasks.spec.ts` (5); `project-overview.spec.ts` updated (four tabs; open count → filtered Tasks). Focused set `project-tasks`, `project-overview`, `tasks-migration`, `board-migration`, `milestones-migration`, `projects-migration`: **35 passed** (author's pre-review run); product-data counts before/after: projects 0, tasks 0, time_entries 0 |
| Independent review | Pest: 12 suites, **603 passed (3255 assertions)**; global-budget Pest (`TaskListInertiaTest`, `TaskAssigneeOptionsTest`): **19 passed (84)**; Vitest focused set: **23 files / 346 tests**; Playwright `project-tasks` + `project-overview` + `tasks-migration`: 20 passed, **1 failed** (the 30 s timeout of the `project-tasks` navigation journey; passes alone in ~23 s, 3/3 on repeat) |
| Playwright after remediation | Focused set `project-tasks`, `project-overview`, `tasks-migration`, **3 workers**, product counts 0/0/0 → 0/0/0 on every run. **Run 1: 12 passed, 9 failed**: all in `tasks-migration`, each in under ~1.5 s, cause **not captured and unresolved** (the same test passed alone immediately after). **Run 2: 21 passed, 0 failed, 0 skipped. Run 3: 21 passed, 0 failed, 0 skipped.** The owner accepted the two consecutive clean runs as sufficient post-remediation evidence; hosted PR CI remains the merge gate. The transient is not investigated further unless hosted CI reproduces a failure |
| Remediation facts | The navigation journey now uses `test.slow()`: under 3-worker load it took up to **32.9 s**, proving the old 30 s limit insufficient; the allowance is now **90 s**. No global timeout, retry, worker or CI setting changed. The forced-overflow navigation case (A3.4) is exercised in that journey, so the overflow branch is non-vacuous. `project-overview.spec.ts`'s project-navigation locator is `exact`. Production code is unchanged by the remediation (27 production files hash-identical before and after) |
| Pint | 12 changed PHP files; 2 style fixes (new tests) |
| `./dev check` (alone) | **All checks passed**: CLI self-tests 196 assertions; `git diff --check` pass; Pint pass; frontend `npm run check` pass (typecheck, ESLint, Prettier, **Vitest 97 files / 1150 tests**, `vite build` 421 modules); full Pest **1830 passed (10127 assertions)**, 524 s (WP2 close: Vitest 96 / 1120, Pest 1702 / 9560) |

### A3.12 Deviations and findings

1. **Header.** The tab reuses the Overview's `EntityHeader` grammar (overline "Project", name as `h1`, lifecycle, Settings, tabs) rather than a `PageHeader "Tasks"`; "Tasks" is the current tab, the trail's last segment, the document title and a visually hidden `h2`. Dates stay on the Overview.
2. **Milestone control: a compliant reading of §13.2 (independent review ruling: COMPLIANT).** "Always offered" makes Milestone part of the project Tasks filter vocabulary, unlike the global list, where it exists only once a single project is selected (§9.5); the same cell defines the options as the project's milestones. With zero milestones the option set is empty, so the select is not rendered. The URL parameter remains supported: a real milestone with no tasks can still be selected and yields zero rows, and a foreign, nonexistent or malformed id is dropped and reveals no metadata. No UI change.
3. **"View in Tasks" copy** for the Overview figures and "View tasks" for the section action, replacing the board wording.
4. **Shared hook refactor** of the global Tasks page (A3.7) — behaviour-neutral; it keeps one implementation of focus repair and bulk.
5. **Test-only issues found during the run** (no product change): `route()` binds a `project` query key to the `{project}` route parameter (tests now build such query strings by hand); a Playwright `navigation "Project"` locator also matched the drawer's "Projects views" (now `exact`); fast successive filter changes in one test raced the previous visit (the test waits for each URL, as `/tasks` tests do). Built assets were rebuilt (`npm run build`) before Playwright; no stale-asset failure recurred.

6. **Independent review and test-only remediation (2026-10-04).** Verdict *WP3 needs small remediation*; the reviewer found **no product, security or architecture defect** (project-scoped `TaskQuery`, authorization, customer safety, the shared task-list hook and query budgets all confirmed). Applied, tests and this document only:
   - **F1, timeout.** In the reviewer's focused 3-worker run `project-tasks.spec.ts` "the Overview counts open the filtered Tasks tab…" hit the 30 s timeout (20 passed, 1 failed; alone ~23 s, 3/3 passes). The test is one journey whose cost is `seed()`, so it is marked `test.slow()` (tripled timeout for that test only) rather than split, which would pay the seed twice. No global timeout, retry, worker or CI change.
   - **F2, forced overflow.** Added to the (already `slow`) navigation journey as described in A3.4, not to the responsive test, which already used ~90% of its 30 s budget under 3-worker load and was left as reviewed.
   - **F3, locator.** `project-overview.spec.ts` `projectNav` now uses `{ name: 'Project', exact: true }`, as the new spec does.
   - **F4, board sort:** no change; the order is deterministic (`id` last) and only edge-cases with corrupt column positions.
   - **Post-remediation evidence:** see A3.11.
7. **Test-suite observation (non-blocking).** Under 3-worker load several other Playwright tests reached roughly 20-27 s; the longest observed was about 26.5 s against the default 30 s timeout. No further `test.slow()` markers were added because none has failed or crossed the timeout. If hosted CI exposes one as a real failure, harden that specific test rather than raising timeouts or retries globally.

**Carried forward (unchanged):** StagePath blocked triangle; optional health-reason exhaustive typing; strict `dl` on the Overview figures; stale fixture strings; the E2E stale-asset warning; the duplicated minutes formatter; the Helpdesk/Directory/Finance/System blue-accent debt. **WP4** still owns Board/Milestones adopting `ProjectWorkspaceNav` and the shared header, the Milestones/Settings trails, the Projects index, Milestones and create/edit migration.

### A3.13 Files changed

**Production (PHP):** `Queries/TaskQuery` (project scope, board sort, project options, project paginate), `Queries/TaskListState` (project input/echo, `PROJECT_SORTS`, guards), `Http/Presenters/TaskListPresenter` (`projectRow`, `vocabulary`), `Http/Controllers/TaskController` (uses `vocabulary`), `Http/Controllers/ProjectTaskListController` (new), `routes/web.php`, `Shared/Navigation/NavigationBuilder`.
**Frontend:** new `pages/projects/tasks/index.tsx`, `components/tasks/project-task-filter-bar.tsx`, `components/tasks/task-list-actions.tsx`; changed `components/projects/project-workspace-nav.tsx`, `components/tasks/task-table.tsx`, `task-title-cell.tsx`, `task-filter-bar.tsx` (exports), `task-list-query.ts`, `pages/tasks/index.tsx` (shared hook), `pages/projects/show.tsx` (links), `types/tasks.ts`.
**Tests:** as in A3.11. **Docs:** this amendment and the status lines.
**Not changed:** migrations, models, policies, `TaskRowAbilities`, `TaskAssigneeOptions`, `TaskService`, `ProjectTaskController`, `StagePath`, Board/Milestones/index/create/edit pages, any Helpdesk, Directory, Finance or System file, dependencies, CI, config.
