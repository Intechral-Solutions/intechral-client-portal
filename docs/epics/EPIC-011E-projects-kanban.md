# EPIC-011E: Projects and Kanban Migration

**Status:** Verified (WP0–WP10 complete, 2026-09-23; owner accepted the NVDA smoke walkthrough as sufficient for this stage and deferred device-matrix and exhaustive assistive-technology testing to final platform-level QA — see [Amendment 11](#amendment-11-wp10-results-and-verification-closeout-2026-09-22-to-2026-09-23))
**Parent epic:** [EPIC-011: React Frontend Migration](./EPIC-011-react-frontend-migration.md)
**Prerequisites:** [EPIC-011A: React Foundation and Coexistence Contract](./EPIC-011A-react-foundation-coexistence.md), [EPIC-011B: Dashboard and Profile Migration](./EPIC-011B-dashboard-profile.md), [EPIC-011C: Authentication and Invitation Migration](./EPIC-011C-authentication-invitations.md), [EPIC-011D: Time Tracking and Persistent Timer Migration](./EPIC-011D-time-tracking-timer.md)
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)
**Related:** [EPIC-005: Project Management](./EPIC-005-projects.md), [EPIC-010B: Tenant Scoping](./EPIC-010B-tenant-scoping.md), [EPIC-010C: Billed Time-Entry Locking](./EPIC-010C-billed-time-entry-locking.md)
**Amendments:** [Amendment 1 (2026-09-21)](#amendment-1-locked-decisions-and-clarifications-2026-09-21): locked decisions D1 to D6, D7 finding, status contract, drag accessibility, optimistic-move spike, dnd-kit policy; [Amendment 2 (2026-09-21)](#amendment-2-d7-resolution-and-final-implementation-clarifications-2026-09-21): D7-B locked, member-data minimization, EPIC-005 reconciliation, C5/C6 final clarifications, implementation-branch gate; [Amendment 3 (2026-09-21)](#amendment-3-wp0-results-2026-09-21): WP0 executed, optimistic design passed unchanged, dnd-kit spike passed with implementation requirements; [Amendment 4 (2026-09-21)](#amendment-4-wp1-results-2026-09-21): WP1 implemented and gated, S1/S2 browser-confirmed, lock protocol refined by the stress test, implementation decisions and observations; [Amendment 5 (2026-09-22)](#amendment-5-wp5-results-2026-09-22): WP5 implemented and gated, board flipped Blade→React, move contract switched to redirect-back, structural memo proven, deviations and observations; [Amendment 6 (2026-09-22)](#amendment-6-wp5-remediation-2026-09-22): independent review remediation — reconciliation-reload sequencing, quick-add duplicate-submit guard, requestMove synchronous-throw hardening; [Amendment 7 (2026-09-22)](#amendment-7-wp6-results-2026-09-22): WP6 implemented and gated, dnd-kit pointer/touch drag layered over the WP5 board, drag-time board projection removed after a real-browser render-loop crash, deviations and observations; [Amendment 8 (2026-09-22)](#amendment-8-wp7-results-2026-09-22): WP7 implemented and gated, task detail flipped Blade→React, a pre-existing task-delete redirect defect found and fixed, checklist toggle contract switched to redirect-back, deviations and observations; [Amendment 9 (2026-09-22)](#amendment-9-wp8-results-2026-09-22): WP8 implemented and gated, `/tasks` flipped Blade→React as a kind-aware unified list, a ticket eager-load regression caught and fixed before commit, both Blade view directories removed, deviations and observations; [Amendment 10 (2026-09-22)](#amendment-10-wp9-results-2026-09-22): WP9 documentation reconciliation and test consolidation, no runtime code changed; [Amendment 11 (2026-09-22)](#amendment-11-wp10-results-and-verification-closeout-2026-09-22-to-2026-09-23): WP10 verification pass — acceptance-contract matrix, authorization/privacy/board-architecture review, real-browser accessibility walkthrough, three checklist accessibility defects found and fixed, one browser-test race fixed, four candidate findings disproved, lifecycle decision (stays Implemented pending a screen-reader and real-device pass)

---

## Amendment 1: Locked Decisions and Clarifications (2026-09-21)

Owner decisions D1 to D6 are **locked**. At the time of this amendment, D7 remained the only open owner decision; it is now resolved by [Amendment 2](#amendment-2-d7-resolution-and-final-implementation-clarifications-2026-09-21). Further live inspection added the clarifications below. **Where this amendment and earlier wording disagree, this amendment wins unless Amendment 2 supersedes it.** The affected sections have been rewritten to match, and §28 is the decision register. Status is unchanged: EPIC-011E is **Planned**, EPIC-011D remains Verified, EPIC-011 remains In Progress.

| Item | Lock or clarification | Applied in |
|---|---|---|
| D1 | Structural project-task mutations require `ProjectPolicy::manage` (project manager holding `projects.manage`, or `projects.admin`), not visibility. Members keep comments and checklist toggling | §2, §5, §10, §11, §16, §23 |
| D2 | A company link is metadata and grants no project access. Index and task-list queries are fixed; `ProjectPolicy` is **not** broadened | §4, §16, §23 |
| D3 | Standalone tasks keep their current create/list capability; no edit, delete, or complete | §15, §16, §23 |
| D4 | A project or task referenced by **any** `TimeEntry` cannot be hard-deleted; historical context is never nulled | §16, §23, §29 |
| D5 | Minimal checklist authoring (add, remove) is in scope for managers/admin; completion stays a native checkbox | §14, §23, §24 |
| D6 | Task time panel: own time to the viewer; all users' time only with `time.view_all` | §5, §11, §23 |
| D7 | Member eligibility was still open at Amendment 1; **resolved as option B by Amendment 2**: only `projects.admin` may add/remove members or assign member roles | §5, §6, §16, §23, §28, §29 |
| Status | Board column is authoritative for board tasks; `Task.status` for standalone/ticket tasks; no dual write | §15 |
| Drag | Move menu is the canonical keyboard/mobile path; drag is pointer-only with no focusable drag control | §9, §10 |
| Spike | `router.optimistic()` was provisional until WP0; **WP0 passed all eight gate items unchanged (Amendment 3)**. The fallback (a move descriptor, never a board copy) is not selected | §8, §29 |
| dnd-kit | Stable `core` + `sortable` + `utilities`, versions pinned by lockfile, library types confined to two adapter files | §9 |
| WP1 | Backend and Blade-visible defects are fixed and regression-tested before any React route flip | §29 |

> **Forward note (2026-09-29).** D3 is superseded for future work by [EPIC-014 Q4](./EPIC-014-tasks-workspace-overhaul.md#191-epic-011e-d3-standalone-tasks-are-createlist-only): standalone tasks gain detail, edit, Complete/Reopen and delete for their creator or current assignee, under the recorded-time guard. D3 remains the accurate record of this epic's scope; the behaviour changes in EPIC-014 WP2/WP5.
>
> **Status (2026-10-01, EPIC-014 WP7).** The supersession is implemented: `tasks.show`, `tasks.update`, `tasks.destroy`, `tasks.complete`, `tasks.reopen` and `tasks.assignee.update` exist for standalone tasks (EPIC-014 WP2 backend, WP5 detail page), authorized by `TaskPolicy` for the task's **creator or current assignee** only, with delete through the shared recorded-time guard. The current contract is [EPIC-014](./EPIC-014-tasks-workspace-overhaul.md) §7.2 and §10. The D3 pin in `ProjectPinnedBehaviorTest` was rewritten to that route surface, and the AA10/A9.7 browser residue closed in EPIC-014 WP7. D3 above is preserved as history.

### Conflicts and corrections found while amending

| # | Finding | Consequence and resolution |
|---|---|---|
| C1 | D1 tightens current behavior: `move`, `store`, `update`, `destroy` authorize `view`, the Blade board shows *Add task* and drag to every member, and EPIC-005 story 005-02 says a *project member* wants to "visualize and manage tasks". No separate task permission exists, and every existing task test acts as `operator`, so nothing proves member-level create/move was deliberate | Implemented as locked. Plain members (typically client users) get a **read-only board** plus comments and checklist toggling. Recorded as an accepted behavior change; the Blade views hide the controls in WP1 so the interim UI never offers a forbidden action, and WP9 corrects EPIC-005's stale wording |
| C2 | D2 contradicts the documented tenant matrix that still says the project boundary is "project membership **or linked company**" (EPIC-010B table, ADR-005, `rbac-design.md`, `database-schema.md`) and the create/edit copy ("grant their organization members visibility") | Behavior follows the lock. WP9 adds corrective notes to those documents; WP3 corrects the UI copy. `projects.view_org` and `tasks.view_org` become inert for project rows; they stay in the catalogue and role defaults untouched |
| C3 | D4 cannot be guaranteed by application code alone: `time_entries.project_id/task_id` are `nullOnDelete`, so a race (timer started between check and delete) or a DB-level cascade (`projects.created_by` and `tasks.project_id` cascade) would silently null history | The plan adds one additive, reversible FK-hardening migration (`restrictOnDelete`) as a backstop. This **supersedes the earlier "no schema changes" decision (T7)** |
| C4 | Tickets have no delete route and `DevSeeder` seeds no tickets (EPIC-011D, Post-Review Hardening Notes, *Browser-test data hygiene*, records why the Blade-tracker E2E used a throwaway project). The earlier plan to re-point that test at a ticket page would leave undeletable fixtures | §21 and WP7 replace it with an idempotent seeded fixture ticket; §21 and WP3 also fix the 011D multi-timer test that treats `/projects` as a Blade document |
| C5 | `time.view_own` exists in the catalogue but is enforced nowhere; personal time routes are `can:time.log` and reports are `can:time.view_all` | D6 uses exactly those two permissions and does not wire `time.view_own` |
| C6 | The container runs PHP 8.3 (composer `^8.3`) while the host CLI is 8.4. The HTML5 tree-builder check used for defect S2 relied on PHP 8.4 `Dom\HTMLDocument`, which does not exist in the container | Pest regression tests use a non-8.4 structural check (§23); the S2 evidence stands as parser-level reasoning, and a browser reproduction is still required |
| C7 | Standalone tasks **are** time-trackable: `contextOptions` offers any task assigned to the user, and standalone tasks carry `status`, not a column | The status fix must be kind-aware (§15); D3 capability inventory records this omitted capability |
| C8 | `TicketPolicy::view` is owner-or-operator only, while the ticket list and `/tasks?view=org` include same-company tickets | Ticket-derived task rows keep their current list behavior but render no link the ticket policy would deny; flagged for EPIC-011F |

---

## Amendment 2: D7 Resolution and Final Implementation Clarifications (2026-09-21)

All owner decisions D1 through D7 are now **locked**. D7 is resolved as **option B**. There is no remaining product decision blocking implementation. **Where Amendment 2 conflicts with Amendment 1 or later section text, Amendment 2 wins.** The body and decision register below have been updated so implementation does not need to branch on an unresolved member-eligibility policy.

| Item | Final rule | Applied in |
|---|---|---|
| D7 | **Only `projects.admin` may add/remove project members, change project-member roles, or provide initial extra members during project creation.** A non-admin project manager retains every other `ProjectPolicy::manage` capability but cannot mutate membership | §2, §5, §6, §16, §23, §28, §29, §30 |
| Member data | Only an actor allowed to manage membership receives the candidate-user directory. Non-admin project managers receive no `memberCandidates` prop and no candidate emails; existing membership is rendered read-only from a minimal `{id,name,role,isOwner}` DTO | §5, §6, §16, §23 |
| Authorization | Add a dedicated server authorization boundary for membership management (recommended: `ProjectPolicy::manageMembers`) that returns true only for `projects.admin`. `projects.members.sync` uses it. `projects.store` remains available to normal project managers, but a non-admin request that supplies extra `members` is refused rather than silently ignored | §2, §6, §16, §23 |
| D1 docs | D1 is an intentional product change even though EPIC-005 previously said a project member manages tasks. WP9 corrects EPIC-005 so current product documentation no longer contradicts the manager-only structural-mutation rule | §4 (C1), §29, §30 |
| C5 | `time.view_own` remains permission-model debt. EPIC-011E does not start enforcing or redefining it; D6 continues to use only `time.log` and `time.view_all` | §3.4, §11, §27 |
| C6 | The nested-form issue must be characterized in a real browser before being described as browser-confirmed. Whether or not the destructive behavior reproduces, the invalid nested form is removed in WP1 and covered by a structural regression test that runs on PHP 8.3 | §4 (S2), §23, §29 |
| Done tasks | Done-column project tasks are excluded from timer-context options **server-side** through the kind-aware status contract; React filtering is never the enforcement boundary | §15, §16, §23 |
| Drag accessibility | The pointer drag affordance itself remains outside the keyboard/accessibility tree; the Move menu is the canonical keyboard, assistive-technology, and mobile movement operation | §9, §10 |
| Branch gate | Planning documentation is committed first; implementation starts only from a dedicated EPIC-011E branch based on the verified EPIC-011D state, not from a branch named for EPIC-011D | §29 |

### D7-B operational consequences

- `projects.admin` may select any existing `users` row as a project member because the current user model has no active/disabled lifecycle state. This is an administrative directory operation, not a new tenant-derived eligibility rule.
- Non-admin project managers do **not** receive the portal user directory, even if React would hide the controls. Data minimization is part of the authorization contract.
- On project create, the creator is still attached automatically as manager by the existing service. A non-admin manager may create a project but may not add other initial members. An administrator may provide initial members.
- On project edit, non-admin managers see the current member list read-only (name, role, owner marker) so the page remains understandable, but they receive no candidate list, email directory, role controls, add/remove controls, or member-sync form.
- `projects.members.sync` is administrator-only. Existing project membership still controls visibility through `ProjectPolicy::view`; D7 does not alter project visibility.
- Assignment eligibility remains separate: a project task may be assigned only to an existing member of that project (A3). D7 governs who may change project membership, not who may be assigned once already a member.
- Delegated member management for ordinary project managers is deferred until the product has a canonical project-member eligibility model. Organization membership and company links are **not** repurposed to invent that model in EPIC-011E.


## Amendment 3: WP0 Results (2026-09-21)

WP0 was executed exactly as written on a throwaway branch, in a real browser, against the real move endpoint. **The Inertia optimistic-move design passed all eight gate items unchanged; the `fetch` + `pendingMove` fallback is NOT selected.** The dnd-kit spike passed on React 19 and Vite 8. All spike code, temporary dependencies, fixture data, and the throwaway branch were removed; only this documentation is retained. Full results are in §8 (optimistic gate) and §9 (dnd-kit).

Tested versions: `@inertiajs/core` and `@inertiajs/react` 3.7.1, React and React DOM 19.3.0, Vite 8.1.0, `@vitejs/plugin-react` 6.1.1, `@dnd-kit/core` 6.3.1, `@dnd-kit/sortable` 10.0.0, `@dnd-kit/utilities` 3.2.2 (with transitive `@dnd-kit/accessibility` 3.1.1), Playwright 1.63.0 with Chromium 153.

| Topic | Outcome | Applied in |
|---|---|---|
| Playwright environment | Host Chromium cannot launch (13 missing shared libraries, no sudo). **The suite runs inside the `portal_app` container** with `PLAYWRIGHT_BASE_URL=http://nginx`; an existing spec passed there | §24, §29 |
| Optimistic design | Passed unchanged (8 of 8) | §8 |
| dnd-kit | Passed: handle-only activation, cross-column and same-column sorting, empty-column drop, autoscroll, touch handle drag, reduced motion | §9 |

**Implementation requirements added by the spike** (each is also stated where it applies):

1. **`TaskCard` must use a structural `memo` comparator.** Inertia hands the optimistic callback a deep clone of the props, so every task object is a new reference during the optimistic phase; a reference-equality `memo` re-rendered all cards, while a comparator on the rendered fields re-rendered only the moved card (§8, §25).
2. **Rollback restores the last client-known state, not fresh server state,** for non-Inertia failures (403, 404, 5xx, offline). The plan's `router.reload({ only: ['columns'] })` after those failures is therefore required, not optional (§8).
3. **Single flight is mandatory,** not defensive: without the guard Inertia sent both requests concurrently and the server applied them out of order (§8).
4. **Drag-preview clearing:** the preview descriptor is cleared by an effect on `props.columns` (after the optimistic or rolled-back props land), never at drop time, which avoids a snap-back frame. A failed drop was verified to roll back, clear the preview, and leave the board usable (§9).
5. **Drop position:** `onDragOver` fires only when the `over` target changes, so the final index is derived at drop time from the current layout; use a pointer-first collision strategy with `closestCorners` fallback, and test "drop below the last card" after re-aiming, because the target column shifts once the preview inserts the card (§9).
6. **Announcements** must be built from the derived target (column name and position), not from dnd-kit's raw `over`, which is usually the dragged card's own placeholder once the preview has moved it (§9).
7. **The horizontal board scroller is a native keyboard tab stop in Chromium.** Give it a deliberate `role="region"`, accessible name, and `tabIndex={0}`, as already planned (§7, §9).

---

## Amendment 4: WP1 Results (2026-09-21)

WP1 (backend hardening and characterization) is **implemented** on `epic-011e-projects-kanban`, uncommitted, with no React change and no decision reopened. EPIC-011E stays **Planned**; the status moves with the epic, not the work package. Tests were written first: the original backend was characterized (22 tests green against unmodified production code), then the target contract was written and run red (**96 failures**), then fixed.

**Gates (all green, final run 2026-09-21, after the maintainer applied the migration to the development database and after the dashboard fix W17):** full Pest on MariaDB 10.11, container PHP 8.3.33: **735 passed** (405 before WP1); focused WP1 suites (Projects, Dashboard, tenant scoping, navigation): 378 passed; Pint over the whole repository; `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest 73/73, build), exit 0; the full Playwright suite (1.63.0, Chromium in `portal_app`, `PLAYWRIGHT_BASE_URL=http://nginx`) against the migrated, RESTRICT-enforced development schema: **14 passed** (10 existing plus the 4 new WP1 regressions); `git diff --check` clean. The concurrency stress was run at 10 workers x 60 moves for three seeds (1,800 moves) with no error, no deadlock, and dense columns throughout. Development-database hygiene after the browser run: 0 projects, columns, members, milestones, tasks, comments, checklist items, time entries and allocation blocks, no `E2E`-named or hostile-name rows, only the two seeded accounts; `sessions` is the documented, uncleaned table (it fell from 66 to 36 through normal garbage collection during the run).

### Where the live result differs from, or adds to, the plan

| # | Item | Outcome |
|---|---|---|
| W1 | **S2 is now browser-confirmed** (closes the Amendment 2 / C6 condition) | On the unmodified page in Chromium 153, clicking *Save Changes* submitted `_method=PUT` then `_method=DELETE`, redirected to `/projects`, and the project returned 404 afterwards: no confirmation, no error. Three forms existed in the DOM and none was nested (the parser had discarded the inner tag). Fixed: the delete form is now a sibling in a Danger Zone section. Pinned by a Playwright regression and a PHP-8.3 stack-based Pest test |
| W2 | **S1 is now browser-confirmed as DOM injection** | On the unmodified create page, a user named `</select><img src=x onerror="...">` injected one `<img src="x">` element into the manager's DOM when *Add member* was clicked. Script execution was not asserted (the probe read the DOM before the asynchronous `onerror`). `@json` hex-escapes the payload, so the served HTML was inert; the flaw was purely the client interpolation into `innerHTML`. Fixed with a server-rendered, Blade-escaped `<template>` cloned by `template.content.cloneNode(true)` |
| W3 | **The stress test found a real locking defect in the first implementation** | A deadlock survived the three transaction retries. Two causes: (a) the task's current column was read before the locks, so when it changed the code locked the new column *out of order*; (b) that first plain read also opened the REPEATABLE READ snapshot, so positions read after the locks could be stale. Final protocol (**supersedes the wording of §16.2, which stays correct in outline**): read the task's column outside any transaction; inside the transaction lock the source and target column rows in ascending id order, then the task row (`FOR UPDATE`); if the task's column differs from what was read, return and **restart the whole attempt** (up to 5) instead of locking anything else; only then read positions (the first plain read, so a fresh snapshot); the transaction is additionally retried on deadlock (3). Exhausted restarts answer 409 |
| W4 | **Assignee rule refinement (I8, server side)** | `assignee_id` must be a member of the route's project, **or equal to the task's current assignee**. Saving a task unchanged is not a new assignment, so a person who has since left keeps the task and the WP7 form can show them "(no longer a project member)" without a 422. Any *other* non-member is rejected. The Blade edit form now includes the ex-member option for the same reason |
| W5 | Non-admin extra initial members answer **403** | `projects.store` calls `authorize('manageMembers', Project::class)` when `members` is non-empty, before validation. An empty `members` array is allowed. `ProjectPolicy::manageMembers(User, Project\|string\|null)` accepts a project or the class name; it returns `projects.admin` and nothing else, independent of `projects.manage` |
| W6 | `projects.members.sync` route | The `can:projects.manage` middleware was removed from this one route (§16). A9 is otherwise untouched: create, edit, update, destroy, companies and milestone writes keep the middleware, pinned by a test. Consequence, unchanged from before: the Blade board *Settings* link is shown to anyone `manage` allows, including a `projects.admin` holder without `projects.manage`, who then gets 403 from the middleware. **WP5 must not reproduce this**: the React board derives the link from a dedicated `abilities.openSettings` (§5, §7), not from `abilities.manage` |
| W7 | Blade view data now matches the WP3 prop names | `create`: `companies`, optional `memberCandidates`. `edit`: `project`, `members` (`{id,name,role,isOwner}`), optional `memberCandidates` (`{id,name,email}`, administrators only), `companies`, `linkedCompanyIds`. `memberCandidates` is absent from the view data, not empty, for everyone else, and the non-admin edit page lists members read-only without emails |
| W8 | Service API | `ProjectService` gained `createTask`, `moveTask` (rewritten), `deleteTask`, `deleteProject`. Errors: unknown or foreign column on the HTTP move is 422 on `column_id`; a task or column that vanishes mid-request is 404; a blocked delete is a 422 `ValidationException` on `delete` (HTTP redirect-back with the error for Blade, 422 JSON for XHR); a foreign-key violation from the race between the check and the DELETE is mapped to the same message. Dense positions from 0; create appends at the true tail even over legacy gaps or duplicates; a no-op move writes nothing and leaves `updated_at` alone |
| W9 | Checklist | `POST` and `DELETE` routes as planned (manager only, redirect-back). Position is `max + 1` (0 when empty), the 100-item cap and the tail position are serialised by a row lock on the task. Toggle accepts an optional boolean `completed` (idempotent) and still flips when it is omitted; it still returns JSON until WP7 |
| W10 | `Task` gained `kind()` with `KIND_*` constants, and the SQL scopes `done()`, `open()`, `overdue()`; `Project::visibleTo()`, `withTaskStats()`, `completionFromCounts()`; `ProjectMilestone::withTaskCounts()`, `completionFromCounts()`, `isOverdueAt()` | `Task::isOverdue()`, `Project::overdueTasks()` and the milestone page now share one rule: due before today and not done by the kind-aware definition. `overdueTasks()` therefore also counts a null-column task whose raw status is not `done` (the pinned §15 edge case); before, it silently ignored those |
| W11 | Query budgets (measured, warm request, 3 rows against 30) | Projects index **21 to 106 queries before, 4 and 4 after**; board 15 to 69 before, **8 and 8**; milestones 10 to 64 before, **4 and 4**; `/tasks` **10 and 10**. Each `@can` inside a loop was replaced by one policy check per page, because it ran one membership query per row |
| W12 | D2 on `/tasks` | The org tab is the union of my tasks, tasks of company-linked projects **the viewer can open**, and tasks on tickets of the viewer's companies (ticket rows keep their list behavior, C8). Links are decided per page in two batches (`openableProjects`, `openableTickets`): a project the viewer cannot open, or a ticket `TicketPolicy` denies, still shows its name or number as plain text, never a link |
| W13 | D6 on the Blade panel | Only the `task` context of `x-time-tracker` is scoped: own entries and own total unless `time.view_all`; running entries stay excluded. Project and ticket contexts are unchanged. A `time.view_all` holder without `time.log` still sees no Blade panel (the React panel in WP7 changes that, §11) |
| W14 | D4 migration | `2026_09_21_120000_restrict_time_entry_project_and_task_deletes` switches `time_entries.project_id` and `.task_id` from `SET NULL` to `RESTRICT`, both columns stay nullable, `down()` restores `SET NULL`. Verified in a Pest test (rollback then forward, in its own fixture-free file because DDL commits implicitly), and through `migrate:rollback --step=1` and `migrate` on the testing database. **The maintainer then applied it to the development database (`portal`, batch 3)**; verified afterwards without re-running it: `migrate:status` lists it as run, no migration is pending, both foreign keys report `RESTRICT` for delete and update, both columns are still nullable, and the testing database is separately configured (`intechral_client_portal_testing`, its own batch 1) with the same rules. The application guard does not depend on the constraint. A side effect of the backstop, consistent with C3: deleting a *user* whose created project holds recorded time would now fail on the foreign key instead of nulling history; no user-deletion route exists |
| W15 | Read-only audit (§16 "Existing data") | `php artisan projects:audit-integrity [--json]` (service `ProjectIntegrityAudit`, SELECT only, proven by a before/after table fingerprint). Counts: column from another project, milestone from another project, project task assigned to a non-member, time entries whose project or task row is missing, projects and tasks the D4 rule would refuse to delete. On the development database every count is 0, but that database currently holds **no** projects or tasks, so this says nothing about production data; **`projects:audit-integrity` must be run against populated production data before the restrictive foreign-key migration is applied there** (and before any legacy cleanup is contemplated); no cleanup was designed or run here |
| W16 | Test support | `TaskFactory` default column now belongs to the task's own project (the old default produced the A1 shape); states `inColumn()` and `assignedTo()`; `TaskChecklistItemFactory`, `TaskCommentFactory` (models gained `HasFactory`); shared fixtures in `tests/Feature/Projects/ProjectTestHelpers.php` |
| W17 | **Dashboard project count (D2), fixed in WP1** | `DashboardController` counted **every** active project for any `projects.manage` holder, so a non-administrator project manager saw a total that disagreed with the index and disclosed how many projects existed beyond their access; and a `projects.admin` holder without `projects.manage` saw the smaller member-only figure while their index lists everything. It now uses `Project::visibleTo($user)->where('status', 'active')->count()`, the same scope as the index, with no visibility logic duplicated and no change to `ProjectPolicy`. `ProjectDashboardCountTest` (6 tests, written red first: 5 failed) covers a manager who can open one of two active projects (1, not 2), a manager in no project (0), a plain member, a company link (ignored), administrators with and without `projects.manage` (all active), and equality with the index for every actor kind |

### Tests added or changed

| File | Purpose |
|---|---|
| `ProjectAuthorizationMatrixTest` | 25 routes x 8 actors (200 cases) plus authorization-before-404, authorization-before-deletion-guard, and cross-project manager checks |
| `ProjectIntegrityTest` | A1 to A3, A7, I8, move and ordering (dense, clamped, legacy repair, no-op, 404), status contract per kind, timer-context options, overdue rule, checklist D5, comments, standalone D3 |
| `ProjectDeletionGuardTest`, `TimeEntryForeignKeyMigrationTest` | D4 cases 1 to 10 and the migration case, including billed, invoiced, unbilled and running entries and the race mapping |
| `ProjectVisibilityTest` | D2: index equals the policy for every user kind, `visibleTo` equals the policy, `ProjectPolicy` surface, `/tasks` rows and links, informational copy |
| `ProjectMemberManagementTest` | D7-B: sync, initial members, validation, data minimization, read-only member DTO |
| `ProjectBladeRegressionTest` | S1, S2, D1 Blade controls, D4 error display, D6 panel, ticket panel untouched |
| `ProjectQueryBudgetTest`, `ProjectIntegrityAuditTest` | P1 to P3 and the audit |
| `ProjectDashboardCountTest` (with the existing `DashboardInertiaTest`, unchanged) | W17: the dashboard figure equals the visible active projects and the index |
| `ProjectMoveConcurrencyTest` (+ `tests/Support/project_task_worker.php`) | Parallel PHP processes on committed rows: cross and within-column moves, creates racing stale indexes, deletes racing moves. Cleans up after itself; `PROJECT_STRESS_WORKERS`, `_MOVES`, `_SEED` scale a soak run |
| `ProjectPinnedBehaviorTest` | The characterized behavior WP1 deliberately leaves alone (A9, status contract, null-column edge, comment/toggle access, D3 route surface) |
| `tests/Browser/wp1-blade-regressions.spec.ts` | Temporary (deleted with the Blade views, §24): S2, D4, S1 with a real hostile user, D1 read-only board with a second browser context |

`ProjectManagementTest`, `ProjectTaskTest` and `ProjectMilestoneTest` pass unmodified.

### Observations and non-goals, deliberately not changed

- **A9 and the Settings link** stay as they were in Blade (pinned); the React board is planned to avoid the dead link through `abilities.openSettings` (§5, §7, W6).
- **`time.view_own`** remains unenforced permission-model debt (C5); WP1 neither wires nor redefines it.
- **User deletion**: no route exists and none is designed here; the RESTRICT backstop only means such a delete would now be refused for a user whose project holds recorded time.
- The Blade board quick-add and the milestone forms still swallow validation errors (X5); the React pages fix that in WP4 and WP5.
- Milestone selection in the Blade task edit form was not added (I4 is WP7 in React); the endpoint now validates it.

---

## Amendment 5: WP5 Results (2026-09-22)

WP5 (the board without drag) is **implemented** on `epic-011e-projects-kanban`, uncommitted, no `dnd-kit` package installed, task detail/`/tasks`/tickets untouched and still Blade. EPIC-011E stays **Planned**; the status moves with the epic, not the work package.

**Gates (all green, 2026-09-22):** full Pest on MariaDB, container PHP 8.3: **797 passed** (up from 763 after WP3; WP4 added milestone coverage in between); Pint clean over the whole repository; `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest 263/263 across 42 files — up from 171 after WP3 — build), exit 0; the full Playwright suite (1.63.0, Chromium, `portal_app`) against the shared development database: **22 of 23 passed**; `git diff --check` clean.

The one Playwright non-pass is `auth-migration.spec.ts`'s "React login reports invalid credentials" test, a file WP5 does not touch. Under the full suite's 6-worker parallel load it intermittently fails on two different symptoms across repeated runs (an empty accessible-description read, and a login that never reaches `/dashboard`), both consistent with Fortify's login rate limiter reacting to six concurrent sign-ins against the shared development database. Run alone, or serially with `--workers=1` alongside the rest of the suite, it passed **every time** (verified twice). This is pre-existing browser-suite infrastructure behavior, not a WP5 regression; no WP5 file is in its call path.

### Where the live result differs from, or adds to, the plan

| # | Item | Outcome |
|---|---|---|
| X1 | `app/Http/Presenters/ProjectBoardPresenter.php` (new) | `project()`, `column()`, `task()`. Matches §5's `BoardTask`/`BoardColumn` shapes exactly; `overdue` and column-done state go through `Task::isOverdue()`/`isDone()` only, never re-derived |
| X2 | `move` contract switch | `ProjectTaskController::move` now returns `back()->with('success', 'Task moved.')` instead of `response()->json(['ok' => true])`, exactly as §2 anticipated. A genuine Inertia partial reload (`X-Inertia-Partial-Data: columns,flash`) after the redirect returns only `columns`, `flash`, and Inertia's own always-shared `errors` — pinned by a Pest test that drives the real partial-reload headers rather than asserting on the JSON body shape |
| X3 | `projects.store` response | Now an ordinary `redirect()->route('projects.board', $project)` again: the `Inertia::location()` full-page-visit workaround from WP3 (needed only because the board was still Blade) is removed, since the board is a React page as of this work package |
| X4 | `requestMove` stability under React's newer ref-mutation lint | `react-hooks/refs` (part of this repo's React Compiler-era ESLint config) forbids assigning `ref.current` during render, which the initially-drafted "latest value ref" pattern did. Fixed by moving the three ref assignments (`columns`, `abilities`, `projectId`) into a bare `useEffect(() => {...})` (no dependency array, so it runs after every commit); `requestMove` itself stays a `useCallback` with an empty dependency array and is never recreated, which is what keeps it a stable identity for `TaskCard`'s structural memo |
| X5 | "Move to \<column\>" append semantics vs. memo stability | Rather than pass each card `column.tasks.length` (which changes on every move for the affected columns, and would force `columns`, and therefore every card's Move menu, off a stable reference), a cross-column "Move to" selection sends `APPEND_TO_END` (`Number.MAX_SAFE_INTEGER`) as the position and lets the same clamp that already handles any out-of-range index — client-side in `applyMove`, and server-side under the column lock (§16.2) — land it at the true tail. `columnSummaries` (`{id,name,isDone}`) is a separate, `useMemo`'d, content-keyed prop from the full `columns`, so it stays referentially stable across moves that don't add, remove, or rename a column |
| X6 | Structural memo test strategy | `React.Profiler`'s `onRender` fires once per commit for a Profiler's whole subtree even when a memoized child bails out, so it cannot distinguish "re-rendered" from "bailed out" by itself; an early Profiler-based render-count suite gave false failures unrelated to any real bug. `taskCardPropsAreEqual` (the comparator `TaskCard`'s `memo` uses) is exported and unit-tested directly instead — true/false for every rendered-field and structural-prop combination — plus one `render`/`rerender` DOM check that a genuine field change does propagate. This is what §25/WP0's "Add tests proving..." requirement is actually about: the comparator WP5 wrote, not React's own trusted `memo` mechanics |
| X7 | `board.blade.php` deletion cascade | Deleting it also retired `tests/Browser/wp1-blade-regressions.spec.ts` (its own header already said it would be deleted with the Blade board); its one remaining case (D1 read-only board) is now `board-migration.spec.ts`'s "plain project member" test against the React board. Two **pre-existing** browser tests needed small updates because they exercised the Blade board's own markup or copy, neither a WP5 regression: `time-migration.spec.ts`'s embedded-tracker setup used `.add-task-btn`/`Task title…` (Blade quick-add markers), updated to the React board's `Add task to <column>` button and `New task title` label; `projects-migration.spec.ts`'s create-project flow asserted `getByRole('alert')` for the "Project created successfully." flash, which was the *Blade* board's location-visit fallback page; the shared React `FlashRegion` renders a success flash as `role="status"`, and now that `projects.store` no longer needs that fallback (X3), the assertion is updated to match |
| X8 | `TimerContextLink` flip | `contextLinkModes.project` flipped from `'document'` to `'inertia'`, exactly as §21 specified for WP5; `task` and `ticket` are untouched. Adopted by the timer bar and the Time page; a Vitest case pins the new default alongside the still-`'document'` kinds |
| X9 | Deliberately not added | No `board-dnd.tsx`, `board-card-handle.tsx`, or `board-announcements.ts` beyond the pure success/failure message builders; no `dnd-kit` package; no keyboard drag; no fake focusable drag handle; no second time-report or status model |

### Tests added or changed

| File | Purpose |
|---|---|
| `app/Http/Presenters/ProjectBoardPresenter.php`, `ProjectBoardController.php`, `ProjectTaskController::move` | The board DTO and its Inertia render; the redirect-back move contract |
| `ProjectBoardInertiaTest` (new) | Component/prop shape, minimal DTO (no raw model/description/comment/checklist-text/email leakage), column/task order, column-done and overdue rules, `abilities.manage`/`abilities.openSettings` per actor (including the A9 `admin_only` case), checklist aggregate counts, the move partial-reload contract (`only` returns exactly `columns`+`flash`+`errors`), 404 on a foreign project id |
| `ProjectIntegrityTest`, `ProjectTaskTest` (edited) | Move-response assertions flipped from `assertExactJson(['ok'=>true])`/`assertOk()` to `assertRedirect()`, matching the new contract; behavior itself (dense positions, locking, concurrency) is unchanged and still covered by the existing WP1 suites |
| `ProjectInertiaPagesTest` (edited) | `projects.store`'s Inertia-request test updated from the 409/`X-Inertia-Location` assertion to an ordinary `assertRedirect()` |
| `ProjectBladeRegressionTest` (edited) | The board-specific D1 Blade-markup test removed (superseded by the React board's own coverage); task-page, D4, and D6 Blade cases untouched |
| `board-moves.test.ts`, `board-announcements.test.ts` (new) | The pure `applyMove`/`locateTask`/`isNoopMove` transform and the announcement-string builders, exhaustively |
| `move-task-menu.test.tsx`, `task-card.test.tsx`, `quick-add-task.test.tsx`, `board.test.tsx`, `pages/projects/board.test.tsx` (new) | Menu keyboard behavior and payloads (opened by keyboard per the WP2 jsdom finding); the structural-memo comparator; quick-add open/close/validate/focus; the full optimistic→success/422/403/404/500/network/401/419 reconciliation matrix against the extended Inertia test double, single-flight, live region, focus restoration; the page's header and Settings-link gating |
| `resources/js/test/inertia.tsx` (extended) | `router.optimistic(cb).put/post/patch/delete(...)` recording (`optimisticSubmitted()`), with its own self-test; the double still never applies the transform or replays a rollback itself, by design (a test drives both, the way real Inertia's props swap would) |
| `tests/Browser/board-migration.spec.ts` (new) | Flows 1 (index→board with a persistent timer, Board↔Milestones stays Inertia), 5 (keyboard-only cross-column move, focus return, live-region text), 6 (read-only board for a plain member; open/comment/toggle still work), plus same-column up/down, and a phone-viewport Move-menu flow |
| `tests/Browser/time-migration.spec.ts`, `tests/Browser/projects-migration.spec.ts`, `tests/Browser/milestones-migration.spec.ts` (edited) | Updated for the Blade→React board flip, as detailed in X7 above and the stale "still Blade" comment in the milestones spec |
| `tests/Browser/wp1-blade-regressions.spec.ts` | Deleted (superseded; see X7) |

### Observations and non-goals, deliberately not changed

- **WP6 is untouched.** No `dnd-kit` package, no pointer/touch drag, no drag handle, no `DragOverlay`. The Move menu is the only mutation path, exactly as designed.
- **Task detail, `/tasks`, and tickets remain Blade.** Title links on the board are plain anchors; `TimerContextLink`'s `task` and `ticket` modes are untouched.
- **The server-side move algorithm is untouched.** WP5 changed only the HTTP response contract (redirect-back instead of JSON), never the locking/ordering/dense-position logic WP1 hardened and stress-tested; the existing concurrency suite (`ProjectMoveConcurrencyTest`) passes unmodified.
- **No new state library, no reducer, no board copy.** `Board` reads `columns` only from its props; the only local state is `pendingTaskId`, `quickAddColumnId`, and the two live-region strings, matching §8's architecture exactly.

---

## Amendment 6: WP5 Remediation (2026-09-22)

An independent review of the uncommitted WP5 diff (after it was committed as `feat: migrate project board to React`) found no BLOCKER and no HIGH finding, but two MEDIUM correctness gaps and one LOW defensive gap, all in the two files `board.tsx` and `quick-add-task.tsx` already own. This amendment records the remediation; WP5's architecture, `applyMove`, the `APPEND_TO_END` sentinel, `ProjectService` ordering/locking, authorization, the structural memo comparator, the Move menu, quick-add's product scope, navigation visit modes, and the Blade cleanup are all **unchanged**. No `dnd-kit`, no WP6 work. EPIC-011E stays **Planned**; WP5 remains the current work package.

**Gates (all green, 2026-09-22):** focused Vitest (`board.test.tsx`, `quick-add-task.test.tsx`) 26/26; full `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest 270/270 across 43 files, build), exit 0 — one full-suite run hit an unrelated pre-existing 5-second timeout in `milestone-form-dialog.test.tsx` (a file this remediation does not touch), confirmed non-reproducing in isolation and on a clean re-run of the full suite; full Pest unchanged (no backend file touched, so Pint was not re-run); `git diff --check` clean; the WP5 Playwright board spec and the full `./dev test:e2e` suite re-run unchanged. No PHP file is in this diff.

### Y1 — Reconciliation-reload sequencing (MEDIUM, Fix 1)

**The gap.** `board.tsx`'s failure path fired `router.reload({ only: ['columns'] })` as an unsequenced side effect, and `onFinish` released the single-flight guard as soon as the failing PUT itself settled — before that reload had returned. A user could then start and complete a second, successful move while the first move's stale reconciliation read was still in flight; if that read's response arrived after the second move's own response, it would silently overwrite the newer canonical board with older data.

**The fix.** The single-flight guard (`pendingRef`) is now held through the whole failure-plus-reconcile cycle, not just the initiating PUT: `reconcileAfterFailure()` sets a local `awaitingReconciliation` flag before calling `router.reload(...)`, and the guard is released by *that reload's own* `onFinish`, not the PUT's. A second move is therefore refused, by construction, for as long as an earlier move's reconciliation read is outstanding — the exact overlap the race needed can no longer occur. This is the "keep move input locked until the reconciliation reload completes" alternative the review offered as acceptable, chosen over a version-check race because Inertia 3.7.1's `reload()` applies its response to page props before any callback of ours runs, so a generation check could only ever *detect* a stale overwrite after the fact, never prevent it — sequencing is the only mechanism that is actually watertight against the installed adapter's timing. The tradeoff is a slightly longer busy window after a failure (one extra GET's round trip, not the whole UI — quick-add and navigation are unaffected); this was judged the smaller cost against silently losing a move.

A monotonic `moveGenerationRef` token was added alongside the guard as defense in depth: `onSuccess`, and the two settle points (the PUT's own `onFinish` and the reload's `onFinish`), only publish live-region text and focus restoration when their move is still the current one. Under the guard's sequencing this should never actually differ in practice, but it makes "a stale callback cannot publish a side effect for a move a newer one has superseded" an explicit, tested invariant rather than an accident of the guard's current shape, and it stays correct if a future caller (WP6) ever reaches these callbacks by a different path.

Regression coverage (`board.test.tsx`, describe blocks "reconciliation sequencing" and the four updated failure tests): a full interleaving test proves a second move is refused while the first move's reconciliation reload is outstanding, is allowed once that reload settles, and that a defensive re-invocation of the first move's already-consumed reload callback cannot steal focus back or otherwise publish for the superseded move; a second test proves the reload still happens and the guard still clears cleanly when no later move is ever attempted.

### Y2 — Quick-add synchronous duplicate-submit guard (MEDIUM, Fix 2)

**The gap.** `quick-add-task.tsx` relied solely on `disabled={form.processing}` to prevent a second submission; that attribute only takes effect after React commits the re-render `useForm`'s internal state update triggers, leaving a same-tick window (a fast double click, or Enter repeating) in which `submit()` could fire twice.

**The fix.** A synchronous `useRef` guard (`submittingRef`) claims the request before `form.post` is called and is released in `onFinish` — the one callback shared by every terminal outcome (success, a validation error that leaves the form open and editable, an HTTP/server error, and a network failure) — rather than only in `onSuccess`, so a validation failure does not strand the form unable to resubmit. Nothing about quick-add's create-confirmed (not optimistic) behavior, its manager-only scope, or its per-column server validation changed.

Regression coverage (`quick-add-task.test.tsx`, describe block "duplicate-submission guard"): two `fireEvent.submit` calls dispatched back to back in the same synchronous block (not `userEvent`, specifically to exercise the same-tick window the button's `disabled` attribute cannot cover) produce exactly one `form.post`; a further submit is accepted once the first request's `onFinish` has actually run, both after a validation error (form stays open) and after a success (form closes and reopens for a fresh add).

### Y3 — `requestMove` synchronous-throw hardening (LOW, Fix 3)

**The gap.** `pendingRef.current` was set before the `router.optimistic(...).put(...)` call chain. A synchronous throw from that chain (before Inertia ever started a visit — building the move URL, or the `router.optimistic`/`.put` calls themselves) would leave nothing to call `onFinish`, permanently stranding the single-flight guard.

**The fix.** The call chain is now wrapped in a narrow `try`/`catch`. On a synchronous throw the guard and pending UI state are cleared, focus returns to the moved card's Move button, and the normal accessible failure message (`"Couldn't save the move. Try again."`) is shown — handled as an ordinary failed move, not rethrown, consistent with every other failure path here resolving to the inline alert rather than surfacing a second, uncontrolled error path. The catch is deliberately narrow: a throw from inside the optimistic transform itself (`applyMove`, pure and exhaustively tested elsewhere) happens later, inside Inertia's own asynchronous visit handling, and is out of this catch's reach by design — this hardens only the synchronous invocation boundary the review identified, not a general error boundary around the move feature.

Regression coverage (`board.test.tsx`, describe block "defensive hardening"): forcing `router.optimistic` to throw synchronously proves no visit is issued, no reconciliation reload is scheduled, the guard clears, the inline failure alert appears, and a subsequent move is accepted normally.

### Non-blocking notes carried forward, not acted on

- **Optimistic overdue styling.** A card moved into or out of a Done column can briefly keep its prior server-computed `overdue` flag during the optimistic window, because `applyMove` relocates a task without recomputing that flag — intentional under the column-authoritative, server-computed status model (§15); the canonical partial-reload response corrects it. Not changed; React still never re-derives overdue.
- **Playwright Fortify rate-limit flake.** Unchanged assessment from Amendment 5: pre-existing shared-test-infrastructure behavior (the per-account login rate limiter under parallel load), not a WP5 defect, and no WP5 file is in `auth-migration.spec.ts`'s call path. This remediation touches no authentication or login behavior, so it carries no new evidence either way; left as test-infrastructure debt.

---

## Amendment 7: WP6 Results (2026-09-22)

WP6 (pointer/touch drag, layered over the WP5 board) is **implemented** on `epic-011e-projects-kanban`, uncommitted. `@dnd-kit/core` 6.3.1, `@dnd-kit/sortable` 10.0.0, and `@dnd-kit/utilities` 3.2.2 are installed with `--save-exact`, matching the WP0-proven versions exactly (`@dnd-kit/accessibility` 3.1.1 arrives transitively, as Amendment 3 anticipated). No other package was added. The Move menu remains fully intact and is the sole keyboard/assistive-technology path; every operation drag can perform is also reachable through it. EPIC-011E stays **Planned**; the status moves with the epic, not the work package.

**Gates (all green, 2026-09-22):** `npm run check` (Wayfinder, typecheck, ESLint including the new `no-restricted-imports` dnd-kit boundary, Prettier, Vitest 309/309 across 44 files, build) exit 0; full Pest unchanged at **797 passed** (no PHP file in this work package — WP6 changed only the client and the Playwright suite); the new `tests/Browser/board-drag.spec.ts` (14 tests): **14/14 passed, confirmed twice in a row**; the full Playwright suite: **34 of 37 passed**, all 12 WP6 tests among them green — the 3 non-passes are the pre-existing Fortify rate-limit flake (two, same symptoms Amendment 5 already documented) and one shared-development-database timer-count isolation symptom under this session's higher (7-worker, 37-test) parallel load, in `auth-migration.spec.ts` and `time-migration.spec.ts` respectively; none touch board or drag code. `git diff --check` clean; no root-owned generated files (`public/build` was rebuilt entirely under the host UID after a stale root-owned artifact from an earlier session was cleared).

### A real crash found and fixed, not just a plan deviation

The single most consequential finding this work package produced: §8/§9's originally planned drag-time board projection (`onDragOver` recording a preview, rendered live through `applyMove` so the board visibly reordered *during* the drag, mirroring the Move menu's own optimistic phase) was built, passed in isolation against a mocked dnd-kit (Vitest), and then **reproducibly crashed the board with React error #185 ("Maximum update depth exceeded") in a real browser**, on any drag that ended over a valid target. This was not caught by Vitest because the crash lives entirely in the interaction between real DOM mutation and dnd-kit's own internals, which the adapter-boundary mock (correctly, per its own design) does not model.

Root cause, traced through a Playwright trace's captured console/network events rather than guessed: live-projecting the board reorders the `columns` array during the drag, which React reconciles into a real DOM reparent (a card's node moving from one column's subtree to another's, or shifting position within one). `@dnd-kit/core`'s own active-node-rect tracking (`useRect`, used unconditionally by every `DndContext`) watches `document.body` with `new MutationObserver(handleMutations)` registered via `.observe(document.body, { childList: true, subtree: true })`, specifically so it can remeasure the dragged node when the DOM changes under it; remeasuring calls `setState`, which can trigger a render, which (with the projection live) can move the node again, which fires the observer again. Live-reordering the DOM during a drag is exactly the shape of body-wide `childList` mutation that mechanism exists to catch. WP0's throwaway spike measured this same pattern as passing, but with materially simpler card markup and no Move menu/live region/badges mounted alongside it; this board's real DOM was, empirically, enough to turn a self-terminating remeasure into a genuine loop.

**Fix:** the live projection was removed rather than chased further into dnd-kit's internals. `BoardDndContext` now renders the real `columns` prop unconditionally — never a projected substitute — for the whole lifetime of a drag; `onDragOver` and the `preview` state it drove are gone entirely. Visual feedback during the drag is the `DragOverlay` ghost alone (a `createPortal` mounted once per drag, not repeatedly); once a drop resolves, `onMove` (`requestMove`) drives its own already-proven optimistic transform exactly as the Move menu does, so the underlying board still reorders smoothly on drop, just not continuously through the drag. This is a smaller surface than the original plan, not a workaround grafted onto it: an entire local-state category (the preview descriptor and its clear-on-settle effect, which itself went through two failed iterations — a render-time "adjust state on prop change" pattern, then a plain `useEffect` — before the real cause was found to be the projection itself, not how it was cleared) was deleted rather than added to. Full reasoning is recorded in `board-dnd.tsx`'s own file header for anyone touching this file next.

### Where the live result differs from, or adds to, the plan

| # | Item | Outcome |
|---|---|---|
| Y1 | No drag-time board projection (see above) | §8/§9's `onDragOver`-driven live preview is not implemented; `resolveDragTarget` (below) is computed once, at drop time, from the real `columns` prop |
| Y2 | `board-moves.ts` gains `resolveDragTarget`, `columnDroppableId`, `findTask` | Pure, dnd-kit-free: turns a dnd-kit `over.id` (a task id, or a column-body id built by `columnDroppableId`) into the same `MoveDescriptor` `applyMove`/`requestMove` already use. Deliberately matches `@dnd-kit/sortable`'s own `arrayMove` utility's index arithmetic (the hovered task's own original array index, unadjusted) rather than a "shift-corrected, insert before" formula drafted first: that formula silently collapsed an adjacent forward drag (the single most common reorder gesture) into a no-op, caught by a real-browser Playwright run, not Vitest, and pinned there by a named regression test afterward |
| Y3 | `board-dnd.tsx` (new), `board-card-handle.tsx` (new) | The two files `@dnd-kit/*` imports are confined to, enforced everywhere else by a new ESLint `no-restricted-imports` rule (two `files` overrides: the restriction, then an exemption for exactly these two paths). `BoardDndContext` (top-level `DndContext`, pointer-only sensor, `closestCorners`, silenced announcements/instructions, `DragOverlay`), `SortableColumnBody` (`useDroppable` + `SortableContext` per column, so an empty column or the space below the last card stays a valid target), `SortableTaskCard` (the narrow wrapper `useSortable`'s per-frame churn is confined to; hands the still-fully-memoized `TaskCard` a `dragHandle` node built via `useMemo`, verified stable against the installed 6.3.1/10.0.0 source since dnd-kit itself keeps `listeners`/`setActivatorNodeRef` referentially stable across ordinary re-renders) |
| Y4 | `TaskCard` gains one new optional prop, `dragHandle?: ReactNode` | Compared by identity in `taskCardPropsAreEqual`, the same treatment as the existing `columns`/`onMove`. `undefined` for every read-only card and every pre-WP6 render, so the WP5 structural-memo contract is unchanged in that case; TaskCard itself still never imports or knows about dnd-kit |
| Y5 | Handle-only activation, exactly as designed | `TaskDragHandle` (`board-card-handle.tsx`): `aria-hidden="true"`, `tabIndex={-1}`, `touch-action: none` on the handle only, dnd-kit's `attributes` never spread anywhere in this adapter (no `role="button"`, no `tabIndex={0}`, no keyboard instruction). `PointerSensor` only, `activationConstraint: { distance: 6 }`, no `KeyboardSensor` configured anywhere |
| Y6 | dnd-kit's own accessibility footprint is real, and one collided with an unrelated existing test | Silencing `announcements` (empty strings) and `screenReaderInstructions.draggable` stops any *text* dnd-kit would announce, but `@dnd-kit/core`'s `Accessibility` component unconditionally renders its own hidden `role="status"` live-region element (`#DndLiveRegion-0`) whenever any `DndContext` mounts — there is no prop to suppress the element itself, only its content. This second `role="status"` region, present on the board for any manager now that dnd-kit is installed, made `projects-migration.spec.ts`'s existing `page.getByRole('status')` flash-message assertion ambiguous (a real, if narrow, WP6-caused regression, caught by the full Playwright suite, not the focused WP6 spec). Fixed by scoping that one assertion with `.filter({ hasText: ... })`, the same disambiguation `board-migration.spec.ts` already used for the board's own `aria-live="polite"` region. Every other existing `getByRole('status')` assertion in the suite runs on a page dnd-kit never mounts on (`/edit`, milestones, time, auth) and was unaffected |
| Y7 | `test/setup.ts` gains a `window.matchMedia` stub | jsdom does not implement `matchMedia`; every real browser this board ships to does (WP0 verified `prefers-reduced-motion` against real Chromium). Defaults to "no preference"; a test that needs to simulate a preference overrides it locally, the same pattern already used for `window.location` in `board.test.tsx` |
| Y8 | `resolveDragTarget`'s column-body branch is exactly the `APPEND_TO_END` sentinel's sibling, not a replacement for it | The Move menu's "Move to `<column>`" still sends `APPEND_TO_END` (unaffected, untouched); a drag ending on a column's own body computes the same "true tail" append by counting that column's own current tasks (minus the active one, if already there) — independently reviewed and already proven safe in Amendment 6's remediation review, reused here without change |

### Tests added or changed

| File | Purpose |
|---|---|
| `board-moves.ts`, `board-moves.test.ts` | `resolveDragTarget`/`columnDroppableId`/`findTask`, exhaustively: same-column forward/backward (including the adjacent-swap regression), cross-column, empty column, populated-column append, a task's own column body, hovering the dragged task's own placeholder, every null/invalid-target case |
| `board-card-handle.tsx`, `board-dnd.tsx` (new) | The two dnd-kit adapter files; see Y3, Y5, Y6 above |
| `board-dnd.test.tsx` (new) | Mocks `@dnd-kit/*` at the adapter boundary (real drag physics is Playwright's job): `BoardDndContext`'s configuration (no `DndContext` at all when `!enabled`, exactly one sensor, silenced announcements/instructions, the overlay's `dropAnimation` under normal and reduced-motion preference), its lifecycle (`onDragEnd` calling `onMove` with the resolved target and nothing else, no-op/unresolvable/busy all refusing without a request, cancel clearing the overlay state without a request, the board always rendering the real `columns` prop verbatim), and `SortableColumnBody`/`SortableTaskCard`'s wiring (the droppable id, no raw dnd-kit object ever reaching `TaskCard`, the `dragHandle` identity staying stable across an unrelated re-render and rebuilding only when `disabled` changes, the dimmed style while `isDragging`) |
| `task-card.tsx`, `task-card.test.tsx` (edited) | The `dragHandle` slot and its identity-based memo comparator; DOM tests proving no meaningful content sits inside an `aria-hidden` ancestor and the handle never joins the tab order |
| `board-column.tsx` (edited) | Branches on `canManage` between `SortableColumnBody`/`SortableTaskCard` and the unchanged plain WP5 rendering — never both, never a hook called conditionally |
| `board.tsx`, `board.test.tsx` (edited) | Wraps the board region in `BoardDndContext`; one new Vitest case (handle present only for a manager, absent for read-only) alongside the full, unmodified WP5/Amendment-6 suite, which still passes verbatim |
| `tests/Browser/board-drag.spec.ts` (new) | Real-browser pointer drag: same-column up/down (including the adjacent-swap case the regression above was found through), cross-column, empty column, populated-column append, an invalid drop (no request, board unchanged), single-flight during a slowed-down move (test-only response delay via `page.route`, not a product-code change), handle-only activation, Move-menu/title-link parity with dnd-kit mounted, a read-only member getting neither handle nor menu, a full successful reconciliation round trip; real-browser touch (swipe scrolls the card body, a CDP-simulated touch drag from the handle moves the task, on a touch-capable context wide enough to keep both the source and destination columns on screen at once — see Y9); horizontal autoscroll; reduced motion |
| `tests/Browser/projects-migration.spec.ts` (edited) | One assertion scoped with `.filter({ hasText: ... })` (Y6); nothing else in this file changed |
| `eslint.config.js` (edited) | The `no-restricted-imports` dnd-kit boundary and its exemption for the two adapter files |

### Y9: touch-viewport note

The touch flow initially failed, non-flakily, because Pixel 7's own 412px viewport does not fit both the source and destination columns (288px each) at once, and a CDP-dispatched touch coordinate beyond the visible viewport hits nothing — the same as it would on real hardware. The test now opens a touch-capable context at a wider viewport (800px) so the flow stays about real touch pointer events rather than off-screen scrolling, which the separate desktop autoscroll flow and the phone-viewport Move-menu flow (`board-migration.spec.ts`) already cover on their own. This is a test-authoring correction, not a product finding; real-device touch (iOS Safari, Android Chrome) remains a WP10 manual check per the original plan.

### Observations and non-goals, deliberately not changed

- **No `KeyboardSensor`, anywhere.** The Move menu is unchanged and remains the sole keyboard/assistive-technology movement path; every WP5 Vitest and Playwright case for it passes unmodified.
- **The server-side move algorithm is untouched.** No PHP file is in this work package; `ProjectService::moveTask` and its WP1 concurrency guarantees are exactly as Amendment 4 left them.
- **`APPEND_TO_END` is unchanged.** The Move menu still uses it; the drag path's column-body append is a separate, independently-correct computation (Y8).
- **No second mutation path.** `onMove` in `board-dnd.tsx` is always literally `requestMove` from `board.tsx`; there is no drag-specific fetch, reducer, or optimistic mechanism anywhere in this work package.

---

## Amendment 8: WP7 Results (2026-09-22)

WP7 (task detail) is **implemented** on `epic-011e-projects-kanban`, uncommitted. `views/projects/tasks/show.blade.php` is deleted; `ProjectTaskController::show` now returns `Inertia::render('projects/tasks/show', ...)` behind three new presenters (`ProjectTaskPresenter`, `TaskStatusPresenter`, `TaskTimeSummaryPresenter`). The task title link from the board and `TimerContextLink`'s `task` kind both flip to Inertia in this work package, completing §21's mixed-state matrix through the task-page row. No new runtime package, no state library; the task time panel uses the existing `TimerProvider`/`useTimers()` unchanged. EPIC-011E stays **Planned**; the status moves with the epic, not the work package.

**Gates (all green, 2026-09-22):** focused Pest (`ProjectTaskDetailInertiaTest.php` plus every edited Projects file) 100% green; full Pest, including `ProjectMoveConcurrencyTest.php`: **814 passed, 0 failed** on MariaDB, run twice (once isolating that file for a faster iteration loop, once as the full unfiltered suite; both green); Pint clean; full `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest **348/348** across 50 files, build) exit 0; `git diff --check` clean; `tests/Browser/task-detail-migration.spec.ts` (new, 4 flows): **4/4 passed** against a rebuilt container bundle, reconfirmed clean at the end of this amendment's validation pass (see below). The full `./dev test:e2e` suite passed **35 of 37** in its first full run (2 non-passes: the pre-existing Fortify/shared-database flakiness Amendments 5 and 7 already documented, in `auth-migration.spec.ts` and an unrelated `time-migration.spec.ts` manual-entry-edit timeout, neither touching project or task code). A later re-run at this session's own higher parallelism (8 workers) landed on a host already under load from the session's own concurrent activity (`uptime` load average 15.4 against 16 cores, 2.9 GiB swapped) and produced 9 failures spanning entirely unrelated specs (auth, board, milestones ×2, projects ×2, time) plus 2 of this work package's own; a follow-up run of every failing spec together at 2 workers, once load had settled, passed **17/17**, and the fixture-cleanup counts (`projects=1 tasks=0 time_entries=0`) were identical before and after the noisy run — confirming host contention, not a functional regression, and that fixtures still clean up correctly even when a run is failing. No root-owned generated files (the container's `public/build` was rebuilt under the host UID after each frontend change).

### A real, pre-existing defect found and fixed, not just a plan deviation

`ProjectTaskController::destroy()` returned `back()`, the same helper `store()` and `update()` use. For those two, `back()` is correct: the previous URL is the task's own page, and that page still exists after a create or an edit. For `destroy()` it is not: the previous URL is *also* the task's own page, and that page no longer exists once the task is gone — implicit route-model binding 404s reconstructing it, which Inertia's client then handles as a non-Inertia response with a hard fallback navigation to that 404. The net effect, invisible to every existing test because none of them asserted the redirect's actual destination (only `assertRedirect()`, with no target), is that deleting a task from the task page never reached the board at all. `tests/Browser/task-detail-migration.spec.ts`'s unreferenced-delete flow caught this directly, against the real browser, exactly as intended. **Fixed** to `redirect()->route('projects.board', $project)`, matching §11's own contract ("After success the server redirects to the board"). `ProjectTaskTest.php` and `ProjectDeletionGuardTest.php`'s delete assertions now pin the board explicitly so this cannot regress silently again.

### Where the live result differs from, or adds to, the plan

| # | Item | Outcome |
|---|---|---|
| Z1 | `destroy()`'s redirect target | See above — a genuine pre-existing bug, not a WP7 regression, fixed because WP7 is the work package that finally exercises the destination end-to-end |
| Z2 | Checklist toggle contract flip | `toggleChecklistItem` now returns `back()` (redirect-back) instead of `response()->json(['completed' => ...])`, exactly as §14/§29 direct for this work package. No flash message on a toggle — too frequent to announce; add and remove keep theirs. `ProjectIntegrityTest.php`'s idempotency test and `ProjectPinnedBehaviorTest.php`'s collaboration test both flip from asserting the JSON body to asserting the redirect and the resulting DB state |
| Z3 | `TaskStatusPresenter` added as its own small class | §15 describes `TaskStatusDto` as shared by the board, detail, and `/tasks`; only detail needs it in this work package (WP8 owns `/tasks`, and retrofitting the already-shipped board DTO was out of scope here), so it is a tiny reusable static method (`forTask()`) rather than logic inlined into `ProjectTaskPresenter`, so WP8 can adopt it without duplicating the column-first rule |
| Z4 | DevSeeder fixture ticket (`TKT-E2E1`) | Added per §21's own recommended resolution: `firstOrCreate` on a fixed ticket number, owned by the seeded operator account, idempotent across runs. `time-migration.spec.ts`'s "started from the embedded Blade tracker" flow moves onto it, because the project/task pages it used as its throwaway host stop existing as Blade destinations in this work package |
| Z5 | `assignee`/`toggleChecklist`/`comment` abilities are computed, not hard-coded `true` | `manage` is `Gate::allows('manage', $project)`, matching the structural task routes exactly (policy only, no route middleware, D1); `comment`/`toggleChecklist` are literally `true` with a comment explaining why (the `authorize('view', ...)` already run to reach the page makes them trivially true, same as the board's `manage`/`openSettings` split records its own reasoning inline) |

### Tests added or changed

| File | Purpose |
|---|---|
| `ProjectTaskPresenter.php`, `TaskStatusPresenter.php`, `TaskTimeSummaryPresenter.php` (new) | The task detail, status, and D6 time-summary DTOs (§5, §11, §15) |
| `ProjectTaskController.php` (edited) | `show()` → `Inertia::render`; `destroy()` redirect fix (Z1); `toggleChecklistItem()` contract flip (Z2) |
| `ProjectTaskDetailInertiaTest.php` (new) | Page contract and DTO minimality; D1 `abilities.manage`/options presence per actor including `admin_only`; I4/I8 assignee and milestone option scoping (departed assignee kept, never folded into the pool; foreign-project milestones excluded); checklist and comment DTO shape, hostile text stored and served raw; D6 scope/privacy per actor (own, project-manager-grants-nothing, `time.view_all`, `time.view_all`-without-`time.log`, neither) with no billing/invoice/description leakage |
| `ProjectBladeRegressionTest.php` (edited) | The two task-page-specific Blade-HTML assertions (D1 form visibility, D6 panel content) retired in favor of the DTO-level tests above, which pin the same behavior against the page React now owns; the D4 and ticket-embed assertions in this file are untouched |
| `ProjectIntegrityTest.php`, `ProjectPinnedBehaviorTest.php` (edited) | Checklist toggle assertions flipped from the JSON body to the redirect contract (Z2) |
| `ProjectTaskTest.php`, `ProjectDeletionGuardTest.php` (edited) | Delete assertions now pin `projects.board` as the exact redirect target (Z1) |
| `ProjectQueryBudgetTest.php` (edited) | New case: comment and checklist-item counts on the task page don't grow the query count (3 vs 30 rows) |
| React (new): `pages/projects/tasks/show.tsx`; `components/projects/task-edit-form.tsx`, `task-checklist.tsx`, `task-comments.tsx`, `task-time-panel.tsx`, `task-delete-button.tsx`, `task-status-badge.tsx`, each with its own `.test.tsx` | The page and its focused, independently-tested pieces (§19's "no generic form engine" honored: one `useForm` per concern, no shared form abstraction) |
| `types/projects.ts`, `types/time.ts` (edited) | `TaskDetail`, `TaskStatusDto`, `MilestoneRef`, `UserRef`, `TaskEditOptions`, `TaskChecklistItemData`, `TaskCommentData`, `TaskTimeSummary` |
| `task-card.tsx`/`.test.tsx` (edited) | Title link flips from a plain anchor to `<Link prefetch>` (§7, §21) |
| `timer-context-link.tsx`/`.test.tsx` (edited) | `task` flips to `'inertia'` in `contextLinkModes` (§21) |
| `DevSeeder.php` (edited) | The idempotent fixture ticket (Z4) |
| `tests/Browser/task-detail-migration.spec.ts` (new) | Board↔task Inertia navigation with a persistent timer surviving it; manager field edit plus I4 milestone assign/clear; manager checklist-add, member toggle, member cannot author/remove, reconciled across two browser contexts; unreferenced-task delete (caught Z1) and D4-blocked delete with the dialog error |
| `tests/Browser/time-migration.spec.ts` (edited) | Embedded-tracker flow re-pointed to the fixture ticket (Z4) instead of a throwaway project/task |
| `tests/Browser/board-migration.spec.ts` (edited) | One comment corrected (the task link is now an Inertia navigation, not a document load); no assertion changed, and none needed to change |
| `views/projects/tasks/show.blade.php` | Deleted, along with its inline `fetch` + `location.reload()` checklist script (§22) |

### Observations and non-goals, deliberately not changed

- **`update()` keeps `back()`.** Its previous URL — the task's own page, from its last GET — still exists after an edit, so returning there is correct; only `destroy()` had the defect.
- **The manager edit form does not resync its own `useForm` state from a fresh `task` prop after a save.** The read-only panels elsewhere on the page do (they read props directly). This is the same behavior the project edit page's `DetailsSection` already has today (§6) — not a new pattern, and not treated as a defect here.
- **No `time.view_own` enforcement introduced.** D6 remains exactly `time.log` (own) / `time.view_all` (all), the locked decision; `time.view_own` stays unused permission-model debt for WP9 to record, not wire in.
- **The Time page's own manual-entry and `ContextSelector` flows are untouched.** The task panel starts a timer directly against `task_id`, bypassing the context-selection UI entirely, which is why its own Playwright coverage needed no changes to that page.
- **No archiving, no second timer implementation, no new drag/library/state dependency.** All out of scope per §19/§27 and confirmed unnecessary here.

---

## Amendment 9: WP8 Results (2026-09-22)

WP8 (the unified `/tasks` list) is **implemented** on `epic-011e-projects-kanban`, uncommitted. `views/tasks/index.blade.php` is deleted, along with the now-empty `resources/views/tasks` and `resources/views/projects` directory trees (every file under both was already deleted by earlier work packages; WP8 removed the last file and the trees themselves). `TaskController::index` now returns `Inertia::render('tasks/index', ...)` behind a new `TaskListPresenter`, reusing `TaskStatusPresenter` (WP7) unchanged. The `Tasks` navigation item flips to `visit: 'inertia'`, completing §21's mixed-state matrix: every project and task page is now React. No new runtime package, no state library, no new mutation route; standalone tasks keep exactly their WP1 capability (create and list only).

**Gates (all green, 2026-09-22):** focused Pest (`TaskListInertiaTest.php`, `ProjectVisibilityTest.php`'s edited `/tasks` cases, `NavigationBuilderTest.php`) green; full Pest: **822 passed, 0 failed** (up from 814 after WP7), run on MariaDB in the container; Pint clean over the whole repository; full `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest **400/400** across 58 files, build) exit 0; `git diff --check` clean; the new `tests/Browser/tasks-migration.spec.ts` run in isolation: **5/5 passed, confirmed twice in a row**; no root-owned generated files (a root-owned `test-results/.last-run.json` left over from an earlier session was cleared before this work package's own Playwright runs).

Two full `./dev test:e2e` runs (8 workers, 46 tests) repeated the exact host-contention pattern Amendments 7 and 8 already documented: the first produced 9 non-passes spanning unrelated specs (auth, board-drag ×2, board, inertia-coexistence, milestones, projects, task-detail) plus this work package's own nav test; a follow-up run of that exact failing set together at 2 workers passed **8/9** (the ninth was a genuine, if narrow, finding of this work package's own making — see below). The second full run, after that finding was fixed, again produced 7 non-passes spanning unrelated specs plus this work package's nav test; re-run together at 2 workers once load had settled, all **7/7 passed**, this work package's test among them. No file in this work package's diff is in the call path of any of the unrelated non-passes. **Genuine finding (fixed):** the full-suite run's own repeated fixture-count warning ("the suite may have leaked fixtures") was traced to a real cause worth recording — see below, not merely asserted away as noise.

### A real regression found and fixed before it ever reached a passing state

Restricting the controller's eager-loaded columns to exactly what each presenter renders (`ticket:id,ticket_number`) silently broke ticket-row link visibility: `TicketPolicy::view`'s owner check (`$ticket->user_id === $user->id`) compares against an attribute the trimmed projection never loaded, so it read as `null` and denied every ticket owner their own link. Caught immediately by `ProjectVisibilityTest.php`'s pre-existing "renders no ticket link that TicketPolicy would deny" case (which this work package converted from a Blade `assertSee` assertion to a DTO assertion, but did not otherwise rewrite) — not a new test written to find it, the existing WP1 coverage did its job. Fixed by adding `user_id` to the ticket projection (loaded for the authorization check, never rendered to the page). This is the general hazard column-limited eager loading introduces: a presenter's own field list is not the only thing a column-restricted relation needs to satisfy.

### A test-fixture finding: a fixed literal title collides with a leaked prior run

The first full `./dev test:e2e` run's own "the suite may have leaked fixtures" warning was traced to a real, if narrow, cause rather than dismissed: an earlier interactive Playwright invocation of this work package's own new spec, run under this session's heavy concurrent activity, left one project (`E2E WP8 nav project`, with its one task and one stopped time entry) undeleted — the fixture teardown's own HTTP calls are as vulnerable to host contention as anything else in a full run, matching the class of risk Amendment 7 first identified. A later run of the same spec then created a second project with the **identical literal title**, and `tasks-migration.spec.ts`'s own `getByRole('link', { name: 'E2E WP8 nav task' })` locator — scoped to text, as every comparable locator elsewhere in this suite is — resolved to two elements and failed with a Playwright strict-mode violation, not a silent pass. The leaked project and its one (already-stopped) time entry were removed through the application's own service layer (`ProjectService::deleteProject`, the same authority `DELETE /projects/{id}` calls); the standalone-task title already carries a `Date.now()` suffix specifically because standalone rows can never be deleted this way (D3, AA10), and this finding is the same hazard class arriving from the other direction — a project fixture that *can* be deleted but occasionally, under contention, isn't. No other spec in this suite currently guards against it either; changing that suite-wide convention is outside WP8's scope and is noted here for whoever next investigates full-suite flakiness, not fixed by adding timestamp suffixes to every fixture title in every spec.

### Where the live result differs from, or adds to, the plan

| # | Item | Outcome |
|---|---|---|
| AA1 | `TaskListPresenter` (new) | One `row()` method building the whole `TaskRow` DTO from a kind switch, plus two private per-kind context builders. Reuses `TaskStatusPresenter::forTask()` (WP7) and `ProjectTaskPresenter::userRef()` (WP7) rather than duplicating either |
| AA2 | Flat `TaskRow` shape, not a TypeScript union type | §5's own DTO design for `/tasks` is already a single shape with a kind-scoped `context` object (`{kind, label, url}`) plus a top-level `url`; every field in it is genuinely common to all three kinds (id, title, priority, status, dueDate, overdue, assignee), so this work package implements exactly that rather than introducing a `ProjectTaskListItem \| StandaloneTaskListItem \| TicketTaskListItem` union the epic's own architecture does not call for. No fake values result: a standalone row's `context.url` and `url` are always `null` (D3, no destination exists), a ticket row's `url` is always `null` (its own "page" is `context.url`, the ticket itself), and status is computed once by the shared kind-aware presenter, never re-derived per kind in React |
| AA3 | `view` query parameter: clamped, not validated-and-rejected | §17 describes `/tasks?view=` as "validated as an enum"; a `$request->validate(['view' => 'in:mine,org'])` was drafted and measured against the real stack first (a throwaway Pest probe, discarded after use): a `ValidationException` on a plain GET redirects back to `url()->previous()` (the application root, since a fresh request has no referer), and answers a **409** instead when sent with `X-Inertia: true` — neither is a sane response to a mistyped navigation-tab query string. Implemented instead as `$request->query('view') === 'org' ? 'org' : 'mine'`: a closed two-value enum that degrades to the narrower, always-safe view rather than erroring, pinned by `TaskListInertiaTest.php`'s "treats an unrecognized view value as mine" case |
| AA4 | `canViewOrg` prop (new, not in §5's literal listing) | The Blade page hid the org tab whenever `companyIds` was empty (a `tasks.view_org` holder in no company-linked organization gains nothing from it); the React page needs the equivalent server-computed boolean to decide the same thing, since React must not infer tab visibility from whether any row happens to be present. `canViewOrg = ! empty($companyIds)`, identical to the Blade condition it replaces |
| AA5 | `createOptions` prop (new, not in §5's literal listing) | §15's vocabulary rule ("the server is the source of truth… forms receive labelled `{value, label}` options as props") already governs the task-detail edit form (WP7's `options.priorities`); this work package's standalone create form gets the same treatment (`priorities`, `statuses`) rather than hard-coding labels in `create-task-form.tsx`, so `Task::PRIORITIES`/`Task::STATUSES` stay the one place those lists are defined |
| AA6 | Standalone create form starts open when it arrives with a validation error | X5 (Blade quick-add/milestone forms swallowing validation errors) was fixed for the board and milestones in WP4/WP5; this work package's own create form is new, not fixed, but the same defect shape was caught by its own Vitest suite before being shipped: `useState(() => form.hasErrors)` rather than a hard-coded `false`, so a failed submission's error is never hidden behind an unopened "+ New task" disclosure |
| AA7 | No kind-specific row components | `ProjectTaskRow`/`StandaloneTaskRow`/`TicketTaskRow` were considered per the epic's own "only if the actual differences justify it" guidance; the only markup that differs by kind is the title cell (linked only for a project task) and the context cell (`TaskContextLink`, already one component keyed on `context.kind`), so a single `TaskListRow` plus a small `TaskTitleCell` covers it without speculative per-kind files |
| AA8 | Ticket-derived rows: Pest/Vitest only, not Playwright | Characterized in WP1 (§3.1) and unchanged here: nothing in this application's own code creates a ticket-derived task, only test factories do. `tests/Browser/tasks-migration.spec.ts` says so in its own header rather than fabricating a database backdoor to reach that row kind in a real browser |
| AA9 | D2's company-linked-but-not-a-member scenario: Pest only, not Playwright | Every locked-behavior browser spec so far in this suite (board, milestones, task detail) has left D2's company/organization scenario to Pest, because reproducing it needs an `Organization`/`CrmCompany` fixture no browser spec in this epic has ever built. This work package follows the same line rather than being the first to add that infrastructure; D2 remains exhaustively pinned server-side (`ProjectVisibilityTest.php`, 10 cases covering exactly this). The browser suite instead proves the plainer fact unique to a real session: a task assigned to one person is simply absent from someone else's list |
| AA10 | Standalone-task Playwright fixture: accepted, disclosed test debt | D3 (locked) forbids adding a standalone-task delete route, so the one standalone row `tasks-migration.spec.ts` creates cannot be removed through the application afterward. Its title carries a `Date.now()` suffix so repeated suite runs never collide on identical text (which would otherwise trip a Playwright strict-mode match against an earlier run's own undeletable leftover — caught by running the new spec twice before considering it done). This is the same trade-off already recorded for the WP7 `DevSeeder` fixture ticket (§21, Z4), disclosed in the spec's own file header rather than silently accumulating rows without comment |

### Tests added or changed

| File | Purpose |
|---|---|
| `TaskListPresenter.php` (new) | The unified row DTO (AA1, AA2) |
| `TaskController.php` (edited) | `index()` → `Inertia::render`; kind-aware row mapping via `$tasks->through(...)`; `canViewOrg`/`createOptions` props (AA3–AA5); `store()` unchanged (D3) |
| `TaskListInertiaTest.php` (new) | Page contract and component name; DTO minimality (no email/description/raw-model/unrelated-id leakage); kind-aware status per kind (project column-first, standalone and ticket raw-status); `context.kind` per actual task kind; labelled vocabulary shape; the clamped `view` parameter |
| `ProjectVisibilityTest.php` (edited) | The six pre-existing `/tasks` org-tab and link tests converted from `viewData('tasks')`/`assertSee` (Blade) to DTO-array assertions against `viewData('page')['props']`, behavior otherwise unchanged; one test's assertion tightened to also check `canViewOrg` |
| `NavigationBuilderTest.php` (edited) | `tasks` item's `visit` assertion flipped from `'document'` to `'inertia'` |
| React (new): `pages/tasks/index.tsx`; `components/tasks/task-list-row.tsx`, `task-title-cell.tsx`, `task-context-link.tsx`, `create-task-form.tsx`, each with its own `.test.tsx` | The page and its focused, independently-tested pieces; reuses `PriorityBadge`, `TaskStatusBadge`, `Pagination`, `PageHeader`, `NativeSelect`, `Input`, `Label`, `Textarea`, `FormFieldError` from WP2/WP5/WP7 rather than duplicating any of them |
| `types/tasks.ts` (new) | `TaskKind`, `TaskContext`, `TaskRow`, `TaskCreateOptions` |
| `app/Shared/Navigation/NavigationBuilder.php` (edited) | `tasks` item's `visit` argument flipped to `'inertia'` (§21: completes the mixed-state matrix) |
| `tests/Browser/tasks-migration.spec.ts` (new) | Inertia navigation to and from a project task page with a persistent timer surviving both directions; mixed-kind rendering (linked project row, unlinked standalone row) and the absence of any edit/delete/complete control on a standalone row; cross-account visibility (a second browser context, not a re-sign-in on the shared page — Y-pattern already used by `task-detail-migration.spec.ts`); standalone-create validation round trip; phone-viewport usability |

### Observations and non-goals, deliberately not changed

- **No project-task structural mutation from `/tasks`.** No inline edit, complete toggle, drag, or milestone/assignee editing was added; the title link is the page's only route into a project task, landing on the WP7 detail page where those controls already live.
- **No new task-kind taxonomy, tabs-for-styling, Kanban, or data-grid framework.** The existing `mine`/`org` views are links, exactly as the Blade tabs were; no new filter was invented.
- **`ProjectPolicy` and `TicketPolicy` are unchanged.** D2 continues to be enforced by intersecting with `Project::visibleTo()` (WP1) and `Gate::allows('view', $ticket)`, both already proven equal to their policies; this work package added no new visibility rule of its own.
- **Timer context for a standalone task is untouched.** `AccessibleTimeContext`/`contextOptions` (WP1) already exclude a standalone task's timer-context `url`, independent of this page; `/tasks` and the timer bar's context link are separate concerns that happen to share no code that needed touching here.
- **`time.view_own` remains unenforced permission-model debt**, unchanged from every prior work package's note.

---

## Amendment 10: WP9 Results (2026-09-22)

WP9 (documentation reconciliation and test consolidation) is **implemented** on `epic-011e-projects-kanban`, uncommitted. A read-only audit preceding this work package established that the code-level cleanup §22 originally assigned to WP9 had already happened incrementally in WP3–WP8: `resources/views/projects/` and `resources/views/tasks/` were already gone, no HTML5 drag/drop or stale `view('projects.`/`view('tasks.` reference existed, and dnd-kit remained correctly isolated. WP9 therefore touched **no runtime application file**: no route, controller, service, model, migration, or dependency changed. Its scope was documentation and one test consolidation.

### Documentation reconciliation

| Document | Change |
|---|---|
| `docs/epics/EPIC-011-react-frontend-migration.md` | Phase E: added a status line ("Implemented... WP10 outstanding"); the six Phase-E rows in the Blade/Inline JavaScript Migration Inventory marked `migrated (deleted; EPIC-011E WPn)`; the Route/View Coverage Matrix's Phase E row rewritten to state none of those areas are Blade-rendered any more |
| `docs/architecture/adr/ADR-007-inertia-react-frontend.md` | New "Kanban board interaction layer (EPIC-011E)" subsection: dnd-kit as pointer/touch enhancement only, pinned exact versions, Move menu as the canonical path, no `KeyboardSensor`, drag and menu share one server move path |
| `docs/epics/EPIC-010B-tenant-scoping.md` | Tenant-Scoping Matrix `Project` row footnoted; new note below the table explaining D2 supersedes "linked company" as a visibility grant, with the A5 defect history preserved |
| `docs/architecture/adr/ADR-005-organization-multitenancy.md` | Decision paragraph's ambiguous "project/company membership" phrase replaced with a direct current-state statement: company link is visibility metadata only |
| `docs/architecture/rbac-design.md` | Same clarification added inline to the Data Scoping section; new "Known gap — `time.view_own`" note added after the permission catalogue |
| `docs/architecture/database-schema.md` | Same D2 clarification appended to the existing data-scoping callout |
| `docs/epics/EPIC-005-projects.md` | STORY-005-02 gets a current-state note pointing at D1 (manager-only structural mutation); the original user story text is untouched |
| `docs/epics/README.md` | EPIC-011E status: `Planned` → `Implemented` |
| `docs/epics/EPIC-011E-projects-kanban.md` (this document) | Top status line: `Planned` → `Implemented (WP0–WP9 complete; WP10 outstanding)`; WP9's own §29 entry rewritten to describe what actually happened instead of the original pre-audit plan |

No historical amendment or user story was rewritten; every change is either a direct edit to a still-current architecture statement or a dated supersession note next to the original text.

### Test consolidation: `ProjectBladeRegressionTest.php`

Its three assertions were each resolved individually, not dropped as a block:

| Old assertion | Disposition | Replacement |
|---|---|---|
| "shows the recorded-time error on the task page" (`assertSee` against a followed redirect) | Superseded, removed | `ProjectDeletionGuardTest.php` → "blocks deleting a task that has time" (`assertSessionHasErrors(['delete' => TASK_TIME_MESSAGE])`), plus that file's adjacent race/FK-backstop/billed-entry cases the old test never covered |
| "still renders every project page for an administrator with tasks present" (loop of `assertOk()` over all 7 routes) | Superseded, removed | One `assertInertia(...)->component(...)` case per route, already present and stronger (proves the actual component, not just HTTP 200): `ProjectInertiaPagesTest.php` (index, create, edit), `ProjectBoardInertiaTest.php` (board), `ProjectMilestoneInertiaTest.php` (milestones), `ProjectTaskDetailInertiaTest.php` (task detail), `TaskListInertiaTest.php` (`/tasks`) |
| "leaves the ticket time panel untouched" | Retained, relocated | Moved verbatim (actor construction updated to the plain `User::factory()->create()->assignRole('user')` pattern `tests/Feature/Tickets/*` already uses) to `tests/Feature/Tickets/TicketEmbeddedTimeTrackerTest.php`, with a docblock explaining why a Ticket-only regression lives outside `Projects/` |

`ProjectBladeRegressionTest.php` is deleted; nothing in it lacked a replacement.

### Validation

Focused: the new `TicketEmbeddedTimeTrackerTest.php` and `ProjectDeletionGuardTest.php`, plus every Inertia page-contract file named above — 107 tests, 727 assertions, all green. Full Pest: **820 passed, 0 failed** (822 from WP8, net −2: −3 for the deleted file, +1 for the relocated test). Pint clean. Full `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest **374/374** across 54 files, build) exit 0. `git diff --check` clean. No root-owned generated file.

Full `./dev test:e2e`: 44 of 46 passed on the first run. Two non-passes, both pre-existing and unrelated to any file this work package touched:
- `milestones-migration.spec.ts` "a plain project member sees milestones read-only..." failed on a Fortify rate-limit exhaustion (`sign-in.ts`'s own retry loop hit its cap); re-run alone at 2 workers, **passed**. Host contention, matching the exact class Amendments 7–9 documented.
- `auth-migration.spec.ts` "React login reports invalid credentials and logout returns to the auth shell" failed both in the full run and re-run alone at 1 worker — the same spec Amendment 8 already named as pre-existing "Fortify/shared-database flakiness" independent of project/task code. This test does not use the shared `signIn()` retry helper the rest of the suite relies on, so repeated validation runs against the same seeded email during this session's own testing plausibly exhausted its throttle window. Left as-is: fixing Fortify rate-limit test infrastructure is explicitly out of this work package's scope.

Fixture count drifted from `tasks=7` to `tasks=8` across the full run, the same "leaked fixture under host contention" class Amendment 9 traced and documented; this work package's diff touches no Playwright spec or fixture code, so it is not a new instance of that hazard.

### Observations and non-goals, deliberately not changed

- **No product behavior changed.** Every edit in this work package is to a Markdown file plus one test file move; `git diff` against `app/`, `routes/`, `database/migrations/`, and `package.json` is empty.
- **`time.view_own` is recorded, not implemented.** The new rbac-design.md note describes the gap; no permission, policy, or controller changed.
- **Tickets and every other still-Blade module are untouched.** `tickets/show.blade.php`, `components/time-tracker.blade.php`, `timer-overlay.js`, and every Admin/Billing/CMS/CRM/Operator/Organization Blade view remain exactly as WP8 left them.

---

## Amendment 11: WP10 Results and Verification Closeout (2026-09-22 to 2026-09-23)

WP10 (hardening and verification) was executed on `epic-011e-projects-kanban` against the committed WP0–WP9 state (`c6eadd4`, clean tree). It is a verification pass, not a feature work package: no route, controller, service, model, policy, migration, presenter, or dependency changed. The only production change is three small accessibility fixes in one React component (`task-checklist.tsx`), each covered by a new Vitest regression; the only test change is a latent race fixed in `board-drag.spec.ts`.

**Lifecycle outcome: EPIC-011E is `Verified` (2026-09-23).** Every automated gate is green and every acceptance contract traced in this pass is supported. The agent-run portion of WP10 could not perform the screen-reader or real-device checks that this work package names, because neither is possible in the container environment. The owner subsequently ran a real **NVDA smoke walkthrough on Windows** and accepted it, together with the browser/accessibility-tree/keyboard automation below, as sufficient verification for this stage; device-matrix and exhaustive assistive-technology testing are **deliberately deferred** to final platform-level QA. See "Manual accessibility verification" below for exactly what was and was not done.

### Gates (2026-09-22, after the fixes below; re-confirmed at closeout 2026-09-23)

| Gate | Result |
|---|---|
| Pest (MariaDB, container PHP 8.3) | **820 passed, 3257 assertions**, 0 failed |
| `npm run check` (Wayfinder, typecheck, ESLint, Prettier, Vitest, build) | exit 0; **Vitest 378/378 across 54 files** (was 374; +4 from this pass) |
| Pint | clean over the whole repository |
| `git diff --check` | clean |
| Build isolation | `@dnd-kit` appears in **exactly one** built chunk (`board-*.js`); versions pinned exactly (`6.3.1`/`10.0.0`/`3.2.2`, no caret) |
| Playwright, full suite, 2 workers | **46 of 46 passed** |
| Playwright, full suite, default (8) workers | 44 of 46; both non-passes in `auth-migration.spec.ts`, Fortify login throttling — **4/4 pass in isolation** once the rate-limit window clears |

### Defects found and fixed

All three product defects are in `task-checklist.tsx` and share one root cause; all were invisible to the existing Vitest suite and surfaced only in a real browser.

| # | Severity | Defect | Fix |
|---|---|---|---|
| V1 | Minor (WCAG 3.3.2 / 4.1.2 A) | The checklist "Add an item…" field had **no accessible name** — a placeholder only, no `<label>` and no `aria-label`. Every other text input in this epic's pages has one | `<Label htmlFor="checklist-add-title" className="sr-only">Add a checklist item</Label>`, matching the existing `task-comments.tsx` and `quick-add-task.tsx` pattern |
| V2 | Minor (focus management) | **Toggling a checklist item stranded focus on `<body>`.** The checkbox is `disabled` while its own request is in flight, which blurs it in every real browser; nothing put focus back. A keyboard user had to re-Tab after every toggle | Focus restored to the toggled checkbox once it is re-enabled |
| V3 | Minor (focus management) | **Adding a checklist item also stranded focus on `<body>`** — despite an existing `document.getElementById('checklist-add-title')?.focus()` in the add request's `onFinish`. That call was a silent no-op: React had not yet committed `setAdding(false)`, so the field was **still `disabled`** at the moment `.focus()` ran, and a disabled element cannot take focus | Same mechanism as V2 |

**Shared fix.** A `refocusIdRef` records which control should regain focus, and one `useEffect` (running after every commit) restores it as soon as that element exists and is no longer disabled. Restoring from a request's own `onFinish` is structurally too early; the effect runs after the re-enable commit. `removeItem` — which already worked, because it focuses a *different*, non-disabled element — was routed through the same mechanism so a neighbour that happens to be mid-request is not skipped either. No API, contract, or optimistic-toggle behavior changed.

Verified afterwards in a real browser: add → focus on `#checklist-add-title`; toggle → focus retained on the toggled checkbox; remove → focus on the neighbouring item.

**Test defect (V4, browser-suite race).** Three `board-drag.spec.ts` tests asserted a card's new position and then called `page.reload()`. The assertion is satisfied by the **optimistic** transform, before the move PUT returns, so the reload aborted a still-pending request and the move was legitimately lost — a test race, not a product fault (the server is authoritative by design). It passed whenever the request won the race and failed under full-suite contention; it was the `board-drag` non-pass in this session's first full run. Fixed with `expectMoveSettled()`, which waits for `aria-busy="false"` on the board region — the single-flight guard's own signal, cleared in the move's `onFinish`, i.e. once the authoritative response has landed. `board-migration.spec.ts`'s equivalent reload test was already safe: it waits on the live-region announcement, which is published in `onSuccess`.

### Candidate findings investigated and disproved

Recorded because each looked like a defect under a first measurement and would otherwise be re-reported by the next reviewer:

| Candidate | Why it was not a defect |
|---|---|
| "Projects index cards show no focus ring" | The card carries `transition-shadow` (150 ms). Reading computed style immediately after a real `Tab` caught the ring at **0.066 px / 3 % opacity** — the first frame. Re-measured after the transition settles: `rgb(79, 70, 229) 0 0 0 2px`, a full 2 px ring. The `<a>` itself carries `outline-none` with a stretched `::after` on purpose; the ring belongs to the card, which is the visible target |
| "Dark mode has 1.08:1 body contrast" | The probe's luminance parser did not understand `oklch()`. Actual values are `oklch(0.13 …)` background against `oklch(0.985 …)` text — a correct, high-contrast dark theme |
| "Focus is lost after submitting a comment" | Measured before `onSuccess` ran. With a settle wait, focus returns correctly to `textarea#task-comment-body` |
| "Move up announces the wrong position" | A stale live-region read: the previous announcement also contained the task title, so the wait matched immediately. Waiting for the text to *change* gives the correct sequence (down → "position 2 of 3", up → "position 1 of 3") |

### What was verified, and how

**Authorization (real browser, forged requests as a plain project member and cross-project as an administrator).** Every probe refused server-side, never by a React ability flag: forged move `403`, forged task delete `403`, forged `projects.members.sync` `403`; project A's task on project B's move route `404` and on B's show route `404`; project B's real column id on A's move endpoint `422` and on A's task-create endpoint `422`; unknown milestone `422`; non-member assignee `422`; foreign checklist item `404`; negative position `422`. A plain member's board renders zero Add-task buttons, zero Move buttons, no Settings link, no edit form, no delete button and no checklist authoring — but keeps the comment box (D1 exactly as locked).

**Privacy.** All seven presenters read end to end. No email, OAuth/provider field, password/security data, billing or invoice flag, time-entry description, or raw Eloquent serialization reaches any page, at any nesting depth. `memberCandidates()` is the only carrier of emails and is gated by `ProjectPolicy::manageMembers` (`projects.admin`) at both call sites, with the prop **absent** rather than empty otherwise — confirmed in the browser. `TaskTimeSummaryPresenter` returns `null` without `time.log`/`time.view_all`, `own` scope otherwise, and adds `userName` only under `time.view_all`; a project member saw no other user's name on the task page.

**Board state architecture.** `board.tsx` holds no copy of `columns`; its only state is `pendingTaskId`, `quickAddColumnId` and two live-region strings. The Move menu and dnd-kit both call the identical `requestMove` (`onMove={requestMove}` in both places), so single-flight, generation guarding, reconciliation and failure handling cover drag and menu equally. No move queue, no second board store, no stale-callback publication, no duplicated server ordering logic (the client clamp in `applyMove` is presentation-only; the server clamps authoritatively under the column lock).

**dnd-kit boundary.** Imported by exactly two files, enforced by `no-restricted-imports`; no `KeyboardSensor` anywhere; one `PointerSensor` with a 6 px activation distance; the handle is `aria-hidden="true"` with `tabIndex={-1}` and dnd-kit's `attributes` never spread. A 45-press Tab sweep across the board never entered an `aria-hidden` subtree.

**Move-menu parity (keyboard only, real browser).** Every operation drag can perform is reachable from the menu, each with a correct announcement and focus returning to the moved card's own Move button: same-column down (`position 2 of 3`), same-column up (`position 1 of 3`), cross-column into an empty column (`1 of 1`), append to a populated column (`2 of 2`), the Done column (`… to Done (done), position 1 of 1`), and end-of-column no-ops correctly `aria-disabled`. Moves persisted across a full reload.

**Coexistence and the timer.** Task detail → Board → Milestones → `/tasks` → Projects all preserved an injected `window` value, proving four genuine Inertia visits with no layout remount; Projects → Tickets did not, proving the intended document visit. A timer started from the task page stayed present across all four Inertia visits, reconstructed as the Blade tracker on Tickets (**exactly one** Stop control — no duplicate surface), and reconstructed again on return to React. Browser back/forward is correct across migrated pages and across the React↔Blade boundary.

**Responsive (390 px) and theme.** No document-level horizontal overflow on any of the six pages; the board region is the only horizontal scroller (1504/358 px, intentional); the Move menu performs a complete move at phone width; every button is ≥ 24 px; the milestone dialog fits (358×487 in 390×844). Dark mode renders correct semantic tokens on all pages, dialogs, and disabled menu items; no inline literal colours were found on any page.

**Dialogs and focus.** `role="dialog"` with `aria-labelledby` and `aria-describedby`; focus lands inside on open; 8 consecutive Tabs stayed trapped inside; focus returns to the correct opener after a successful save, after Escape, and after Cancel — never to `<body>`. Note: Radix renders no `aria-modal` attribute here, but everything outside the dialog is `aria-hidden="true"` (including `main`), which confines assistive technology equivalently.

**Query and frontend performance.** `ProjectQueryBudgetTest` pins all five pages (index, board, task detail, milestones, `/tasks`) as query-count-independent of row count, plus milestone-count equality. No polling, no `setInterval`, and no `fetch` anywhere in the projects/tasks React tree; no per-card request. The per-second timer clock lives in `RunningTimerBar`, a sibling of `{children}` in the layout, so it never re-renders the page tree, and its interval only runs while a timer exists.

**Data integrity and schema.** `ProjectMoveConcurrencyTest` passes unmodified in the full suite (parallel cross-column moves stay dense and lossless; creates racing stale indexes; deletes racing moves). The D4 guards are intact in `ProjectService` (app check, plus `QueryException` 1451 mapped to the same friendly refusal), and `2026_09_21_120000_restrict_time_entry_project_and_task_deletes` is reversible (`down()` restores `SET NULL`, both columns stay nullable). It is applied to the development database (batch 3) with nothing pending, and `projects:audit-integrity` reports 0 across all six checks there.

> **Production prerequisite (unchanged, restated):** a populated production database **must** run `php artisan projects:audit-integrity` — read-only — and resolve any non-zero count **before** `2026_09_21_120000_restrict_time_entry_project_and_task_deletes` is applied there. This pass re-confirmed it is the **only** EPIC-011E migration with a data prerequisite; no other migration in the repository carries one.

**Cleanup and documentation.** The WP9 closeout still holds: `resources/views/projects` and `resources/views/tasks` do not exist; no `view('projects.`/`view('tasks.` call remains; no HTML5 drag/drop, `draggable`, or `dataTransfer` anywhere; the two surviving `location.reload()` calls are EPIC-011D session-expiry recovery (401/419), not the retired checklist script; no TODO/FIXME/WP markers in the projects or tasks code; no unreferenced React component in either directory; navigation visit modes and `contextLinkModes` are `inertia` for project and task, `document` for ticket. Documentation agrees across D1, D2, D4, D5, D6, board status source of truth, React renderer state, dnd-kit architecture, and the `time.view_own` debt; no contradiction was found.

### Manual accessibility verification (what was and was not done)

WP10 as written calls for a screen-reader walkthrough (NVDA or VoiceOver) and a real-device touch check. Neither could be run by the agent pass: no screen reader exists on the WSL2 host, in `portal_app`, or reachable from either (no NVDA, no VoiceOver, no Orca), Chromium runs headless in the container, and no physical device is attached. The record below separates what was actually performed from what was deferred, so this remains auditable.

| Check | Status |
|---|---|
| Keyboard-only walkthrough and accessibility-tree inspection (agent, real Chromium) | **Performed.** Computed accessible names, roles, `aria-hidden` subtrees, live-region text content, heading outline, landmarks, focus order, focus trap, and `progressbar` semantics (`aria-valuenow/min/max` with an accessible name), across the board, Move menu, dialogs, checklist, task detail, `/tasks`, and both themes. Reported as accessibility-tree evidence, never as screen-reader evidence |
| **NVDA smoke walkthrough (owner, on Windows)** | **Performed, 2026-09-23.** Behaviour was sensible: page landmarks and navigation were announced coherently, and link/control states were announced correctly. No obvious missing or misleading application semantics were observed, and no blocking accessibility issue was found. **This was a smoke test, not an exhaustive screen-reader certification** — it does not claim full coverage of every flow, verbosity mode, or browse/focus-mode interaction |
| Exhaustive screen-reader certification and assistive-technology matrix (NVDA + JAWS + VoiceOver, multiple browsers) | **Deferred** to final platform-level QA, by explicit owner decision |
| Real-device touch check (iOS Safari, Android Chrome) | **Not performed.** CDP touch emulation is covered by `board-drag.spec.ts` (a body swipe scrolls, a handle drag moves) and the phone-viewport Move-menu flow passes, but neither is a real device, exactly as §9/§24 and Amendment 7 (Y9) anticipated. **Deferred** to final platform-level QA, by explicit owner decision |

**This deferral is a deliberate scope and verification decision, not a claim that the deferred tests occurred.** The owner chose to hold device-matrix and deeper assistive-technology testing until the broader platform modernization is closer to completion, so that it is done once against the finished surface rather than repeatedly per phase. EPIC-011E is marked Verified on the strength of the automated gates, the independent authorization/privacy/architecture review, the keyboard and accessibility-tree walkthrough, and the owner's NVDA smoke test — with the deferred items recorded here and carried into final platform QA rather than silently closed.

### Debt confirmed as *not* EPIC-011E

- **Fortify login rate limiting under parallel Playwright load** — shared test infrastructure. Fortify allows five attempts per minute per email and the suite signs in repeatedly as two seeded accounts; at 8 workers the window is exhausted. Evidence: the only two non-passes in the default-concurrency run were both in `auth-migration.spec.ts` (one reporting `Sign in as operator@intechral.test stayed rate limited` verbatim), that file passed **4/4 in isolation** after the window cleared, the full suite passed **46/46 at 2 workers**, and no EPIC-011E file is in its call path. `auth-migration.spec.ts` also bypasses the shared `signIn()` retry helper, which is why it is usually first to fail. **Non-blocking for EPIC-011E; should be tracked as a separate test-infrastructure item** (give that spec the retry helper, or relax the throttle in the testing environment).
- **Standalone-task fixture accumulation** — the development database gained exactly one row per full-suite run (8 → 9 → 10 → 11), and every leftover is an `E2E WP8 standalone task*` row with null project, column and ticket. This is the accepted, disclosed D3/AA10 trade-off: D3 forbids a standalone-task delete route, so the fixture cannot be removed through the application. Projects stayed at 4 and time entries at 0 across every run, so ordinary cleanup is working. Not a leak.
- **Host-contention sensitivity of the browser suite** — shared test infrastructure. At default concurrency (8 workers on 16 cores) the full Playwright suite is sensitive to load on the developer host: Amendments 7–10 each recorded scattered failures across unrelated specs that passed when re-run at low concurrency once load settled, and WP10 reproduced the same pattern (a `board-drag` reload test that took 1.0 min under load passed in 5.9 s in isolation — that one turned out to be a genuine test race, V4, and is now fixed). The residual sensitivity is timing, not correctness. **Non-blocking for EPIC-011E**: the full suite passes **46/46 at 2 workers**, and every high-concurrency non-pass has been traced to either Fortify throttling or host load, never to project or task code.
- **`time.view_own`** — unenforced permission-model debt, recorded in `rbac-design.md` by WP9 and untouched here.
- **EPIC-005 STORY-005-02** still checks "Custom columns configurable per project", which no route or UI provides. Column management is explicitly out of scope (§27), so this is a pre-existing EPIC-005 documentation inaccuracy, not an EPIC-011E gap. Left for EPIC-005 to correct rather than edited from here.

**None of the above blocks EPIC-011E verification.** The two test-infrastructure items (Fortify throttling, host-contention sensitivity) are properties of how the shared browser suite is run, not of the migrated Projects and Tasks surface: controlled-concurrency and isolated runs are green, and no EPIC-011E file sits in the call path of any affected spec. They are recorded here so they are carried forward as separate test-infrastructure work rather than absorbed into this epic.

### Files changed by WP10

| File | Change |
|---|---|
| `resources/js/components/projects/task-checklist.tsx` | V1–V3: the `sr-only` label and the `refocusIdRef` + effect focus restoration |
| `resources/js/components/projects/task-checklist.test.tsx` | Four new regressions (accessible name; focus after toggle, after add, after remove), each documenting why jsdom cannot reproduce the original defect |
| `tests/Browser/board-drag.spec.ts` | V4: `expectMoveSettled()` and its three call sites |

---

## 1. Goal

Migrate every Blade-rendered project and task page to Inertia 3 + React 19 + TypeScript, keeping Laravel authoritative for authorization, validation, tenant scoping, and ordering. Replace the mouse-only HTML5 kanban with a board that has an equivalent keyboard-accessible workflow, works on touch devices, and reconciles with the server after every mutation.

This is not a lift-and-shift. The live assessment found authorization and integrity defects (§4) that the React UI would otherwise inherit or expose more prominently. They are fixed in a backend-hardening work package (WP1) that lands before any page is converted.

### Parent scope finding

The parent roadmap ([EPIC-011 Phase E](./EPIC-011-react-frontend-migration.md#phase-e-projects-and-tasks)) confirms Projects and Tasks as the next phase. The live repository agrees on scope but differs from the parent's planning inventory in four ways:

| Parent roadmap says | Live repository | Consequence |
|---|---|---|
| Views: index/create/show/edit, board, tasks, milestones, unified task list | Seven Blade views exist; `projects.show` is a redirect to the board, so "show" is the board. `projects/index` and `projects/tasks/show` are not in the parent's inline-script inventory | Inventory below is authoritative |
| `projects/create` and `projects/edit`: "conditional form behavior" | Both build member rows by imperative DOM code that interpolates user names/emails into `innerHTML`; `edit` contains a nested `<form>` | Treated as defects (§4), not parity |
| "Replace native HTML5 drag/drop with `@dnd-kit/core`; local optimistic state" | The live board only ever appends to the end of a column, ignores the response, and has no keyboard path. Inertia 3.7.1 (installed) ships `router.optimistic()` | dnd-kit is retained but narrowed and demoted to a pointer/touch enhancement; the accessible path is library-independent (§8–§10) |
| Checklist interactions, comments/forms | Checklist items can only be *toggled*; nothing can create one. Milestones can never be attached to a task from the UI | D5 (locked): checklist authoring is in scope; §11/§12 defect fixes |

Roadmap statuses at the time of writing: EPIC-011A Implemented, EPIC-011B Implemented, EPIC-011C Verified, EPIC-011D Verified, EPIC-011 In Progress. This document does not change them.

### Guiding constraints (inherited)

Inertia monolith, no SSR, no Sanctum browser architecture, no TanStack/Redux/Zustand, Wayfinder for every URL, Tailwind 4 + selective shadcn, behavioral parity rather than pixel parity, local deterministic gates instead of CI, PDF work out of scope.

---

## 2. Exact Route and Page Scope

All 22 routes below were verified against `php artisan route:list` on the live repository.

### Pages converted to Inertia

| Method | URI | Route name | Controller | Inertia component |
|---|---|---|---|---|
| GET | `/projects` | `projects.index` | `ProjectController@index` | `projects/index` |
| GET | `/projects/create` | `projects.create` | `ProjectController@create` | `projects/create` |
| GET | `/projects/{project}/edit` | `projects.edit` | `ProjectController@edit` | `projects/edit` |
| GET | `/projects/{project}/board` | `projects.board` | `ProjectBoardController@show` | `projects/board` |
| GET | `/projects/{project}/tasks/{task}` | `projects.tasks.show` | `ProjectTaskController@show` | `projects/tasks/show` |
| GET | `/projects/{project}/milestones` | `projects.milestones.index` | `ProjectMilestoneController@index` | `projects/milestones/index` |
| GET | `/tasks` | `tasks.index` | `TaskController@index` | `tasks/index` |

### Retained mutation and redirect routes (same URLs, same names)

"Manager" below means `ProjectPolicy::manage`: `projects.admin`, or `projects.manage` **and** a `manager` membership on that project.

| Method | URI | Route name | Authorization and change in this epic |
|---|---|---|---|
| GET | `/projects/{project}` | `projects.show` | Unchanged: `view`, redirects to `projects.board` |
| POST | `/projects` | `projects.store` | Unchanged project-create authorization. Creator remains manager automatically. **D7-B:** only `projects.admin` may submit additional initial `members`; a non-admin request that supplies them is refused |
| PUT | `/projects/{project}` | `projects.update` | Unchanged |
| DELETE | `/projects/{project}` | `projects.destroy` | Manager; **blocked when any TimeEntry references the project or any of its tasks (D4)** |
| PUT | `/projects/{project}/members` | `projects.members.sync` | **D7-B:** `projects.admin` only, via a dedicated membership-management authorization boundary. Candidate IDs must be existing users; roles remain `member\|manager` |
| PUT | `/projects/{project}/companies` | `projects.companies.sync` | Unchanged (already tenant-validated); metadata only (D2) |
| POST | `/projects/{project}/tasks` | `projects.tasks.store` | **Manager (D1).** Column, milestone, assignee scoped to the project; append under column lock |
| PUT | `/projects/{project}/tasks/{task}` | `projects.tasks.update` | **Manager (D1).** Covers fields, assignee, milestone; scoped IDs; milestone added to the UI |
| DELETE | `/projects/{project}/tasks/{task}` | `projects.tasks.destroy` | **Manager (D1); blocked when any TimeEntry references the task (D4)** |
| PUT | `/projects/{project}/tasks/{task}/move` | `projects.tasks.move` | **Manager (D1).** Covers column moves and reordering. Validated, transactional, locked, dense positions. Contract change to redirect-back lands in WP5 |
| POST | `/projects/{project}/tasks/{task}/comments` | `projects.tasks.comments.store` | `view` (member or admin), **unchanged** |
| PUT | `/projects/{project}/tasks/{task}/checklist/{item}/toggle` | `projects.tasks.checklist.toggle` | `view` (member or admin), **unchanged**; accepts optional `completed` (idempotent set); redirect-back contract lands in WP7 |
| POST | `/projects/{project}/tasks/{task}/checklist` | `projects.tasks.checklist.store` | **New (D5, locked).** Manager |
| DELETE | `/projects/{project}/tasks/{task}/checklist/{item}` | `projects.tasks.checklist.destroy` | **New (D5, locked).** Manager |
| POST / PUT / DELETE | `/projects/{project}/milestones[/{milestone}]` | `projects.milestones.store/update/destroy` | Manager, unchanged |
| POST | `/tasks` | `tasks.store` | Any authenticated user (unchanged, D3). Server enforces the capability the form already offers: assignee is the actor or none. **No new routes for standalone tasks** |

Structural task routes authorize with `$this->authorize('manage', $project)` only. They deliberately do **not** add a `can:projects.manage` route middleware, because the policy is authoritative and admits `projects.admin` (see A9).

Controller actions switch from `view(...)` to `Inertia::render(...)` page by page. Because each URL is served by exactly one implementation at any time, there is no dual Blade/React rendering of the same route (see §21).

### Not converted

`projects.show` (pure redirect), all mutation endpoints above, the Blade `x-time-tracker` component (still used by `tickets/show.blade.php` until EPIC-011F), the existing time JSON endpoints.

---

## 3. Current Behavior Inventory

### 3.1 Backend

| Concern | Live behavior |
|---|---|
| Middleware | Everything is behind `auth`. `create/store/edit/update/destroy/members/companies` and milestone writes add `can:projects.manage` at the route level. Task, comment, checklist, move, and standalone `/tasks` routes have **no** permission middleware |
| `ProjectPolicy::view` | `projects.admin` **or** membership in `project_members`. `projects.view_org` is *not* consulted |
| `ProjectPolicy::manage` | `projects.admin`, or (`projects.manage` **and** membership with `role = manager`) |
| `ProjectPolicy::create` | `projects.manage` or `projects.admin` (the `projects.create` catalogue permission is unused) |
| Index scoping | `projects.admin`: all projects. `projects.view_org`: member projects **plus** projects linked to the user's organization companies. Otherwise: member projects. 20 per page |
| Permission defaults | `operator` role receives all permissions (including `projects.admin`). `user` role receives `projects.view`, `projects.view_org`, `tasks.view_org` |
| Navigation | `Projects` item requires `projects.view`; `Tasks` item is ungated. Both use `visit: 'document'` |
| Task modes | Three shapes share one table: **board tasks** (`project_id` + `column_id`), **standalone tasks** (both null, own `status` enum), **ticket tasks** (`ticket_id`). Nothing in application code creates ticket tasks |
| Task state | Board tasks derive "done" from `column.is_done_column`. The `tasks.status` column is never updated when a board task moves |
| Ordering | `tasks.position` (unsigned int, no unique constraint). Create uses `max(position) + 1` (first task lands at 1). `ProjectService::moveTask` decrements the source tail and increments the target from the drop index, then updates the task; no transaction, no lock, no bounds |
| Columns | Five defaults seeded by `ProjectService::create`. No route or UI creates, renames, reorders, or deletes columns |
| Milestones | `project_milestones` with name, due date, description; task FK is `nullOnDelete` |
| Checklist | `task_checklist_items` (title, completed, position). Only a toggle endpoint exists |
| Comments | `task_comments` (body up to 5000). Create only |
| Dependencies | `task_dependencies` table and relations exist. No route, UI, or validation |
| Time coupling | `time_entries.project_id` and `time_entries.task_id` are `nullOnDelete`. `AccessibleTimeContext` lets a task's assignee use it as a timer context even without project membership. Timer DTO links to `projects.tasks.show` / `projects.board` |
| Ticket coupling | `Ticket::tasks()` relation and `/tasks?view=org` inclusion of tasks on tickets of the user's companies |
| Tenancy | `Project` has no `organization_id` and no global scope. Access = membership, company link, or admin. Company inputs are validated by `AccessibleCrmCompany` (EPIC-010B). Member and assignee inputs are **not** scoped |

### 3.2 Presentation (Blade)

| View | Lines | Inline behavior |
|---|---:|---|
| `projects/index.blade.php` | 99 | None. Card grid; per-card completion, overdue, and member counts computed by extra queries |
| `projects/create.blade.php` | 189 | Script: dynamic member rows built via `document.createElement` + `innerHTML` string interpolation; inline `onclick` row removal |
| `projects/edit.blade.php` | 211 | Same member-row script. Details form, companies form, members form. **Delete form nested inside the details form** |
| `projects/board.blade.php` | 229 | Script + `<style>`: quick-add disclosure per column; HTML5 drag/drop (`draggable="true"`, `dragstart/dragover/drop`); fire-and-forget `fetch` PUT to the move endpoint |
| `projects/milestones/index.blade.php` | 203 | Script: new-milestone disclosure; hand-rolled edit "modal" (`div.hidden` toggled), backdrop click to close; inline `onsubmit="return confirm(...)"` on delete |
| `projects/tasks/show.blade.php` | 236 | Script: checklist toggle button → `fetch` PUT → `location.reload()`. `@can('manage')` gates the edit form and delete; embeds `x-time-tracker` for `time.log` |
| `tasks/index.blade.php` | 208 | Script: new-task disclosure; reopens on validation errors. Tabs `Assigned to Me` / `My Organization` (URL `?view=`) |

Total: 1,375 Blade lines, six inline `<script>` blocks, one `<style>` block, five inline `onclick`/`onsubmit` handlers. No project-specific file exists in `resources/js` (grep-verified); the only shared script involved is the `@once`-pushed script inside `x-time-tracker`, which stays.

### 3.3 Existing tests

35 Pest tests in three files, all Blade-era:

| File | Tests | Character |
|---|---:|---|
| `Projects/ProjectManagementTest.php` | 17 | Guests, `view`/`manage` for one plain member vs operator, creation, member sync, delete, index (one HTML `assertSee`) |
| `Projects/ProjectTaskTest.php` | 9 | Create, non-member create 403, 404 on foreign project, JSON move, `moveTask` gap closing, update, delete |
| `Projects/ProjectMilestoneTest.php` | 9 | View, CRUD, 403, 404, completion percentage |

Plus one cross-tenant company test in `Crm/TenantScopingTest.php` (project company sync) and two 011D Playwright references (`time-migration.spec.ts` navigates `Projects` and, in the "embedded Blade tracker" test, **drives the Blade project create form, the Blade board quick-add (`.add-task-btn`), and the Blade task page**).

Almost every test acts as `operator` (`projects.admin`, all permissions). The non-admin `projects.manage` path in `ProjectPolicy::manage` is covered only by the company-sync tenant test. Nothing covers: comments, checklist, standalone `/tasks`, the org index/policy relationship, cross-project column/milestone/assignee IDs, non-member update/delete/move/comment, ordering beyond one gap-close, concurrency, or query counts.

### 3.4 Additional audit findings (Amendment 1)

| Topic | Finding |
|---|---|
| Users | `users` has no active, disabled, suspended, or soft-delete column. "Active portal user" is not representable beyond "a row exists". Invited but unregistered people live in `invitations`, not `users`. There is no user-deletion route; FKs cascade (`projects.created_by`, `organizations.owner_id`, `organization_members.user_id`) |
| Organizations | `organization_members(organization_id, user_id, role admin\|member)` with a unique pair is the canonical user-to-organization relationship (EPIC-010B). It is curated only by operators (`crm.manage` routes) and by the owner attach on organization creation. Org self-service is not built. Nothing requires an operator or staff user to belong to an organization |
| Project to organization | `Project` has no `organization_id`. The only path is `project_company` to `crm_companies.organization_id`, which D2 now classifies as metadata |
| Who can be a non-admin manager | `ProjectPolicy::manage` needs the `projects.manage` permission. Only the `operator` role (which also holds `projects.admin`) has it by default; the `user` role does not. Non-admin managers exist only through custom roles or direct grants (as `TenantScopingTest` does) |
| Task status | Raw `tasks.status` is read in exactly three places: `Task::isDone()` (only when `column_id` is null), `Task::effectiveStatus()` (only when there is no column), and `TimeEntryController::contextOptions` (`whereNotIn('status', ['done'])`, wrong for board tasks). `TaskController::store` and the `/tasks` create form write it for standalone tasks. No report, filter, dashboard metric, or export reads it. The migration that added the column states its intent: "Explicit status for non-board tasks; board tasks derive state from their column". Nothing in code or docs contradicts that |
| Standalone tasks | Beyond create and list, standalone tasks are usable as timer and time-entry contexts when assigned to the user (`contextOptions`, `AccessibleTimeContext`); their timer context has no URL. No other capability exists (routes: only `tasks.index`, `tasks.store`) |
| Time history | `TimeEntry` has **no soft deletes**; deletes are hard. FKs `project_id` and `task_id` are `nullOnDelete` |
| Time permissions | Personal time routes: `can:time.log`. Reports/export: `can:time.view_all`. `time.view_own` is never enforced. The operator report DTO exposes `userName`, project name, description, and billing/lock flags to `time.view_all` holders only |
| Ticket policy | `TicketPolicy::view`: ticket owner, or an operator holding `tickets.view` |
| PHP | Composer requires `^8.3`; the `portal_app` container runs 8.3.33; the host CLI is 8.4.1 |
| Installed Inertia | `@inertiajs/core` and `@inertiajs/react` 3.7.1 expose `router.optimistic()`, `useForm().optimistic()`, `onHttpException`, and `onNetworkError` (source read; behavior is proven in WP0, not assumed) |

---

## 4. Known Defects Discovered

Severity: **H** = fix before any UI conversion, **M** = fix in this epic, **L** = fixed as a side effect of the React implementation.

### 4.1 Authorization and tenancy

| # | Sev | Defect | Evidence |
|---|---|---|---|
| A1 | H | **Cross-project column injection.** `column_id` is validated only as `exists:project_columns,id`, and `moveTask` never checks the column belongs to the task's project. A member of project A can create or move A's tasks into project B's column (the task then renders on B's board with title, assignee, and milestone) and shifts B's positions | `ProjectTaskController.php:32,89`; `ProjectService.php:58-74` |
| A2 | M | `milestone_id` accepts any milestone in any project: leaks the name onto the card and inflates the other milestone's task counts | `ProjectTaskController.php:36,63` |
| A3 | M | `assignee_id` and `members.*.user_id` accept any user ID. An assignee outside the project sees the task in `/tasks`, may start timers on it (`AccessibleTimeContext::canUseTask` grants assignees), and its label/link appears in their timer bar | `ProjectTaskController.php:35,62`; `TaskController.php:47`; `ProjectController.php:64,138`; `AccessibleTimeContext.php:47` |
| A4 | M | **Resolved by lock D1.** Task create/update/delete/move/comment/checklist all authorize `view` (membership) only, although the Blade UI shows edit and delete to managers. Target: structural mutations require `manage`; comment and checklist toggle stay `view` (C1) | `ProjectTaskController.php:29,56,75,85,100,115`; `tasks/show.blade.php:155` |
| A5 | M | **Resolved by lock D2 (queries, not policy).** The index lists company-linked projects and the create/edit copy promises org visibility, but `ProjectPolicy::view` denies non-members, so those links 403. `/tasks?view=org` rows link to member-only task pages and owner-only tickets. Fix: remove the company branch from the index, intersect the org tab with policy-visible projects, and render no link the destination policy would deny. `ProjectPolicy` is not changed | `ProjectController.php:24-34`; `ProjectPolicy.php:15-22`; `create.blade.php:86`; `TaskController.php:26-31` |
| A6 | M | Create/edit expose every user's name and email to any `projects.manage` holder, and member/assignee IDs are unscoped. **Resolved by D7-B:** only `projects.admin` receives the candidate-user directory or may mutate membership; non-admin managers receive a minimal read-only member list. Assignee eligibility remains project-members-only (A3) | `ProjectController.php:46,95` |
| A7 | M | Standalone `tasks.store` has no authorization beyond `auth`, and accepts any assignee | `TaskController.php:42-59` |
| A8 | M | **Resolved by lock D4.** Deleting a project or task nulls `time_entries.project_id/task_id` by FK, including billed, invoice-linked, and running entries. Target: any referencing TimeEntry blocks the hard delete, with a friendly domain error and an FK `restrictOnDelete` backstop (C3) | `2026_03_26_120007_update_time_entries_task_id_fk.php`; `create_time_entries_table.php:21` |
| A9 | L | Route middleware requires `projects.manage` while `ProjectPolicy` also admits `projects.admin`; a role with admin but not manage is blocked at the route. Latent (operator holds both). **Pinned by a characterization test and left unchanged in 011E**; new structural task routes use the policy only | `routes/web.php:125,133,152` |

### 4.2 Security in the presentation layer

| # | Sev | Defect | Evidence |
|---|---|---|---|
| S1 | M | **DOM-injection candidate; fixed in WP1 while Blade still works.** Member option markup is built with template strings (`${u.name} &lt;${u.email}&gt;`) assigned to `innerHTML`. Names are user-controlled (profile), so a name containing markup such as `</select>…` can break out of the select in a manager's browser. **Browser-confirmed as DOM injection in WP1 (Amendment 4, W2).** WP1 replaced string-building with a server-rendered, Blade-escaped `<template>` row | `create.blade.php:164-165`; `edit.blade.php:186-187` |
| S2 | **H** | **Invalid nested form on the edit page; fixed in WP1 while Blade still works.** The delete `<form>` sits inside the update `<form>`. HTML5 tree construction with PHP 8.4 `Dom\HTMLDocument` indicates the inner form tag is discarded and both `_method` inputs become associated with the outer form; `parse_str` keeps the later `DELETE`, making destructive submission a credible risk. **Browser-confirmed in WP1 (Amendment 4, W1):** in the `portal_app` container's Chromium, *Save Changes* deleted the project. It is now fixed: the delete form is outside the update form, pinned by a Playwright regression and a PHP-8.3-compatible structural Pest test | `edit.blade.php:23,83-92` |

### 4.3 Data integrity and correctness

| # | Sev | Defect | Evidence |
|---|---|---|---|
| I1 | M | `moveTask` is not transactional, takes no locks, does not bound `position`, and uses increment/decrement arithmetic. Concurrent moves or moves past the tail can leave gaps and duplicates | `ProjectService.php:58-74`; validation `min:0` only |
| I2 | L | Create positions start at 1 (`max(null)+1`), the client uses 0-based indexes | `ProjectTaskController.php:41` |
| I3 | M | Dual task state: moving to *Done* does not update `tasks.status`, and `TimeEntryController::contextOptions` filters on `status != 'done'`, so tasks in Done columns remain offered as timer targets. Not a dual-write bug: the contract is column-for-board, `status`-for-standalone/ticket (§15). Fix the consumer, do not mirror the field | `TimeEntryController.php:206-208` |
| I4 | M | **Milestones cannot be attached to tasks from the UI.** The API validates `milestone_id`, but no form sends it, so every milestone shows 0 tasks and 0% unless data was seeded. The card badge and milestone progress are effectively dead features | `board.blade.php:134-149`; `show.blade.php:159-205` |
| I5 | M | **In scope (lock D5).** Checklists cannot be authored: no create or remove route or UI; the section is hidden when empty. No factories exist for checklist items or comments | `ProjectTaskController.php`; `show.blade.php:38` |
| I6 | M | Off-by-one overdue: `Task::isOverdue()` uses `due_date->isPast()` on a midnight date, so a task due *today* is overdue all day, while `Project::overdueTasks()` uses `< today()`. The milestone view has the same `isPast()` check | `Task.php:115-120`; `Project.php:94`; `milestones/index.blade.php:71` |
| I7 | L | Standalone tasks are create-only with no detail, edit, complete, or delete. **Preserved by lock D3.** The `/tasks` page must represent them accurately (no link, status from `status`) without adding mutations. The assignee select offers only *Me*/*Unassigned* while the server accepts anyone; the server is tightened to what the form already offers | `tasks/index.blade.php:44-47,126-137` |
| I8 | L | The edit form builds the assignee select from *current* members, so saving a task whose assignee has left the project silently clears the assignee | `show.blade.php:184` |

### 4.4 Interaction and accessibility

| # | Sev | Defect | Evidence |
|---|---|---|---|
| X1 | M | Board is mouse-only (`draggable="true"`); no keyboard or assistive-technology path. EPIC-005 checks "keyboard fallback" while its own Known Gaps says "not confirmed": confirmed absent. HTML5 drag/drop is also not reliably operable on touch devices (platform limitation; not device-tested here) | `board.blade.php:86,189-218` |
| X2 | M | Drop handling always `appendChild`s the card, so the computed position is always "last". Reordering within a column and insertion at a chosen index never happen, although the server supports positions | `board.blade.php:196-217` |
| X3 | M | Move response is ignored: a failed request leaves the DOM in the wrong place until reload; column counts never update | `board.blade.php:209-216` |
| X4 | M | Checklist toggle is an icon-only button with no accessible name or checked state, calls a non-idempotent toggle, then `location.reload()` (loses scroll and focus) | `show.blade.php:55-64,231`; `ProjectTaskController.php:119` |
| X5 | M | Milestone edit "modal" is a `div` with no dialog role, focus management, or Escape handling. Milestone create/edit and board quick-add validation failures are never displayed (forms are hidden and have no `@error`) | `milestones/index.blade.php:128-163` |
| X6 | L | Native `confirm()` for deletes; flash markup duplicated per view | Various |

### 4.5 Performance

| # | Sev | Defect | Evidence |
|---|---|---|---|
| P1 | M | Board: `checklistItems` and `column` are lazy-loaded per card (`$task->checklistItems->…`, `isOverdue()` → `isDone()`) | `ProjectBoardController.php:14-18`; `board.blade.php:110-114` |
| P2 | M | Index: per card `completionPercentage()` (2 queries), `overdueTasks()` (1-2), and `members()->count()` twice, ×20 cards; it also eager-loads `creator` that is never rendered | `projects/index.blade.php:44-45,84`; `ProjectController.php:23,32` |
| P3 | L | Milestones: `completionPercentage()` runs 2 queries per milestone | `milestones/index.blade.php:71` |

### 4.6 Noted, not fixed

Unused `projects.client_id` column; unused `projects.create` permission; `projects.created_by` cascades project deletion when a user is deleted; column management and task dependencies exist only as schema. See §27.

---

## 5. Project Page and Data DTO Contracts

Rules (same discipline as EPIC-011D):

- Page props are built by dedicated presenter classes, never by serializing models. No `toArray()` of an Eloquent model reaches a page.
- Field names are `camelCase`. Date-only fields (`dueDate`, `startDate`, `targetDate`) are `YYYY-MM-DD` strings and are never timezone-converted in the browser. Timestamps are ISO-8601 UTC and are formatted with `Intl` on the client.
- Users are exposed as `{ id, name }` by default. Under D7-B, email appears only in the administrator-only `MemberCandidate` directory used to disambiguate users during member management. Non-admin project managers receive no candidate directory and no candidate emails.
- Derived values (`overdue`, `status`, `abilities`) are computed by the server so React never re-implements a rule. **Abilities are display hints only.** Every route still authorizes (§16).
- Presenters use eager loading and aggregate counts (§25); a presenter must not trigger a query per row.

```ts
type ProjectStatus = 'active' | 'on_hold' | 'completed' | 'archived';
type TaskPriority = 'low' | 'medium' | 'high' | 'critical';
type UserRef = { id: number; name: string };
type MilestoneRef = { id: number; name: string };
```

### `projects/index`

```ts
type ProjectCard = {
    id: number; name: string; description: string | null; // description truncated server-side
    status: ProjectStatus; targetDate: string | null;
    completion: number;        // 0-100, tasks in done columns / all tasks
    overdueCount: number;      // due_date < today, not in a done column
    memberCount: number;
};
props: { projects: Paginated<ProjectCard>; abilities: { create: boolean } }
```

The listed set is exactly the set `ProjectPolicy::view` allows (administrators: all; everyone else: member projects). The `creator` relation that is loaded today but never rendered is dropped. `Paginated<T>` moves from `types/time.ts` to a shared `types/pagination.ts`, and the pagination markup used by the Time page becomes a shared component (§20).

### `projects/create` and `projects/edit`

```ts
type CompanyOption = { id: number; name: string };
type ProjectMemberRef = { id: number; name: string; role: 'member' | 'manager'; isOwner: boolean };
type MemberCandidate = { id: number; name: string; email: string };   // administrators only

create props: {
    companies: CompanyOption[];
    abilities: { editMembers: boolean };
    memberCandidates?: MemberCandidate[];   // present only when editMembers === true
}
edit props:   {
    project: { id; name; description; startDate; targetDate; status; budget: string | null };
    members: ProjectMemberRef[];             // read-only DTO is safe for managers who cannot edit membership
    memberCandidates?: MemberCandidate[];    // administrators only; omitted otherwise
    companies: CompanyOption[]; linkedCompanyIds: number[];
    abilities: { delete: boolean; editMembers: boolean };
}
```

D7-B makes `abilities.editMembers` true only for `projects.admin`. The candidate-user directory is **omitted**, not merely hidden or populated as an empty client-side control, for every other actor. Existing membership is still shown read-only to a non-admin project manager using `ProjectMemberRef`, which carries no email. `budget` stays a decimal string end to end.

### `projects/board`

```ts
type BoardTask = {
    id: number; title: string; priority: TaskPriority;
    dueDate: string | null; overdue: boolean;
    assignee: UserRef | null; milestone: MilestoneRef | null;
    checklist: { done: number; total: number };
};
type BoardColumn = { id: number; name: string; isDone: boolean; tasks: BoardTask[] };   // tasks ordered (position, id)

props: {
    project: { id: number; name: string; status: ProjectStatus };
    columns: BoardColumn[];
    abilities: {
        manage: boolean;        // create / move / reorder (ProjectPolicy::manage, D1). Comments and checklist toggles live on the task page
        openSettings: boolean;  // the Settings link: whether the actor can actually reach projects.edit (see A9 note below)
    };
}
```

There is no separate "contribute" ability: for structural mutations `manage` is the only gate (D1), and members without it see a **read-only board**. The payload carries no description, comments, emails, or checklist item text; `checklist` counts come from `withCount`.

### `projects/tasks/show`

```ts
type TaskDetail = {
    id: number; title: string; description: string | null;
    priority: TaskPriority; dueDate: string | null; overdue: boolean;
    status: TaskStatusDto;                              // see below
    column: { id: number; name: string; isDone: boolean } | null;
    assignee: UserRef | null; assigneeIsMember: boolean;
    milestone: MilestoneRef | null;
};
props: {
    project: { id: number; name: string };
    task: TaskDetail;
    checklist: { id: number; title: string; completed: boolean }[];
    comments: { id: number; body: string; createdAt: string; author: UserRef }[];
    options: { members: UserRef[]; milestones: MilestoneRef[]; priorities: { value: TaskPriority; label: string }[] } | null;   // only when abilities.manage
    abilities: {
        manage: boolean;            // edit fields, assign, milestone, delete, checklist add/remove (D1, D5)
        comment: boolean;           // any member or admin (unchanged)
        toggleChecklist: boolean;   // any member or admin (unchanged)
        logTime: boolean;           // time.log
    };
    timeSummary: TaskTimeSummary | null;                // D6
}

type TaskTimeSummary = {
    scope: 'own' | 'all';           // 'all' only when the viewer holds time.view_all
    totalMinutes: number;           // over the same scope, completed entries only
    entries: { id: number; date: string; durationMinutes: number; userName?: string }[];   // latest 5; userName only in 'all'
};
```

The summary carries no descriptions, billing or invoice flags, emails, or user IDs. `timeSummary` is a partial-reloadable prop (§17) so it can refresh when a timer stops without reloading comments or checklist.

### Task status DTO (shared by board, detail, and `/tasks`)

```ts
type TaskStatusDto = {
    label: string;                  // column name for board tasks; 'To Do' | 'In Progress' | 'Done' for others
    done: boolean;                  // column.is_done_column for board tasks; status === 'done' otherwise
    source: 'column' | 'status';    // which field is authoritative for this task
};
```

Computed by the presenter through `Task::isDone()` / `effectiveStatus()` semantics; React never reads a raw `status` string (§15).

### `projects/milestones/index`

```ts
type MilestoneItem = {
    id: number; name: string; description: string | null; dueDate: string;
    taskCount: number; doneCount: number; completion: number; overdue: boolean;
};
props: { project: { id; name }; milestones: MilestoneItem[]; abilities: { manage: boolean } }
```

### `tasks/index`

```ts
type TaskRow = {
    id: number; title: string; priority: TaskPriority;
    status: TaskStatusDto;
    dueDate: string | null; overdue: boolean; assignee: UserRef | null;
    context: { kind: 'project' | 'ticket' | 'standalone'; label: string; url: string | null };
    url: string | null;      // null unless the actor's policy would allow the destination
};
props: { tasks: Paginated<TaskRow>; view: 'mine' | 'org'; canViewOrg: boolean }
```

`url` and `context.url` are computed from the same checks the destination route enforces (`ProjectPolicy::view` for projects, `TicketPolicy::view` for tickets) using one batched lookup rather than one policy call per row. A row that the actor may see but not open (for example a task assigned to them in a project they no longer belong to) renders its context as plain text with no link. Standalone rows have `url: null` and no actions by design (D3).

### Shared timer DTO change (additive)

`ActiveTimer.context` gains `id` (and the `TimerContext` TS type follows) so the React task page can recognise "a timer is running for this task" without URL matching. The field is additive; `timer-overlay.js` ignores it. **Implemented in WP2** for every context branch (timer start, active list, allocation entries).

---

## 6. Project Create/Edit Strategy

### Create (`projects/create`)

One Inertia `useForm` always holds `name, description, start_date, target_date, status, budget, companies: number[]`. When `abilities.editMembers` is true (`projects.admin` only under D7-B), it also holds `members: { key: string; user_id: number | ''; role }[]`. `key` is a client-only stable row identity (`crypto.randomUUID()`), removed by `transform` before submit, so removing a middle row cannot re-bind React state to the wrong row (the Blade version re-indexes names by a monotonically growing counter). A non-admin project manager sends no `members` field; if a forged request supplies extra members anyway, the server refuses it rather than silently discarding it.

- Submit `POST projects.store`; server validation errors map to `errors['members.0.user_id']` etc. and render beside the offending row. On success the server redirects to the board and the flash region shows the message.
- For `projects.admin`, member rows are controlled `<select>`s (no `innerHTML`; S1 disappears structurally). Users already chosen are excluded from other rows' options, and the server still de-duplicates. Non-admin project managers receive no candidate-user directory and no member-entry controls.
- The actor is shown as a locked *Owner / Manager* row, matching `ProjectService::create` and the "you will be added automatically" copy.
- Company checkboxes render inside a `<fieldset><legend>` with corrected, informational copy: linking a company records the client relationship and **does not grant its organization members access to the project** (D2).
- **Member editing is gated by `abilities.editMembers` (D7-B).** It is editable only for `projects.admin`. Other project managers see only the creator/owner and current member list as read-only names and roles; they receive no `memberCandidates` prop or candidate emails.
- Native `<select>` and `<input type="date">` are kept (consistent with EPIC-011D); no combobox or date-picker library.

### Edit (`projects/edit`)

Three **independent** forms with their own dirty state, processing state, and error bags, mapped 1:1 to the three existing endpoints:

| Section | Endpoint | Notes |
|---|---|---|
| Details | `PUT projects.update` | name, description, dates, status, budget. `preserveScroll` |
| Linked companies | `PUT projects.companies.sync` | Sends `companies: []` when all are unchecked (`present\|array`) |
| Members | `PUT projects.members.sync` | **D7-B:** rendered as a form only for `projects.admin`. Owner row locked as manager and not removable; server re-adds the creator as manager regardless. Other project managers see the same membership as a read-only list and receive no candidate directory |

Delete lives in a *Danger zone* section using the existing `ConfirmationDialog` (replacing the nested form and native `confirm()`), sends `DELETE projects.destroy`, and shows the D4 guard result ("This project has recorded time and cannot be deleted.") as a form error rather than a 500; the existing `archived` status is the available alternative. Three forms rather than one aggregate endpoint keeps the migration free of backend contract changes and avoids partial-save ambiguity.

---

## 7. Project Show Architecture

`GET /projects/{project}` remains a server redirect to the board, so the board **is** the project home. Deep links from the timer bar, dashboard, and tasks list keep working unchanged.

Page composition (`projects/board`):

```
AppLayout (persistent; TimerProvider and RunningTimerBar stay mounted)
└─ ProjectHeader        breadcrumb · name · status badge · Milestones link · Settings link (abilities.openSettings)
   └─ BoardScrollRegion role="region" aria-label="Kanban board" tabIndex=0, overflow-x-auto, snap on small screens
      └─ BoardColumn × n   heading · count (from state) · Add task · task list
         └─ TaskCard × n   drag handle · title link · priority · due · assignee · checklist · milestone · Move menu
```

- The page owns full-width layout inside `<main>`; only the scroll region scrolls horizontally, never the document (checked at mobile widths, §24).
- Structural controls (Add task, drag handle, Move menu) render only when `abilities.manage` (D1). The **Settings link renders only when `abilities.openSettings`**, which the server computes from what `projects.edit` will actually admit today (`manage` policy **and** the `projects.manage` route middleware, A9), so a `projects.admin` holder without `projects.manage` is not offered a link that answers 403. The Blade board still shows that link in that one case (pinned, Amendment 4 W6); the React board must not carry the defect over, and the authorization model itself is not changed here. Other members see a read-only board and can still open tasks, comment, and toggle checklist items.
- Column count is derived from `columns[i].tasks.length` on every render, fixing X3's stale header count.
- Task title links use Inertia `<Link prefetch>` once the task page is React (WP7); before that they use a plain anchor (§21).
- Project header navigation is real links, not tabs, so each destination keeps its own URL and history entry.

---

## 8. Kanban State Architecture

**Conclusion: the server-provided `columns` prop is the only durable board state. No component keeps a copy of the board, and no state library is added.** Everything else is a short-lived overlay that holds a *move descriptor*, never a board array.

| Layer | Owner | Lifetime |
|---|---|---|
| Confirmed board | Inertia page props (`columns`) | Until the next response |
| Pending move | Inertia optimistic overlay (`router.optimistic`); in the fallback, a one-element `pendingMove` descriptor | From menu selection or drop until the response |
| Drag preview | Local state holding `{ taskId, overColumnId, overIndex }` only | While a pointer drag is active |

The rendered board is always derived: `applyMove(applyMove(props.columns, pendingMove?), dragPreview?)`. Because both overlays are descriptors applied to the current props, a navigation, partial reload, or flash can never leave a second, diverging copy of the board behind.

**WP6 deviation ([Amendment 7](#amendment-7-wp6-results-2026-09-22)):** the "drag preview" row above and the `onDragOver`-driven descriptor described later in this section were built as planned, but reproducibly crashed the board in a real browser (a `@dnd-kit/core`-internal `MutationObserver` feedback loop triggered by live-reordering the DOM mid-drag). WP6 removed the live projection: the board renders the real `columns` prop for the whole drag, a `DragOverlay` ghost is the only visual feedback while dragging, and `applyMove` is reached only once, at drop time, through `requestMove`'s existing optimistic transform — the same path the Move menu already uses. `resolveDragTarget` (not a preview descriptor) computes the drop's move intent directly from the real board at `onDragEnd`.

### Why not a reducer seeded from props

Copying props into component state is the classic derived-state bug: after any navigation or reload the copy and the props diverge, and the component then owns a reconciliation problem Inertia already solves. The earlier draft's fallback ("reducer seeded from props, re-keyed by a version") is withdrawn for the same reason.

### Pure move function

A dependency-free module `resources/js/components/projects/board-moves.ts` exports:

```ts
applyMove(columns: BoardColumn[], move: { taskId: number; toColumnId: number; toIndex: number }): BoardColumn[]
```

It removes the card from its column, inserts it at `toIndex` (clamped to `0..length`) in the target, and returns new arrays without mutating input. It serves the optimistic overlay, the drag preview, and the fallback, and is unit-tested exhaustively (same column up/down, cross column, empty target, first/last, clamping, unknown IDs are a no-op).

### Move flow (one code path for pointer, touch, and menu)

1. Trigger: drag end (pointer or touch) or a Move menu selection. Both call `requestMove(taskId, toColumnId, toIndex)`.
2. Guard: return if a move is pending, `!abilities.manage`, or the move is a no-op.
3. `router.optimistic((props) => ({ columns: applyMove(props.columns, move) })).put(move.url(...), { column_id, position }, { only: ['columns', 'flash'], preserveScroll: true, preserveState: true, onHttpException, onNetworkError, onError, onFinish })`. The `onHttpException` and `onNetworkError` callbacks return `false` so the move surfaces as an inline alert, not Inertia's default error modal.
4. The endpoint authorizes `manage`, validates, moves under lock, and redirects back; Inertia's partial reload returns the authoritative `columns`.
5. Success: server props replace the overlay (positions may legitimately differ if someone else moved cards). Failure: the overlay is discarded, the board returns to the last confirmed state, and an inline `role="alert"` message appears.

### Concurrency: single flight, last write wins

While one move is pending, every handle and Move menu is disabled (`aria-busy` on the board); moves are refused, not queued. Installed Inertia sends optimistic visits as *async* requests that run concurrently, and the server gives no ordering guarantee between concurrent PUTs, so single flight must be enforced by the client. There is no expected-source check: two people moving the same card is last write wins and the mover always sees the authoritative result. The client index is "position in the target column as I saw it"; the server clamps and renormalizes.

### Failure and reconciliation

| Outcome | UI |
|---|---|
| 2xx / redirect | Server columns replace overlay |
| 422 (invalid column, stale index) | Revert, inline message, `router.reload({ only: ['columns'] })` |
| 403 (lost manager authority) | Revert, message, reload |
| 404 (task or column deleted) | Revert, "This task no longer exists", reload |
| 419 / 401 | Same session-expiry handling as EPIC-011D |
| Network error / 5xx | Revert, "Couldn't save the move. Try again.", controls re-enabled |

### Focus and announcements

Moving a card across columns unmounts and remounts it under another parent, which drops DOM focus. After a settled move (success or revert) the board restores focus to the card's Move button by task id, and a polite live region announces the outcome (`"Moved “Fix login” to In Progress, position 2 of 5"`) or an assertive one the failure. The live region is shared by the menu and pointer paths.

### Spike gate (WP0): PASSED 2026-09-21 (8 of 8, design unchanged)

**What the installed 3.7.1 source does** (read from `@inertiajs/core/dist/index.js`, then executed in WP0; results below):

- `router.optimistic(cb)` stores a one-shot callback consumed by the next `visit()`, which forces `async: true`.
- The callback receives a deep clone of current props and returns a partial props object. Changed keys have their previous values saved as *baselines*; the merged props are applied with `setPropsQuietly`, which swaps the page asynchronously with `preserveState`.
- On success with no validation errors, the response props replace the overlay. On validation errors, HTTP exceptions, network errors, or cancellation, `onFinish` unregisters the callback and, if still on the same component, *replays* baselines plus any other pending callbacks (the rollback).
- While other optimistic visits are pending, `preserveOptimisticProps` keeps overlaid keys and updates their baselines from the response.
- There is no per-URL serialization of async requests.

**The spike must prove, on a throwaway branch against the real endpoint and this repository's React adapter:**

1. A pure `applyMove` transform through the callback produces exactly the expected props, and the original props object is not mutated.
2. Rollback on validation failure (422 redirect with errors), authorization failure (403), 404, 500, and offline: `columns` returns to the pre-move value, and returning `false` from `onHttpException` / `onNetworkError` suppresses the default modal so the inline alert is used.
3. On success the canonical server response replaces the optimistic state, including when the server order differs from the optimistic order (simulate a concurrent insert), and no baseline or pending callback is left behind.
4. With the client guard, exactly one move request is in flight at a time and a second attempt is refused; also record what happens without the guard, for the record.
5. Page props remain the durable board state: after unrelated visits, `router.reload({ only: ['columns'] })`, and flash updates, the rendered board equals the props.
6. No reducer or store copy exists: the board components read `columns` from props only, and drag preview and pending state hold descriptors. Enforced by review and, where practical, a lint or test assertion.
7. Partial reload (`only`), `preserveScroll`, and `preserveState` leave unrelated props, scroll, and focus intact, and dropping a card shows no one-frame snap-back (the optimistic swap is asynchronous).
8. React adapter behavior: `usePage()` re-renders after `setPropsQuietly`, and `preserveEqualProps` keeps memoized `TaskCard` identities stable when nothing changed.

Record the result of each item, the installed versions, and the date in this section. **Pass = items 1 to 8 all pass.**

#### WP0 results (executed 2026-09-21)

Method: throwaway branch; a temporary Inertia page using the exact move flow above (`router.optimistic(...).put(...)` with `only: ['columns','flash']`, `preserveScroll`, `preserveState`, single-flight guard); the **real** `projects.tasks.move` endpoint with throwaway hooks (return `back()` for Inertia requests, and header-driven delay and forced-500 injection); a fixture project on the development database; Playwright with Chromium 153 inside `portal_app`. Failure cases used the real behaviors: 422 from an invalid `position`, 404 from a missing task ID, 403 from a plain member whose membership was removed while the board was open, 500 by injection, and offline through the browser context.

| # | Gate item | Result | Evidence |
|---|---|---|---|
| 1 | Pure `applyMove` through the callback; original props not mutated | **Pass** | Optimistic DOM equalled the independently computed expected order. The callback ran once and received a deep clone (a different object from the page props, equal JSON). `applyMove` ran on a deep-frozen input without throwing, and the page's original props object was unchanged |
| 2 | Rollback on 422, 403, 404, 500, offline; default modal suppressed | **Pass** | 422, 403, 404, 500: DOM went before, optimistic, before, in both DOM-mutation and painted-frame sequences. Offline failed fast enough that the optimistic state was never painted (React batched the two swaps) and the board stayed unchanged. `onSuccess` was never called. Returning `false` from `onHttpException` and `onNetworkError` suppressed Inertia's error dialog in every case (no iframe or dialog, no error HTML in the page). 422 produced `props.errors.position` and `onError`. The busy flag cleared in `onFinish` each time |
| 3 | Canonical server response replaces optimistic state; no residue | **Pass** | With a concurrent insert the stale client showed `[B1, A1, B2]` optimistically; the server result `[A3, A1, B1, B2]` replaced it exactly and equalled the database. A later failing move rolled back to that fresh server state, and an external change followed by `reload({ only: ['columns'] })` applied immediately, so no baseline or pending callback was left behind |
| 4 | One move in flight at a time | **Pass** | Guard on: the second move was refused and exactly one `/move` request was sent. Guard off (recorded for the record): both requests were sent concurrently, the server applied them out of order (the undelayed second ran first), and the final order differed from issue order. The guard is mandatory |
| 5 | Page props remain the durable board state | **Pass** | After partial reloads (`meta`, `flash`), a full reload, a same-page visit, and a `columns` reload, each combined with external server changes, the rendered board equalled the database every time |
| 6 | No reducer or store copy | **Pass** | The board page has no `useState` or `useReducer`; columns come only from `usePage().props`. New props won immediately. In the dnd page the preview state is a descriptor rendered through `applyMove(props.columns, preview)` |
| 7 | Partial reload, scroll, focus; no snap-back | **Pass** | The PUT carried `X-Inertia-Partial-Data: columns,flash`. The unrelated `meta.loadedAt` (a server `microtime`, which changes on every full render) stayed identical, proving the follow-up response was partial. Document scroll (400 to 400), the horizontal board scroller (300 to 300), focus, and the typed input value were all preserved. DOM states were `before`, then `optimistic = final` with no intermediate revert, both in mutation records and painted frames. (`preserveScroll` restores only `[scroll-region]` elements; the window scroll is retained because the DOM nodes persist.) |
| 8 | React adapter re-render and memo behavior | **Pass, with a requirement** | `usePage()` re-rendered on the quiet swap. With a reference-equality `memo`, all six cards re-rendered in the optimistic phase (the clone gives every task a new reference), none during the server-response phase (`preserveEqualProps` keeps the optimistic references when the response is deep-equal), and none on an unrelated partial reload. With a structural comparator only the moved card re-rendered. **`TaskCard` therefore uses a structural comparator** |

**Decision:** the optimistic design is confirmed **unchanged**. The fallback is not selected and remains documented only as a contingency.

**Findings recorded for implementation**

- Rollback replays the *last client-known baseline*. For a validation redirect (an Inertia response) the response updates the baseline first, so the rollback lands on fresh server columns. For non-Inertia failures (403, 404, 5xx) and network errors the rollback restores the pre-move client state, which can be stale. The reload-after-failure rule in the table above is therefore required.
- After a 422 the `errors` prop stays populated until the next visit; the board must not render stale errors from a previous move.
- Very fast failures can collapse into no visible optimistic frame. That is acceptable and desirable.
- The throwaway `move` hook that returns `back()` for `X-Inertia` requests worked as designed (303 to the referrer with partial-reload headers); WP5 makes it the permanent contract.

**Fallback (already designed, no state library):** focused `fetch` JSON as `TimerProvider` does. The move endpoint returns JSON; a local transient reducer holds only `{ pendingMove, status, error }`; the board renders `applyMove(props.columns, pendingMove)`; on success it calls `router.reload({ only: ['columns'] })` and clears `pendingMove` once the new props arrive; on failure it clears `pendingMove`, so the board falls back to the unchanged props automatically. The pure `applyMove`, the single-flight guard, the failure table, and focus handling are identical in both designs, so the choice changes one hook and the endpoint's response contract.

### Why no TanStack, Redux, or Zustand

Single page, single resource, one writer per interaction, no background refresh, no cross-page cache. Inertia props plus one pure function cover it. (`@hello-pangea/dnd` was rejected partly because it depends on `react-redux`; see §9.)

---

## 9. Drag-and-Drop Recommendation

### What the live requirements actually need

| Requirement | Verdict |
|---|---|
| Move card to another column | Required |
| Reorder within a column | Required by the server contract (`position`) and by normal kanban expectations; the Blade board never delivered it (X2) |
| Keyboard operation | Required, **but satisfied by a non-drag control** (§10). WCAG 2.5.7 (Dragging Movements) requires a single-pointer alternative; 2.1.1 requires keyboard operability. A Move menu satisfies both regardless of the drag library |
| Touch | Desirable. HTML5 DnD is not a reliable touch mechanism; pointer-event DnD is |
| Sortable columns | **Not required.** Columns are not reorderable (no column management exists) |
| Screen-reader feedback | Required for both paths (live announcements) |

### Options considered

| Option | Verdict |
|---|---|
| Native HTML5 DnD in React | Zero dependency, but no touch, no autoscroll control, poor insertion feedback. Would leave touch users on the menu only. Acceptable fallback, not recommended |
| **`@dnd-kit/core` + `@dnd-kit/sortable`** | **Recommended.** Pointer-event based (mouse and touch), headless, supports handle activators, multi-container sortables, `DragOverlay`, autoscroll. Measured cost (esbuild, minified + gzip, the symbols this board would import): **≈16.5 kB**. Stable 6.3.1 / 10.0.0; peer `react >=16.8` so React 19 is fine. Last published December 2024 (quiet, not abandoned) |
| `@dnd-kit/react` 0.5.0 (+ `dom`, `helpers`) | Rewrite of the above, pre-1.0 and actively changing. Not appropriate as a foundation yet |
| `@hello-pangea/dnd` 18.0.1 | Board-shaped API and built-in keyboard, but ≈**31.5 kB** gzip (measured, ~2×) and it pulls `react-redux` + `redux` as transitive dependencies, which sits badly with the "no Redux" principle even though it is internal. Its built-in keyboard dragging would be a *second* keyboard path next to the Move menu |
| `@atlaskit/pragmatic-drag-and-drop` | Built on native HTML5 DnD; same touch limitation as the current board |

### Recommendation

Add exactly:

```
@dnd-kit/core      ^6.3.1
@dnd-kit/sortable  ^10.0.0
@dnd-kit/utilities ^3.2.2    (imported directly for CSS.Transform; already a transitive dependency of sortable)
```

Do **not** add `@dnd-kit/modifiers`, `@dnd-kit/accessibility` (it ships transitively with core), or the `@dnd-kit/react` family. If the WP0 spike shows an unacceptable React 19 / Vite 8 issue, fall back to native HTML5 DnD plus the Move menu; the menu path and `applyMove` are unaffected.

### Configuration

- **Activation:** a visible grip **handle** per card via `setActivatorNodeRef` + `listeners`, with `touch-action: none` on the handle only. `PointerSensor` with `activationConstraint: { distance: 6 }`. Handle-only activation keeps the title link, Move button, page scroll, and horizontal board scroll unambiguous on touch. (Grab-anywhere from the Blade board is deliberately traded away.)
- **No `KeyboardSensor`.** The Move menu is the keyboard and assistive path. The handle is pointer-only: `aria-hidden="true"`, `tabIndex={-1}`, and the `attributes` from `useSortable` (which add `role="button"`, `tabindex="0"`, and a "press space to lift" description) are **not** spread. This avoids an extra tab stop per card and instructions that do not apply. It is a **hard requirement** (§10): no focusable drag control may imply that Enter or Space starts a drag, and `accessibility.screenReaderInstructions` is set so no keyboard instruction text is emitted.
- **Structure:** one `DndContext` (collision `closestCorners`), one `SortableContext` per column (`verticalListSortingStrategy`) plus `useDroppable` on the column body so empty columns accept drops, and a `DragOverlay` for the ghost card. Columns themselves are not sortable.
- **Drag preview:** `onDragOver` records a `{ taskId, overColumnId, overIndex }` descriptor in local state (never a copy of the board) that is rendered through `applyMove`; `onDragEnd` commits through `requestMove` (§8) and clears the preview; `onDragCancel` clears it.
- **Announcements:** custom `announcements` (`onDragStart/Over/End/Cancel`) in the project's vocabulary, feeding the same live region as menu moves.
- **Isolation:** every `@dnd-kit` import lives in `components/projects/board-dnd.tsx` and `board-card-handle.tsx`. Nothing else in the codebase may import it (a `no-restricted-imports` lint rule enforces this), and no dnd-kit type crosses the adapter boundary: the adapter exposes library-agnostic props (`onMove({ taskId, toColumnId, toIndex })`, render callbacks) so domain components never see `UniqueIdentifier`, `DragEndEvent`, or similar. Replacing the library, including a later move to the `@dnd-kit/react` API once it is 1.0, touches two files. Vite already code-splits pages (`import.meta.glob`), so the library loads only with the board chunk; WP10 verifies this in the build output.
- **Read-only users:** no handle, no Move menu, no quick-add, and no `DndContext` sensors unless `abilities.manage` (D1).

### WP0 dnd-kit spike results (executed 2026-09-21)

Method: the packages were copied into `node_modules` only (`package.json` and the lockfile were never changed); a throwaway page combined dnd-kit with the optimistic move above (handle-only `PointerSensor` with `distance: 6`, no `KeyboardSensor`, `attributes` not spread, `DragOverlay`, per-column `SortableContext` plus `useDroppable`, a preview descriptor rendered through `applyMove`, custom announcements, `screenReaderInstructions` override). Desktop tests used Chromium 153 through real mouse events; touch tests used the Pixel 7 emulation with real touch events through the DevTools input pipeline.

| Check | Result |
|---|---|
| React 19 and Vite 8 | **Pass.** Vite 8.1.0 built the page and React 19.3.0 ran it with no console or page errors. The page chunk, including dnd-kit, was 51.05 kB raw and 16.91 kB gzip, consistent with the earlier ≈16.5 kB estimate |
| Multi-column sorting | **Pass.** Cross-column drops landed at the top and in the middle of the target and persisted; DOM equalled the database; no snap-back frame (DOM states `before`, then `after`) |
| Same-column reorder | **Pass.** Move up and move down both persisted correctly |
| Empty-column drop | **Pass.** A card dropped on an empty column landed there |
| Drop after the last card | **Pass after re-aiming.** The first attempt appeared to fail, but the pointer had been aimed at coordinates that the preview had already shifted; re-aiming at the shifted layout appended correctly, both on the last card's lower half and on the column body below the last card (pointer-first collision with `closestCorners` fallback) |
| Handle-only activation | **Pass.** Dragging from the card body started no drag and sent no request; dragging from the handle worked. Tab, Space, and Enter never started a drag |
| Accessibility tree | **Pass.** The handle carries only `aria-hidden="true"` and `tabindex="-1"`; no `role`, `aria-roledescription`, or `aria-describedby` exists anywhere on the page; the cards have no ARIA attributes. dnd-kit still renders a hidden (`display: none`) instructions node containing our pointer-only text, and a live region (`role="status"`, `aria-live="assertive"`) whose announcements fired during pointer drags. The only Tab stop on the page was the horizontal scroll container, which Chromium makes keyboard-focusable natively |
| Touch and pointer | **Pass on emulation.** Pointer events were `pointerType: "touch"`. A swipe that started on a card body scrolled the board container (0 to 220 px) and did not start a drag or send a request; a touch drag from the handle moved the card across columns and persisted. No `TouchSensor` was needed. This is emulation through a real touch pipeline, not a physical device, so the real-device check (iOS Safari, Android Chrome) stays in WP10 |
| Horizontal autoscroll | **Pass.** Holding the pointer at the right edge scrolled the container from 0 to its 628 px maximum in about 360 ms, and the card dropped into a column that had scrolled into view |
| Reduced motion | **Pass.** With `prefers-reduced-motion: reduce`, the overlay's drop animation disappeared (overlay lifetime after release 11 to 13 ms versus 262 to 275 ms) and card transitions dropped from 0.2 s to 0 through `dropAnimation={null}` and omitting the sortable `transition` |
| Failed drop | **Pass.** With a forced 500 the card returned, the preview descriptor cleared, and the next drag succeeded |

**Outcome:** dnd-kit is confirmed for WP6 as planned (`core` + `sortable` + `utilities`, handle-only, pointer-only, no `KeyboardSensor`). The native-HTML5 fallback is not selected. The implementation requirements this produced are listed in Amendment 3.

### Version policy (Amendment 1)

The stable `@dnd-kit/core` and `@dnd-kit/sortable` line is used because the newer `@dnd-kit/react` API is still pre-1.0 and this migration favors a stable, known sortable API. During WP6: install the three packages, **pin compatible versions in the committed lockfile** (and `--save-exact` in `package.json` given the quiet upstream), keep every import in the two adapter files, and do not spread library types through domain components. No packages are installed as part of this planning amendment.

### ADR follow-up

ADR-007 and the parent roadmap name `@dnd-kit/core`. This epic keeps that package family but narrows it (`core` + `sortable`, handle-only, no keyboard sensor). WP9 adds one dated note to ADR-007 recording the measured comparison and the "menu is the canonical accessible path" rule.

---

## 10. Keyboard-Accessible Task Movement

The accessible workflow does not depend on drag and drop, and it is **not** a second-class fallback: it is the canonical keyboard, screen-reader, and mobile movement interface. Drag is a pointer-only enhancement layered over it.

### Design choice (locked)

Of the two acceptable designs (a pointer-only drag affordance plus a Move menu, or full `KeyboardSensor` support), this plan uses the first. The Move menu gives deterministic, testable, equivalent functionality; a second keyboard path through dnd-kit across scrolling multi-column containers would be harder to make reliable and would duplicate the announcement and focus logic.

**Requirement:** no focusable control may imply that Enter or Space will start a drag. The drag handle is not in the tab order, is hidden from assistive technology, is not a button, and carries no draggable/sortable role description or keyboard instruction.

### The Move menu

Each card has a **Move** button (`aria-label="Move “{title}”"`) that opens the existing Radix `DropdownMenu` (already a dependency; roving focus, typeahead, Escape, and focus return provided). Items:

- `Move to <column>` for **every other column of the project** (a done column carries a "(done)" suffix in its accessible name);
- `Move up` (disabled at the first position) and `Move down` (disabled at the last) within the current column.

`Move to top` / `Move to bottom` are optional and only added if usability testing shows long columns need them.

The menu, the Move button, the drag handle, and quick-add render **only when `abilities.manage`** (D1). Members without it see a read-only board.

### Behavior requirements

| Requirement | Detail |
|---|---|
| Same request path | Every selection calls `requestMove(taskId, toColumnId, toIndex)` (§8), the function drag end also calls. `Move to <column>` appends to the end of the target column; up/down use `index ∓ 1` in the same column |
| Tab order per card | Title link, then Move button. Nothing else is tabbable |
| Menu operation | Enter or Space opens; Arrow keys navigate; Enter selects; Escape closes and returns focus to the Move button |
| Focus return | After a settled move, focus returns to the moved card's Move button (looked up by task id, since the card changed parent), also after a failed move |
| Live region | A polite region confirms success with the column name and position; an assertive region reports failure. Shared with the pointer path |
| Pending | Move buttons are `aria-disabled` and the board `aria-busy` while a move is in flight |
| Touch and small screens | The same button works; hit target at least 44 px on coarse pointers; usable without dragging |
| Reduced motion | Drag overlay and drop animation disabled under `prefers-reduced-motion` |

### Verification

Vitest exercises the menu with `user-event` (Tab, Enter, ArrowDown, Escape) against a mocked Inertia `router`, asserting the exact request payload for a cross-column move, up, and down, the optimistic result, focus return, and both live-region messages. A dedicated test asserts the drag handle has no `tabindex >= 0`, no `role="button"`, no `aria-roledescription`, and no `aria-describedby` reference to keyboard instructions. Playwright repeats the keyboard flow in a real browser. Drag physics is not simulated in jsdom: drag handlers are tested by invoking `onDragOver/onDragEnd/onDragCancel` with synthetic events, and pointer/touch dragging is covered in Playwright (§24).

---

## 11. Task Create / Edit / Detail Behavior

**Structural task mutations are manager-only (D1, locked).** That covers create, edit fields, delete, assign/reassign, assign/remove milestone, move between columns, reorder, and checklist add/remove. Ordinary members keep comments and checklist toggling and see everything else read-only. The server refuses every structural request from a non-manager regardless of what React renders.

### Create

- **Board quick-add (parity, manager-only):** per-column *Add task* opens an inline form with a title field. It posts `column_id`, `title`, and `priority: 'medium'` to `projects.tasks.store` with `preserveScroll` and `only: ['columns', 'flash']`, appends at the end of the column, closes, and returns focus to *Add task*. Validation errors render inline (fixing X5's silent failures). One quick-add form is open at a time. The full field set is edited on the task page, as today.
- The server appends under a column lock; the client does not compute positions for creates.

### Detail page (`projects/tasks/show`)

Two-column layout preserved: main (title/description, checklist, comments) and sidebar (details, time, edit).

- **Read view (everyone who may view):** title, plain-text description (`whitespace-pre-wrap`; no HTML or Markdown rendering is introduced), priority badge, status (`TaskStatusDto`), assignee, due date with overdue styling, milestone.
- **Edit form (`abilities.manage`):** title, priority, assignee (`options.members`), **milestone (new field, I4)**, due date, description. `useForm` + `PUT projects.tasks.update`, `preserveScroll`, inline errors. The assignee select always includes the current assignee, labelled "(no longer a project member)" when `assigneeIsMember` is false, so saving cannot silently unassign (I8). Assignee candidates are project members only (A3).
- **Delete (`abilities.manage`):** `ConfirmationDialog`. If any TimeEntry references the task the server refuses (D4) and the dialog shows the returned error ("This task has recorded time and cannot be deleted."); nothing is deleted. After success the server redirects to the board.
- **Status:** displayed only. Moving is a board operation; the detail page gains no move control.
- **Checklist and comments:** §14 and §13.
- **Time panel (D6, locked):** replaces the Blade `x-time-tracker` embed with a React panel using the persistent `useTimers()` provider.
  - *Start/stop controls* appear only with `time.log` (the personal-time gate in force today). "Running for this task" is derived from `timers` via the new `context.id`.
  - *Summary* is `timeSummary`: the viewer's **own** completed time on this task (needs `time.log`), or **all users'** completed time with names only when the viewer already holds `time.view_all`. Project-manager status grants nothing. A `time.view_all` holder without `time.log` sees the all-users summary read-only, without controls. A viewer with neither sees no summary.
  - When the running timer for this task disappears (stopped here or in the timer bar) the panel triggers `router.reload({ only: ['timeSummary'] })`.
  - No second time-report permission model is introduced; the DTO follows the EPIC-011D minimal-entry pattern (no descriptions, billing or invoice flags, emails, or user IDs).
- **Breadcrumb:** Projects / project / task, using Inertia `Link`.

---

## 12. Milestone Behavior

Preserved: list ordered by due date, per-milestone task count and completion, overdue indicator, create/edit/delete for `manage`, view for members.

Changes:

- **Create and edit use one accessible dialog** (Radix `Dialog`, already a dependency): labelled fields, focus trap, Escape, focus return, `aria-modal`. Server validation errors render in the dialog and the dialog stays open (X5). Edit prefills from the row; there is no `data-*` transport.
- **Delete** uses `ConfirmationDialog`; copy states that linked tasks stay but lose their milestone (`nullOnDelete`).
- **Counts** come from one query with `withCount` (tasks, and tasks in done columns) and `overdue` uses `due_date < today` and `completion < 100` (fixes I6 at the milestone boundary). `ProjectMilestone::completionPercentage()` is retained and must agree with the aggregate (a Pest test pins equality).
- **Tasks can now actually be attached** through the task edit form (I4). The milestone page still shows counts only; listing a milestone's tasks is a new feature (out of scope).
- Validation and authorization are unchanged (`manage`, 404 on foreign milestone), and `tasks.milestone_id` written by the task endpoints is validated against the same project (A2).

---

## 13. Comment Behavior

Preserved (and **not broadened**): any user who may `view` the project (member or administrator) can post; comments are listed oldest first with author and time; body is required and at most 5,000 characters; plain text.

- `useForm({ body })` posting to `projects.tasks.comments.store` with `preserveScroll` and `only: ['comments', 'flash']`. **Server-confirmed, not optimistic**: the row needs the server's ID, timestamp, and author. On success the textarea resets and keeps focus; the flash message and a polite live region announce "Comment added".
- Rendered as text nodes with `whitespace-pre-wrap break-words`. No HTML, Markdown, or link rewriting is introduced; React escaping is the XSS boundary and a Vitest case pins that markup renders literally.
- A character counter appears near the limit; the server remains the validator.
- Author is `{ id, name }` only; no email.
- Timestamps use `<time dateTime={iso}>` formatted with `Intl`, replacing server-side `diffForHumans()`.
- The form is hidden when `abilities.comment` is false. Because company links grant no access (D2), the only non-commenting viewers are people who cannot open the page at all.
- **Not included:** edit, delete, reactions, mentions, notifications, attachments (§27).

---

## 14. Checklist Behavior

Checklist is an explicit part of Phase E, but the current product cannot create items, so the feature is a dead end. **D5 is locked: this epic completes the minimum useful lifecycle.**

### Permissions (server-enforced)

| Action | Who | Change |
|---|---|---|
| Add item | Project manager (`manage`) or `projects.admin` | **New** |
| Remove item | Project manager (`manage`) or `projects.admin` | **New** |
| Toggle completion | Any member or administrator (`view`), exactly as today | Unchanged; inspected and not unsafe (low impact, idempotent once `completed` is supplied) |

### Behavior

- Each item is an ordinary native `<input type="checkbox">` with a real `<label>` (fixes X4's unnamed icon button). The progress bar is a `role="progressbar"` with `aria-valuenow`, and the "2 of 5 complete" text is a polite live region.
- **Toggle is optimistic with rollback.** `router.optimistic` flips the item and recomputes progress, then `PUT projects.tasks.checklist.toggle` with an explicit `completed` boolean. The endpoint is **idempotent** when `completed` is supplied (a replayed request cannot flip it back) and still toggles when it is omitted, so any old caller keeps working. Response is redirect-back with `only: ['checklist']`, `preserveScroll`; no `location.reload()`. Non-members and the non-`view` case are refused by the server.
- **Add:** an "Add item" input under the list for managers. `POST projects.tasks.checklist.store` with `title` (required, trimmed, at most 255 characters), appended at `max(position) + 1`, at most 100 items per task. Server-confirmed; inline validation errors; the input keeps focus.
- **Remove:** a per-item remove button (`aria-label="Remove “{title}”"`) for managers, `DELETE projects.tasks.checklist.destroy`; focus moves to the next item (or the add input when none remain). A single line-item removal does not use a confirmation dialog.
- Non-managers see checkboxes that they can toggle, but no add/remove controls. A task with no items shows an empty state with the add input for managers and nothing for others.
- An item that does not belong to the route's task returns 404 for toggle and remove.

### Explicitly not added

Checklist groups, templates, drag or any reordering, due dates, assignees, nested items, rename, bulk operations.

### Supporting work

`TaskChecklistItemFactory` and `TaskCommentFactory` (WP1), backend authorization/validation tests, Vitest coverage of the checklist component, and a Playwright flow that creates its own checklist items through the UI (removing the need for seeded checklist data).

---

## 15. Assignment, Status, and Priority Behavior

### Task status source of truth (explicit domain contract)

`tasks` holds three kinds of task in one table. Each kind has exactly one authoritative status source.

| Kind | Identified by | Authoritative workflow state | Completion (`done`) | Written by |
|---|---|---|---|---|
| **Project-board task** | `project_id` not null | The board **column** (`column_id`, shown by its name) | `column.is_done_column` | Board moves and creation set `column_id`. `tasks.status` is ignored and left at its DB default |
| **Ticket task** | `ticket_id` not null, no project | `tasks.status` | `status = 'done'` | No creation path exists today |
| **Standalone task** | neither | `tasks.status` | `status = 'done'` | `tasks.store` only (create-only, D3) |

Rules:

1. **No dual write.** A board move does not update `tasks.status`, and nothing backfills it. Mirroring would create a second source of truth that drifts whenever a column is renamed or marked done.
2. **Single implementation.** `Task::isDone()` and `Task::effectiveStatus()` already encode this (column first, `status` only when there is no column). They remain the only definitions; a `Task::kind()` helper (board, ticket, standalone) and SQL-equivalent scopes `Task::open()` and `Task::done()` are added so queries cannot re-invent the rule.
3. **Page DTOs carry an explicit computed status** (`TaskStatusDto`: `label`, `done`, `source`) wherever a unified UI needs a common display value (`/tasks`, task detail, board cards). React never reads or compares a raw `status` string.
4. **Consumer fix:** `TimeEntryController::contextOptions` currently applies `whereNotIn('status', ['done'])` to every task, which wrongly keeps Done-column board tasks selectable. It becomes kind-aware via `Task::open()`; standalone and ticket tasks keep working by `status`, board tasks by column.
5. **Edge (pinned, not solved):** a board-shaped task whose column FK was nulled (`nullOnDelete`; no UI deletes columns) falls back to `status` under the existing `isDone()`. It appears on no board. A characterization test records this.

**Evidence and audit.** The migration that introduced the column says "Explicit status for non-board tasks; board tasks derive state from their column"; `isDone()`/`effectiveStatus()` are column-first; project completion, overdue counts, and milestone completion all use columns. Nothing in code or docs shows `status` was meant to mirror the column, so no contradiction is reported. The audit of raw `status` readers is in §3.4 (three readers, one of them buggy; no report, filter, dashboard, or export reads it; `/tasks` already renders through `effectiveStatus()`).

**Characterization tests come first** (WP1, before any behavior change): pin `isDone`, `effectiveStatus`, and `isOverdue` per kind; pin that a board move leaves `status` untouched; pin `contextOptions` for a standalone task, an in-progress board task, a Done-column board task (currently offered, flipped in the fix commit), a ticket task, and the null-column edge.

### Standalone tasks (D3, locked): current capability, no expansion

Inspection of routes, controllers, views, and time integration found the full existing capability set:

- Create from `/tasks` with title, description, assignee (**Me or Unassigned** in the UI), priority, initial status (`todo`, `in_progress`, `done`), and due date. Authorization is `auth` only.
- List on `/tasks` in the assignee's "Assigned to Me" tab (title as plain text, `Standalone` context, status, priority, assignee, due).
- **Timer and time-entry context** when assigned to the user (found in this amendment; it was missing from the first audit). The timer context has no URL.
- **Nothing else:** no detail page, edit, complete, delete, comments, or checklist. Routes are only `tasks.index` and `tasks.store`.

This epic **preserves exactly that**: the React `/tasks` page keeps the create form and the list, shows standalone rows unlinked and without actions, and adds no route that mutates a standalone task. The only server change is enforcing what the form already offers: `assignee_id` must be null or the actor, closing the arbitrary-assignee hole (A7). A Pest test asserts the route list still contains only `tasks.index` and `tasks.store` for standalone tasks, so a future PR cannot expand the surface silently.

### Other assignment rules

| Concern | Rule |
|---|---|
| Vocabulary | The server is the source of truth (`Task::PRIORITIES`, `Project::STATUSES`). Forms receive labelled `{ value, label }` options as props; the client only maps a value to a badge style and tolerates unknown values with a neutral fallback |
| Project-task assignee | Optional; must be a **member of the project** (server-enforced, A3), changed only by managers (D1). The edit form includes the current assignee even if they left, labelled (I8) |
| Priority | `low`, `medium`, `high`, `critical`; default `medium`; shown as a labelled badge (colour is never the only signal) |
| Due date | Date-only; overdue means `due_date < today()` in the application timezone and not done by the kind-aware definition, computed once on the server (I6) |
| Milestone | Optional; must belong to the project (A2); selectable in the task edit form (manager-only, I4) |

---

## 16. Permission and Tenant Boundaries

React visibility is never authorization. Every mutation and page route authorizes on the server. The `abilities` props exist only to hide controls the server would refuse, and a route-authorization matrix test (§23) proves the server refuses them.

### Actors

| Actor | Definition |
|---|---|
| Guest | Unauthenticated |
| Outsider | Authenticated, not a member, not an administrator (a company link makes no difference, D2) |
| Member | `project_members.role = member` |
| Manager-role without permission | `role = manager` on the pivot but lacks `projects.manage` (`ProjectPolicy::manage` is false) |
| Project manager | Member with `role = manager` **and** `projects.manage` |
| Admin/operator | `projects.admin` |
| Non-member assignee | Assigned a task (pre-existing data) but not a member |

### Capability matrix (current vs target)

| Capability | Current server behavior | Target |
|---|---|---|
| Guest to any route | Redirect to login | Unchanged |
| List projects | Admin: all. `projects.view_org`: members plus **company-linked**. Otherwise members | **Admin: all. Everyone else: member projects.** One shared scope `Project::visibleTo($user)` matches `ProjectPolicy::view` exactly (D2) |
| View board, task page, milestones | Admin, member | Unchanged |
| Create project; edit/delete project; companies; milestone writes | Route `can:projects.manage` and policy `manage` | Unchanged; A9 pinned |
| Delete project | `manage` | `manage` **and** no TimeEntry references the project or its tasks (D4) |
| Members sync and candidate list | `manage`; all users offered; IDs unscoped | **D7-B:** `projects.admin` only. Non-admin managers get no mutation form and no candidate directory; existing members are read-only. Candidate IDs must reference existing `users` rows |
| Create task, edit fields, delete, assign, milestone, move, reorder, checklist add/remove | `view` | **`manage` (D1)**; delete also needs no TimeEntry reference (D4) |
| Comment; checklist toggle | `view` | **Unchanged** (member or admin) |
| Standalone `POST /tasks` | Any authenticated user; any assignee | Any authenticated user; assignee is the actor or none (D3) |
| Start a timer on a task | `AccessibleTimeContext` | Unchanged; A3 stops new non-member assignees |
| Task time panel | Any `time.log` holder sees every user's entries and the total | Own entries need `time.log`; all users only with `time.view_all` (D6) |

### Route authorization rules

- Structural task routes call `$this->authorize('manage', $project)`. They add no `can:projects.manage` middleware (it would exclude a role holding `projects.admin` alone, which the policy admits).
- Membership mutation is deliberately stricter than ordinary project management: `projects.members.sync` uses a dedicated membership-management authorization boundary (recommended `ProjectPolicy::manageMembers`) that returns true only for `projects.admin`. If the route currently inherits `can:projects.manage`, remove that middleware from the member-sync route so an actor holding `projects.admin` is not accidentally rejected by the weaker-but-different permission gate; the policy/ability is the sole membership-mutation authority. Project creation remains available through the existing create authorization, but extra initial `members` are prohibited unless the actor also satisfies that membership-management boundary.
- Comment and checklist-toggle routes keep `authorize('view', $project)`.
- The 404 for a child that does not belong to the route's project is unchanged and evaluated after authorization, so an unauthorized actor learns nothing about which IDs exist.

### Integrity and scoping rules (server)

1. **Ownership of referenced IDs.** `column_id` must be a column of the route's project; `milestone_id` a milestone of that project; `assignee_id` null or a **member of that project**. Enforced with scoped `Rule::exists(...)->where('project_id', …)` (members via `project_members`). Failure is a 422 on that field (A1 to A3).
2. **Move algorithm.** One `DB::transaction`: lock the source and target `project_columns` rows in ascending ID order (`lockForUpdate`), read both task-ID lists ordered by `(position, id)`, splice, rewrite positions densely `0..n-1` for the affected columns, clamp the requested index to `0..count(target)`. Reject a task/column pair from different projects. The column rows are the mutex; this also tolerates today's legacy gaps and duplicates (I1, I2). **Implementation refinement (Amendment 4, W3):** the task's column is read before the transaction and re-checked under the lock, and the attempt restarts if it changed, so locks are only ever taken in one order.
3. **Create and delete** take the same column lock: create appends at `count`; delete closes the gap.
4. **One overdue implementation.** `Task::isOverdue()`, `Project::overdueTasks()`, and the milestone check all use `due_date < today()` (I6).
5. **Status is kind-aware** (§15).
6. **Visibility equivalence.** The projects index query and the task-row link computation both derive from `ProjectPolicy::view`. `ProjectPolicy` itself is not broadened.

### Deletion rule with time history (D4, locked)

A Project or Task referenced by **any** `TimeEntry` cannot be hard-deleted. This applies to billed, unbilled, invoice-linked, stopped, and running entries alike, because historical provenance must remain intact. EPIC-010C billed/invoice-linked immutability remains a hard invariant, and no path may null or otherwise touch an entry's context.

| Delete | Blocked when |
|---|---|
| Task | any `TimeEntry` has `task_id = task` |
| Project | any `TimeEntry` has `project_id = project`, **or** any `TimeEntry` has `task_id` in the project's tasks |

Mechanics:

- Authorization runs first (`manage`); only then is the guard evaluated, so an unauthorized actor cannot probe for time history.
- The check lives in one service method used by both controllers, runs inside the delete transaction, and returns a `ValidationException` on the `delete` key ("This project has recorded time and cannot be deleted." / "This task has recorded time and cannot be deleted."), never a database exception. React renders it in the confirmation dialog. `TimeEntry` has no soft deletes, so a plain existence query is complete.
- **Backstop (C3):** one additive migration changes `time_entries.project_id` and `time_entries.task_id` from `nullOnDelete` to `restrictOnDelete`, with a reversible `down()`. It closes the timer-start race and any cascade (`tasks.project_id`, `projects.created_by`) that bypasses the application guard. A residual FK violation that reaches the app is mapped to the same validation message. No data changes.
- No archiving is introduced. The existing project `archived` status remains the available alternative for projects and may be mentioned in the error copy; tasks have no equivalent (move them to a done column). Archive semantics are a future product feature.
- Entries are not touched by a blocked delete (verified by test for billed and unbilled entries alike).

### Existing data

A3 and A1 stop new cross-project data, but pre-existing rows may exist. WP1 delivers a **read-only audit query** and reports counts for: tasks whose column belongs to another project, tasks with a milestone from another project, tasks assigned to non-members, time entries whose task or project no longer exists, and projects/tasks that the new D4 rule would now refuse to delete. It does **not** rewrite data.

### Tenant summary

Company links are validated by the existing `AccessibleCrmCompany` rule and are **metadata only**: they grant no project visibility (D2), are shown as informational in the UI, and remain usable for reporting/context. Assignees are project-scoped (A3). Under D7-B, only `projects.admin` may alter the project-member set, and only that actor receives the candidate-user directory. Child records (tasks, milestones, columns, comments, checklist items) are always resolved through the route's project, 404 on mismatch, and now also validated on write. Documentation that describes "project membership or linked company" as the project boundary is corrected in WP9 (C2).

---

## 17. Inertia Data Strategy

| Concern | Decision |
|---|---|
| Navigation | Full Inertia visits between migrated pages inside the persistent `AppLayout`. `<Link prefetch>` on project cards and task links once their targets are React |
| In-page mutations | `router`/`useForm` with `preserveScroll: true` and `only: [...]` so only the affected props reload |
| Optimistic | Task move and checklist toggle only (`router.optimistic`). Creates, edits, deletes, and comments are server-confirmed because they need server IDs/timestamps or can be refused |
| Partial reload keys | Board: `columns`, `flash`. Task page: `comments`, `checklist`, `timeSummary`, `flash`. Projects/milestones/tasks lists: reload the page prop set |
| Deferred / `WhenVisible` | Not used initially. `timeSummary` is cheap. Revisit only if measured (§25) |
| Pagination | Laravel paginator `->withQueryString()`; `/tasks?view=` (mine or org) validated as an enum; page links via shared pagination component |
| Validation | Standard Inertia error bags per `useForm`; nested keys (`members.0.user_id`) rendered beside the row |
| Flash | Existing shared `flash` prop and `FlashRegion`; messages unchanged |
| Standalone JSON | None added. The only JSON in play is EPIC-011D's timer endpoints via `TimerProvider`. `useHttp` is not needed |
| Lightweight props | `options` (members, milestones, priorities) only computed when the actor may edit; presenters return arrays of scalars |
| Error surfaces | 403/404/419/500 follow the existing Inertia error handling; move failures use the inline alert (§8) |

Route-level authorization (`authorize` in controllers, `can:` middleware) is not moved into presenters or shared props.

---

## 18. Wayfinder Usage

All URLs come from generated modules; no string paths (the Blade scripts hard-coded `/projects/{id}/...`).

| Need | Import |
|---|---|
| Index/create/edit/board/show/store/update/destroy | `@/routes/projects` |
| Members / companies sync | `@/routes/projects/members`, `@/routes/projects/companies` |
| Tasks: show/store/update/destroy/move | `@/routes/projects/tasks` |
| Comments / checklist | `@/routes/projects/tasks/comments`, `@/routes/projects/tasks/checklist` |
| Milestones | `@/routes/projects/milestones` |
| Standalone tasks | `@/routes/tasks` |
| Timer (existing) | `@/routes/time/timer`, `@/routes/time/timers` |

`resources/js/routes` and `resources/js/actions` are generated and gitignored, so `npm run check` (which runs `wayfinder:generate` first) must be green after adding checklist authoring routes (D5) or changing parameter shapes. Route names and URIs stay stable except the two additive checklist routes.

---

## 19. shadcn and Library Additions

### Runtime dependencies (three, all one family)

`@dnd-kit/core ^6.3.1`, `@dnd-kit/sortable ^10.0.0`, `@dnd-kit/utilities ^3.2.2` (§9), installed in WP6 with versions pinned by the lockfile. No other runtime package.

### Local shadcn-style primitives (no new packages)

| File | Purpose |
|---|---|
| `components/ui/textarea.tsx` | Description, comment, milestone description |
| `components/ui/native-select.tsx` | Consolidates the class string currently repeated across Time pages; used for status, priority, assignee, milestone, role, member selects |
| `components/ui/progress.tsx` | `role="progressbar"` bar for project, milestone, and checklist progress |
| `components/ui/dropdown-menu.tsx` | shadcn-style wrapper over the already-installed `@radix-ui/react-dropdown-menu`, used by the Move menu (the layout currently uses the raw primitive) |
| `components/ui/form-dialog.tsx` | Wrapper over the already-installed `@radix-ui/react-dialog` for the milestone dialog, sharing chrome with `ConfirmationDialog` through `components/ui/dialog-shell.tsx` (WP2) |
| `components/pagination.tsx` | Extracted from the Time page; used by Time, Projects, Tasks |

### Deliberately not added

Radix Select, Tabs, Popover, Sheet, Toast, Checkbox (native input is more testable and accessible here), Combobox/command palette, date-picker, `react-hook-form`/`zod`, table library, Markdown or rich-text editor, date library (`Intl` is enough), any state library. Any of these needs an ADR-007 amendment, not a work-package decision.

---

## 20. Shared vs Module-Specific Components

**Shared** (reusable by tickets, billing, CRM later): `ui/textarea`, `ui/native-select`, `ui/progress`, `ui/dropdown-menu`, `ui/form-dialog`, `ui/dialog-shell` (chrome shared by `ConfirmationDialog` and `FormDialog`), `components/pagination`, `lib/dates.ts` (date-only and timestamp formatting extracted from the Time page), `types/pagination.ts`, and the `test/inertia.tsx` test double. `components/time/timer-context-link.tsx` is shared between the Time module and the project pages that link to it.

**Module-specific** (`resources/js/components/projects/`, kebab-case files): `project-header`, `project-card`, `project-status-badge`, `priority-badge`, `member-rows-editor`, `board`, `board-column`, `task-card`, `quick-add-task`, `move-task-menu`, `board-moves.ts` (pure), `board-dnd.tsx`, `board-card-handle.tsx`, `board-announcements.ts`, `task-detail-form`, `checklist`, `comments`, `task-time-panel`, `milestone-list`, `milestone-dialog`, `task-table`.

**Pages:** `pages/projects/{index,create,edit,board}.tsx`, `pages/projects/tasks/show.tsx`, `pages/projects/milestones/index.tsx`, `pages/tasks/index.tsx`, each assigning `Page.layout = (page) => <AppLayout>{page}</AppLayout>` like the Time pages, each with a co-located `*.test.tsx`.

Rules: no component outside `components/projects/board-dnd.tsx` and `board-card-handle.tsx` imports `@dnd-kit`; pure logic (`board-moves.ts`, `board-announcements.ts`) has no React or library imports; presentation components receive DTOs and callbacks and do not call Wayfinder routes directly except page and form containers.

---

## 21. Blade and Inertia Coexistence

Unlike EPIC-011D (where a Blade page and a React page both drove one timer), each project/task URL is served by exactly **one** implementation at any moment: a controller action either returns `view()` or `Inertia::render()`. Coexistence therefore means *navigation between migrated and unmigrated pages* and *one embedded Blade widget*.

### Link rules

- A React page links to a still-Blade destination with a plain anchor (full document load). It **never** uses an Inertia `Link`/`router.visit` to a Blade route (Inertia would receive a non-Inertia response and show its error modal).
- The WP that migrates a destination replaces every plain anchor that points to it with `Link`.
- Blade pages keep normal anchors and use the `NavigationBuilder` for the shell.

### Navigation flags

- `NavigationBuilder`: `projects` item flips to `visit: 'inertia'` in WP3 (index becomes React); `tasks` item flips in WP8. `NavigationBuilderTest` assertions change with them.
- Dashboard quick action and metric link for projects flip in WP3.
- The timer bar and the Time page render context links by `type`: `Project` links become Inertia `Link` in WP5 (board), `Task` in WP7, `Ticket` stays a document link until EPIC-011F. Implemented as one small `TimerContextLink` map rather than scattered conditionals.

### Timer coexistence

- The React task page uses `useTimers()` from the persistent provider (EPIC-011D). It does **not** include the Blade `x-time-tracker`.
- The Blade `x-time-tracker` component, its `@once` script, and `timer-overlay.js` remain because `tickets/show.blade.php` still embeds them.
- Inertia→Blade and Blade→Inertia transitions reload/rehydrate exactly as in EPIC-011D.
- **Two 011D browser tests assume Blade project pages and are corrected (C4):**
  - `time-migration.spec.ts`, "multiple timers persist through Inertia and reconstruct across Blade documents", clicks *Projects* and asserts the Blade timer overlay (`#timer-overlay`, `.timer-clock`). After the WP3 flip `/projects` is React, so WP3 re-points that step to the **Tickets index**, a Blade page until EPIC-011F that needs no fixture.
  - "a timer started from the embedded Blade tracker is reconstructed by React" creates a project, adds a task through the Blade board, and opens the Blade task page; it stops being valid at WP5 and WP7. Tickets are the only surviving embed, but tickets have no delete route and `DevSeeder` seeds none, so creating one per run would leave undeletable fixtures (against the EPIC-011D hygiene rules). **Recommended resolution (WP7):** an idempotent `DevSeeder` fixture ticket (fixed title, `firstOrCreate`, owned by the seeded account the suite signs in as); the test starts and stops its timer there and cleanup deletes only the timer entries. **Alternative:** retire that one browser test and rely on a Pest test of the ticket-page embed plus the remaining multi-timer coexistence test.

### Mixed-state matrix

| After WP | Index/create/edit | Board | Task page | Milestones | `/tasks` |
|---|---|---|---|---|---|
| 1 | Blade | Blade | Blade | Blade | Blade |
| 3 | **React** | Blade | Blade | Blade | Blade |
| 4 | React | Blade | Blade | **React** | Blade |
| 5–6 | React | **React** | Blade | React | Blade |
| 7 | React | React | **React** | React | Blade |
| 8 | React | React | React | React | **React** |

---

## 22. Blade and Script Cleanup Plan

Each Blade view is deleted in the same work package that flips its controller action, so dead pages never linger.

| Delete when | File | Contained |
|---|---|---|
| WP3 | `views/projects/index.blade.php`, `create.blade.php`, `edit.blade.php` | Member-row `innerHTML` scripts (S1), nested form (S2), inline `onclick`/`onsubmit` |
| WP4 | `views/projects/milestones/index.blade.php` | Disclosure script, hand-rolled modal, inline confirm |
| WP5 | `views/projects/board.blade.php` | HTML5 drag/drop script and `.task-list.drag-over` `<style>` |
| WP7 | `views/projects/tasks/show.blade.php` | Checklist `fetch` + `location.reload()` |
| WP8 | `views/tasks/index.blade.php` | New-task disclosure |

After WP8 the directories `resources/views/projects` and `resources/views/tasks` are removed.

**Retained on purpose:** `resources/views/components/time-tracker.blade.php` (used by `tickets/show.blade.php` until EPIC-011F), `resources/js/timer-overlay.js`, and the `@stack('scripts')` in the Blade layout.

**Verification greps (WP9):** no `view('projects.`, `view('tasks.` in `app/`; no Blade view references `route('projects.`/`route('tasks.` except via retained shared partials; no `draggable="true"`, `dragstart`, or `dataTransfer` anywhere under `resources/`; the parent EPIC-011 inline-script inventory rows for `projects/board`, `projects/create`, `projects/edit`, `projects/milestones/index`, `projects/tasks/show`, and `tasks/index` are marked migrated. There are no new intentional Blade exceptions.

---

## 23. Testing Matrix

Existing baseline: 35 Pest tests, almost all acting as `operator`. They remain authoritative for behavior that does not change; HTML-fragment assertions (`assertSee('Visible')`) become `assertInertia`. The current JSON assertion on `tasks.move` (`assertJson(['ok' => true])`) changes in WP5 with the contract. **Characterization tests are written first (WP1) and only then flipped by the fix that changes the behavior.**

### Pest (authoritative)

| Area | Cases |
|---|---|
| **Actor-by-route authorization matrix** | One Pest dataset: every route in §2 × actors in §16 (guest, outsider, member, manager-role-without-permission, project manager, admin, non-member assignee). Structural task routes (store, update, destroy, move, checklist add, checklist remove): guest redirected, outsider/member/manager-role-without-permission/non-member assignee **403**, project manager and admin succeed. Comment and checklist toggle: member, project manager, admin succeed; outsider and non-member assignee 403. Page routes: member, manager, admin 200; outsider 403. `POST /tasks`: any authenticated user. Member-sync coverage is explicit under D7-B: only `projects.admin` succeeds; project manager, manager-role-without-permission, member, outsider, and non-member assignee are refused. `projects.store` remains available to a non-admin project manager when no extra members are supplied, but a forged non-empty `members` payload is refused. Authorization precedes 404 and the deletion guard. Authorization precedes 404 and the deletion guard |
| **Visibility equivalence (D2)** | Property-style test: for a mix of member, non-member, company-linked, and admin scenarios, every project on the index page passes `ProjectPolicy::view` and every project the policy allows appears; company-linked non-member projects **never** appear and never gain access; `/tasks` rows never carry a `url` that the destination policy denies; index props contain no `creator`, email, or unlisted fields; `ProjectPolicy` source behavior is unchanged (existing policy tests still pass) |
| **Member management (D7-B)** | `projects.admin` may sync members and receives `memberCandidates`; non-admin project managers may still create/edit projects but cannot sync members, change roles, or submit extra initial members. Their Inertia props omit `memberCandidates` entirely and existing membership contains name/role/owner only, with no email directory. Candidate IDs must exist; duplicate IDs and invalid roles are rejected; creator remains manager. Include an actor holding `projects.admin` without `projects.manage` to prove member-sync authorization is not accidentally coupled to the old route middleware (A9 on the edit page itself remains pinned) |
| **Deletion with time history (D4)** | (1) unreferenced task and project delete succeed; (2) direct project time blocks project deletion; (3) task time blocks task deletion; (4) task time blocks the parent project's deletion; (5) billed entries remain untouched (same `project_id`, `task_id`, `billed`, invoice link) after a blocked delete; (6) unbilled entries remain untouched; (7) a running timer also blocks; (8) the response is a validation error on `delete`, not a 500 or `QueryException`; (9) authorization is intact: outsider, member, and manager-role-without-permission get 403 before the guard runs and learn nothing; a manager of a different project gets 403; (10) FK backstop: `information_schema` shows `RESTRICT` on both `time_entries` FKs and a raw DB delete of a referenced project or task fails; (11) migration is reversible |
| **Task structural integrity** | Create in own column; **foreign column, foreign milestone, non-member assignee, non-member assignee on update: 422**; update/delete by every actor; 404 on project mismatch; title/description limits; ex-member assignee shown in options |
| **Move and ordering** | Cross-column, within column up/down, insert at 0/middle/end, position clamped; positions dense `0..n-1` after move, create, and delete; legacy gaps/duplicates normalize on first move; foreign column 422; foreign task 404; non-manager 403; deleted task/column 404; no-op idempotent |
| **Concurrency and staleness** | MariaDB stress test in the style of EPIC-011D's allocation harness: parallel moves within and across two columns yield dense positions, no duplicates, no lost cards, no deadlock; a stale client index after a concurrent insert stays valid; a move of a task deleted mid-request returns 404 cleanly |
| **Checklist authoring (D5)** | Add/remove: project manager and admin succeed; member, outsider, manager-role-without-permission 403; title required, trimmed, ≤255; 100-item cap; append position; item from another task 404 on remove and toggle. Toggle: member allowed (unchanged); `completed` supplied is idempotent (replay does not flip); omitted still toggles |
| **Comments** | Member, manager, admin can post; outsider and non-member assignee 403; body required and ≤5000; stored raw (no HTML processing); oldest first; DTO author is `{id,name}` only |
| **Status contract (§15)** | Characterize first, then fix: `isDone`/`effectiveStatus`/`isOverdue` per kind; a board move leaves `status` unchanged; null-column edge; `contextOptions` per kind (Done-column board task excluded after the fix; standalone and ticket tasks still governed by `status`); `Task::open()`/`done()` scopes agree with `isDone()`; `TaskStatusDto` `source` per kind; `/tasks` renders through the DTO |
| **Standalone tasks (D3)** | Route list contains no mutation route for standalone tasks beyond `tasks.store`; create accepts only assignee = actor or none; validation unchanged (status enum, priority, title); `mine`/`org` scoping and `tasks.view_org` gating unchanged; standalone rows have `url: null`; standalone task still selectable as a timer context when assigned and open |
| **Task time panel (D6)** | Member with `time.log`: own entries and own total only, other users' rows absent; project manager without `time.view_all`: same as member; `time.view_all` holder: all users with names and the all-user total; `time.view_all` without `time.log`: summary present, no controls; neither: `timeSummary` null; DTO has no description, billing/invoice flag, email, or user ID; running entries excluded; the summary matches the scope total |
| Assignment | Members only; null allowed; changed only by managers; `AccessibleTimeContext` unchanged for legitimate assignees |
| Milestones | View for members, 403 outsiders, write = manager only, validation, 404 on foreign project, `withCount` aggregate equals `completionPercentage()`, due-today is not overdue, delete nulls task milestone |
| Time and ticket coupling | Timer DTO includes `context.id`; timer start still enforces `AccessibleTimeContext`; ticket tasks appear in `/tasks?view=org` only for the actor's companies; ticket link null when `TicketPolicy` denies |
| **Blade defect regressions (WP1)** | Edit page HTML: every `<form>` is closed before the next opens (depth never exceeds 1), the update form contains exactly one `_method` input with value `PUT`, and the delete form is a sibling. Create/edit pages: a user named `<img src=x onerror=alert(1)>` or `</select><script>` appears **escaped** in the served source (server-rendered member template), and no client string-built `<option>` markup remains. Uses a stack-based check, **not** PHP 8.4 `Dom\HTMLDocument` (container is 8.3, C6). Blade board/task pages omit structural controls for non-managers |
| Query budgets | Board, index, milestones, and tasks index run a query count independent of row count (compare 3 vs 30 rows; small tolerance) |
| Inertia contracts | `assertInertia` component, prop shape, and *absence* of unlisted fields for all seven pages |
| Factories | `TaskChecklistItemFactory`, `TaskCommentFactory`; `ProjectFactory`/`TaskFactory` states (`inColumn`, `assignedTo`) |
| Audit | The read-only WP1 audit query returns the counts described in §16 without modifying data |

### Vitest and React Testing Library

Behavior, not markup snapshots:

| Area | Cases |
|---|---|
| `board-moves.ts` (pure) | Cross-column, same-column up/down, empty target, first/last, clamp, unknown IDs no-op, immutability |
| Board render | Columns, counts derived from state, cards, priority/overdue/assignee/milestone/checklist display, empty column, **non-manager: no handle, no Move button, no quick-add** |
| **Move menu** | Tab to Move, Enter opens, lists **every other column** plus Move up/down, ArrowDown, Enter selects → exact payload to a mocked `router`; up/down disabled at the ends; Escape closes and returns focus; focus restored to the moved card even after a failed move; same `requestMove` for menu and drag |
| **No focusable drag control** | The drag handle has no `tabindex >= 0`, no `role="button"`, no `aria-roledescription`, no `aria-describedby` to keyboard instructions, and is `aria-hidden` |
| Drag handler logic | Invoke `onDragOver/onDragEnd/onDragCancel` with synthetic events → preview descriptor updates, commit calls `requestMove`, cancel discards. **No pointer physics in jsdom.** dnd-kit types do not appear outside the two adapter files (lint-enforced) |
| Server reconciliation | Success replaces optimistic state; failure (422/403/404/500/offline) restores prior columns, shows the alert, reloads where specified; controls disabled while pending; a second move is refused; the board never holds a copy of `columns` (a test re-renders with new props and asserts the new props win) |
| Announcements | Live-region text for success, failure, and drag start/over/end |
| Quick-add | Open/close, one at a time, inline validation error, focus return, manager-only |
| Project forms | Administrator path: member rows add/remove, stable identity after middle-row removal, owner locked, duplicate exclusion, nested error mapping, no markup injection. Non-admin manager path: no member editor, no candidate list/email data, existing members rendered read-only. Edit sections submit independently; delete dialog shows the D4 error |
| Milestone dialog | Focus trap, Escape, focus return, error display, prefill on edit |
| Task detail | Edit form (manager-only) with milestone field; ex-member assignee option; delete dialog with the D4 error; member view has no edit/delete/add-remove controls but keeps comment and toggle |
| Comments | Submit, reset, focus, counter, literal rendering of `<script>` text |
| Checklist | Native checkbox semantics, optimistic toggle and rollback, progress semantics, manager add/remove with focus handling, non-manager sees toggle only |
| Time panel | Scope `own` vs `all` rendering, controls only with `logTime`, running detection by `context.id`, `timeSummary` reload when the timer disappears |
| Tasks page | Tabs as links with `aria-current`, standalone rows unlinked and without actions, unlinkable project/ticket rows plain text, form errors, pagination |
| Shared primitives | `textarea`, `native-select`, `progress`, `pagination`, `form-dialog`, `dropdown-menu` |

`npm run check` (wayfinder, typecheck, ESLint, Prettier, Vitest, build) must be green.

---

## 24. Playwright Critical Flows

Critical flows only, in `tests/Browser/projects-migration.spec.ts` (plus the WP1 Blade regressions in a temporary spec deleted with the Blade views). The suite runs against the shared development database, so fixtures follow the EPIC-011D hygiene rules: create through the real UI or HTTP endpoints, prefix names `E2E`, register the project with `E2eCleanup.trackProject` as soon as its URL is known, and delete through the application's own endpoints. **Because of D4, teardown must delete every time entry that references the fixture before deleting the project** (already the case: entries first, project second); a failed entry deletion now surfaces as a blocked project delete and is reported by the fixture.

1. **Index → board with a persistent timer.** Start a timer, open Projects and a board from the navigation, assert the running timer bar and elapsed time never reset, then follow Milestones and task links.
2. **Create project (React form).** As `projects.admin`, add a member row, submit, and land on the board with the flash message; a validation error renders next to the field. A non-admin project-manager path confirms project creation still works without member controls and that no candidate-user directory is present.
3. **Edit-page safety regression.** Change the name, click *Save changes*, assert the project still exists with the new name (guards S2). Delete via the dialog removes it; a project with recorded time shows the D4 error and remains. (WP1 runs the Blade form version first; WP3 re-runs it against React.)
4. **Mouse drag.** Drag a card by its handle to another column, reload, assert persistence and column counts; reorder within a column.
5. **Keyboard move.** Using only the keyboard: focus Move, choose a column, assert the card moved, focus returned, and the live-region text; move up/down within a column.
6. **Non-manager member.** A member without manager authority sees a read-only board (no Add task, handle, or Move), can open a task, post a comment, and toggle a checklist item.
7. **Mobile and touch.** At a phone viewport with touch: no document-level horizontal scroll, the board region scrolls, and the Move menu works with an adequate target size. Touch dragging via CDP touch events is included **only if stable**; otherwise it is a documented manual real-device check (iOS Safari, Android Chrome) in WP10.
8. **Task detail.** As a manager: edit fields including milestone, add a comment, **add checklist items through the UI (D5)**, toggle and remove one, start and stop a timer from the panel and see the own-time total update; a `time.view_all` account sees per-user rows.
9. **Mixed navigation.** React project page to a Blade page (Tickets) and back with the timer still running, plus the corrected 011D coexistence tests (§21).

Not automated end to end: every CRUD field, every validation rule, permission permutations (covered by Pest), the cross-browser matrix (WP10 manual pass).

**Environment (confirmed in WP0, 2026-09-21):** the host cannot run Playwright. Its Chromium headless shell fails to start because 13 shared libraries are missing (`libglib-2.0`, `libgobject-2.0`, `libgio-2.0`, `libatk-1.0`, `libatk-bridge-2.0`, `libXcomposite`, `libXdamage`, `libXfixes`, `libXrandr`, `libgbm`, `libxkbcommon`, `libasound`, `libatspi`) and there is no passwordless sudo. **The suite runs inside the `portal_app` container**, which has Playwright 1.63.0 and Chromium 153 installed:

```bash
docker exec -e PLAYWRIGHT_BASE_URL=http://nginx portal_app sh -c 'cd /var/www/app && npx playwright test <spec>'
```

The default `http://localhost:4242` base URL does not resolve inside the container, so the override is required. Verified by running an existing spec (`inertia-coexistence.spec.ts`, "mobile navigation") to a pass. Two practical consequences: files the container writes (for example `public/build`) are root-owned and cannot be deleted by the host user, so builds and their cleanup go through `docker exec`; and browser specs must be written to run with the container as the browser host.

---

## 25. Performance Considerations

| Area | Plan |
|---|---|
| Queries | Presenters use `with()` for `assignee:id,name`, `milestone:id,name`, and `withCount` for checklist totals/done, project task/done/overdue counts, member counts, and milestone task/done counts. Target: query count independent of row count on board, index, milestones, and tasks index (asserted, §23) |
| Board payload | Card DTOs only (no descriptions/comments/emails). Sizing check in WP10 with a seeded 500-task project. If a Done column becomes large in practice, cap it at the most recent N with a "show all" partial reload; not implemented now |
| Task list rows | One batched membership/ticket-visibility lookup for link computation, not one policy query per row |
| Moves | Row locks limited to two column rows; rewrite only positions that change; requests are single-flight |
| React rendering | `TaskCard` memoized with a **structural comparator** (task fields, not object identity, because Inertia clones props for optimistic callbacks; WP0 measured all cards re-rendering under a reference `memo` versus only the moved card under a structural one), stable callbacks, no virtualization (columns are small). Drag preview updates only the affected columns |
| Bundle | dnd-kit measured at ≈16.5 kB gzip for the symbols used (§9), isolated to the board chunk by `import.meta.glob` page splitting; WP10 compares the build report before/after and confirms no other page imports it |
| Navigation | `Link prefetch` on project cards and task links; not on Move menus or mutations |
| No polling | Board refreshes on mutation and navigation only |

---

## 26. Rollback and Coexistence Safety

- **Release granularity:** the epic merges and releases as one unit, like EPIC-011D. Work packages are commit checkpoints (WP5 without WP6 has no pointer drag and is not a release point).
- **One additive migration, no data migration.** The only schema change is the reversible D4 backstop (`time_entries.project_id/task_id` to `restrictOnDelete`, `down()` restores `nullOnDelete`); it changes no rows. Positions normalize lazily on the first move of an affected column. Reverting application code therefore needs no data rollback; reverting the migration is `migrate:rollback` of that one file. This supersedes the earlier "no schema changes" decision.
- **Stable routes.** URLs and route names are unchanged (only the two D5 checklist routes are added), so bookmarks, timer-bar links, dashboard links, and Wayfinder references survive a revert.
- **One WP, one flip, one deletion.** Each controller flip and its Blade deletion are in the same commit, so `git revert` of a WP restores a working Blade page.
- **WP1 is Blade-safe.** It tightens validation, authorization, ordering internals, and deletion, and it edits the Blade views only to (a) fix S1 and S2, (b) hide structural controls from non-managers so the interim UI never offers a refused action (C1), (c) show the deletion error, and (d) correct the company-link copy. The Blade contracts that React will replace (`move` returns `{ok:true}`, checklist toggle returns JSON) are unchanged until their React consumers land (WP5, WP7).
- **Timer safety:** Blade↔Inertia transitions are covered by the 011D coexistence tests, corrected as described in §21.
- **Fallback designs pre-agreed:** Inertia optimistic → focused `fetch` plus a single `pendingMove` descriptor (§8); dnd-kit → native HTML5 DnD plus the Move menu (§9). Both leave the accessible path, `applyMove`, and the single-flight guard untouched.

---

## 27. Out of Scope

Column creation/rename/reorder/delete and per-project workflows; task dependencies UI/validation; Gantt, burn-down, project dashboards, and reports; CSV/PDF export (EPIC-012); attachments, labels, rich text/Markdown; comment edit/delete, mentions, notifications, internal-only comments; watchers and subscriptions; live updates, polling, or real-time collaboration; bulk task operations; moving a task between projects; project templates; **project or task archiving semantics beyond the existing project `archived` status**; **any company-derived project access or new project-visibility rule (D2)**; **edit, delete, complete, comment, or checklist for standalone tasks (D3)**; **checklist groups, templates, ordering, due dates, assignees, nesting, and rename (D5)**; **a second time-report permission model (D6)**; **delegated member management or a tenant-derived member-eligibility model beyond D7-B**; saved views, search, and filters on the projects and tasks lists; new project or task fields; changing time-entry semantics; ticket-to-task creation; dropping `projects.client_id` or `tasks.status`; redesigning the permission catalogue or role seeding (including the now-inert `projects.view_org` for project rows); changing the ticket module's own list/detail visibility (EPIC-011F); enforcing or redefining the currently-unused `time.view_own` permission; TanStack/Redux/Zustand; SSR; changing EPIC-011D timer or allocation behavior beyond the additive `context.id` and the `contextOptions` fix.

---

## 28. Decision Register

### Locked owner decisions (Amendments 1–2, 2026-09-21)

| # | Decision | Where implemented |
|---|---|---|
| D1 | Structural project-task mutations (create, edit fields, delete, assign/reassign, milestone assign/remove, move, reorder, other position changes, checklist add/remove) require `ProjectPolicy::manage` (manager with `projects.manage`, or `projects.admin`). Members keep comments and checklist toggling. The server is authoritative; React abilities are display hints | §2, §11, §16, §23 |
| D2 | A company link is metadata and grants no project visibility. Index and `/tasks` queries never render links `ProjectPolicy` would deny. `ProjectPolicy` is not broadened. Company-derived access needs a future explicit product/permission design | §4 (A5), §16, §23 |
| D3 | Standalone tasks keep their current capability (create, list, timer context). No edit, delete, or complete. `/tasks` represents them accurately without expanding mutations | §15, §23 |
| D4 | A Project or Task referenced by any TimeEntry cannot be hard-deleted (task: task time; project: direct project time or any task's time). Billed and unbilled alike. Domain error, not a DB exception. EPIC-010C immutability stays. No new archiving | §16, §23 |
| D5 | Minimal checklist authoring (add, remove) for manager/admin; toggle keeps its current actors; native checkbox controls; no groups, templates, ordering, dates, assignees, nesting | §14, §23, §24 |
| D6 | Task time panel: own time to the viewer; all users' time only with `time.view_all`; project-manager status alone grants nothing; no new permission model | §5, §11, §23 |
| D7 | **Option B:** only `projects.admin` may add/remove members, change member roles, or provide extra initial members. Non-admin project managers receive no candidate-user directory and see membership read-only | §2, §5, §6, §16, §23, §29 |

### D7: Project member eligibility — resolved as option B

**Owner decision (Amendment 2): B.** Only `projects.admin` may add or remove project members, change project-member roles, or provide extra initial members during project creation until the product has a canonical project-member eligibility model.

**Why B is the current contract.** There is a trustworthy *organization membership* relationship (`organization_members`, operator-curated, the basis of the 010B tenant scopes), but **no relationship that defines a project-member pool**:

- `Project` has no organization; the only project↔organization path is the company link, which D2 declares metadata and must not gain authority through eligibility either.
- Staff and operators are not required to belong to any organization, so "users sharing an organization with the acting manager" would exclude valid org-less staff managers and still would not define which client users belong in a particular project.
- `organization_members.role` (`admin|member`) says nothing about project eligibility.
- No user lifecycle flag exists (no active, disabled, or deleted state), so for the administrator directory "eligible existing portal user" currently means an existing `users` row.
- Non-admin project managers exist only via custom roles or direct grants; the seeded `user` role lacks `projects.manage`.

**Rejected alternatives for this epic**

| Option | Why not selected |
|---|---|
| **A.** Admin adds any user; ordinary manager adds from a same-organization pool | The manager half would invent a new organization-derived eligibility rule, conflicts with org-less staff managers, and has no canonical project scope |
| **C.** Ordinary managers add any portal user | Exposes the global user directory and permits cross-tenant membership without a product rule |

**Implementation contract**

1. Add a dedicated membership-management authorization boundary (recommended `ProjectPolicy::manageMembers`) whose current rule is `projects.admin`.
2. `projects.members.sync` requires that ability. `ProjectPolicy::manage` alone is insufficient. The route must not also require `can:projects.manage`; membership authorization is intentionally `projects.admin`-specific.
3. `projects.store` remains available to actors allowed to create projects. The creator is attached automatically as manager. Extra submitted `members` are allowed only when the actor may manage membership; an unauthorized non-empty members payload is refused rather than ignored.
4. `memberCandidates` is computed and serialized **only** when the actor may manage membership. It is omitted entirely from all other page props. The admin candidate DTO is minimal `{id,name,email}`.
5. Existing members are rendered to non-admin project managers as a read-only minimal `{id,name,role,isOwner}` list, with no candidate emails or add/remove/role controls.
6. Administrators may choose any existing user row. D7 does not invent active/disabled semantics that the user model does not have.
7. Project-task assignee eligibility remains project-members-only and is unchanged by D7.
8. Existing membership continues to govern `ProjectPolicy::view`; D7 changes who may edit membership, not who may view once already a member.
9. Delegated project-member management is deferred until a future design defines a canonical eligibility pool. Organization membership and company links are not repurposed for that purpose.

**Privacy requirement:** tests must prove a non-admin project manager cannot obtain the candidate-user directory by inspecting the Inertia payload, not merely that the React controls are hidden.

### Decisions taken in this plan (override only with a reason)

| # | Decision |
|---|---|
| T1 | Moves preserve the exact insertion index; the menu's "Move to column" appends (the Blade board's effective behavior) |
| T2 | The Move menu is the canonical accessible path; pointer drag is an enhancement with no focusable drag control |
| T3 | Optimistic moves via Inertia with single-flight blocking, **confirmed by the WP0 spike (8 of 8, unchanged)**; fallback documented only as a contingency (§8) |
| T4 | Drag is handle-activated, pointer-only, with no `KeyboardSensor` |
| T5 | Milestone becomes selectable in the task edit form (manager-only) |
| T6 | One overdue rule: due date strictly before today |
| T7 | **Revised:** one additive reversible FK-hardening migration for D4 (C3); no other schema change, no feature flag |
| T8 | Board-task status comes from the column; `tasks.status` is never dual-written or read for board tasks (§15) |
| T9 | Checklist toggle becomes idempotent (`completed` supplied); old toggle behavior remains when omitted |
| T10 | Structural task routes use the policy only, no `can:projects.manage` middleware; A9 is pinned, not changed |
| T11 | Corrected E2E plan: multi-timer test re-pointed to the Tickets index in WP3; embedded-tracker test moves to an idempotent seeded fixture ticket in WP7 (§21) |

---

## 29. Ordered Implementation Work Packages

**Backend hardening (WP1) precedes every React route flip.** The frontend migration must not mask backend correctness issues.

### WP0: Branch gate, environment, and spikes
**Status: complete (2026-09-21).** Results are recorded in Amendment 3, §8, §9, and §24.
- **No owner decisions remain open.** D1 through D7 are locked; D7-B is the membership contract in Amendment 2.
- Planning documentation is committed and the implementation runs on the dedicated branch `epic-011e-projects-kanban`, created from `dc06003` plus the Amendment 2 documentation commit `1b4d9fb`.
- Playwright environment confirmed: it runs inside `portal_app` (§24).
- **Optimistic-move spike:** executed against the installed `@inertiajs/core`/`react` 3.7.1 and the real endpoint. **All eight items passed; the design is unchanged and no fallback was selected.**
- **dnd-kit spike:** executed on React 19.3.0 and Vite 8.1.0. Passed. The packages were installed only in `node_modules` for the spike and removed; the real install remains WP6.
- All spike code, fixtures, temporary modules, and the throwaway branch were removed; the working tree was verified clean.
**Exit (met):** branch and base are correct; spike results and the fallback decision are written into §8 and §9.

### WP1: Characterize and harden the backend (Blade still works)
**Status: complete (2026-09-21).** Results, deviations and observations are recorded in [Amendment 4](#amendment-4-wp1-results-2026-09-21). Not committed. The D4 foreign-key migration has been applied to the development database and verified; production still needs the audit-then-migrate sequence described in W15.
Tests first (characterization, then the failing tests for each defect), then fixes, in this order of risk:
1. Characterization suite: actor-by-route matrix against *current* behavior, status contract (§15), `contextOptions`, current member sync, current index/policy mismatch, A9, edit-page structure.
2. **Cross-project column injection** (A1), **foreign milestone IDs** (A2), **foreign/ineligible assignee IDs** (A3): project-scoped validation on create, update, move, and standalone create (assignee is the actor or none).
3. **Task mutation authorization** (D1): `manage` on structural routes; `view` retained for comment and checklist toggle. Blade hides Add task, drag, and the edit form from non-managers.
4. **List/policy visibility mismatch** (D2): remove the company branch from the index; `Project::visibleTo`; intersect the `/tasks` org tab with policy-visible projects; no denied links; correct the create/edit copy. `ProjectPolicy` untouched.
5. **Ordering transaction and locking** (I1, I2): `ProjectService::moveTask`, create, and delete under column locks with dense positions; concurrency stress on MariaDB. `move` still returns `{ok:true}`.
6. **Project/task deletion with TimeEntry provenance** (D4): service guard, validation error, Blade shows the error, plus the reversible FK `restrictOnDelete` migration and its tests.
7. **Overdue-rule inconsistency** (I6) and **done-column timer-target behavior** (I3): kind-aware `Task::open()`/`done()`, `contextOptions` fix.
8. **Nested project edit/delete form** (S2): attempt reproduction against the legacy Blade page in Playwright before changing it. If reproduced, pin the destructive behavior; if not, record the parser finding as potential only. Either way, move the delete form outside the update form and add the PHP-8.3-compatible Pest structural test.
9. **Member-name `innerHTML` XSS candidate** (S1): server-rendered escaped `<template>` member row, no client string-built markup, Pest escaping test and a hostile-name Playwright check.
10. **Membership management and foreign member IDs** (A6, D7-B): add the administrator-only membership authorization boundary; restrict `projects.members.sync` and initial extra members accordingly; serialize `memberCandidates` only for administrators; validate candidate existence, de-duplicate IDs, validate roles, and keep creator as manager. Add Inertia-prop privacy tests for non-admin managers.
11. Checklist add/remove endpoints, idempotent `completed` on toggle, `TaskChecklistItemFactory`/`TaskCommentFactory` (D5).
12. Blade D6 patch for the task-context branch of `x-time-tracker` (own time unless `time.view_all`); ticket context untouched.
13. **Relevant query-count regressions** (P1 to P3): eager loading and aggregate counts; query-budget tests.
14. Read-only audit query (§16).
**Exit (met):** all new and existing backend tests pass on MariaDB; Blade pages work and offer no action the server refuses; concurrency stress green; no React change.

### WP2: Shared frontend foundation
**Status: complete (2026-09-21).** Not committed. Nothing in WP3 onward was started, no route or page was migrated, and no dnd-kit package was installed.
`types/pagination.ts`, `lib/dates.ts`, pagination extraction (Time page refactored with its tests unchanged), `textarea`, `native-select`, `progress`, `dropdown-menu`, `form-dialog`, `TimerContextLink`, additive `context.id` on the timer DTO and TS type, Inertia test helpers.
**Exit (met):** `npm run check` green (Vitest 112, was 73; existing Time, Profile, layout and timer-bar tests unchanged and passing); full Pest 737 (735 plus 2 for `context.id`); Pint clean; the Time, coexistence and auth browser specs pass in Chromium; Time page behavior unchanged.

Where the result differs from, or adds to, the plan:

| Item | Outcome |
|---|---|
| `ui/dialog-shell.tsx` (not in the plan) | The chrome the plan says `FormDialog` shares with `ConfirmationDialog` (overlay, panel, title, description, close button) is one small component both now render, instead of two copies. `ConfirmationDialog` is otherwise unchanged; the panel gained `max-h` and `overflow-y-auto` so a tall form cannot run off a phone screen |
| `FormDialog` focus return | **Radix returns focus only to its own `Dialog.Trigger`**; a dialog opened through controlled state (the milestone Edit button in WP4) has none, so closing dropped focus onto `<body>`. Found by the focus-return test, fixed in `FormDialog` (it remembers the opener at open time and refocuses it on close; skipped when the caller passes `trigger`). `FormDialog` also calls `preventDefault` and `stopPropagation` on submit so a dialog rendered inside another form cannot submit it through React's portal event bubbling. API: `open`, `onOpenChange`, `title`, `description`, `submitLabel`, `onSubmit`, `processing`, optional `trigger`; fields are children, the footer (Cancel, submit) is built in |
| `lib/dates.ts` | `formatDate` (date-only, UTC-anchored, the Time page's former `displayDate`) and `formatTimestamp` (instant, viewer locale; the Profile page's session time now uses it). Malformed input is returned unchanged rather than throwing during render. Verified under `TZ=America/Los_Angeles` and `Pacific/Kiritimati` |
| `Pagination` | Takes the paginator (`<Pagination paginator={entries} />`) and now renders inside `<nav aria-label="Pagination">`. **Used by both the Time page and the operator time report** (the plan named one), so the duplicate markup is gone from both |
| `NativeSelect` | Adopted at all six existing raw selects (Time filter, operator report x3, both `ContextSelector` selects); stacked forms pass `className="w-full"`, filter rows size to content as before. Gains the focus ring `Input` already had |
| `dropdown-menu` | Wrapper exports `DropdownMenu`, `Trigger`, `Content` (portal included), `Item`, `Label`, `Separator`, `Group`. The persistent layout's user menu now uses it (its tests unchanged), so the wrapper has a second consumer and no duplicated class strings before the Move menu arrives |
| `TimerContextLink` | `contextLinkModes` (`project`, `task`, `ticket`, all `'document'` today) is the one edit point for the WP5 and WP7 flips; `ticket` stays `'document'` until EPIC-011F. Adopted by the timer bar and the Time page context column. It accepts the timer DTO's `'Project'` and the entry DTO's `'project'`. A destination with no `url` renders as text |
| `context.id` (backend) | Added to all three branches of `TimeEntryController::buildContextPayload`, so it appears on timer start, active-timer list and allocation entries. `TimerContext.id: number` in TS. Additive; `timer-overlay.js` ignores it. Two Pest tests |
| Inertia test helper | `resources/js/test/inertia.tsx`: one `vi.mock` factory (`inertiaReactMock`) providing `Head`, `Link` (Inertia-only props never reach the DOM), `router` spies, a state-backed `useForm` (all three setter shapes, `reset`, configurable errors and processing) and `usePage`, plus `setFormErrors`, `setFormProcessing`, `setPageProps`, `resetInertiaMock`. It has its own test. Existing test files keep their local mocks (they were deliberately left untouched); WP3 onward use the helper |
| **Test finding for WP5** | Under jsdom, Radix `DropdownMenu` opens on *pointer* interaction only for the first test in a file; later tests do not open. Opening by keyboard (Tab, Enter) is reliable and is the canonical path (§10), so **menu tests must open by keyboard**. Keyboard opening focuses the first item and arrow keys skip disabled items, which the Move menu's disabled Move up/down at the ends relies on |
| Deliberately not added | No project or task DTO modules (`types/projects.ts` and the ability types in §5): the presenters and pages that use them arrive in WP3 to WP8, and shared types with no consumer would be speculative. `types/pagination.ts` is exported from `types/index.ts` |

### WP3: Projects index, create, edit
**Status: complete (2026-09-22).** Not committed. No route or page outside index/create/edit was touched; no `dnd-kit` package was installed.
Presenters, `projects/index|create|edit`, administrator-only member-rows editor (D7-B), read-only member list for non-admin managers, three-form edit page (members form present only for administrators), delete dialog with the D4 error, informational company copy, `NavigationBuilder` and dashboard link flips, Pest `assertInertia` conversions, Vitest, delete three Blade views. **Re-point the 011D multi-timer test's *Projects* step to the Tickets index** (§21).
**Exit (met):** S1/S2 gone in React with browser regression (`ProjectInertiaPagesTest`, Vitest hostile-name coverage, and the `projects-migration.spec.ts` browser flow); Playwright flows 2 and 3 pass (index→create, create as admin and as non-admin manager, edit-page safety regression, admin membership editing, non-admin read-only membership, unreferenced delete, D4-blocked delete, mobile). Full Pest 763 (737 plus 26 net new), Pint clean, `npm run check` green (Vitest 171, was 112), full Playwright 16/16 in `portal_app`, `git diff --check` clean.

Where the result differs from, or adds to, the plan:

| Item | Outcome |
|---|---|
| `app/Http/Presenters/ProjectPresenter.php` (new) | `card()`, `detail()`, `members()`, `memberCandidates()`, `companyOptions()`, `linkedCompanyIds()`. Matches §5's DTO shapes exactly; no `toArray()` of a model reaches a page |
| `abilities.create` (index) and `abilities.editMembers` (create/edit) | Computed from what the route actually admits (`Gate::allows(...)` **and**, for `create`, `$user->can('projects.manage')`), the same A9 discipline WP1 established for `abilities.openSettings`. A `projects.admin`-only actor sees no *New project* link and gets no dead link |
| `projects.store` response contract | An Inertia (`X-Inertia`) request that succeeds gets `Inertia::location()` (409 with `X-Inertia-Location`), a full-page visit to the still-Blade board, because the board is not yet a React page (§21); a plain (non-Inertia) request keeps the ordinary redirect. `session()->flash('success', ...)` is set before either path, so the board's existing Blade flash rendering shows it unchanged. This becomes an ordinary redirect once WP5 lands |
| Three independent edit forms | `updateProject`, `syncCompanies`, `syncMembers`, `deleteProject` error bags, one per endpoint, as planned. `errors` is namespaced per bag in the shared Inertia prop; confirmed by a dedicated Pest test that submits all three plus a delete and checks each error stays in its own bag |
| `ui/dialog-shell.tsx` gained `onOpenAutoFocus`/`onCloseAutoFocus` passthroughs (not in the WP2 plan) | `FormDialog`'s own focus-return fix (WP2) needed them; `ConfirmationDialog` also gained an optional `error` prop so the D4 refusal renders inside the open delete dialog rather than as a separate page-level alert |
| `MemberRowsEditor` accessible name (found by the Playwright suite, not Vitest) | The Role select's label mixed an `aria-hidden` visible span with a `sr-only` full-text span; jsdom (Vitest/RTL) correctly excludes the hidden span from the computed name, but real Chromium does not, producing a duplicated name ("RoleRole for member 1") that also collided with `getByLabel('Member 1')`. Fixed with a plain visible "Role" label plus an explicit `aria-label` on the `<select>`, which is deterministic in every browser. This is exactly the class of defect §24 (browser flows) exists to catch that a jsdom suite cannot |
| Test double (`test/inertia.tsx`, WP2) gained `transform()`, per-submission recorded `data` (post-transform), and a working `clearErrors()` | Needed by the create/edit forms (`transform()` strips client-only member-row keys before submit) and by the delete dialog's clear-on-close behavior; covered by new tests on the double itself |
| `E2eCleanup.trackProject` (browser test support) | Changed from a single `projectId` to a `Set`, since WP3's flows create more than one project per test (one per actor). Same method signature, same teardown order (time entries, then projects) |
| 011D coexistence, `time-migration.spec.ts` | "multiple timers... reconstruct across Blade documents": the *Projects* nav step now goes to *Tickets* (still Blade until EPIC-011F), exactly as §21/§29 direct. "a timer started from the embedded Blade tracker...": not reassigned until WP7, but its *setup* step (create a throwaway project) used the old Blade label/button text; updated to the new React page so the rest of that still-Blade-board/task-page journey keeps working until WP7 replaces the whole test |
| `wp1-blade-regressions.spec.ts` trimmed | S1, S2 and the project half of D4 removed (superseded by `ProjectInertiaPagesTest`, the Vitest hostile-name/S2-equivalent coverage, and `projects-migration.spec.ts`); only the D1 read-only-board test remains (still exercises the unmigrated Blade board), with its setup updated to the React create/edit pages |
| Blade-assertion Pest tests converted | `ProjectManagementTest`, `ProjectVisibilityTest`, `ProjectDashboardCountTest`, `ProjectMemberManagementTest` read `viewData('page')['props']` instead of `viewData('projects')`/`assertSee`, now that those routes return `Inertia::render()` |
| `InertiaFoundationTest` | Its Blade-coexistence example switched from `projects.index` (now Inertia) to `tickets.index` (still Blade) |
| Deliberately not added | No `board`, `milestones`, `tasks/show`, or `tasks/index` page; no `dnd-kit`; no board/task presenter or DTO |

### WP4: Milestones
**Status: complete (2026-09-22).** Not committed. No other page (board, task detail, `/tasks`) was touched; no `dnd-kit` package was installed.
`projects/milestones/index`, dialog for create/edit, delete confirmation, aggregate counts, delete Blade view.
**Exit (met):** errors visible in the dialog; focus-managed dialog (opens with the target's values, closes and returns focus to its own opener, confirmed for both a dismiss gesture and a successful save); counts equal `completionPercentage()` (pinned by `ProjectQueryBudgetTest`, unchanged from WP1).

Where the result differs from, or adds to, the plan:

| Item | Outcome |
|---|---|
| Task↔milestone assignment: **not WP4**. | §12 itself says the milestone page "still shows counts only; listing a milestone's tasks is a new feature (out of scope)," and Amendment 4 (W1, on the Blade characterization) is explicit that "I4 is WP7 in React." WP4 implements no task list, no assignment control, and no task-scoped candidate lookup on this page; `milestone_id` selection stays where WP1 left it (validated `projects.tasks.*` endpoints), to be surfaced by WP7's task edit form |
| `app/Http/Presenters/ProjectMilestonePresenter.php` (new) | One method, `item()`, requires a milestone already loaded through `ProjectMilestone::scopeWithTaskCounts()` (WP1); computes `completion`/`overdue` from the model's own `completionFromCounts()`/`isOverdueAt()` so the page never re-derives the rule, and a Pest test pins presenter output equal to those methods |
| `abilities.manage` (A9) | Computed as `Gate::allows('manage', $project) && $user->can('projects.manage')`, the same discipline WP3 established for `abilities.create`/`abilities.openSettings`: the milestone mutation routes require `projects.manage` (`ProjectPinnedBehaviorTest`'s existing A9 case), which the bare `manage` policy does not, so a `projects.admin`-only actor is not offered New Milestone/Edit/Delete only to have them 403 |
| `store`/`update`/`destroy` responses | Plain `redirect()->route('projects.milestones.index', ...)`, unchanged from before: unlike WP3's `projects.store`, every milestone route's destination is *already* a React page, so no `Inertia::location()` full-page-visit workaround is needed here |
| `MilestoneFormDialog` (new) | One dialog handles both create and edit, stays mounted for the page's lifetime (never conditionally torn down on close), and resyncs its fields from the target milestone via an effect keyed on the open transition, rather than a fresh component instance per row. Two things were found and fixed while building it, both recorded below |
| **Finding 1 (component bug, fixed):** `autoFocus` conflicts with `FormDialog`'s WP2 focus-return contract | An `autoFocus` field inside a `FormDialog` breaks `onCloseAutoFocus`'s restore-to-opener logic in a way reproducible in a minimal harness against the **unmodified** WP2 `FormDialog` (confirmed via `git diff` showing no change to that file). Fixed locally by not using `autoFocus` on the milestone dialog's Name field, matching the existing WP2/WP3 precedent (neither uses it either); `FormDialog` itself is untouched. Flagged for whoever next needs initial-field focus in a dialog |
| **Finding 2 (jsdom-only, not a real bug):** a dialog closed by a callback invoked outside React's synthetic event system (exactly how Inertia's real `onSuccess` fires) does not reliably show the restored focus in jsdom | Reproduced against the unmodified `FormDialog` in isolation; the dismiss-gesture path (Escape, Cancel) is unaffected. Verified **not to reproduce in a real browser**: the Playwright flow explicitly asserts focus returns to the Edit button after a real successful save, and it passes. The one affected Vitest assertion was adjusted to check the functional outcome (dialog closes) and documents why, rather than asserting on a jsdom-specific gap |
| Deliberately not added | No task list or task badge beyond the two counts; no milestone owners, budgets, colors, or dependency graph; no `MilestoneProgress` sub-component (the progress bar is a few lines inline in `MilestoneCard`, used once per card, not worth a separate file per the "used once, keep it local" rule) |

### WP5: Board without drag
**Status: complete (2026-09-22).** Results, deviations and observations are recorded in [Amendment 5](#amendment-5-wp5-results-2026-09-22). Not committed. No `dnd-kit` package was installed; task detail, `/tasks`, and tickets remain Blade.
`projects/board`, `BoardColumn`/`TaskCard`, manager-only quick-add and Move menu, `board-moves.ts`, the move flow chosen by WP0, single flight, failure handling, live region, focus restoration, `move` contract switch, `TimerContextLink` flip for Project, delete Blade board.
**Exit (met):** a fully keyboard-operable, touch-usable board; read-only for non-managers; Vitest menu/reconcile/no-focusable-drag suites and Playwright flows 1, 5, and 6 pass (plus additional WP5 flows beyond the minimum).

### WP6: Drag-and-drop layer
**Status: complete (2026-09-22).** Results, deviations (notably the removal of the drag-time board projection after a real-browser render-loop crash) and observations are recorded in [Amendment 7](#amendment-7-wp6-results-2026-09-22). Not committed. No PHP file touched; task detail, `/tasks`, and tickets remain Blade.
`@dnd-kit/core` 6.3.1, `@dnd-kit/sortable` 10.0.0, `@dnd-kit/utilities` 3.2.2 installed with `--save-exact`, matching WP0 exactly; `board-dnd.tsx` and `board-card-handle.tsx` are the only importers, enforced by a `no-restricted-imports` ESLint rule; library-agnostic adapter props (`onMove(taskId,toColumnId,toIndex,taskTitle)`, a render-prop `children`) so **no dnd-kit types leak into domain components**; silenced `announcements`/`screenReaderInstructions`, `DragOverlay`, reduced-motion, no `KeyboardSensor`.
**Exit (met):** Playwright flow 4 (`board-drag.spec.ts`) passes, 14/14, confirmed twice; touch behavior is exercised directly (not merely documented) per flow 7, with the viewport note in Amendment 7 Y9; the Move menu path is unaffected (its full WP5/Amendment-6 suite passes unmodified); no focusable drag control (pinned by both Vitest and a real-browser handle-only Playwright flow).

### WP7: Task detail
**Status: complete (2026-09-22).** Results, deviations (notably the pre-existing `destroy()` redirect defect found and fixed) and observations are recorded in [Amendment 8](#amendment-8-wp7-results-2026-09-22). Not committed. No `dnd-kit`/state-library dependency added; `/tasks` and tickets remain Blade.
`projects/tasks/show`: manager edit (milestone, ex-member assignee), delete with the D4 error, comments, checklist with authoring (D5), the D6 time panel; checklist toggle switches to the redirect contract; `TimerContextLink` flip for Task; delete Blade task view. The idempotent fixture ticket was added to `DevSeeder` and the embedded-tracker E2E moved onto it (§21).
**Exit (met):** Playwright flow 8 passes (`tests/Browser/task-detail-migration.spec.ts`, 4/4); project pages no longer use `x-time-tracker`.

### WP8: Unified task list
**Status: complete (2026-09-22).** Results, deviations (notably the ticket eager-load regression caught and fixed, AA1–AA10) and observations are recorded in [Amendment 9](#amendment-9-wp8-results-2026-09-22). Not committed. No `dnd-kit`/state-library dependency added.
`tasks/index`: tabs as links, D2-safe links, D3 behavior (standalone unlinked, no actions), kind-aware status DTO, form errors, pagination; `tasks` nav flip; both Blade view directories removed.
**Exit (met):** no dead org links; standalone surface unchanged; Pest (822 passed) and Vitest (400/400) green; `tests/Browser/tasks-migration.spec.ts` 5/5, confirmed twice.

### WP9: Documentation reconciliation and test consolidation
**Status: complete (2026-09-22).** Results are recorded in [Amendment 10](#amendment-10-wp9-results-2026-09-22). Not committed. No runtime application code, route, controller, service, model, migration, or dependency changed.

The code-level Blade/JS cleanup §22 originally assigned to WP9 was already carried out incrementally by WP3–WP8 (each phase deleted its own Blade view in the work package that flipped its controller action); a read-only audit preceding this work package confirmed the §22 verification greps already pass against the live repository. WP9 itself was therefore documentation reconciliation and one test consolidation: the parent EPIC-011 inventory/coverage tables marked migrated; an ADR-007 note on the Kanban drag layer; corrective notes for the D2 change (company link is visibility metadata only) in `EPIC-010B`, `ADR-005`, `rbac-design.md`, and `database-schema.md`; a supersession note on EPIC-005's STORY-005-02 pointing at D1; `time.view_own` recorded as unenforced permission-model debt in `rbac-design.md`; `docs/epics/README.md` and this document's own status line moved off `Planned`; and `ProjectBladeRegressionTest.php` retired in favor of its stronger Inertia-era replacements, with its one still-load-bearing Ticket assertion relocated to `tests/Feature/Tickets/TicketEmbeddedTimeTrackerTest.php`.
**Exit (met):** the §22 verification greps pass; no document still describes a project's linked CRM company as a visibility grant; full Pest, Vitest, Pint, and build gates green; `ProjectBladeRegressionTest.php`'s three assertions are each either superseded by a named stronger test or relocated, never merely dropped.

### WP10: Hardening and verification
**Status: complete (2026-09-23).** Results are recorded in [Amendment 11](#amendment-11-wp10-results-and-verification-closeout-2026-09-22-to-2026-09-23). No route, controller, service, model, policy, migration, presenter, or dependency changed.

Full Playwright suite including mixed navigation; mobile and responsive pass; keyboard and screen-reader walkthrough (NVDA or VoiceOver) of board, menu, dialogs, checklist; real-device touch check; performance measurements (§25); full gates. Status moves to Implemented, then Verified, per the lifecycle.

**Done:** acceptance-contract matrix; independent authorization/IDOR review including forged cross-project and plain-member requests in a real browser; privacy/DTO review of all seven presenters; board state/concurrency and dnd-kit boundary review; Move-menu/drag parity for every direction; keyboard-only and accessibility-tree walkthrough; focus/dialog closeout; 390 px responsive pass; light/dark theme pass; navigation coexistence and timer continuity across the React↔Blade boundary; task-kind review; query-budget and frontend-performance review; data-integrity and migration-readiness review; cleanup and documentation consistency; all automated gates (Pest 820, Vitest 378, Pint, build, `git diff --check`, Playwright 46/46 at 2 workers). Three accessibility defects (V1–V3) and one browser-test race (V4) were found and fixed; four candidate findings were investigated and disproved.

**Manual accessibility:** the agent pass could run neither the screen-reader nor the real-device check (no screen reader on the WSL2 host or in the container; no physical device), and said so rather than substituting for them. The owner then ran a real **NVDA smoke walkthrough on Windows** (2026-09-23): landmarks, navigation and control states announced coherently, no obvious missing or misleading semantics, no blocking issue. That is a smoke test, not an exhaustive certification. **Exit met on the owner's decision** to accept it for this stage and to **defer** device-matrix and deeper assistive-technology testing to final platform-level QA. See Amendment 11, "Manual accessibility verification".

---

## 30. Acceptance Criteria

Checked boxes were confirmed by the WP10 verification pass ([Amendment 11](#amendment-11-wp10-results-and-verification-closeout-2026-09-22-to-2026-09-23)); the method is named per item. The two unchecked items are blocked by the environment, not by the implementation.

**Scope and cleanup**
- [x] All seven pages in §2 are Inertia/React pages; `resources/views/projects` and `resources/views/tasks` no longer exist — *code inspection + one `assertInertia` component test per route*
- [x] No project/task inline script, `draggable`, `dataTransfer`, or `location.reload()` remains; `x-time-tracker` and `timer-overlay.js` remain only for tickets — *WP10 cleanup greps; the two surviving `location.reload()` calls are EPIC-011D 401/419 session-expiry recovery*
- [x] Every URL uses Wayfinder; route names and URIs unchanged apart from the two D5 checklist routes — *code inspection; `wayfinder:generate` runs clean in `npm run check`*

**Locked decisions**
- [x] D1: every structural task mutation is refused for non-managers by the server, proven by the actor-by-route matrix; members retain comments and checklist toggling; the board is read-only for them — *`ProjectAuthorizationMatrixTest` (25 routes × 8 actors) + real-browser forged requests: move 403, delete 403; member board has no Add/Move/Settings/edit/delete but keeps comments*
- [x] D2: the project index equals the set `ProjectPolicy::view` allows; no rendered link is one the destination policy denies; `ProjectPolicy` unchanged; no document describes company link as access — *`ProjectVisibilityTest` + documentation review of EPIC-010B, ADR-005, `rbac-design.md`, `database-schema.md`*
- [x] D1 documentation: EPIC-005 no longer states that every project member may manage tasks; current documentation reflects manager-only structural mutation — *STORY-005-02 current-state note verified in place*
- [x] D3: `/tasks` shows standalone tasks accurately with no new mutation route or control — *`TaskListInertiaTest`, `tasks-migration.spec.ts`, route-surface inspection*
- [x] D4: all seven required deletion tests pass; historical time entries are never nulled or modified; the FK backstop is in place and reversible — *`ProjectDeletionGuardTest`, `TimeEntryForeignKeyMigrationTest`; migration `down()` restores `SET NULL`*
- [x] D5: manager/admin can add and remove checklist items; members can toggle; native checkboxes; backend, Vitest, and Playwright coverage — *plus the WP10 accessibility fixes V1–V3*
- [x] D6: the time panel shows own time to the viewer and all users' time only with `time.view_all` — *`ProjectTaskDetailInertiaTest` per actor; browser-confirmed that a member sees no other user's name*
- [x] D7-B: only `projects.admin` can add/remove members, change member roles, or provide extra initial members; non-admin project managers receive no candidate-user directory/email data and can still create/edit projects without membership controls — *`ProjectMemberManagementTest`; forged `projects.members.sync` by a member returns 403; `memberCandidates` prop absent, not empty*

**Defects**
- [x] A1 to A3: foreign column, milestone, and assignee IDs rejected on every write path with tests — *`ProjectIntegrityTest` + real-browser probes: foreign column 422 on both move and create, unknown milestone 422, non-member assignee 422, foreign checklist item 404, cross-project task 404*
- [x] S1 and S2 fixed in Blade (WP1) and covered by regression tests, and absent in React — *the Blade views no longer exist; `ProjectInertiaPagesTest` and Vitest hostile-name coverage*
- [x] I1 to I3, I6: moves locked and dense, concurrency stress green on MariaDB, Done-column tasks not offered to the timer, uniform overdue rule — *`ProjectMoveConcurrencyTest` passes in the full suite; `ProjectIntegrityTest` "offers timer context by kind and never a Done-column board task"*
- [x] I4, I5, I8: milestone assignable, checklist usable, ex-member assignee preserved — *`ProjectTaskDetailInertiaTest`, `task-detail-migration.spec.ts`*
- [x] P1 to P3: query counts independent of row count — *`ProjectQueryBudgetTest` across all five pages*

**Board and accessibility**
- [x] Tasks move across and within columns by keyboard through the Move menu alone (every other column, up, down), with focus return and live-region announcements (WP5) — *re-verified in WP10 for every direction, including empty-column, append, Done and disabled no-op ends*
- [x] The drag handle is not focusable and exposes no keyboard instructions (WP6) — *`aria-hidden="true"`, `tabIndex={-1}`, `attributes` never spread; a 45-press Tab sweep never entered an `aria-hidden` subtree*
- [x] Pointer drag works by handle and persists after reload; touch users can move tasks (menu required) (WP6) — *pointer drag, persistence and CDP touch emulation all pass (`board-drag.spec.ts` 14/14), and the Move menu completes a full move at 390 px. **Real-device confirmation (iOS Safari, Android Chrome) was not performed and is deferred to final platform-level QA** by owner decision (Amendment 11)*
- [x] Failed, forbidden, stale, and offline moves revert with a visible alert; no move can be issued while another is pending (WP5)
- [x] The optimistic-move spike results are recorded and the chosen design matches them (WP0/WP5)
- [x] Column counts, progress, and overdue indicators always reflect the current state (WP5)
- [x] Screen-reader walkthrough (NVDA or VoiceOver) of board, menu, dialogs and checklist — *owner-run **NVDA smoke walkthrough** on Windows, 2026-09-23: landmarks, navigation and control states announced coherently, no obvious missing or misleading semantics, no blocking issue. A smoke test, **not** an exhaustive certification; the agent pass contributed keyboard-only and accessibility-tree verification, reported as exactly that. Deeper assistive-technology matrix testing is deferred to final platform-level QA (Amendment 11)*

**Quality gates**
- [x] Pest: new suites plus the converted baseline pass on MariaDB — *820 passed, 3257 assertions*
- [x] `npm run check` passes (wayfinder, typecheck, ESLint, Prettier, Vitest, build) — *exit 0; Vitest 378/378 across 54 files*
- [x] Playwright flows 1 to 9 pass in the agreed environment; test data is cleaned up; the corrected 011D coexistence tests pass — *46/46 at 2 workers; the only residue is the documented D3/AA10 standalone-task row, one per run, which no route can delete*
- [x] `@dnd-kit` versions are pinned in the lockfile, imported only by the two adapter files, and present only in the board chunk — *exact pins, `no-restricted-imports` boundary, and one built chunk confirmed in the build output*
- [x] ADR-007 note added; corrective documentation notes added — *verified present and mutually consistent*
- [x] EPIC-011E status updated and the parent roadmap Phase E marked complete — *EPIC-011E → `Verified` (2026-09-23) here and in `docs/epics/README.md`; parent EPIC-011 deliberately stays `In Progress` because later child phases remain. `Done` is not claimed: it still requires the repository's normal final integration/merge lifecycle*
