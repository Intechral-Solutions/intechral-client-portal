# EPIC-017: Helpdesk MVP and Customer Shell

**Status:** **Planned** (2026-10-07). WP0, this document, is the design gate. **Owner review is complete (2026-10-07):**
- the architecture is approved;
- owner decisions O1–O3 are **approved** ([§37](#37-owner-decisions));
- plan choices P1–P6 are **approved** ([§36](#36-plan-level-choices-approved));
- the E2E cleanup command is locked ([§25.3](#253-playwright)).

No implementation has started, and no implementation branch exists.
**Update (2026-10-08):** WP1 (the customer shell foundation) is implemented on `feature/epic-017-helpdesk-mvp-customer-shell`, uncommitted and awaiting independent review ([Amendment 1](#amendment-1-wp1-customer-shell-foundation-pr-1)). The status stays **Planned** until PR 1 merges; nothing has been pushed and hosted CI has not run. The sentence above describes the design-gate baseline.
**Update (2026-10-11):** WP1 and WP2 are implemented and committed **locally** on `feature/epic-017-helpdesk-mvp-customer-shell`, in five commits on top of `main` (`6058dc1` WP1, `67d1f9d` G1, `1ff93f4` G2, `8220deb` G3-A, `56cad95` G3-B). [Amendment 2](#amendment-2-wp2-customer-helpdesk-in-react-pr-2) is the WP2 evidence ledger and closes WP2 locally. **Nothing has been pushed, no PR exists, and hosted CI has not run for any of it.** The status stays **Planned**: WP3 (operator ticket workspace), WP4 (operator queue, bulk and reports) and WP5 (notification hardening and closeout) are not started, and the "uncommitted, awaiting independent review" wording in the 2026-10-08 update above is historical.
**Class:** Product functionality + UX foundation (Product Roadmap [Release 1 → Helpdesk](../product/product-roadmap.md#helpdesk-release-1) and [Customer product and customer shell](../product/product-roadmap.md#customer-product-and-customer-shell); Release 1 Phase 2)
**Renderer record:** This is the implementation contract for [EPIC-011 Phase F (Tickets)](./EPIC-011-react-frontend-migration.md#phase-f-tickets). No separate EPIC-011F document is created.
**Design contract:** [Direction D](../design/direction-d-design-system.md): §7 customer shell, §10.4 ticket statuses, §13 account menu, §14 focus and keyboard, §15 states.
**Security baseline:** [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) (Verified), its [final security and integrity contract](./EPIC-010D-helpdesk-security-hardening.md#final-security-and-integrity-contract) and locked decisions D1–D5.
**Shell seam:** [EPIC-013 §23](./EPIC-013-direction-d-shell-design-system.md#23-audience-presentation-and-the-shell-seam): the presentation discriminator, `AppShell` as the single branch point, and chrome-agnostic pages.
**Prerequisites:**
- [EPIC-013](./EPIC-013-direction-d-shell-design-system.md), [EPIC-014](./EPIC-014-tasks-workspace-overhaul.md), [EPIC-015](./EPIC-015-projects-ux-expansion.md) and [EPIC-016](./EPIC-016-direction-d-blade-theme-control-adoption.md): all Done.
- The `@shadcn/lint` adoption ([PR #29](https://github.com/Intechral-Solutions/intechral-client-portal/pull/29)) is **merged** and is part of the current `main` baseline: final head `e0cc0bf671d6deb6bef9b54d012eaa5c99647113`, merge commit `61e46ab176fb223c8190698e535a580b46a2d95d` (2026-10-08 00:34 UTC). It is **no longer a WP1 dependency** ([§24](#24-shadcnlint-contract), [§31](#31-work-packages)). Its post-merge `main` CI is recorded under the integrated baseline below.

**Integrated baseline (current).** `main` @ `61e46ab176fb223c8190698e535a580b46a2d95d`, the merge of PR #29, on top of the WP0 commit `9f94667` (`docs: establish EPIC-017 Helpdesk MVP`). `AGENTS.md` and `src/eslint-suppressions.json` are on `main`.
- **WP0 commit run** 37707351215 (`9f94667`): green.
- **PR #29 merge run** 37708447871 (`61e46ab`): overall **green**. Attempt 1 had `./dev check gates` green and the Playwright browser suite **red**. Attempt 2, an unchanged rerun of the same commit, was green on both jobs. **The cause of the first Playwright failure was not established**; it is recorded as an observed flake and is not diagnosed here. It belongs with the known browser-suite timing debt carried to Release 1 hardening ([§28](#28-existing-technical-debt)).

**Historical planning baseline (2026-10-07/08).** WP0 was written against `main` @ `2fd0918e17f6378442d566cd6b0703ec1c7a3ff9` (the merge of PR #28; `main` CI run 37694314651 green), **while PR #29 was still open**. It was re-verified as still open on 2026-10-08 00:10 UTC during the owner-review remediation, before it merged at 00:34 UTC. The sections below that mention PR #29 as open are marked as that historical baseline.

---

## Contents

1. [Purpose](#1-purpose)
2. [Context and baseline](#2-context-and-baseline)
3. [Owner rulings and locked contracts](#3-owner-rulings-and-locked-contracts)
4. [Current-state inventory](#4-current-state-inventory)
5. [Customer shell: product contract](#5-customer-shell-product-contract)
6. [Customer shell: non-goals](#6-customer-shell-non-goals)
7. [Customer identity model](#7-customer-identity-model)
8. [Routing and layout boundary](#8-routing-and-layout-boundary)
9. [Renderer strategy](#9-renderer-strategy)
10. [Helpdesk MVP scope](#10-helpdesk-mvp-scope)
11. [Email and notifications](#11-email-and-notifications)
12. [Module search](#12-module-search)
13. [Status, priority and internal-note semantics](#13-status-priority-and-internal-note-semantics)
14. [Customer navigation and module registration](#14-customer-navigation-and-module-registration)
15. [Operator and customer separation](#15-operator-and-customer-separation)
16. [Responsive contract](#16-responsive-contract)
17. [Accessibility contract](#17-accessibility-contract)
18. [Shared props](#18-shared-props)
19. [Page contracts](#19-page-contracts)
20. [Data-model boundary](#20-data-model-boundary)
21. [Directory dependency check](#21-directory-dependency-check)
22. [Finance and Projects adoption seam](#22-finance-and-projects-adoption-seam)
23. [Release-engineering handoff](#23-release-engineering-handoff)
24. [`@shadcn/lint` contract](#24-shadcnlint-contract)
25. [Testing strategy](#25-testing-strategy)
26. [Query and performance boundaries](#26-query-and-performance-boundaries)
27. [Security and privacy review](#27-security-and-privacy-review)
28. [Existing technical debt](#28-existing-technical-debt)
29. [Incidents](#29-incidents)
30. [Knowledge / CMS](#30-knowledge--cms)
31. [Work packages](#31-work-packages)
32. [Branch and PR strategy](#32-branch-and-pr-strategy)
33. [Exit criteria](#33-exit-criteria)
34. [Risks](#34-risks)
35. [Deferred items](#35-deferred-items)
36. [Plan-level choices (approved)](#36-plan-level-choices-approved)
37. [Owner decisions](#37-owner-decisions)
38. [Restart and handoff notes](#38-restart-and-handoff-notes)
39. [Amendment 1: WP1 Customer Shell Foundation](#amendment-1-wp1-customer-shell-foundation-pr-1)

---

## 1. Purpose

Deliver the **Release 1 Helpdesk MVP**. Its first package is the **dedicated customer shell** ([Release 1 ruling D1](../product/product-roadmap.md#owner-rulings-d1d4)).

At the end of the epic:

- **Customers** use a calm, Focused-presentation portal. The top bar carries Home and Support, plus links to their existing Projects, Invoices and Resources. In it they:
  - raise requests;
  - follow and reply to their own requests;
  - attach files;
  - see only customer-safe information.
- **Operators** work the Helpdesk queue and ticket workspace in React on the Operational shell. Internal notes stay clearly separated, ticket time runs through the existing timer contracts, and reporting is at least at today's CSV parity.
- **Every Helpdesk Blade view is retired.** The EPIC-010D security contract holds unchanged.
- **The customer shell is a durable product frame.** Finance and Projects adopt it later without rewriting it.

## 2. Context and baseline

**Preflight (2026-10-07):**

| Check | Result |
|---|---|
| Branch / HEAD | `main` @ `2fd0918e17f6378442d566cd6b0703ec1c7a3ff9`, equal to `origin/main`, clean *(historical planning baseline; the current baseline is in the header)* |
| Latest `main` CI | Run 37694314651 (merge of PR #28): **green**. The earlier red run 37661096171 (`docs: approve shadcn lint adoption`) was the Finance fixture collision that PR #28 fixed. |
| Open PRs *(at the planning baseline)* | **#29 `Adopt shadcn lint for Direction D React styling`** (`tooling/shadcn-lint-adoption`, head `e0cc0bf`) was open, mergeable and green, and **not merged** at that time. It is tooling only and excluded Helpdesk and customer-shell work. **It has since merged** (`61e46ab`, 2026-10-08 00:34 UTC). |
| Helpdesk branches / partial work | None. No `EPIC-017` reference, no Helpdesk or customer-shell branch, and no React Ticket page or type exists. |
| Local branches *(at the planning baseline)* | `tooling/shadcn-lint-adoption` (PR #29). Remote `origin/*` feature branches were historical, already merged. |

**Historical note on the planning premise.** Two of the briefs that produced this gate described the `@shadcn/lint` adoption as already merged while PR #29 was in fact still open on the remote. The gate was therefore written against the true state: PR #29 open, green and unmerged. **That has since been resolved**: PR #29 merged on 2026-10-08, so the policy this document plans against (its `AGENTS.md`, the ESLint configuration and the `docs/testing/ci.md` additions) is now on `main`.

**Documents read for this gate:**

- Release 1:
  - the roadmap Release 1 section in full, plus its historical sections;
  - `docs/epics/README.md`;
  - [Platform Product & UX Direction](../product/platform-product-ux-direction.md);
  - [Information Architecture](../product/information-architecture.md).
- Design: [Direction D](../design/direction-d-design-system.md) §1, §5–§10, §13–§20.
- Security and earlier epics:
  - EPIC-010D in full;
  - EPIC-013 §23 and §31;
  - EPIC-016 §9, §13.1, §21, §22 and Amendment 4 (A4.13);
  - EPIC-011 Phase F and the migration principles;
  - EPIC-011E C8;
  - EPIC-004's known gaps;
  - EPIC-015 §22.
- Architecture and testing: [`rbac-design.md`](../architecture/rbac-design.md), [`ci.md`](../testing/ci.md), [`e2e-browser-suite.md`](../testing/e2e-browser-suite.md).
- Code: the live Ticket code, the shell, navigation and identity code, which [§4](#4-current-state-inventory) inventories.

## 3. Owner rulings and locked contracts

Every ruling below was recovered from the current repository text and **still stands**. None is superseded.

| Source | Ruling | Effect on this epic |
|---|---|---|
| Release 1 **D1** | Release 1 includes the dedicated customer shell. It is the **first package** of the Helpdesk MVP. Finance and Projects adopt it later. Organization switching is **not** built merely because the shell exists. | WP1 is the customer shell. No switcher ([§7](#7-customer-identity-model)). |
| Release 1 **D2** | The Advanced Projects core is estimates, planned budgets and estimate-vs-actual. Approvals, change control and SOW are later. | Helpdesk depends on none of them. |
| Release 1 **D3** | Release 1 is a clean install plus a small scripted seed. | No legacy Ticket migration work. Development tickets are disposable ([roadmap principle 8](../product/product-roadmap.md#roadmap-principles)). |
| Release 1 **D4** | cPanel compatibility is the baseline constraint; the host is not preselected; RE-0 decides. | Runtime assumptions go to the RE handoff ([§23](#23-release-engineering-handoff)), not into this epic. |
| Release 1 owner ruling 1 | Knowledge/CMS is Post-v1. Existing `/pages` stay operational as they are. | No Knowledge work. `/pages` keeps working for customers ([§14](#14-customer-navigation-and-module-registration)). |
| Release 1 boundary | Helpdesk core is **A**: the operator queue and workspace in React, the customer request/reply flow, attachments by visibility, ticket time, and reporting at least at CSV parity. Incidents are **B** (default Post-v1). Knowledge is **C**. Module-local search is **A**. Existing email notifications are **A** (maintain). The in-app notification centre is **B** (default Post-v1). | The scope in [§10](#10-helpdesk-mvp-scope). |
| Roadmap Helpdesk absorption list | The MVP absorbs EPIC-016's Helpdesk deferrals: `confirm()` replaced by dialogs, the page frame and `DataTable` density, "All Statuss" / "All Prioritys", bulk-bar error placement, the customer label "Waiting on you", and the hourglass glyph in the shared `Status` vocabulary. | [§28](#28-existing-technical-debt) |
| **EPIC-010D D1** | A customer sees **their own** Tickets. Company/organization membership grants **no** visibility. `tickets.view_org` is reserved and unused. | Authorization matrix ([§7.3](#73-authorization-matrix)) |
| EPIC-010D D2 | Only a user who currently holds `tickets.assign` may be assigned. | Kept unchanged. |
| EPIC-010D D3 | `company_id` is not inferred or backfilled. Whether new Tickets persist organization context belongs to Helpdesk/Directory design. | Decided: **wait for Directory** ([§20](#20-data-model-boundary)). |
| EPIC-010D D4 | Replies are plain text: no Markdown, rich text or sanitizer dependency. | Kept unchanged. |
| EPIC-010D D5 | Ticket-derived tasks stay dormant. | Kept unchanged. |
| EPIC-013 §23 | Presentation is not authorization. `shell.presentation` is server-resolved. `AppShell` is the only branch point. Pages are chrome-agnostic. | The shell architecture ([§8](#8-routing-and-layout-boundary)) |
| Direction D §7 | Customer shell: 60px top bar, no rail, no drawer, a centred column of at most 1080px, at most 5 nav items, an `accent-line` underline for the current item, a menu sheet below 900px. Customers **never** get a drawer, a global timer, internal notes, bulk actions, dense tables or operational vocabulary ("SLA", "queue"). | The shell contract ([§5](#5-customer-shell-product-contract)) |

**Owner rulings recorded by this gate (approved 2026-10-07):**

| Ruling | Decision | Where |
|---|---|---|
| **O1** | **Approved, option (a).** The Focused nav omits Tasks and Time. General `user` time permissions are unchanged. **Helpdesk ticket time is operator-only**, enforced server-side. | [§37](#37-owner-decisions) |
| **O2** | **Approved, option (a).** A customer reply moves `pending_user` → `in_progress` and `resolved` → `open`; a customer reply on `closed` is refused. | [§37](#37-owner-decisions) |
| **O3** | **Approved, option (c).** Customer emails on `pending_user` and `resolved`, plus a new-ticket operator alert to `HELPDESK_NOTIFY_ADDRESS`, which is **mandatory in production**. | [§37](#37-owner-decisions) |
| **P1–P6** | **Approved**, P1 with a permanent drift invariant | [§36](#36-plan-level-choices-approved) |
| E2E cleanup | **Locked:** a local/testing-only cleanup command; accepted fixture growth is rejected | [§25.3](#253-playwright) |

**Open questions this gate resolves (approved with the gate):**

- **Direction D §20 Q1, routing topology:** same routes with capability-aware pages ([§8](#8-routing-and-layout-boundary)).
- **Direction D §20 Q3, multi-organization switcher:** not built in Release 1's Helpdesk ([§7](#7-customer-identity-model)).
- **Customer label:** "Support" ([§14](#14-customer-navigation-and-module-registration)).

## 4. Current-state inventory

Paths are relative to `src/`. The inventory was read from live code at the baseline.

### 4.1 Routes (`routes/web.php:79-109`)

| Route | Name | Middleware | Controller | Renderer |
|---|---|---|---|---|
| `GET /tickets` | `tickets.index` | `auth`, `can:tickets.view` | `TicketController@index` | Blade |
| `GET /tickets/create`, `POST /tickets` | `tickets.create`, `tickets.store` | `auth`, `can:tickets.create` | `TicketController@create/store` | Blade |
| `GET /tickets/{ticket}` | `tickets.show` | `auth`, `can:tickets.view` | `TicketController@show` (`authorize('view')`) | Blade |
| `POST /tickets/{ticket}/replies` | `tickets.replies.store` | `auth` | `Operator\TicketReplyController@store` (`authorize('reply')` first) | — |
| `GET /attachments/{attachment}/download` | `tickets.attachment.download` | `auth` | `TicketController@downloadAttachment` (`authorize('downloadAttachment')`) | download |
| `/operator/tickets` (index), `/bulk`, `/reports`, `/reports/export`, `/{ticket}`, `/{ticket}/status`, `/{ticket}/assign`, `/{ticket}/replies` | `operator.tickets.*` | `auth`, `can:tickets.assign` | `Operator\Ticket*Controller` | Blade, plus a CSV download |

Ticket time uses the `/time/*` routes with `ticket_id`.

**Schedule:** `tickets:auto-close` runs daily at 02:00 (`bootstrap/app.php:16`). It closes tickets that have been resolved for 72 hours or more.

### 4.2 Domain

- **Ticket** (`app/Models/Ticket.php`):
  - Statuses (DB enum): `open`, `in_progress`, `pending_user`, `resolved`, `closed`.
  - Priorities: `low`, `medium`, `high`, `critical`.
  - `TRANSITIONS` map (L117-123). `SLA_HOURS`: critical 4, high 8, medium 24, low 72.
  - `sla_due_at` is set once at creation and never recalculated.
  - `isOverdue()`. `scopeSearch($term, $includeInternal = false)` over title, description, number and reply bodies.
  - Categories are a controller constant: General, Technical, Billing, Account, Other.
- **TicketReply** (`is_internal`), **TicketAttachment** (`reply_id` null means a ticket-body attachment), **TicketStatusHistory** (actor nullable).
- **Policy** (`TicketPolicy`):
  - `view` and `reply`: owner, or holder of `tickets.assign`.
  - `viewInternal`: `tickets.assign` and `view`.
  - `downloadAttachment`: Ticket → Reply → Attachment.
- **Service** (`TicketService`):
  - id-derived `TKT-` numbers;
  - reply recipients: owner and assignee, minus the author, de-duplicated, each still passing `view`; internal notes notify nobody;
  - transitions write a history row and send no notification;
  - assignment eligibility (`assertAssignable`);
  - `autoCloseResolved` bypasses `transition` and writes history with a null actor.
- **Notifications:** `TicketCreatedNotification` goes to the submitter. `TicketRepliedNotification` goes to the recipients above. Both are mail only, `ShouldQueue`, and use the default Markdown `MailMessage` template. **Both link to `/tickets/{id}` for every recipient, operators included.**
- **Attachments:**
  - At most 10 per request, 20 MB each, **no type rule**.
  - Stored on the private `local` disk under `tickets/{id}/`, **outside** the row transaction (F-7).
  - Downloaded through the policy with `Content-Disposition: attachment`.
- **`company_id`** is validated and auto-filled on create, then **never persisted** (F-1). No page displays it.

### 4.3 Customer-facing Blade behaviour today

| Page | Fact |
|---|---|
| `tickets/index` ("My Tickets") | Own tickets (`forUser`). Search plus status filter. `created_at DESC`, 20 per page, `withQueryString`. Columns: title / number · category, priority, status, created. |
| `tickets/create` | Subject, category, priority (customer may choose **Critical**, which sets a 4h SLA), description, a company selector (dead input, F-1), attachments. |
| `tickets/show` | Shows: description, ticket-body attachments, public replies, a reply form only while `isOpen()`, "Details" (category, submitted, **"SLA Due" with an Overdue mark**, "Assigned to" name), and a "History" that prints raw values (`pending_user`). **It also embeds `<x-time-tracker>` under `@can('time.log')`.** |

**Code facts the documentation did not record:**

| # | Fact | Evidence | Classification |
|---|---|---|---|
| **N1** | **Customers see operator time on their own tickets.** The built-in `user` role holds `time.log` (`PermissionCatalogue::userDefaults`). For ticket context, the embedded tracker lists the last 5 entries from **all users**, with their names and durations, plus the total, and offers "Start Timer". | `components/time-tracker.blade.php:18-32` (the own-time restriction applies only to `task` context); `tickets/show.blade.php:163-170` | **Security / privacy defect. A Release 1 requirement (blocker).** Closed in WP2 by requester-prop absence and server-side operator-only ticket-time eligibility ([O1](#37-owner-decisions), [§27](#27-security-and-privacy-review)). |
| N2 | The operator ticket page has **no** time tracker. Operators time tickets only from the customer route. | `operator/tickets/show.blade.php` | WP3 |
| N3 | `pending_user` has three labels: badge "Pending", filters and select "Pending user", customer history raw `pending_user`. | `_status_badge.blade.php`, `tickets/index.blade.php:31-35`, `tickets/show.blade.php:153` | WP2/WP3 copy |
| N4 | The server accepts `?category=` on the queue and `action=status` on bulk, but neither has a control. | `Operator\TicketController@index`, `TicketBulkController` | WP4 |
| N5 | The customer page shows operational vocabulary ("SLA Due", "Overdue"), which Direction D §7 forbids for customers. | `tickets/show.blade.php:126-136` | WP2 |
| N6 | The account-menu items "Security & MFA", "Connected accounts" and "Sessions" link to `/profile#security`, `#connected-accounts` and `#sessions`. **No such ids exist** on `pages/profile/show.tsx`. This applies to both renderers. | `components/shell/account-menu.tsx`, `layouts/partials/shell/account-menu.blade.php` | WP1 (the customer account menu reuses it) |
| N7 | React pages that are chrome-agnostic by EPIC-013 §23 still depend on `TimerProvider`, which only `OperatorShell` mounts. | `pages/time/index.tsx`, `components/tasks/task-list-actions.tsx`, `components/projects/task-time-panel.tsx` use `useTimers`/`useOptionalTimers`; `operator-shell.tsx:200` | WP1 risk ([§8.3](#83-shell-provided-contexts)) |

### 4.4 Shell, navigation and identity today

- **Inertia:**
  - Pages assign `Page.layout = (page) => <AppShell>{page}</AppShell>` (16 pages).
  - `AppShell` reads `shell.presentation` and always returns `OperatorShell` (`components/shell/app-shell.tsx`).
  - `shell.presentation` is the constant `'operational'` (`HandleInertiaRequests.php:49-51`), typed as the literal `'operational'` (`types/navigation.ts`).
  - No SSR. Auth pages use `AuthLayout`.
- **Shared props:**
  - `auth.user`: `id`, `name`, `email`, `avatar.initials`, `avatar.url = null`.
  - `auth.permissions` (lazy): the user's own flattened permission names.
  - `shell.presentation`.
  - `navigation` (lazy): `NavigationBuilder::build()`.
  - `flash`: success, error, status, warning, bulk.
  - No operator-only data is shared to all users.
- **NavigationBuilder** produces `{currentWorkspace, workspaces[]}`. Each workspace has `presentation.operational` panel defaults, `visit` (`inertia` or `document`), and `context[]` sections. A `user`-role customer sees **home, projects, tasks, helpdesk ("My requests"), time, finance ("My invoices"), resources (`/pages`)** (`tests/Feature/NavigationBuilderTest.php:296-320`).
- **Blade root:**
  - `layouts/app.blade.php` renders the same rail, drawer and utility bar from the same navigation (`ShellComposer`), with `data-shell="operational"`.
  - Both roots inline `resources/js/shell/bootstrap.js` from disk on every request (RE-2 item).
  - `BrandMark::inline` also reads its SVG from disk on every call.
- **Identity:**
  - "Customer" is the built-in Spatie role `user`; there is no customer concept in code.
  - The operator capability is `tickets.assign`.
  - Invitations assign `user` and attach no organization.
  - Organizations (`organization_members`) and `crm_companies` exist, but no customer surface uses them for Helpdesk.
  - Tenancy scopes still check `hasRole('operator')` (EPIC-010D F-5, Directory).

### 4.5 Tests today

- **Pest:** `tests/Feature/Tickets/`, 11 files and about 125 `it` blocks plus datasets:
  - submission, reply, status workflow, operator queue;
  - the EPIC-010D characterization and target suites (security, visibility, assignment, bulk, export, number);
  - the embedded tracker.
- **Related suites:** `NavigationBuilderTest`, `BladeShellTest`, `ShellContractTest`, `DashboardInertiaTest`, the Time eligibility suites, and the Blade theme guard. `BladeThemeGuardTest`'s exact 59-path inventory **includes every Helpdesk view**.
- **Vitest:** no ticket suites; the dashboard and timer suites touch tickets incidentally.
- **Playwright:**
  - `blade-theme-controls.spec.ts` and `blade-shell.spec.ts` (Blade Helpdesk pages);
  - `time-migration.spec.ts` (timer on the seeded `TKT-E2E1` at `/tickets/{id}`, as operator).
  - Tickets have **no delete route**, so browser specs use only the seeded fixture today.

## 5. Customer shell: product contract

The customer shell is a **product frame**: the durable customer workspace that every customer module plugs into, not a skin.

| Responsibility | Contract |
|---|---|
| **Presentation** | The **Focused** family (EPIC-013 §23.1). Its React component is `CustomerShell`; its Blade twin is the Focused top bar in `layouts/app.blade.php` ([§9.3](#93-blade-parity-for-the-focused-frame)). |
| **Navigation** | One top bar, 60px: brand mark, then customer nav items (at most 5, plain language, [§14](#14-customer-navigation-and-module-registration)), then the account trigger. Below 900px, a menu button opens a menu sheet. No rail, drawer, utility bar or breadcrumb landmark is ever rendered. |
| **Module identity** | The current nav item is marked with `aria-current="page"` and the `accent-line` 2px underline. The current item comes from the server (`isActive`), never inferred on the client. |
| **Page frame** | Pages render inside a centred column of at most 1080px (Direction D §5.1). They keep composing `PageFrame` / `PageHeader` / `Section` / primitives exactly as today. `PageFrame` widths apply inside the column (`reading` 640/700/720 for forms, conversation and account). |
| **Breadcrumbs** | No shell breadcrumb. A record page may render an in-content back link ("All requests") as ordinary page content. The shell owns no trail. The `trail` prop that `AppShell` accepts is ignored by `CustomerShell`. |
| **Identity / account** | A 34px circular avatar is the trigger (Direction D §13.1). It is a `<button>` with `aria-haspopup="menu"`, `aria-expanded` and the name "Account menu: {name}". It opens the **same** `AccountMenu` component (personal items only). |
| **Account actions** | Profile, Security & MFA, Connected accounts, Sessions, Appearance (Light/Dark), Notifications (disabled, "Not available yet"), Sign out. No admin item and no keyboard-shortcut item (that one is for operators). The dead anchors (N6) are fixed by adding the target ids on `profile/show`, in both renderers. |
| **Theme** | The existing no-flash contract: `bootstrap.js` sets `data-theme` before paint, and `use-appearance` changes it. No new theme state. |
| **Flash / status** | The same `FlashRegion` (success and status are `role="status"`, error is `role="alert"`), placed at the top of the content column. |
| **Route announcements** | The same `usePageAnnouncement` polite live region that `OperatorShell` uses (`[data-shell-announcer]`). |
| **Timer** | **No global timer pill or tray** (Direction D §7, locked). See [§8.3](#83-shell-provided-contexts) for the timer context. Hiding timer chrome is presentation, **not** a security boundary: ticket-time eligibility is enforced on the server (O1). |
| **Logout** | The account menu's Sign out, as the existing `router.post(logout)`. |
| **Customer-safe boundary** | The shell renders only `navigation` entries that the server projected for the Focused presentation ([§14](#14-customer-navigation-and-module-registration)), and only from capability-filtered data. It contains no operator affordance (bulk, queue, internal, SLA, counts). |
| **Responsive** | [§16](#16-responsive-contract) |
| **Accessibility** | [§17](#17-accessibility-contract) |
| **Future modules** | Finance and Projects register Focused nav entries server-side ([§22](#22-finance-and-projects-adoption-seam)). |

The shell is **deliberately smaller than the operator shell**: no drawer state, no pin, no `Ctrl+\`, no width-class rail variants, no breadcrumb or view switcher, and no timer.

**Customer Home.** The roadmap's first-package scope includes a customer Home.

- `/dashboard` keeps its route, controller and capability branching.
- When the presentation is Focused, it renders a calm customer Home page component in place of the operator dashboard component (same route, capability-aware page, [§8](#8-routing-and-layout-boundary)).
- Reading order (Direction D §7): **Waiting on you** (own `pending_user` requests), then **Your open requests**, then **Invoices** (existing own-invoice summary, `billing.view` only), then **Your projects** (existing, `projects.view` only).
- It shows only data the current dashboard already computes for this actor. There are **no new domain queries** beyond the "waiting on you" filter of the existing own-ticket query.
- The Home is deliberately modest. Each section links to its module.

## 6. Customer shell: non-goals

Out of scope for the shell package and this epic:

- organization switching;
- customer project dashboards;
- customer invoice or payment redesign (Finance);
- Knowledge/CMS;
- global search;
- an in-app notification centre;
- approvals;
- cross-module automation;
- Helpdesk incidents (conditional; [§29](#29-incidents));
- Directory model consolidation;
- new authentication providers;
- operator-shell redesign;
- user-selectable shell presentation (EPIC-013 §31.1 stays Future);
- the "System" theme option;
- density preference.

**Required by the current seam, and therefore in scope:**

1. **Blade parity for the Focused frame** ([§9.3](#93-blade-parity-for-the-focused-frame)). Customers reach Blade pages (`/my/invoices`, invoice pay, `/pages`, and the Helpdesk until WP2) that would otherwise jump back into the operator rail.
2. **Hoisting the timer context out of `OperatorShell`** ([§8.3](#83-shell-provided-contexts)), because chrome-agnostic pages depend on it (N7).
3. **Fixing the account-menu anchors (N6),** because the customer account menu would ship dead links otherwise.

## 7. Customer identity model

### 7.1 What exists

- A customer-facing user is an ordinary `User` with the built-in Spatie role `user` (or any role without operator capabilities).
- Identity is `users.id`, name and email, plus MFA and SSO state on the profile.
- **Ticket ownership is `tickets.user_id`.** It is the only access-bearing link between a customer and a ticket.
- `tickets.company_id` is **informational provenance only** (EPIC-010D D1, D3), and it is never written today.
- Organization membership exists (`organization_members`) but grants no Helpdesk visibility.

### 7.2 What the shell exposes

- The account menu header shows **name and email**, not organization.
- Direction D §13 allows "organization (customer)" in the header. But a customer may have zero, one or several organizations today, and the canonical Organization is a Directory outcome. Showing one now would mean choosing among `organizations` and `crm_companies` before Directory decides.

**The seam:** an optional `organization` field on the shell's account identity, **absent in this epic**. Directory, which owns the customer relationship classification, may populate it later. No placeholder UI is drawn.

### 7.3 Authorization matrix

Ticket authorization is unchanged from EPIC-010D. "Customer" means an authenticated user without `tickets.assign`. "Operator" means any holder of `tickets.assign`, whatever role grants it. A **peer** is another customer in the same organization or company. The matrix uses no role names.

| Action | Owner (customer) | Peer customer | Unrelated customer | Operator (`tickets.assign`) | Seam |
|---|---|---|---|---|---|
| Create a ticket | ✓ (`tickets.create`) | ✓ own | ✓ own | ✓ own (as requester) | route `can:tickets.create` |
| List (`/tickets`) | own only | **✗** | **✗** | own requests only; the queue is the operator universe | `Ticket::forUser` (list == view for customers) |
| View detail | ✓ | **✗ 403** | **✗ 403** | ✓ (see [P2](#36-plan-level-choices-approved) for which page) | `TicketPolicy::view` |
| Public reply | ✓ | ✗ 403, no side effect | ✗ 403, no side effect | ✓ | `TicketPolicy::reply`, first statement |
| Post an internal note | ✗ (`is_internal` coerced to false) | ✗ | ✗ | ✓ | `viewInternal` |
| See internal notes and their attachments | **never** (not in props, not downloadable) | never | never | ✓ | `viewInternal`, `downloadAttachment` |
| Download a body or public-reply attachment | ✓ | ✗ | ✗ | ✓ | `downloadAttachment` |
| Search hits from internal-note text | **never** | — | — | ✓ (queue) | `scopeSearch(includeInternal)` |
| Change status | ✗ (only the implicit transitions of [O2](#37-owner-decisions)) | ✗ | ✗ | ✓ within `TRANSITIONS` | operator route, plus `authorize('view')` |
| Assign / bulk / reports / CSV export | ✗ | ✗ | ✗ | ✓ | route `can:tickets.assign` |
| See priority | ✓ (they set it) | — | — | ✓ | — |
| See SLA due / overdue | **✗ (vocabulary, N5)** | — | — | ✓ | presentation of props |
| See assignee name | ✓ ("Handled by", [§19.3](#193-ticket-detail-ticketsticket)) | — | — | ✓ | — |
| See ticket time (entries, total, other users' time) | **✗ (N1, O1)**: absent from props | ✗ | ✗ | ✓ (operator workspace) | requester resource omits all time keys; operator resource is served only behind `tickets.assign` |
| Start new ticket-context time | **✗ server-refused (O1)**: 422, no entry and no timer created | ✗ | ✗ | ✓ | `AccessibleTimeContext`: ticket context requires `tickets.assign` **and** `TicketPolicy::view` |

Capability names in use:
- **Used:** `tickets.view` (route gate for `/tickets*`), `tickets.create`, `tickets.assign` (the operator capability).
- **Defined but unused:** `tickets.view_org` (reserved, EPIC-010D D1) and `tickets.admin`.
- **No new permission is introduced.**

## 8. Routing and layout boundary

### 8.1 Decision: same routes, capability-aware pages

This resolves Direction D §20 Q1 and IA "Open" with the IA's recommended default.

- **Customer Helpdesk routes stay `/tickets*`. Operator routes stay `/operator/tickets*`.** This is today's topology. No route is added, renamed or removed for topology.
- The customer workspace is the **Focused presentation of the same routes**. A route's page may render a customer-specific component when that is the right product (customer Home).
- **There is no `/portal/*` or `/customer/*` namespace.** Reasons:
  - The authorization universe is already route- and policy-defined, so a second namespace would duplicate gates.
  - Mail and timer links would fork.
  - Finance and Projects already serve customers on shared routes (`/my/invoices`, `/projects/*`).

### 8.2 Applying the shell: one resolver, one branch

1. **`ShellPresentation` resolver (server).**
   - One class decides `operational` or `focused` per request, from the authenticated user.
   - It is the only writer of `shell.presentation` (Inertia shared prop) and of the Blade `$shell` (`ShellComposer`).
   - **Rule ([P1](#36-plan-level-choices-approved), approved):** `operational` if the user currently holds **any operator-surface capability** in an explicit, Pest-pinned list: `tickets.assign`, `crm.manage`, `billing.manage`, `users.view`, `roles.view`, `settings.view`, `cms.edit`, `time.view_all`, `tasks.view_all`, `projects.manage`, `projects.admin`. Otherwise `focused`.
     - **Mixed-capability users:** **any** operator-surface capability selects `operational`.
     - A user holding only customer/member capabilities gets `focused`.
     - It reads capabilities through the Gate. It reads **no role names** and needs **no new permission**.
   - It chooses **projection only**. It is never consulted for authorization (EPIC-013 §23.1), and no route, policy or query reads it.
   - **Permanent drift invariant (P1).** Every capability that gates an operator, admin, CRM, System or other operator-only surface must be **either** in the operational-presentation set **or** explicitly classified as **presentation-neutral** in the same resolver class. A Pest guard enforces this.
     - **Discovery, not trust.** The guard derives the gating capabilities from actual usage wherever practical:
       - `can:` and `permission:` middleware on the registered routes under the operator-only prefixes (`/operator`, `/admin`, `/crm`, `/organizations`, operator Finance and System routes);
       - the capabilities `NavigationBuilder` uses to unlock operator-only workspaces or views.

       Each discovered capability must be classified. A capability added to such a surface later fails the guard until someone classifies it.
     - **Additional guard.** Every catalogue capability outside `PermissionCatalogue::userDefaults()` must also be classified.
2. **`AppShell` gains its second branch:** `case 'focused': return <CustomerShell>`. No page changes its `Page.layout`, and no page branches on presentation.
3. **Blade:** `layouts/app.blade.php` is the only Blade branch point. For `focused` it renders the Focused top bar partial instead of rail, drawer and utility bar.
4. **The type** `ShellProps.presentation` widens to `'operational' | 'focused'`.

### 8.3 Shell-provided contexts

Pages that use `useTimers` (N7) must not crash or lose behaviour inside `CustomerShell`.

This design is **approved**:

- `TimerProvider` moves from `OperatorShell` to **above the `AppShell` presentation branch**, still `enabled` by `time.log`, so it is **presentation-independent** state for chrome-agnostic pages.
- The timer pill and tray are rendered **only** by `OperatorShell`. `CustomerShell` never renders global timer chrome.
- **Presentation is never a timer authorization rule.** Which contexts may receive time is decided only on the server by `AccessibleTimeContext`. After [O1](#37-owner-decisions), **ticket-context time is operator-only** there, whatever shell renders the page. General project and task time is unchanged (`ProjectTimeAccess`, `TaskPolicy`).
- WP1 inventories every hook a page consumes from the shell before the branch lands. The output is a list in the WP1 amendment.
- **Required tests:**
  - Vitest: `TimerProvider` is present under both presentations where pages need it;
  - Vitest: the Focused presentation renders no pill and no tray;
  - Pest: ticket-context timer start and entry creation are refused for non-operators, with no side effect.

### 8.4 Customer routes under the Focused shell in Release 1

| Route | Under the Focused shell | Renderer at the end of the epic |
|---|---|---|
| `/dashboard` (Home) | ✓ (customer Home component) | React |
| `/tickets`, `/tickets/create`, `/tickets/{ticket}`, reply POST | ✓ | React (WP2) |
| `/profile` | ✓ (account page, reading width) | React |
| `/projects/*` (member projects) | ✓ (existing pages, unchanged content; Projects adopts properly later) | React |
| `/my/invoices*`, `/billing/invoices/{id}/pay*` | ✓ via Blade parity (Finance adopts later) | Blade |
| `/pages*` | ✓ via Blade parity | Blade |
| `/tasks*`, `/time*` | **Not in the Focused nav** ([O1](#37-owner-decisions), approved). The routes stay reachable by existing capability, and general time permissions are unchanged. Rendered in the Focused frame without timer chrome. Ticket-context time is refused server-side. | React |
| `/operator/*`, `/admin/*`, `/crm/*`, `/organizations/*` | **Never.** A Focused user holds none of their gates by construction of the P1 list. A user who does hold one is Operational. | unchanged |

Auth pages keep `AuthLayout`.

## 9. Renderer strategy

### 9.1 Options compared

| Option | For | Against | Verdict |
|---|---|---|---|
| **A.** Customer-only React; operator stays Blade | Smallest | **Contradicts the Release 1 boundary**, which makes the operator queue and workspace in React (Phase F) a class A requirement. Leaves the shared reply controller, the attachments and the tracker split across two renderers indefinitely. | Rejected |
| **B.** Customer and operator migrate together, in one big PR | One cut-over | Too large to review. It mixes a customer-safety review with an operator workflow review. | Rejected as one PR |
| **C.** Shell first, customer pages later, operator unspecified | Matches D1 ordering | Leaves the operator side unplanned | Partially adopted |
| **D. (Adopted)** Shell first. Then the customer Helpdesk in React. Then the operator ticket workspace in React. Then the operator queue, bulk and reports in React. Each Blade view is deleted in the **same PR** that migrates its route. | Every route has exactly one renderer at every commit. Customer safety is reviewed in isolation. Operator work reuses the conversation components. Phase F completes inside Release 1. | Two renderers coexist across WPs, but **per route, never per page**. | **Adopted** |

### 9.2 No half-migrated ownership

- A route switches renderer in exactly one PR, and its Blade view, partials and inline script are **deleted in that PR** (EPIC-011 principles 8 and 9).
- `BladeThemeGuardTest`'s inventory is updated in the same PR.
- Cross-renderer links between React and Blade use `visit: 'document'` until both ends are React. NavigationBuilder entries flip to `inertia` as their routes migrate (EPIC-013 §11.4).
- The shared `Operator\TicketReplyController` returns `back()` with flash. That works for both renderers, so it is not forked.
- **Downloads and CSV export stay plain HTTP responses** (EPIC-011 L502).

### 9.3 Blade parity for the Focused frame

WP1 adds the Focused top bar to the Blade layout, from the same navigation data. It is a minimal twin: brand, nav links, menu sheet below 900px, the avatar trigger with the account menu, and the same tokens.

- This is the EPIC-013 §19 step 5 principle ("the two renderers don't diverge") applied to the second family.
- It is **required**, not polish. Without it a customer moving from Home to Invoices would jump between two unrelated frames for the whole time until Finance.
- The Blade twin holds no Helpdesk logic. It disappears from customer use only when the last customer Blade page migrates (post-Finance), and it is retired then.

## 10. Helpdesk MVP scope

| Capability | Classification | Reason |
|---|---|---|
| Ticket create (customer) | **REQUIRED** | Core customer flow. React, Focused shell (WP2). |
| Ticket list, "my requests" | **REQUIRED** | Core customer flow (WP2). |
| Ticket detail, conversation | **REQUIRED** | Core customer flow (WP2). Operator workspace in WP3. |
| Customer reply | **REQUIRED** | Core. Lifecycle per [O2](#37-owner-decisions) (approved): `pending_user` → `in_progress`, `resolved` → `open`, `closed` refused. |
| Operator reply | **REQUIRED** | Core (WP3). |
| Internal notes | **REQUIRED** (preserve; separate visually) | EPIC-010D boundary preserved. Direction D §10.5 treatment in React (WP3). |
| Status lifecycle | **EXISTING / PRESERVE** (values and transitions unchanged) | Customer labels added ([§13](#13-status-priority-and-internal-note-semantics)). Customer transitions only per O2. |
| Priority | **EXISTING / PRESERVE** | Customer sets it on create, including Critical; shown to both audiences. This preserves existing behaviour and is **not** an endorsement of the final SLA policy ([§13](#13-status-priority-and-internal-note-semantics)). |
| SLA / overdue | **EXISTING / PRESERVE, operator-only** | Hours unchanged, no recalculation, no breach jobs. Removed from customer views (N5). SLA policy redesign is Future. |
| Attachments | **REQUIRED** | Visibility by reply (existing). Adds an **exact extension + content-type allowlist** and **atomic store/cleanup** (EPIC-010D F-7, [§20.1](#201-attachment-type-policy)). |
| Search | **REQUIRED** (module-local) | [§12](#12-module-search) |
| Filters | **REQUIRED** | Customer: status. Operator: status, priority, category (N4), assignee, dates. |
| Pagination | **EXISTING / PRESERVE** | 20 per page (customer), 30 (queue). Query string preserved. |
| Ticket time tracking | **REQUIRED** (operator-only) | On the operator workspace through the existing timer contracts (`TimerControl`, `/time/timer/start` with `ticket_id`). **Operator-only on the server** (O1). The N1 closure is a Release 1 security/privacy requirement ([§27](#27-security-and-privacy-review)). |
| Email notifications | **REQUIRED** (maintain, harden, and add per [O3](#37-owner-decisions)) | [§11](#11-email-and-notifications): Waiting on you and Resolved customer emails, plus the new-ticket operator alert (mandatory in production) |
| Incident handling | **CONDITIONAL → Post-v1** | No day-one operational need found ([§29](#29-incidents)) |
| Knowledge suggestions | **POST-V1** | Owner ruling 1 |
| Customer identity | **EXISTING / PRESERVE** | User and role. No Directory work ([§7](#7-customer-identity-model)). |
| Organization/company display | **POST-V1 (wait for Directory)** | `company_id` is never written. The dead selector is removed from create ([§20](#20-data-model-boundary)). |
| Activity / history | **EXISTING / PRESERVE** (relabel) | Customer sees status changes with customer labels. Operator sees the full history. No new activity model. |
| Operator reports | **REQUIRED at CSV parity** | Reports page in React, CSV unchanged in columns and safety. F-6 date-window fix included ([§28](#28-existing-technical-debt)). |
| Bulk actions | **REQUIRED** (preserve) | Assign, resolve, close. Adds accurate skipped-row reporting (EPIC-010D §14 deferral). |
| Stale-assignee surfacing | **REQUIRED** (small) | EPIC-010D §13 deferral; the operator sees "no longer eligible". |
| Queue inspector (Direction D §9) | **POST-V1** | Target, not required |
| Saved queue views, SLA policies, routing, escalation, automation | **POST-V1** | Roadmap Future |
| Reply-by-email, rich text | **POST-V1** | EPIC-010D D4; no inbound mail |
| Customer-initiated close / "mark resolved" | **POST-V1** | No current behaviour; O2 covers the implicit transitions only |
| Organization-level ticket visibility | **POST-V1** (Directory) | EPIC-010D D1 |

## 11. Email and notifications

**Existing (keep working in production):**

| Event | Recipient | Channel | Gaps found |
|---|---|---|---|
| Ticket created | Submitter | queued mail | Link always `/tickets/{id}` (correct for the submitter) |
| Public reply | Owner and assignee, minus author, de-duplicated, each passing `view` | queued mail | Link is `/tickets/{id}` **for operators too**. User-authored title and preview render through the Markdown mail template (**F-3**). |
| Internal note | nobody | — | — |

**Hardening, required (WP5):**

1. **Per-recipient links.** An operator recipient (`viewInternal`) gets `operator.tickets.show`; the owner gets `tickets.show`. This follows [P2](#36-plan-level-choices-approved).
2. **F-3:** user-authored text (title, reply preview, author name) is rendered as escaped plain text, so Markdown or links typed by a participant cannot become live links in mail. The reply format stays plain text (D4).
3. **Content safety stays as it is:**
   - no internal content in any mail;
   - no preview for a recipient who fails `view` (EPIC-010D A3.2);
   - mail sent only after commit.
4. Tests: `Notification::fake` recipient matrices (kept), link-per-recipient, escaping, and rendered-mail snapshots of the subject and body.

**Gaps that affected a usable workflow.** They were identified in planning and are now **required by [O3](#37-owner-decisions) (approved, option c)**:

- **G-E1.** Nobody was told when a new ticket arrived; operators had to poll the queue.
- **G-E2.** A customer was not told when their request moved to **Waiting on you** (`pending_user`) or **Resolved**. This matters most because auto-close follows 72 hours after Resolved.

**Additions (WP5):**

| Event | Recipient | Contract |
|---|---|---|
| Status becomes `pending_user` ("Waiting on you") | the requester (owner), if they still pass `view` | Queued mail, sent after commit. Customer label and link (`tickets.show`). No internal content, no SLA vocabulary. Not sent when the requester caused the change. |
| Status becomes `resolved` | the requester, as above | The same contract. The body says a reply reopens the request ([O2](#37-owner-decisions)) and that it closes automatically after 72 hours. |
| Ticket created | **`HELPDESK_NOTIFY_ADDRESS`** (one configured operator destination) | Queued mail, sent after commit. Number, title, category, priority, requester name, and an operator link (`operator.tickets.show`). User text is escaped (F-3). The description is **not** included beyond a short escaped preview. **Every `tickets.assign` holder is not mailed.** |

The transitions caused by O2 (a customer reply) and by `tickets:auto-close` emit only the events above that apply. Auto-close to `closed` sends nothing.

**Configuration contract:**

- **Application:** `HELPDESK_NOTIFY_ADDRESS` may be **unset in local and test environments**. When it is unset, the new-ticket alert is skipped, with a log line and no error.
- **Release 1 production readiness:** production **must** have a real operator new-ticket notification destination.
  - The default implementation is `HELPDESK_NOTIFY_ADDRESS`, configured and **verified by the release preflight** (RE-H13, [§23](#23-release-engineering-handoff)).
  - Production Helpdesk does **not** ship with the alert silently disabled.
  - Any different operator notification mechanism proposed before Release 1 requires an **explicit owner ruling**.

**Production dependencies** (to RE-0, [§23](#23-release-engineering-handoff)): a persistent or cron-driven queue consumer, mail transport, a correct `APP_URL`, deliverability, and the verified `HELPDESK_NOTIFY_ADDRESS`.

**Not added:** an in-app notification centre, preferences, digests, a status-change notification platform, and mail to every operator.

## 12. Module search

| | Customer (`/tickets`) | Operator (`/operator/tickets`) |
|---|---|---|
| Universe | `Ticket::forUser` | all tickets |
| Fields | number, title, description, **public** reply bodies | number, title, description, all reply bodies (internal included) |
| Internal text | **never** affects membership, count or pagination (H3) | included |
| Filters | status (customer labels) | status, priority, category, assignee, submitted from/to |
| Sort | `created_at DESC, id DESC` (deterministic tie-break added) | status rank, priority rank, `sla_due_at` (unchanged), then `id` tie-break |
| Server-side | yes, query string. Inertia `preserveState`; `withQueryString` on the paginator. | same |
| Empty states | Truly empty: "You haven't raised any requests yet." plus New request. Filtered empty: "No requests match these filters." plus Clear filters. | same pattern, operator copy |
| Query preservation | search and filters survive pagination, back/forward and return from detail | same, plus bulk actions return to the same query |

- **Wildcard escaping (F-4)** is fixed in `scopeSearch`: `%`, `_` and `\` are escaped in the bound term. This applies to both audiences, and the H3 opt-in is unchanged.
- The search term is **trimmed and capped at 200 characters**.
- **No full-text index, no new search engine, no global search.**

## 13. Status, priority and internal-note semantics

**Domain values are unchanged.** No rename, no migration, and the transition map is unchanged. Only labels change, per audience, in one mapping per renderer.

| Value | Operator label | Customer label | Glyph (shared `Status` vocabulary) |
|---|---|---|---|
| `open` | Open | Open | circle, info |
| `in_progress` | In progress | In progress | half, info |
| `pending_user` | **Waiting on customer** ([P4](#36-plan-level-choices-approved)) | **Waiting on you** | **hourglass**, neutral (added in WP2 to React `status.tsx` **and** Blade `x-ui.status` together, ending the EPIC-016 P6 substitute) |
| `resolved` | Resolved | Resolved | check, success |
| `closed` | Closed | Closed | check, neutral |

**Priority.** Values and the bars treatment (Direction D §10.3) are unchanged.
- Customers keep choosing priority on create, **including Critical** (which sets a 4h `sla_due_at`).
- This is **preserved existing behaviour, not an endorsement of the final SLA policy**. The future SLA-policy design owns whether customer-selected severity remains appropriate.
- No owner decision is required for EPIC-017.

**Overdue** is an operator-only derived signal: the danger tone, with the clock glyph added to the shared vocabulary when WP3 needs it. It is not a status, and it is never shown to customers.

**Internal note.** It is operator-only by `viewInternal` in every channel: props, download, search and mail.
- **React treatment** (Direction D §10.5): lock glyph plus "Internal note", a dashed `warning-glyph` border and a `warning-soft` surface.
- The composer offers **Reply** vs **Internal note** as an explicit, labelled choice, defaulting to **Reply**. The choice is announced, and the submit button names the action ("Send reply" / "Add internal note").

**Visibility interactions.**

| Signal | Customer | Operator |
|---|---|---|
| Status | customer label | operator label, change control |
| Priority | read | read (change: not in MVP, as today) |
| Overdue | — | ✓ |
| Internal note | — (absent from props) | ✓ |
| Customer reply | ✓ | ✓ |

**Customer transitions ([O2](#37-owner-decisions), approved).** There are no explicit customer status controls. A **customer** reply (an author without `tickets.assign`) applies exactly these, inside the existing transition map:

| Status when the customer replies | Result |
|---|---|
| `pending_user` | reply stored, then status → **`in_progress`** |
| `resolved` | reply stored, then status → **`open`** |
| `closed` | **refused server-side**: 422 with a customer-safe validation message. No reply, no attachment, no file, no status change, no history row, no notification. |
| `open`, `in_progress` | reply stored; status unchanged |

- The implicit transition goes through `TicketService::transition`, so the history row records the **customer as actor**, in the same transaction as the reply.
- Operators keep the current operator contract: they may reply on any status, closed included, with no implicit transition. If implementation finds a contradiction, the WP2 amendment records it.

## 14. Customer navigation and module registration

**Release 1 Focused navigation.** Order and labels:

| Item | Shown when | Target | Renderer |
|---|---|---|---|
| Home | always | `/dashboard` | React |
| **Support** | `tickets.view` | `/tickets` | React (from WP2) |
| Projects | `projects.view` (the same gate as the Operational rail; no extra query) | `/projects` | React |
| Invoices | `billing.view` (without `billing.manage`) | `/my/invoices` | Blade |
| Resources | `cms.view` and not `cms.edit` (unchanged rule) | `/pages` | Blade |

- That is at most 5 items, satisfying Direction D §7. The **"Support"** label settles roadmap/IA "Support vs Help" ([P3](#36-plan-level-choices-approved)).
- **Tasks and Time are not Focused nav items** ([O1](#37-owner-decisions), approved). The nav stays within Direction D's maximum of five.
- **No disabled or "coming soon" items** (Direction D: mockups' NEXT/FUTURE tags never ship).
- A primary "**New request**" action appears on the Support pages and Home, not in the top bar. Direction D's "Ask for help" top-bar button is **not** added in Release 1: one ink primary per page is enough, and it would duplicate the Support entry.

**Registration seam.** `NavigationBuilder` stays the single source of navigation truth. Each workspace definition gains an optional **`focused` projection**: `{label, href, order}`. A workspace without one does not appear in the Focused nav.

- `presentation.focused` sits alongside the existing `presentation.operational` panel defaults (EPIC-013 §12.2).
- The builder takes the resolved presentation and serializes `workspaces[]` filtered and labelled for it. For Focused it carries no `context[]` drawer sections, because there is no drawer. A Focused page that needs views uses in-page tabs or filters (EPIC-013 §23.3).
- **Capability filtering is identical for both presentations.** The Focused projection can only **subset** the capability-filtered set; it can never add a workspace. A test pins this: for every persona, Focused keys ⊆ Operational keys.
- Finance and Projects later **edit only their own workspace definition** to change label, target or order. The shell code does not change.

## 15. Operator and customer separation

The separation is structural, not cosmetic:

| Seam | Operator workspace | Customer workspace |
|---|---|---|
| Routes | `/operator/tickets*`, route gate `tickets.assign` | `/tickets*`, gates `tickets.view` / `tickets.create`, policy |
| Authorization | `TicketPolicy` + `tickets.assign` | `TicketPolicy` (owner) |
| Props | Operator ticket resource (internal replies, time, history actors, SLA, assignee candidates) | **Requester resource**: an allowlisted shape. Operator-only keys are **absent**, not empty ([§19](#19-page-contracts)). |
| Page components | `pages/operator/tickets/*` | `pages/tickets/*` |
| Layout | `OperatorShell` (presentation `operational`) | `CustomerShell` (presentation `focused`) |
| Navigation | Helpdesk workspace: My requests, Queue, Reports | Focused "Support" |

**Shared safely:**
- Direction D primitives, `PageFrame`, `PageHeader`, `Section`;
- `Alert` and `FlashRegion`;
- `Status` and `Priority` with per-audience label maps;
- `AccountMenu`, `Avatar`, `BrandMark`;
- the theme;
- a presentational `ConversationEntry` component that receives an already-filtered entry list and **never** filters itself;
- `AttachmentList` / `AttachmentInput`;
- the `Pagination`, `FilterBar` and `EmptyState` patterns.

**Kept distinct:**
- navigation;
- capabilities;
- operational controls (status change, assign, bulk, time);
- the internal-note composer and display;
- reporting and export;
- SLA and overdue;
- the queue `DataTable`.

**Rule:** a customer page module **must not import** from `pages/operator/**` or `components/helpdesk/operator/**`. WP2 adds a static test or lint for this.

## 16. Responsive contract

Customer widths follow Direction D §5.2's customer column, not the operator's 768px rail boundary.

| Width | Behaviour (measurable) |
|---|---|
| **Desktop, ≥ 1024 (checked at 1280)** | Top bar with full nav. Content column ≤ 1080px, centred. |
| **900–1023** | Top bar with full nav. |
| **< 900 (checked at 768)** | Menu button (`aria-expanded`, `aria-controls`). The sheet holds the nav items and is focus-trapped; Esc closes it and returns focus to the button. The account trigger stays in the bar. |
| **390 (mobile)** | As < 900. The ticket list reflows to two-line rows: title/number, then status · updated. Forms are single column; category and priority **stack** (today's `grid-cols-2` does not). The conversation is full width. |

**Assertions at 390, 768 and 1280, in both themes:**
- `document.documentElement.scrollWidth <= clientWidth` (no horizontal overflow);
- the nav is reachable (bar items, or menu button then sheet);
- the account trigger is visible and operable;
- the primary page action ("New request", "Send reply") is visible without horizontal scrolling;
- exactly one `h1`, and a predictable `<title>` (`{Page} - {app}`);
- no operator affordance: no rail, drawer, timer pill, `Internal`, `Queue`, `SLA` or bulk control.

The operator Helpdesk pages follow the existing operator width classes. The queue reflows at S as Tasks does (D9 pattern).

## 17. Accessibility contract

These requirements are measured per surface. **No product-wide WCAG conformance claim is made.**

1. **Keyboard:**
   - every flow (sign in, Home, Support, create, open, reply, attach, sign out) completes with keyboard only;
   - the Tab order follows visual order;
   - the menu sheet and account menu close with Esc and return focus.
2. **Focus:** `:focus-visible` uses the 2px `focus` outline (Direction D §14.1) and is never removed.
3. **Skip link:** "Skip to content" is the first focusable element and targets `main#main-content`.
4. **Landmarks:** `header` holding `nav` (labelled "Main"), then `main`, in that order (Direction D §14.1). The account menu sits in the header outside the nav.
5. **Current location:** `aria-current="page"` on the current Focused nav item, in both the bar and the sheet.
6. **Accessible names** on every control. Icon-only buttons are labelled. The avatar trigger is named "Account menu: {name}".
7. **Headings and titles:** one `h1` per page, and `<title>` set from the page.
8. **Forms:**
   - every field has a visible `<label>`;
   - errors are shown as text under the field, linked by `aria-describedby`, using the server message verbatim;
   - on submit failure, focus moves to the first invalid field;
   - required fields are marked in text, not only by colour;
   - the file input is named, and its limits hint is associated.
9. **Live regions:**
   - the route announcer (polite) on navigation;
   - success flash is `role="status"`, error is `role="alert"`;
   - a sent reply is announced ("Reply sent") and the new entry receives focus or is announced;
   - in-flight buttons keep their width and label "Sending…".
10. **Status:** glyph + text + colour, never colour alone (Direction D §10). The hourglass has a text label.
11. **Mobile navigation semantics:** the sheet is a dialog with a title, a focus trap and Esc to close. The nav inside it is ordinary links (Direction D §14.3; no roving tabindex).
12. **Reduced motion** is honoured for the sheet and menus (Direction D §16).

**Checks:**
- Vitest semantics (roles, names, `aria-current`, `aria-expanded`, `aria-describedby`);
- Playwright keyboard journeys;
- an axe-class scan per customer page **if** an axe tool is already available in the suite; otherwise a manual checklist, **with no new dependency added by this epic without its own decision**.

**Manual NVDA + Firefox pass** on the customer journey (sign in, create, reply, read an operator reply, sign out) and the operator reply/note flow in WP5. It is recorded with findings. The full screen-reader matrix stays Release 1 hardening.

## 18. Shared props

**Minimal, and unchanged in shape except for the presentation:**

| Prop | Customer value | Notes |
|---|---|---|
| `auth.user` | `id`, `name`, `email`, `avatar` | unchanged. No roles, organizations or MFA state. |
| `auth.permissions` | the user's **own** permission names (lazy) | Unchanged. It is the actor's own data, not operator data. Pages use it only to shape content. |
| `shell.presentation` | `'focused'` | the new value, from the resolver |
| `navigation` | the Focused projection ([§14](#14-customer-navigation-and-module-registration)) | lazy and memoized as today |
| `flash` | as today | `bulk` is null for customers |

**Privacy rules:**
- No operator-only key, count (queue size, overdue count) or other user's data is ever placed in shared props.
- No feature flags are shared: availability is expressed by the presence of navigation entries.
- Page props, not shared props, carry module data, built from audience-specific resources.

**Test:** a Focused-user response's shared props match an exact key allowlist.

## 19. Page contracts

### 19.1 Ticket list (`/tickets`, customer, "Support")

- **Title:** "Support". `h1` "Your requests".
- **Primary action:** "New request" (`tickets.create`).
- **Fields per row:** title (link), number, **customer status label + glyph**, priority, created (relative, with an absolute `<time datetime>`).
- **Not shown:** category (it stays a field on detail), assignee, SLA.
- **Sort:** newest first, deterministic.
- **Search and filter:** a search box plus a status filter, server-side, preserved in the query.
- **Pagination:** 20 per page. Page links preserve the query.
- **Empty:** truly empty vs filtered empty ([§12](#12-module-search)).
- **Props:** a paginator of `{id, number, title, status, statusLabel, priority, createdAt, createdAtHuman, href}` only (`createdAtHuman` is the server-rendered relative time the "created" field needs).

### 19.2 Ticket create (`/tickets/create`)

- **Fields:** Subject (required, ≤255), Category (required; the existing 5), Priority (required; default Medium), Description (required), Attachments (optional; ≤10; ≤20 MB each; **type allowlist** [§20.1](#201-attachment-type-policy); named input with an associated hint).
- **Removed:** the company selector and auto-fill (dead input F-1; [§20](#20-data-model-boundary)).
- **Validation:** the server rules are unchanged except the attachment type rule. Errors follow [§17](#17-accessibility-contract).
- **On success:** redirect to the detail page, flash "Request {number} received.", and the confirmation email (existing).
- **Double-submit protection:** the submit button is disabled while in flight.

### 19.3 Ticket detail (`/tickets/{ticket}`)

- **Header:**
  - number (overline), title (`h1`), customer status, priority;
  - "Opened {date}";
  - "Handled by {name}" when assigned ([P6](#36-plan-level-choices-approved)). This keeps today's disclosure of the assignee name only; their email and id are never exposed.
- **Conversation**, at reading width 700:
  - the description first, as the opening entry, with its attachments;
  - then **public** replies in chronological order, each with author, `<time datetime>` and attachments.
- **Reply composer** ([O2](#37-owner-decisions), approved):
  - shown for `open`, `in_progress`, `pending_user` and `resolved` tickets;
  - on `pending_user` and `resolved`, the composer says that replying sends the request back to the team or reopens it;
  - for `closed`, no composer: the message "This request is closed. Raise a new request if you need more help." plus a link. A direct POST is still refused server-side (422).
- **History:** status changes with **customer labels** and no actor names. "Status changed to Waiting on you · {time}" is enough for customers.
- **Category:** shown in a details list.
- **Never in props:**
  - internal replies and their attachments;
  - `sla_due_at` and overdue;
  - **any time data**: entries, the ticket time total, other users' time, a running-timer state (N1, O1);
  - status-history actors;
  - assignee email and id;
  - operator assignee candidates;
  - `company_id`.

  A Pest test asserts these keys are **absent**.
- **No operator controls** (status, assign, internal toggle, timer).

### 19.4 Operator pages (WP3, WP4)

- **Ticket workspace** (`/operator/tickets/{ticket}`):
  - an entity header (number · requester; title; status, priority, overdue);
  - the conversation at reading width, with internal notes in the §10.5 treatment;
  - the composer with Reply / Internal note;
  - a context rail (Direction D §9): status (valid transitions only), assignee (eligible operators; a stale assignee is flagged), details (category, submitted, SLA due, resolved), time (a `TimerControl` plus the ticket's entries and total, `time.log`), and history with actors;
  - consequential changes use `ConfirmationDialog` where the action is destructive or irreversible (close).
- **Queue** (`/operator/tickets`):
  - `DataTable` with selection;
  - a `FilterBar` with status, priority, **category**, assignee, dates and search (fixes "All Statuss" / "All Prioritys");
  - a `BulkBar` with assign, resolve and close, using `ConfirmationDialog` for close;
  - errors next to the bulk bar and a result summary with **applied and skipped counts** (EPIC-010D §14);
  - 30 per page; reflow at S.
- **Reports** (`/operator/tickets/reports`):
  - the same four sections, the date window **fixed to whole days** (F-6), malformed dates as validation errors;
  - the CSV export unchanged in columns, safety and order.

## 20. Data-model boundary

| Element | Disposition | Reason |
|---|---|---|
| `tickets` columns, enums, indexes | **GOOD ENOUGH** | Values and transitions are unchanged. The `[user_id, status]` index serves the customer list. |
| `ticket_replies`, `ticket_attachments`, `ticket_status_histories` | **GOOD ENOUGH** | — |
| `tickets.company_id` | **SHOULD WAIT FOR DIRECTORY** | Never written (F-1). Directory replaces `crm_companies` with the canonical Organization, so writing it now would create data Directory must transform. The column stays; the dead create input and auto-fill are removed (no behaviour change, since nothing persisted). |
| Organization-level visibility (`tickets.view_org`) | **WAIT FOR DIRECTORY** | EPIC-010D D1 |
| Categories as a table | **GOOD ENOUGH** (constant) | No Release 1 need for managed categories |
| SLA policy model | **GOOD ENOUGH** (`SLA_HOURS`) | SLA design is Future |
| Attachment type policy | **NEEDS CHANGE, no schema** | An exact allowlist in one shared validation rule ([§20.1](#201-attachment-type-policy)) |
| Atomic uploads (F-7) | **NEEDS CHANGE, no schema** | Files written during a failed create or reply are deleted. A row is never left without its file. Implemented in `TicketService`. |
| Ticket "last activity" timestamp | **NOT NEEDED** | The list sorts by created. "Waiting on you" covers the customer's call to action. |

**This epic needs no migration.** If implementation discovers a real blocker, the WP stops and amends this section, with the reason, the migration risk, and whether Directory supersedes it.

### 20.1 Attachment type policy

Attachments are not redesigned. What is kept:

- no schema change;
- the private `local` disk;
- policy-gated download (`downloadAttachment`);
- forced `Content-Disposition: attachment`;
- the limits of 10 files and 20 MB each;
- orphan cleanup ([§20](#20-data-model-boundary), F-7).

**The contract WP2 must satisfy:**

1. **One shared validation rule** is used by ticket create **and** both reply routes. It is never duplicated per controller.
2. WP2 defines an **exact allowlist** of (extension, content type) pairs in that rule, records the list in its amendment, and tests every entry plus representative refusals.
   - Broad categories ("documents", "images") are not a contract.
   - Adding a type later is a reviewed change to the list.
3. **Refused at minimum:** executables and scripts (for example `exe`, `msi`, `bat`, `cmd`, `com`, `sh`, `ps1`, `js`, `jar`, `apk`, `dmg`, `app`), **HTML** (`html`, `htm`, `xhtml`, `mht`) and **SVG**.
4. **Neither the filename nor the browser-supplied MIME is trusted alone.**
   - The server detects the content type from the file's bytes (Laravel's `mimetypes` rule, i.e. `finfo`).
   - Both the extension **and** the detected type must match an allowed pair.
   - A file whose detected type is HTML, SVG or executable is refused, whatever its extension.
5. **Archives** (for example `zip`), if WP2 keeps them on the list, are **opaque downloadable files**. The application never extracts, previews, renders or interprets them, or any other attachment.
6. Downloads keep the stored original filename, sanitized for the header, and are never served inline.
7. **No malware scanning** is introduced by this epic. That is recorded as a residual risk for Release 1 hardening.

## 21. Directory dependency check

The roadmap's assumption that **Helpdesk does not require Directory first** was tested against the code and **holds**.

| Assumption | Evidence | Holds |
|---|---|---|
| Customer authorization is user/ticket based | `TicketPolicy::view` is owner or `tickets.assign`; the index is `forUser` | ✓ |
| Company/organization association grants no visibility | `tickets.view_org` is checked nowhere; `TicketVisibilityCharacterizationTest` H4 pins peers out | ✓ |
| The customer shell works with current identity | It needs only `auth.user` and capability-filtered navigation | ✓ |
| Organization switching can wait | No customer surface uses organization context. Tickets, invoices (`client_id`) and projects (membership) are all per user. | ✓ |

**No architectural dependency on Directory.** The only identity seam left for Directory is the optional account `organization` field ([§7.2](#72-what-the-shell-exposes)).

## 22. Finance and Projects adoption seam

Only the seam is defined here. Their workflows are not designed.

1. **Navigation:** a module adds or edits the `focused` projection on its own `NavigationBuilder` workspace ([§14](#14-customer-navigation-and-module-registration)).
2. **Layout:** a React page adopts the shell by doing nothing new. It keeps `Page.layout = <AppShell>` and is chrome-agnostic (EPIC-013 §23.2). A Blade page adopts it through the Blade Focused frame until its renderer migration.
3. **Page frame:** customer pages use `PageFrame` (`reading` or the customer column default) and `PageHeader`. Record pages use `EntityHeader` (with Strata where Direction D §17 allows it).
4. **Title and breadcrumb semantics:**
   - `<title>` comes from the page;
   - the Focused shell has no breadcrumb landmark;
   - record pages render an in-content back link to their module list;
   - `trail` is accepted and ignored by `CustomerShell`, so the same page works in both shells.
5. **Customer-safe props:** each module builds an audience-specific resource for its customer pages, with operator keys absent, as [§19.3](#193-ticket-detail-ticketsticket) does.
6. **Home:** a module may contribute one Home section through the customer Home controller. Finance's existing invoice summary and Projects' existing project list are the Release 1 sections.

## 23. Release-engineering handoff

**RE-0 runs in parallel and is not a blocker of this epic.** These Helpdesk runtime assumptions must be verified by RE-0/RE-1 on the candidate host:

| # | Assumption | Why Helpdesk needs it |
|---|---|---|
| RE-H1 | A **queue consumer** exists (persistent worker, or `queue:work --stop-when-empty` from cron) with a known latency | `TicketCreated`, `TicketReplied` and the O3 notifications (Waiting on you, Resolved, new-ticket alert) are `ShouldQueue` |
| RE-H2 | The **queue driver** decision (Redis default vs `database`) | `.env.example` defaults to Redis |
| RE-H3 | The **scheduler** by cron (at least daily) | `tickets:auto-close` at 02:00 |
| RE-H4 | `SESSION_DRIVER=database` | The suite and the docs assume it. A role change takes effect on the next request (no session-cached permissions). |
| RE-H5 | **Upload limits:** PHP `upload_max_filesize` ≥ 20M, and a `post_max_size` and web-server body limit that accommodate the request. 10 × 20 MB = 200 MB worst case. RE-0 should report the host ceiling, and the app limit may be lowered rather than the host raised. | Attachments |
| RE-H6 | **Writable private storage** `storage/app/private/tickets/`, the disk quota, and the **backup** of `storage/` with the database | Attachments are authoritative data after Release 1 |
| RE-H7 | **Mail transport,** SPF/DKIM deliverability, and the `MAIL_FROM` identity | Customer notifications |
| RE-H8 | A correct **`APP_URL`** behind the production proxy | Absolute links in mail |
| RE-H9 | **Prebuilt assets** (Vite manifest, self-hosted fonts); no Node on the host | React Helpdesk and shell |
| RE-H10 | The per-request disk reads of `shell/bootstrap.js` (known, RE-2) **and `BrandMark::inline`'s SVG read** (newly recorded). The Blade Focused frame must not add another per-request read. | Performance and packaging |
| RE-H11 | **Streamed downloads** work through the host (`Content-Disposition`, no output buffering truncation) | Attachment download, CSV export |
| RE-H12 | The EPIC-010D production preflight runs before Release 1 | Existing gate |
| **RE-H13** | **A real operator new-ticket notification destination is configured and verified.** The production preflight fails when `HELPDESK_NOTIFY_ADDRESS` is unset or invalid in production. A delivery check (a test send, or an equivalent the release tooling defines) confirms that mail reaches it. A different mechanism needs an explicit owner ruling ([O3](#37-owner-decisions)). | Production must not ship Helpdesk without a new-ticket alert |

RE-0 is **not** implemented by this epic and chooses no host. EPIC-017 only states these assumptions. RE-H13 and the queue, mail and `APP_URL` checks (RE-H1, RE-H7, RE-H8) are carried into the release preflight design (RE-1) and must pass before Release 1.

## 24. `@shadcn/lint` contract

The `@shadcn/lint` policy (merged in PR #29, `AGENTS.md`, `eslint.config.js`, `src/eslint-suppressions.json` and `docs/testing/ci.md`) is on `main` and is **authoritative for all React work here**.

It is part of the baseline, not a prerequisite. WP1 re-reads it at start, because the baseline may evolve.

- **All six rules stay at error** for Helpdesk and customer-shell code: `no-raw-colors`, `no-unknown-classes`, `no-restyle`, `no-arbitrary-values`, `no-inline-styles`, `require-static-classes`.
- **No rule is weakened, scoped out or bulk-suppressed** for this epic. `src/eslint-suppressions.json` **must not grow**; it may only shrink.
- **Primitives own visual styling.** Callers add layout only.
  - A new visual need is met by a **variant** on the primitive.
  - A genuinely new primitive or token (candidates: the hourglass and clock `Status` glyphs; a top-nav item; the menu sheet; a conversation entry) goes through an explicit Direction D design-system decision in the owning WP amendment. It is never introduced by bypassing lint.
- Use semantic Direction D utilities only; no raw colours or literals; static class names.
- Runtime values only through the custom-property pattern (`w-(--x)`).
- Any `eslint-disable` needs a single-line, rule-specific justification approved in review. Zero is the target.
- The **Blade** Focused frame is governed by the Blade theme guard (Level A and the exact inventory), not by `@shadcn/lint`.

## 25. Testing strategy

### 25.1 Pest (feature, MariaDB)

- **Authorization matrix** ([§7.3](#73-authorization-matrix)): all EPIC-010D suites stay green. Tests asserting HTML change to Inertia assertions (`assertInertia`) with the **same** authorization outcomes. Coverage is converted, never dropped (EPIC-011 Phase F exit).
- **Own-ticket isolation:** an unrelated customer and a peer (same organization, same company) each get 403 on view, reply and download, with zero side effects. The list never contains another user's ticket.
- **Prop absence (exact-key allowlist):** customer detail and list props contain no `is_internal` entry, internal attachment, `sla_due_at`, **time entries, ticket time total or other users' time**, history actors, assignee id or email, or `company_id`.
- **Ticket-time eligibility (O1, N1):**
  - a customer (the owner included) starting a ticket-context timer, or creating or updating an entry with `ticket_id`, is **refused server-side** with a validation error and no entry, timer or other side effect;
  - an operator is allowed;
  - the EPIC-010D H9 "owner allowed" time-context test is **flipped, converted and recorded** in the WP2 conversion ledger, not deleted;
  - project-time behaviour (`ProjectTimeAccess`) is unchanged, and its suites stay green unmodified.
- **Presentation resolver:**
  - every capability in the P1 list → `operational`;
  - mixed capabilities with any operator-surface capability → `operational`;
  - the built-in `user` → `focused`;
  - `operator` → `operational`;
  - the dynamic `user + projects.manage` manager → `operational`;
  - a role change takes effect on the next request.
- **Resolver drift guard (P1):** every capability that gates a route under the operator, admin, CRM or System prefixes, and every capability `NavigationBuilder` uses for an operator-only workspace, is classified as operational or presentation-neutral (derived from the registered routes and navigation, not only from the hand-written list). The `PermissionCatalogue` classification check is kept as an additional guard.
- **Navigation:**
  - the Focused projection ⊆ the Operational keys for every persona;
  - Focused labels and order;
  - no `/operator`, `/admin` or `/crm` href;
  - shared-props key allowlist.
- **Validation:** create (required fields, enum values, attachment count and size), reply (body required, attachments), malformed filters (no 500).
- **Attachment type policy ([§20.1](#201-attachment-type-policy)):**
  - each allowlisted pair is accepted;
  - executables, HTML and SVG are refused, including a renamed file (an allowed extension with forbidden content) and a spoofed client MIME;
  - the same rule applies on create and both reply routes.
- **Search:** customer internal-text oracle (H3, kept); wildcard escaping; term cap; filter + search + pagination with query preservation.
- **Pagination:** deterministic order with tied `created_at`.
- **Email:**
  - recipient matrix (kept), per-recipient link, escaping, after-commit;
  - **O3:** Waiting on you and Resolved mails to the requester only (not to the actor who caused the change; none on auto-close to `closed`);
  - **O3:** the new-ticket alert goes to `HELPDESK_NOTIFY_ADDRESS` only, is skipped without error when unset outside production, and never goes to `tickets.assign` holders;
  - the production preflight check for the address (RE-H13), as far as it is testable in-app.
- **Attachments:** a failed create or reply leaves no orphan file and no fileless row; the download matrix is kept.
- **Lifecycle (O2):**
  - a customer reply on `pending_user` → `in_progress` and on `resolved` → `open`, each with a history row naming the customer;
  - a customer reply on `closed` → 422 with no reply, attachment row, file, status change, history row or notification;
  - customer replies on `open` and `in_progress` leave the status alone;
  - operator replies cause no implicit transition and are accepted on `closed`.
- **Query budgets** ([§26](#26-query-and-performance-boundaries)).
- **Guard:** `BladeThemeGuardTest` inventory updated per migrating PR; `BladeShellTest` covers the Focused Blade frame.

### 25.2 Vitest

- `CustomerShell`: landmarks; skip link; nav items from props; `aria-current`; menu sheet open, close, Esc, focus return; account trigger name; no timer, rail or drawer; `trail` ignored.
- `AppShell`: the branch; `TimerProvider` is available under both presentations; the Focused presentation renders **no timer pill or tray**.
- Customer pages: list (rows, empty states, filters to query), create (labels, errors, `aria-describedby`, in-flight button), detail (conversation order, attachments, closed-state message, the customer status label "Waiting on you"), reply (announcement, focus).
- Shared `Status`: the hourglass glyph and label.
- Operator pages: composer mode semantics, internal-note treatment, status options limited to valid transitions, stale assignee flag, bulk result summary, filter copy.
- Responsive state: the menu-sheet behaviour at a narrow width, using the existing `setWidthClass` helpers.

### 25.3 Playwright

New dedicated personas, so no existing bucket or fixture is touched:

| Identity | Role | Used by |
|---|---|---|
| `e2e-customer@intechral.test` | `user` | the customer shell and Helpdesk journeys |
| `e2e-customer-other@intechral.test` | `user` | the isolation journey (403 on the first customer's ticket) |

Both are seeded by `DevSeeder` (local/testing only), and each is minted at most once per worker. The login budget rises by ≤ 3 per new persona per run, still at least 2 below the 5-per-minute Fortify ceiling per bucket. The `e2e-browser-suite.md` identities table is updated.

**Journeys:**
1. Customer sign-in lands on the Focused Home.
2. Shell at 1280, 768 and 390, in light and dark: no overflow, nav and sheet keyboard, account menu, sign out.
3. Create a request with an attachment, then see the confirmation.
4. List, search and filter, and the query survives opening and returning.
5. Open, then reply.
6. An operator reply is visible to the customer.
7. **An internal note is NOT visible** (not in the DOM), and its attachment URL returns 403 for the customer.
8. The other customer gets 403 on the first customer's ticket URL and attachment URL, and the ticket is absent from their list.
9. Operator queue: filter, bulk assign, and resolve with confirmation.
10. Operator workspace: reply, note, status, assign, and a ticket timer (operator persona, its own ticket).
11. The Blade Focused frame on `/my/invoices` matches the React top bar (nav, account).

**Fixture rules:**
- **Never select by position** (`e2e-browser-suite.md` §"Shared records").
- Tickets are addressed by the number captured from the create redirect, or by a seeded `TKT-E2E*` fixture.
- Every created ticket title carries a run-unique `E2E-{runId}` prefix.
- **E2E ticket cleanup: locked design (owner/plan ruling, 2026-10-07).** Tickets have no delete route, and none is added. Cleanup uses a **local/testing-only artisan command**, built in WP2. **Accepted fixture growth is rejected.**
  - **Environment guard.** It runs only in explicitly allowed environments (`local`, `testing`, the same allowlist as `DevSeeder`'s E2E fixtures). It **refuses `production`**, and any environment not on the allowlist, with a non-zero exit before touching anything.
  - **Narrow target.** It deletes **only** tickets matching the E2E naming contract: titles starting with `E2E-`, which every browser-created ticket carries as a run-unique `E2E-{runId}` prefix. It cannot be pointed at an arbitrary id, number, user or pattern, so it cannot target customer tickets. The seeded `TKT-E2E*` fixtures are left alone; they are idempotent and owned by `DevSeeder`.
  - **Complete.** It removes the rows (replies, attachments and status histories by cascade) **and** the private files under `tickets/{id}/`.
  - **Reporting.** It reports the counts of tickets and files removed.
  - **Idempotent.** A second run removes nothing and succeeds.
  - **Harness.** The E2E harness calls it during or after teardown (`E2eCleanup` teardown and the `./dev test:e2e` post-run). `tickets` joins the tracked product-data counts, which must return to baseline. *(Refined by the WP2 G3-A rulings in §37: suite-level, never per test or per worker; owner restriction; time/task refusal.)*
  - **Tests.** Pest tests cover the environment refusal, the narrow match (a non-`E2E-` ticket survives), file removal and idempotency.
- **Timer ownership:** customers start no ticket timers. A refusal journey may assert the server rejection without creating time. The operator ticket-timer journey lives in the file that already owns operator ticket timers (`time-migration.spec.ts`), or asserts only on its own entry.
- Full Vitest and full Playwright never run concurrently. Hosted CI is the authoritative complete browser gate.

## 26. Query and performance boundaries

**Realistic N+1 risks get bounded tests** (constant query count for 1 vs N rows, the EPIC-015 A5.16 deterministic pattern):

| Surface | Risk | Bound |
|---|---|---|
| Customer list | per-row status or attachment lookups | constant in page size. No eager loads are needed beyond the row columns. |
| Customer detail / conversation | per-reply user and attachments | eager `replies.user`, `replies.attachments`, `attachments`, `statusHistories`; constant in reply count |
| Operator queue | user, assignee, stale-eligibility per row | eager `user`, `assignee`. Eligibility is computed once per page from the `tickets.assign` holder set. Constant in rows. |
| Operator workspace | time entries, history actors, candidates | eager. The time summary is one aggregate plus one limited list (the tracker's 3 inline queries move to the controller). |
| Shared props / shell | the resolver, navigation | The resolver uses the already-loaded Spatie permission cache: **zero additional queries**. Navigation stays lazy and memoized. Pinned by a test comparing a Focused vs an Operational request. |
| Customer Home | sections | each section a bounded query (`limit`), constant in data volume |
| Search | `LIKE '%term%'` over replies (`whereHas`) | Accepted for Release 1 volumes. Measured on a seeded set of about 5k tickets / 50k replies in WP5. Recorded, not optimized, unless it exceeds an agreed threshold (a hardening item). |
| Attachment metadata | per attachment `formattedSize` | computed in the resource, no queries |

## 27. Security and privacy review

| Threat | Existing protection | Planned test | Gap / action |
|---|---|---|---|
| IDOR / ticket-id guessing | `TicketPolicy::view`; sequential ids; list == view | peer and unrelated customer 403 on show, reply and download, with zero side effects | None. Kept. |
| Customer-to-customer isolation | `forUser`, policy | isolation Pest and Playwright journey 8 | None |
| Internal notes | `viewInternal` on display, download and search; mail silent | prop-absence tests; Playwright journey 7 | **React must not filter client-side.** The requester resource excludes internal content server-side (test). |
| Attachment access and content | Ticket → Reply → Attachment policy; private disk; forced download | existing matrix plus guessed internal id; allowlist tests including renamed and spoofed files | **Add the exact allowlist** with byte-detected type ([§20.1](#201-attachment-type-policy)) and orphan cleanup (F-7). No malware scanning (residual risk, Release 1 hardening). |
| Reply spoofing | `authorize('reply')` first; `user_id` from auth; `is_internal` coerced | existing H1 dataset kept | None |
| Company/organization leakage | `company_id` never written or displayed | prop-absence | The dead selector, which listed the user's org companies, is removed |
| Shared shell props | allowlisted, actor-own only | key-allowlist test | None. No operator counts in the shell. |
| Search result leakage | H3 opt-in | kept, plus wildcard escaping | F-4 fixed |
| Email leakage | recipients pass `view`; internal notes silent; after commit | kept, plus escaping | **F-3:** escape user text in mail. **Per-recipient links** (operators to the operator route). |
| **Operator time disclosure and customer ticket time (N1). Release 1 security/privacy requirement (blocker).** | none: the customer page lists everyone's ticket time, and `AccessibleTimeContext` lets the owner time their own ticket | exact-key prop absence (no entries, total or other users' time); server refusal of customer ticket-context timer start and entry writes, with no side effect; the flipped H9 test | **Closed in WP2 by two server mechanisms:** (1) the requester resource carries no time data, and (2) `AccessibleTimeContext` makes ticket-context time operator-only (`tickets.assign` + `view`). Removing the tracker UI is **evidence, not the boundary**. Hidden nav, hidden timer chrome and the Focused presentation are never relied on. |
| Stale session / authorization change | per-request Spatie check; database sessions | a role-change-next-request test; resolver flips on the next request | None. Presentation is never an access control. |
| Presentation confusion (an operator in the Focused shell, or the reverse) | — | the resolver matrix | By the P1 rule, a user with any operator gate is Operational. Authorization is unaffected either way. |
| CSV injection | `CsvText::safe` | kept | None |

## 28. Existing technical debt

| Item | Source | Disposition |
|---|---|---|
| "All Statuss" / "All Prioritys" | EPIC-016 A4.13 | **INCLUDE** (WP4: the React `FilterBar` replaces it) |
| Bulk-bar field-error placement | A4.13 | **INCLUDE** (WP4) |
| Customer label "Waiting on you" | A4.13 | **INCLUDE** (WP2) |
| Hourglass glyph in the shared `Status` vocabulary | A4.13, P6 | **INCLUDE** (WP2; both renderers) |
| Clock glyph (overdue) | P11 | **INCLUDE** (WP3; both renderers). The arrow glyph stays **LEAVE WITH EXISTING OWNER** (Finance). |
| `confirm()` → dialogs | A4.13 | **INCLUDE** for Helpdesk (bulk close/resolve, single close). Elsewhere **LEAVE WITH EXISTING OWNER**. |
| Page frame and `DataTable` density | P13/P15 | **INCLUDE** for Helpdesk pages (React primitives) |
| 390px table and card clipping (Helpdesk) | A4.13 | **INCLUDE** (React reflow); Finance's **LEAVE WITH EXISTING OWNER** |
| "Choose Files" alignment | A4.13 | **INCLUDE** (React attachment input replaces it) |
| Skipped-row bulk reporting | EPIC-010D §14 | **INCLUDE** (WP4) |
| Stale-assignee surfacing | EPIC-010D §13 | **INCLUDE** (WP3/WP4) |
| Reply-after-close semantics (F-2) | EPIC-010D | **INCLUDE** via O2 (approved): customer replies on `closed` refused; on `resolved` reopen to `open` |
| Notification format (F-3) | EPIC-010D | **INCLUDE** (escaping only; preferences POST-V1) |
| Search wildcard escaping (F-4) | EPIC-010D | **INCLUDE** (WP2) |
| Report date-window correctness (F-6) | EPIC-010D | **INCLUDE** (WP4) |
| Attachment type policy and transactional uploads (F-7) | EPIC-010D | **INCLUDE** (WP2) |
| Organization context on tickets (F-1/D3) | EPIC-010D | **LEAVE WITH EXISTING OWNER** (Directory) |
| Tenancy-scope role-name checks (F-5) | EPIC-010D | **LEAVE WITH EXISTING OWNER** (Directory) |
| Ticket-derived tasks (D5) | EPIC-010D | **POST-V1** |
| `/tasks` org-tab ticket clause (EPIC-011E C8, inert) | EPIC-011E | **LEAVE WITH EXISTING OWNER** (Tasks; inert since F-1) |
| Account-menu dead anchors (N6) | this audit | **INCLUDE** (WP1) |
| Ticket timer only on the customer route (N2) | this audit | **INCLUDE** (WP3) |
| `pending_user` label drift (N3) | this audit | **INCLUDE** (WP2/WP3) |
| Hidden category filter / backend-only bulk `status` (N4) | this audit | **INCLUDE** category filter (WP4). Bulk `status` stays backend-only, unchanged. |
| EPIC-004 stale acceptance checklist | this audit | **DEFER TO RELEASE 1 HARDENING** (doc hygiene); EPIC-017 supersedes it as the Helpdesk contract |
| React `--text-danger` / legacy `Badge` variants outside Helpdesk | EPIC-016 §22 | **LEAVE WITH EXISTING OWNER** |
| Browser-suite timing and the timer/Finance fixture flake | EPIC-016 | **DEFER TO RELEASE 1 HARDENING** |
| Queue inspector | Direction D §9 | **POST-V1** |

## 29. Incidents

- **No day-one need.** No code, data, document or owner ruling shows an operational need for Incidents in Release 1. The Release 1 class stays **B, default Post-v1**.
- **No seam is reserved.** Linking tickets to a future Incident needs only a nullable foreign key added at that time. Status vocabulary and navigation registration already accept new entries. Reserving a column or UI slot now would be speculative (roadmap principle 6).
- **Promotion.** If the owner promotes Incidents before WP3, they become a separately planned package after WP4. They are not folded into the MVP.

## 30. Knowledge / CMS

- **Post-v1.** None of these are included: article authoring, suggestions, ticket-to-knowledge conversion, customer knowledge search.
- **The only interaction is navigation.** Existing `/pages` remain reachable from the Focused nav as "Resources" while CMS exists. When Knowledge lands it **replaces that entry** through the registration seam ([§14](#14-customer-navigation-and-module-registration)).
- **No UI slot is reserved** on ticket pages.

## 31. Work packages

Six packages, WP0 included. Each implementation WP is one PR from the epic branch, is independently shippable, passes `./dev check` and hosted CI, and ends with its amendment in this document.

| WP | Schema | Routes | React | Blade | Mail |
|---|---|---|---|---|---|
| WP0 | — | — | — | — | — |
| WP1 | — | — | ✓ | ✓ (Focused frame) | — |
| WP2 | — | — (renderer only) | ✓ | ✓ (delete customer views) | — |
| WP3 | — | — | ✓ | ✓ (delete operator show) | — |
| WP4 | — | — | ✓ | ✓ (delete queue and reports) | — |
| WP5 | — | — | small | — | ✓ |

### WP0 — Design gate (this document) — **owner review complete; ready to commit**

- **Purpose:** lock scope, architecture and decisions.
- **State:** the architecture is approved; O1–O3 and P1–P6 are approved and recorded (§36, §37).
- **Exit:** committed docs-only on `main`.

### WP1 — Customer shell foundation

- **Purpose:** customers get the Focused presentation on every page they can reach.
- **Scope:**
  - the `ShellPresentation` resolver (P1);
  - `shell.presentation: 'focused'`;
  - the `NavigationBuilder` Focused projection and registration seam ([§14](#14-customer-navigation-and-module-registration));
  - `CustomerShell` with `TopNav`, menu sheet and `AccountTrigger` (34px avatar);
  - the `AppShell` branch;
  - hoisting `TimerProvider` (N7);
  - the Blade Focused frame;
  - the customer Home (focused `/dashboard`);
  - account-menu anchors (N6);
  - the `e2e-customer*` personas.

  **No Helpdesk page changes** beyond its nav label.
- **Contract already decided:** O1's nav set (no Tasks, no Time) and P1's resolver are part of this package's contract, not open inputs.
- **Dependencies:**
  - WP0 committed on `main`;
  - current `main` integrated and green, with a clean implementation start ([§38](#38-restart-and-handoff-notes)).

  The `@shadcn/lint` adoption is already on `main` and is not a dependency.
- **Key tests:** the resolver matrix and the **drift guard**; Focused ⊆ Operational; shared-props allowlist; zero extra queries; `CustomerShell` and `AppShell` Vitest (`TimerProvider` under both presentations, no pill or tray in Focused); Blade Focused frame Pest; Playwright journeys 1, 2 and 11; existing operator shell suites unchanged.
- **Exit criteria:**
  - a `user` customer sees the top bar on Home, Projects, Profile, Tickets (Blade), Invoices and Pages, at 390, 768 and 1280, in both themes, with no overflow;
  - operators see no change;
  - lint clean with the suppression file not grown;
  - hosted CI green.

### WP2 — Customer Helpdesk in React

- **Purpose:** the complete customer support flow on the Focused shell.
- **Scope:**
  - React `pages/tickets/{index,create,show}`;
  - requester resources;
  - reply with attachments;
  - customer status labels;
  - the hourglass in both renderers;
  - search contract and F-4;
  - the exact attachment allowlist ([§20.1](#201-attachment-type-policy)) and atomic uploads (F-7);
  - company selector removal;
  - **N1 security/privacy closure** (O1): requester props carry no time data, and `AccessibleTimeContext` makes ticket-context time operator-only. The tracker is removed from the customer view as evidence. The flipped H9 test is recorded in the conversion ledger.
  - the O2 lifecycle (approved);
  - P2 (`/tickets/{id}` requester-only; a non-owner operator is redirected to `/operator/tickets/{id}`);
  - deletion of the Blade `tickets/{index,create,show}` and the customer-only partials;
  - guard inventory update;
  - the locked **E2E cleanup command** ([§25.3](#253-playwright)).
- **Dependencies:** WP1.
- **Key tests:** the authorization matrix converted to Inertia; prop absence (time included); ticket-time server refusal; the O2 lifecycle; isolation; validation and the attachment allowlist; search and pagination; the cleanup command; Playwright journeys 3–8.
- **Exit criteria:**
  - no customer Helpdesk Blade view remains;
  - every EPIC-010D target test is green, in converted or kept form;
  - N1 is closed **on the server** (props and eligibility) and N5 is gone;
  - E2E runs return the tracked `tickets` count to baseline.
- **Scope note.** WP2 is **one cohesive package**: the customer Helpdesk boundary, requester resources, lifecycle, attachments, search, the security/privacy correction and the deletion of the customer Blade pages. It is not split in advance.
  - **Split trigger:** only if implementation proves the PR is no longer independently reviewable. Then split along the boundary that keeps each PR shippable (for example, the server contract and resources first, then the React pages and Blade deletion), recorded in the WP2 amendment.
  - No package is renumbered.

### WP3 — Operator ticket workspace in React

- **Purpose:** operators work a ticket in React.
- **Scope:**
  - React `pages/operator/tickets/show`;
  - the conversation with internal notes;
  - the Reply / Internal note composer;
  - status (valid transitions) and assignment (eligibility, stale flag);
  - ticket time with `TimerControl` and a summary (N2);
  - the clock glyph;
  - `ConfirmationDialog` for close;
  - operator labels (P4);
  - deletion of Blade `operator/tickets/show` and `components/time-tracker` if it has no other consumer (verify; EPIC-011E lists `timer-overlay.js` for ticket show only);
  - re-homing the `time-migration.spec.ts` ticket-timer test.
- **Dependencies:** WP2 (shared conversation components).
- **Key tests:** H1/H2/H9 kept; internal composer coercion; transitions; assignment eligibility; time eligibility via `AccessibleTimeContext`; query budget; Playwright journey 10.
- **Exit criteria:**
  - the operator workspace is complete;
  - the ticket timer is available only to operators on Helpdesk pages;
  - the Blade show is deleted.

### WP4 — Operator queue, bulk and reports in React

- **Purpose:** complete Phase F.
- **Scope:**
  - React queue with `DataTable`, `FilterBar` (category added), `BulkBar` (assign, resolve, close with confirmation, applied and skipped counts, in-place errors) and reflow at S;
  - stale-assignee display;
  - the React reports page with the F-6 fix;
  - CSV unchanged;
  - deletion of Blade `operator/tickets/{index,reports}` and the remaining partials;
  - NavigationBuilder Helpdesk entries flip to `inertia`.
- **Dependencies:** WP3.
- **Key tests:** queue filters and query preservation; bulk validation and transaction; skipped reporting; export characterization kept; query budget; Playwright journey 9.
- **Exit criteria:**
  - **no Helpdesk Blade view remains**;
  - the guard inventory is updated;
  - reports are at CSV parity.

### WP5 — Notification hardening and closeout

- **Purpose:** production-ready mail and epic verification.
- **Scope:**
  - per-recipient links;
  - F-3 escaping;
  - **O3 (approved):** the "Waiting on you" email, the "Resolved" email, and the new-ticket operator alert to `HELPDESK_NOTIFY_ADDRESS`;
  - the production readiness of the alert destination: the release-preflight check contract handed to RE-1 (RE-H13), and confirmation that production cannot ship with the alert silently off;
  - the cross-cutting authorization review of every Helpdesk route;
  - search volume measurement;
  - NVDA + Firefox manual pass;
  - the full Playwright run on hosted CI;
  - the RE handoff confirmation;
  - the docs closeout (roadmap, index, EPIC-010D/EPIC-011 notes, `e2e-browser-suite.md`).
- **Dependencies:** WP4. The transitions that WP2 and WP3 implement already emit the status events cleanly.
- **Key tests:** the mail matrices, the O3 notifications and the unset-address behaviour; full suites.
- **Exit:** [§33](#33-exit-criteria).

**Splitting rule.**
- WP3 may split into the conversation vs the context rail, and WP4 into the queue vs reports, **only** if implementation size or reviewability requires it.
- WP2's own split trigger is in its scope note.
- Packages are not renumbered, and are never merged.

## 32. Branch and PR strategy

This follows EPIC-016's practice, confirmed from git history: WP0 as a docs commit on `main`, then **one long-lived branch** `feature/epic-017-helpdesk-mvp-customer-shell` from current `main`, with **one PR per WP** into `main`.

- The branch is synced to `main` (fast-forward) after each merge.
- Each PR is independently reviewed and has hosted CI green before merge.
- Nothing is created by this design gate: no branch, no PR.

## 33. Exit criteria

1. Customers (Focused presentation) complete sign in, create (with attachment), list, search, open, reply and sign out, keyboard-only, at 390, 768 and 1280, in both themes, with no horizontal overflow. Customer replies follow the **O2 lifecycle**: `pending_user` → `in_progress`, `resolved` → `open`, `closed` refused with no side effect. This is proven by Pest.
2. Operators complete queue triage (filter, bulk with confirmation), workspace reply and internal note, status, assignment, ticket time, and reports with CSV, all in React.
3. **No Helpdesk Blade view remains.** `BladeThemeGuardTest` is updated. NavigationBuilder Helpdesk entries are `inertia`.
4. Every EPIC-010D contract holds:
   - own-ticket visibility;
   - internal-note boundaries (display, download, search, mail);
   - reply authorization first;
   - assignee eligibility;
   - CSV safety;
   - Ticket numbers.

   Their tests are kept or converted, **none dropped**.
5. Customer props never contain internal content, SLA, **any time data** (entries, total, other users' time), history actors, or `company_id` (exact-key allowlist tests).
   - Ticket-context time is **operator-only on the server**: a customer attempt to start or record ticket time is refused with no side effect (N1 closed, O1).
   - The flipped H9 test is recorded.
6. The `ShellPresentation` resolver is the only presentation writer, `AppShell` and the Blade layout are the only branch points, and no page branches on presentation (static check). The P1 drift guard is green.
7. Customer navigation comes only from the `NavigationBuilder` Focused projection, Focused ⊆ Operational for every persona, and no disabled future items are shown.
8. Existing mail works with per-recipient links and escaped user text.
   - The O3 notifications (Waiting on you, Resolved, the new-ticket alert to `HELPDESK_NOTIFY_ADDRESS`) are implemented and tested.
   - The production requirement for a real new-ticket destination is handed to the release preflight (RE-H13). Production Helpdesk does not ship without it.
9. Query budgets hold on list, detail, queue, workspace and the shell.
10. `@shadcn/lint`, the baseline React design-system guard, is clean with **no growth** in `eslint-suppressions.json`. The Blade guard is green.
11. The NVDA pass is recorded, with findings resolved or carried to Release 1 hardening.
12. `./dev check` and hosted CI (Pest, Vitest, Playwright) are green. Product-data counts, `tickets` included, return to baseline across E2E runs through the **E2E cleanup command**, with tickets addressed by number and never by position.
13. The roadmap, epic index, EPIC-011 Phase F note and E2E identity docs are updated.

## 34. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Chrome-agnostic pages depend on more `OperatorShell` context than the timer | Medium | WP1 inventories the hooks first (N7); Vitest renders each page under `CustomerShell` |
| Two renderers' Focused frames drift | Medium | One navigation source; shared tokens; Pest + Playwright parity journey 11 |
| Converting HTML assertions to Inertia silently drops coverage | Medium | A conversion ledger in each amendment (EPIC-010D A1.9 style): every replaced test is named |
| A requester resource leaks a key through a later change | Medium | An exact-key allowlist test, not a denylist |
| E2E ticket growth (no delete route) | High without action | The locked local/testing-only cleanup command ([§25.3](#253-playwright)); `tickets` is tracked in the run counts |
| The cleanup command is ever run against real data | Low | It refuses production and non-allowlisted environments, matches only the `E2E-` title contract, and takes no target arguments (Pest-tested) |
| The P1 resolver list drifts from new capabilities | Low | Permanent drift guard derived from the registered routes and `NavigationBuilder` (P1), plus the `PermissionCatalogue` classification check |
| The `@shadcn/lint` baseline or suppression file evolves before or during the epic | Low | Re-read the policy at WP1 start; the no-growth rule ([§24](#24-shadcnlint-contract)) applies to whatever the baseline is then |
| Production ships with no new-ticket alert | Medium without a check | RE-H13 preflight; an explicit owner ruling needed for any alternative |
| Upload limits on the host lower than 20 MB | Medium | RE-H5. The app limit becomes a config value. Lowering it is acceptable. |
| Mail Markdown escaping changes the look of existing mails | Low | Snapshot the rendered mail before and after |

**Rollback.** Each WP is a revertable PR. WP1 can be neutralized by forcing the resolver to `operational` (a one-line change), restoring today's capability-filtered shell.

## 35. Deferred items

**Post-v1:**
- Incidents, Knowledge, the queue inspector, saved views;
- SLA policy redesign (business hours, pause, breach alerts), routing, escalation, automation;
- reply-by-email and rich text;
- customer-initiated close;
- organization-level visibility and non-User requesters (Directory);
- the in-app notification centre and preferences;
- global search;
- the "Ask for help" top-bar action;
- user-selectable shell presentation (EPIC-013 §31.1);
- administrative active timer oversight ([roadmap](../product/product-roadmap.md#administrative-active-timer-oversight); not an EPIC-017 release blocker).

**Directory:**
- `company_id` / organization context;
- the account-menu organization field;
- the multi-org switcher;
- F-5 role-name scopes.

**Release 1 hardening:**
- the full screen-reader and device matrix;
- search performance thresholds;
- the EPIC-004 doc hygiene;
- the inherited browser-suite timing flakes.

## 36. Plan-level choices (approved)

These design decisions were taken by this gate and **approved by the owner (2026-10-07)**. None remains an unresolved default.

| # | Status | Choice | Alternative (rejected) |
|---|---|---|---|
| **P1** | **APPROVED** | The presentation resolver is `operational` iff the user holds **any** capability in the explicit operator-surface list ([§8.2](#82-applying-the-shell-one-resolver-one-branch)), otherwise `focused`. It reads capabilities only, never role names; adds no permission; and is never authorization. It is protected by a **permanent drift guard** derived from actual route and navigation capability usage. | A role-name check (EPIC-013 §23 forbids role coupling); a new presentation permission; a user preference (EPIC-013 §31.1, Future) |
| **P2** | **APPROVED** | `/tickets/{id}` is the **requester-safe view**: it never renders operator-only information. An operator viewing **someone else's** request is redirected (302) to `/operator/tickets/{id}`. An operator who is the requester may use the requester view. Mail and timer links resolve per recipient. EPIC-010D H9 tests are converted: the operator still reaches every ticket; only the page changes. | A capability-aware `/tickets/{id}` that shows internal notes to operators |
| **P3** | **APPROVED** | The customer label is "**Support**", the page `h1` is "**Your requests**", and the primary action is "**New request**". | "Help" |
| **P4** | **APPROVED** | The operator label for `pending_user` is "**Waiting on customer**" (replacing "Pending" / "Pending user"). | Keep "Pending" |
| **P5** | **APPROVED** | Customer history shows status changes **without actor names** | Show actor names (today) |
| **P6** | **APPROVED** | Customer detail shows the assignee as "**Handled by {name}**". No email or id is exposed. | Hide the assignee from customers |

**Implementation details deliberately left to the WP amendments.** They are not owner decisions:
- the exact attachment allowlist entries ([§20.1](#201-attachment-type-policy));
- the cleanup command's name and flags;
- the customer email wording;
- whether WP2, WP3 or WP4 needs its split trigger.

## 37. Owner decisions

**All owner decisions raised by this gate are approved (2026-10-07). No implementation package waits on an owner answer.** The original questions are kept for the record.

| # | Question (historical) | Options considered | **Ruling** | Binding contract |
|---|---|---|---|---|
| **O1** | Do Release 1 customers keep Tasks and Time, and ticket time logging? | (a) Focused nav omits Tasks and Time; general time permissions unchanged; ticket time operator-only. (b) Keep Tasks and Time in the Focused nav. (c) Also remove `time.log` and related permissions from `userDefaults`. | **APPROVED: (a)** | See the O1 notes below the table. |
| **O2** | What happens when a customer replies? | (a) `pending_user` → `in_progress`; `resolved` → `open`; `closed` refused. (b) No status effect; refuse on resolved and closed. (c) Keep today's behaviour. | **APPROVED: (a), as written** | See the O2 notes below the table. |
| **O3** | Which email additions does Release 1 need? | (a) None. (b) Customer emails on `pending_user` and `resolved`. (c) (b) plus a new-ticket alert to `HELPDESK_NOTIFY_ADDRESS`. (d) (b) plus an alert to every `tickets.assign` holder. | **APPROVED: (c), with a production requirement** | See the O3 notes below the table. |

**O1 contract:**
- The Focused nav omits **Tasks** and **Time** and stays within Direction D's maximum of five items ([§14](#14-customer-navigation-and-module-registration)).
- General `user` time permissions are **not** removed in this epic. Project time stays governed by `ProjectTimeAccess`.
- **Helpdesk ticket time is operator-only.** Starting or viewing ticket-context time requires `tickets.assign` eligibility through the server-side `AccessibleTimeContext` seam and the operator resource. Hidden nav, hidden timer UI and the `CustomerShell` presentation are **not** the boundary.
- A customer attempt to start ticket-context time is refused server-side with no side effect.
- The EPIC-010D H9 "owner allowed" time-context test is flipped, converted and recorded, not deleted.
- N1 is a **Release 1 security/privacy requirement** ([§27](#27-security-and-privacy-review)).

**O2 contract:**
- A customer reply on **`pending_user` → `in_progress`**, and on **`resolved` → `open`**.
- A customer reply on **`closed`** is **refused server-side**: 422 with a customer-safe validation message, no reply, no status or history side effect.
- History records the customer as actor for an implicit transition.
- Operators keep the current operator contract (they may reply on any status; no implicit transition).
- **This ruling supersedes any earlier conversational shorthand that described a resolved-ticket reply as going to `in_progress`.** The authoritative result is `open`, within the existing transition map ([§13](#13-status-priority-and-internal-note-semantics)).

**O3 contract:**
- A customer email when the status becomes `pending_user` ("Waiting on you"), and when it becomes `resolved`.
- A new-ticket operator alert to **`HELPDESK_NOTIFY_ADDRESS`** only. Every `tickets.assign` holder is **not** mailed.
- The address **may be unset in local and test environments**.
- **Release 1 production must have a real operator new-ticket destination**, configured and verified by the release preflight (RE-H13). It must not ship silently disabled.
- Any other mechanism requires an explicit owner ruling ([§11](#11-email-and-notifications)).

**New owner decisions raised by the remediation pass: none.**

**N1 existing-entry exception — APPROVED (2026-10-08, after the independent WP2 G1 closure review; to be carried into Amendment 2).** This refines the O1 contract for records that already exist; it does not reopen O1.
- An owner may, without requalifying for `tickets.assign` or ticket visibility, do these four things to an **existing** ticket-linked time entry: **stop** it if it is a running timer, **edit the description** of a running timer, **delete** it, or **move it out of ticket context**.
- Existing time permissions, ownership requirements and billing locks remain enforced on all four paths.
- Stopping a running timer records the accumulated elapsed duration against its existing ticket and **retains the entry's `billable` designation**.
- None of this authorizes creating or re-establishing a ticket association. Starting a timer, logging an entry, or moving or editing an entry onto a ticket still requires `tickets.assign` **and** ticket visibility (`AccessibleTimeContext`, `TimeEntryController::update`).
- The owner **accepts the bounded edge case of an abandoned timer accumulating excessive duration** (a pre-existing ticket timer left running is stored in full when stopped).
- No further G1 behaviour change is required. The four paths are pinned as `PRESERVED` in `TicketTimeEligibilityTest`; changing one is an explicit owner act.
- Related future work, not a release blocker: [Administrative active timer oversight](../product/product-roadmap.md#administrative-active-timer-oversight).

**N2 continuity — APPROVED (2026-10-08, before WP2 G2; to be carried into Amendment 2).** Plan item D-1, named "N2 continuity" to keep it apart from the roadmap's D1 and Release 1's D3. It does not reopen O1 or P2.
- The existing embedded `x-time-tracker` is temporarily reused on the **operator** ticket page (`operator/tickets/show.blade.php`), gated by `time.log` inside the route's existing `tickets.assign` gate. It keeps the operator's in-context start/stop, running state, ticket total and recent entries, through the existing script and `/time/*` endpoints. It adds no timer subsystem and **no authorization**: G1's server rule (`tickets.assign` and ticket visibility) still decides every start.
- `/tickets/{id}` is the requester-safe page and carries **no ticket-time data** (exact-key and sentinel tests). The legacy customer Blade page, which was the old workaround, is deleted, not kept.
- **Removal condition.** WP3 owns the replacement: the React operator ticket page's `TimerControl` and time summary (N2). It is removed when that page ships, **not before**, and the removal is exactly this list (found by a repository search for `x-time-tracker`, `time-tracker`, `data-time-tracker`, `timerStarted`, `timerStopped` and `Time Tracked` after WP2 G2 review F4). The same pointer is on the embed itself.
  - **Delete (product):**
    - the `@can('time.log') <x-time-tracker …>` embed in `resources/views/operator/tickets/show.blade.php`;
    - `resources/views/components/time-tracker.blade.php` and `resources/views/components/time-tracker/running.blade.php`;
    - the `timerStarted` listener and the `timerStopped` dispatch in `resources/js/shell/blade-timer.ts` (and the header paragraph there that names the tracker as the consumer). Nothing else dispatches or listens: the only dispatcher is the tracker, and the only listener is this one.
  - **Delete (tests):**
    - `tests/Feature/Tickets/TicketEmbeddedTimeTrackerTest.php`, **except** its last test, "does not give the tracker to the requester…" (requester-side; see Keep below);
    - in `tests/Feature/Tickets/RequesterTicketPropsTest.php`, the two tests marked `TEMPORARY (N2 continuity)`: "N2: puts the tracker on the operator page…" and "N2: offers the operator-page tracker only to a holder of time.log". Whole tests; they are separated from the permanent requester assertions for this reason;
    - in `tests/Feature/Ui/SemanticStateMappingTest.php`, the four tracker tests (stopped, running, server-rendered template and script, endpoint and event contract) and the `components/time-tracker`, `components/time-tracker/running` entries in the "no legacy styling" view list near line 635;
    - in `tests/Feature/Ui/TargetAccessibleNamesTest.php`, "keeps the embedded ticket time tracker Start Timer name on the operator ticket page";
    - in `tests/Unit/Configuration/BladeThemeGuardTest.php`, the `components/time-tracker.blade.php` and `components/time-tracker/running.blade.php` entries (lines near 57, 106 and 514), and the `components/time-tracker` directory root; the exact inventory count changes with them;
    - in `tests/Support/semantic_state_gallery.php`, the `<x-time-tracker.running>` case, and, in `tests/Browser/blade-theme-controls.spec.ts`, the `tracker-running` gallery assertions (the `[data-time-tracker-running]` mark and Stop, inside "every state, rendered by the production partials") and the whole "Semantic state on the operator ticket page: the embedded time tracker" test;
    - in `resources/js/shell/blade-timer.test.ts`, the `timerStopped` dispatch test, the "adopts a timer announced by timerStarted" test, and the two `timerStarted` dispatches used to trigger a reconcile (re-point those at another trigger);
    - in `tests/Browser/time-migration.spec.ts`, **re-home the test, delete one step**: "a timer started from the embedded Blade tracker is reconstructed by React" becomes the React `TimerControl` journey (WP3 already owns this), and the broader time-migration coverage is retained. The step added in G2 review F7, which starts a second timer and stops it with the embedded tracker's own in-place **Stop** button (the block after the React stop, before the `E2eCleanup` comment), exercises only the Blade tracker and **is deleted** with it; it is not re-homed.
  - **Keep (permanent requester-side privacy, N1/O1).** Removing the temporary tracker must not weaken these, and they must pass unchanged after WP3:
    - `RequesterTicketPropsTest`: "leaves ticket time off the requester page…", the exact-key and sentinel tests, and the Time-data sentinels in `propsFixture`;
    - `TicketEmbeddedTimeTrackerTest`'s last test, moved into `RequesterTicketPropsTest` or `TicketTimeEligibilityTest` when the file goes: the requester's page has no tracker and a ticket-context timer start is refused (422 on `ticket_id`);
    - `TicketTimeEligibilityTest` (G1) in full.
  - **Not removable by this work:** `database/seeders/DevSeeder.php`'s fixed ticket `TKT-E2E1` (the theme and timer specs still use it; only the comment naming `x-time-tracker` goes stale), and the comments in `TaskTimeSummaryPresenter` and `task-time-panel.tsx` that cite the tracker historically.

**WP2 G2 review remediation: items carried into Amendment 2** (independent G2 review, 2026-10-09; no Critical, High or Medium findings; none changes a P-choice or an owner ruling):
- **F5, operator who is also the requester.** `/tickets/{id}` is built by the requester presenter for its owner whatever their capability, so an operator opening their own request sees "Replying reopens this request" on a `resolved` request and no composer on a `closed` one, while the operator reply contract (§13, line "Operators keep the current operator contract") applies no implicit transition and accepts a reply on any status. **Disposition for G2 (approved): preserve the requester-safe interface and the server behaviour; no actor-dependent reply semantics.** Record in Amendment 2 as a known discrepancy of an edge persona. Alternatives for a later owner decision: (a) accept as documented, recommended; (b) make `reply.notice` depend on the viewer's `tickets.assign`.
- **F6, page title.** §19.1 says `Title: "Support"` and `h1` "Your requests". The page sets `<Head title="Your requests">`. The spec does not say whether "Title" means the document `<title>`, the workspace label ("Support" is the Focused navigation label), or both, and the route announcer reads the `h1`. **Not changed:** an approved requirement is not rewritten to match the code. For Amendment 2 to settle: `<Head title="Support">` (document title follows the workspace label) or `"Your requests"` (as built).
- **F6, row props.** §19.1 lists the row props without `createdAtHuman`, but its field line requires "created (relative, with an absolute `<time datetime>`)". The server supplies the relative text so the page needs no date library or client clock. Reconciled in §19.1.
- **F7 (closed in G2).** The embedded tracker's in-place **Stop** button is now clicked in the browser: the operator test in `tests/Browser/time-migration.spec.ts` ("a timer started from the embedded Blade tracker is reconstructed by React") starts a second timer on the fixture ticket and stops it with the tracker's own Stop, proving it by the reloaded tracker offering Start again with no timer running. Pest (stop endpoint) and the theme spec's template check remain. The step is temporary with the tracker: see the `time-migration.spec.ts` entry in the removal inventory above.
- **D-2 (optional future hardening; accepted, no requester code affected).** The requester import boundary (`eslint/requester-boundary.js` and the static scan in `resources/js/test/requester-import-guard.test.ts`) does not inspect `import.meta.glob()` references, so `import.meta.glob('./operator/*')` in requester code would not be flagged. No requester module uses it. Handling it, in the rule and the scan together, is optional hardening that no work package depends on. Computed `import()` specifiers (a variable or a concatenation) cannot be resolved statically and are deliberately not judged. The boundary checks direct imports only, not transitive ones.
- **F8 (accepted).** The list page's exact-key assertion is not recursive. The approved row contract is flat; the detail page, which is nested, is checked at every depth.
- **F9 (accepted).** An actor holding `tickets.assign` without `tickets.view` is refused (403) by the `/tickets/{id}` route middleware before the P2 redirect. That is existing route authorization and is unchanged; a queue-only user opens the operator page directly. The navigation visit modes are asserted in `NavigationBuilderTest` and `FocusedNavigationTest`: "My requests" is an `inertia` visit, and the operator Helpdesk workspace with its queue and reports items stays `document`; no authorization changed.

**Not raised as owner decisions** (settled by evidence or existing rulings):
- the routing topology;
- the organization switcher;
- the Directory dependency;
- the renderer strategy (the Release 1 boundary already requires the operator React migration);
- Incidents (B, default Post-v1);
- Knowledge (C);
- schema changes (none);
- customer selection of Critical priority (preserved existing behaviour, owned by the future SLA-policy design, [§13](#13-status-priority-and-internal-note-semantics)).

**WP2 G3-A: E2E ticket cleanup rulings — APPROVED (2026-10-09; to be carried into Amendment 2).** They refine the locked §25.3 cleanup command; they do not reopen it. The full Amendment 2 ledger belongs to G3-C.
1. **Suite-level cleanup.** The untargeted command runs once per suite, never per test or per worker: every worker's tickets share the `E2E-` prefix, so a run mid-suite would delete tickets other workers are still using. `E2eCleanup` (per test) does not call it.
2. **Eligibility.** A ticket is eligible only if its title begins with the literal `E2E-` **and** its requester is a seeded E2E customer (`e2e-customer@intechral.test`, `e2e-customer-other@intechral.test`). The seeded fixtures `TKT-E2E1` and `TKT-E2E2` (titled `E2E fixture: …`) are never eligible.
3. **No targeting.** The command, `e2e:cleanup-tickets`, takes no argument or option. It runs only in `local` and `testing`, and refuses anything else before reading or changing data. *(Review remediation: both the runtime environment and the configured `app.env` must be one of them, because the global `--env` option overrides only the runtime value; see below.)*
4. **Linked records are refused, not detached.** A ticket with any linked time entry or task blocks the whole run, before any deletion, with a non-zero exit. `nullOnDelete` on `time_entries.ticket_id` and `tasks.ticket_id` is unchanged, and nothing is nulled, re-pointed or deleted on those records' behalf.
5. **Files first, failure-aware, idempotent.** Per ticket: remove `tickets/{id}/` on the private `local` disk, confirm it is gone, then delete the row. A file failure keeps the row; a second run removes nothing and succeeds.
6. **Pre-run recovery and post-suite cleanup.** `./dev test:e2e` sweeps before the baseline, cleans once after Playwright has exited, and reconciles `tickets` to the baseline. A mismatch fails the run; nothing is deleted to make it pass. *(Review remediation, **owner-ratified 2026-10-09 (Option A)**: under the wrapper, Playwright's `globalTeardown` hands the cleanup to the wrapper, which runs it as `portal`; a suite started by hand keeps the teardown responsible. The wrapper also reconciles the attachment-directory count. See below.)*
7. **Session isolation is unchanged.** `workers: 3` and the per-worker sessions are untouched.
8. **EPIC-015 task-time behaviour is unchanged.** No journeys (3–8) are part of G3-A.

**WP2 G3-A: independent safety review and remediation** (the review's findings and what was done; **recorded as facts, not as rulings**). Two of the remediations are design choices that needed the owner's explicit confirmation, and **the owner ratified both on 2026-10-09**: the wrapper-owned cleanup run as `portal` (Option A, MEDIUM-2) and `#[DeleteWhenMissingModels]` on the two ticket notifications (Option C, LOW-2). Both were implemented and independently reviewed for technical correctness before that approval; the review was not the approval. An independent review found one High, two Medium and three Low defects in the first implementation. Each is corrected, with regression tests that were also run against deliberately broken versions of the code to confirm they fail.
- **HIGH-1: an unreadable attachment directory read as "already gone".** The first implementation treated the attachment root as invisible only when `tickets/` itself could not be seen. In practice `storage/app/private` is `0777` and `tickets/` is `0700 portal`, so a process running as another user saw `tickets/` but not `tickets/{id}`: it deleted the rows, orphaned the `0600` files and **exited 0**. (The earlier text of this section, which said the command "fails loudly instead of orphaning files", was wrong, and is removed.) The directory state is now **proven by listing** the storage root, `tickets/` and `tickets/{id}`; a path that cannot be listed is *unknown*, never *absent*, and an unknown state keeps the row and fails, refusing the whole run up front when it is systemic. The original wrong-UID scenario was re-run against the real command as a different UID, in both permission shapes: it refuses with exit 1 and keeps the row, the attachment rows and the file.
- **MEDIUM-1: the time/task check did not prevent detachment.** The check made just before deletion took no lock, so a time entry committed in the window was silently set to NULL by `nullOnDelete`. Each ticket is now removed in one transaction that takes `SELECT … FOR UPDATE` on the ticket row (which makes InnoDB hold back any insert or re-point of a child row), re-checks the title and owner, reads the linked rows with locking reads, removes the files and deletes the row. The up-front whole-run refusal is kept as a courtesy, not as the safety mechanism. The `nullOnDelete` keys and EPIC-015 time/task behaviour are unchanged. Proven with a second database connection and the real command: a link committed before the lock makes the command refuse; a link attempted after the lock waits (error 1205) and, once the ticket is gone, is rejected by the foreign key (1452); the persisted state shows nothing attached or nulled. The reviewed task-deletion guard entry is now the exact line `$ticket->delete();`, which deletes the locked ticket, never a task row; any other delete call in that file, or a change to this one, still fails the guard.
- **LOW-1: the environment refusal could be bypassed with `--env`.** `APP_ENV=production php artisan e2e:cleanup-tickets --env=local` ran. Both the runtime environment and the configured `app.env` must now be `local` or `testing`; the bypass is a regression test in a real child process against an unreachable database, which also shows the refusal comes before any query.
- **MEDIUM-2: CI execution identity (Option A; owner-ratified 2026-10-09).** The owner's ruling: when Playwright is launched through `./dev test:e2e`, the wrapper owns the E2E ticket cleanup and runs the command as the `portal` filesystem user, and `globalTeardown` delegates to it in that mode; a direct Playwright run keeps `globalTeardown` responsible and must fail visibly when its identity cannot safely remove the private attachments. A permission failure is never silently ignored. Playwright runs as the host/runner UID, which is not `portal` on a hosted runner, and cannot become `portal` from inside the container. `./dev test:e2e` therefore sets `E2E_TICKET_CLEANUP_OWNER=dev` for Playwright; `globalTeardown` then deletes nothing, and the wrapper runs the cleanup as `portal` after Playwright has exited. A suite started directly keeps `globalTeardown` responsible, and a permission refusal is fatal there. Private-directory permissions are not relaxed and the runner's UID is not assumed. The wrapper also reconciles the **attachment-directory count** (read as `portal`, baseline taken after the recovery sweep, an unreadable directory failing the run and never counting as zero), because a missing ticket row is no proof its files are gone; it is not an orphan sweeper and deletes nothing. The CLI self-tests cover the hand-off signal, the execution identity, the ordering, and the teardown hook itself.
- **LOW-2: queued notifications (Option C; owner-ratified 2026-10-09).** `TicketCreatedNotification` and `TicketRepliedNotification` are `ShouldQueue` over Redis. When the worker unpacks a job whose ticket or reply no longer exists, the missing-model error (`ModelNotFoundException`) is raised immediately, before the notification runs. Without the attribute the job is failed on the spot, with no retry, and lands in `failed_jobs`. Laravel 13.17 (installed) honours `#[DeleteWhenMissingModels]` for queued notifications (verified, not assumed), so both classes now carry it and that one case, a missing ticket or reply, ends as a successful discard with no `failed_jobs` row. **This is an explicitly authorized, narrowly scoped production notification lifecycle change (owner-ratified 2026-10-09):** two classes, no change to queue workers, connections, recipients or message content. Nothing else changes: a failure while the notification is being sent (a mail transport error included) still fails and is retried as before, and an existing ticket still sends. A deleted *notifiable* (the recipient user) is not part of this change or of the approval: the notifiable is queued as a collection, which restores empty, so such a job already did nothing without the attribute (observed in an independent probe, with and without it). Tests use the real database queue and `queue:work --once`, and include a control without the attribute that does fail. The cleanup never purges Redis or `failed_jobs`.
- **LOW-3: stale eligibility.** The title and owner are re-checked on the locked row before deletion.

Limitations that remain, accepted:
- **The filesystem is not transactional.** A crash after the files went and before the commit leaves a ticket with no files; the next run deletes it. An E2E ticket's files are not recoverable once removed. Nothing can lose a row while its files remain.
- **Single-suite constraint.** A host lock plus a probe of the container's process table stop `./dev` against `./dev` and a visible running suite. They cannot see another machine or a suite started after the check, and the lock has two small windows (between creating the directory and writing the PID; two runs clearing the same stale lock). It is a supported constraint, not a guarantee.
- **Interruption.** The teardown ran after a failed test, an abruptly exiting worker and SIGINT in disposable probes; it does not run after SIGKILL, an out-of-memory kill or a lost container. Under the wrapper the post-run cleanup runs whenever the wrapper itself is still alive when Playwright exits: after an ordinary Playwright failure and after a worker failure. It is **not guaranteed** if the wrapper terminates (its terminal closes, or it is signalled or killed) or the host or container is lost: the cleanup step then never runs, and whether Playwright stops with the wrapper has not been probed (it may keep running in the container). The recovery sweep at the start of the next `./dev test:e2e` removes eligible leftovers, but only once that invocation has passed the single-suite checks, and a suite still running refuses it. The single-suite constraint above is unchanged.
- **Lock waits.** A conflicting lock held by someone else waits up to `innodb_lock_wait_timeout`, and a deadlock with a concurrent time-entry write is possible in principle; either ends in that ticket being reported and kept, never in a detach.
- **Command and disk belong together.** The command assumes the database and the `local` disk are one environment's (`tickets/{id}` from this database's ids); a storage root with no `tickets/` directory beside attachment rows is refused for that reason.
- **Permission tests.** Another user's access cannot be simulated without root, so the Pest tests remove permissions from the current user and are skipped as root; the different-UID run is recorded separately above.

## 38. Restart and handoff notes

- **Read first:** this document §1–§9, §31, and §36–§37; the roadmap [Release 1](../product/product-roadmap.md#release-1-re-orientation-2026-10-06); Direction D §7, §10.4, §13 and §14; EPIC-010D's [final contract](./EPIC-010D-helpdesk-security-hardening.md#final-security-and-integrity-contract); EPIC-013 §23.
- **Before WP1:**
  1. Fetch, fast-forward `main` to `origin/main`, and check that the tree is clean and the latest `main` CI is green.
  2. Confirm WP0 is committed on `main`.
  3. Create or switch to `feature/epic-017-helpdesk-mvp-customer-shell` from current `main`.
  4. Run `./dev doctor` and `./dev check`.
  5. **Re-inventory** the Helpdesk views and shell hooks against this document's §4 (EPIC-011 L484: inventories are planning baselines).
  6. **Re-read the `@shadcn/lint` baseline** (`AGENTS.md`, `eslint.config.js`, `src/eslint-suppressions.json`), because it may evolve.
  7. Begin WP1.
- **Do not assume:**
  - that the line numbers in §4 are still exact;
  - that `@shadcn/lint`'s suppression baseline is unchanged;
  - that RE-0 has chosen a host.
- **The owner rulings are settled:** O1–O3 and P1–P6 are approved (§36, §37). Revisiting one is an explicit owner act, recorded with a date.
- **Parallel tracks, never blockers:** RE-0 host discovery, EPIC-012 discovery and the Directory data-model ADR may run alongside. Directory implementation follows this epic. No other epic may touch the customer shell or the shared `Status` vocabulary while EPIC-017 is in progress (roadmap [Parallelism](../product/product-roadmap.md#parallelism)).

---

## Amendment 1: WP1 Customer Shell Foundation (PR 1)

> **Status (2026-10-08): WP1 implemented on `feature/epic-017-helpdesk-mvp-customer-shell`; uncommitted and unpushed at the time of writing, awaiting independent review.** EPIC-017 stays **Planned** until PR 1 merges ([§32](#32-branch-and-pr-strategy)); it is **not** marked merged or verified, and **hosted CI has not run**. WP2 is not started. The historical design-gate text above is unchanged. No owner ruling (O1–O3) or plan choice (P1–P6) was reopened, and this amendment raises **no new owner decision**.

### A1.1 Starting point and method

- **Starting SHA:** `865896066c28bd031c069dee9fee34368290bbb7` (`8658960`, `docs: update EPIC-017 post tooling merge`), equal to `main` and `origin/main`; branch `feature/epic-017-helpdesk-mvp-customer-shell`; working tree clean; no open PR; no remote EPIC-017 branch. `main` CI run 37747293979 for that SHA was green (`./dev check gates` and the Playwright suite). `./dev doctor` reported no failure (three pre-existing ownership warnings), and the entry `./dev check` on the untouched tree was green (Vitest 99 files / 1,687 tests; Pest 2,453 tests / 13,589 assertions).
- **Lint baseline at the start:** `src/eslint-suppressions.json` = **15 files / 31 suppressions** (`no-arbitrary-values` 17, `no-restyle` 10, `require-static-classes` 4), SHA-256 `5bf90c806b213966df0df1c2a5f2dcbfb587718a458802a0fb3175714dfbe688`. **At the end the file is byte-identical** (`cmp` against a copy taken before the first edit).
- **Method.** The plan's order, with the work kept in separable semantic units for the eventual commits: resolver, classification and drift guard; the Focused navigation projection; the profile anchors; the `TimerProvider` hoist; the `/time` Stop preservation; the React customer shell; the personas; the presentation switch with the Blade frame and the test conversions; the customer Home; the browser closeout. Three revisions to the approved plan were applied as given: **Revision 1** (the `/time` local Stop, F1), **Revision 2** (the sheet width in `app.css`, not a utility) and **Revision 3** (the section scroll margin only when an `id` is present).
- **No schema, route, policy, permission, `PermissionCatalogue`, `ProjectTimeAccess`, `AccessibleTimeContext`, `TimerProvider`, timer API or Helpdesk file changed.** No new RBAC permission, dependency, design token or CI change.

### A1.2 The resolver contract (P1)

`App\Shared\Shell\ShellPresentation` (enum `Operational = 'operational'`, `Focused = 'focused'`) and `App\Shared\Shell\ShellPresentationResolver`.

- `forUser(?User): ShellPresentation` — `Operational` for a guest, and for any user for whom `$user->can($c)` holds for **any** `$c` in `ShellPresentationResolver::OPERATIONAL`; otherwise `Focused`. It reads capabilities through the Gate and **never a role name**.
- `forRequest(Request): ShellPresentation` — memoized on `$request->attributes`, keyed by the user's identifier, so a changed user is recomputed. **No static state**; the class is stateless.
- **Cost.** `can()` answers from the user's already-loaded roles and permissions, so the resolver adds **zero queries** (proved below, A1.14). `shell.presentation` is now a lazy closure, so a partial reload that does not ask for it resolves nothing.
- **Readers (the only ones).** `HandleInertiaRequests` (the one writer of `shell.presentation`, and the presentation passed to `NavigationBuilder`), `ShellComposer` (the one writer of the Blade `$shell`), and `DashboardController` (which chooses *which page component* the route renders; the operator branch is byte-for-byte unchanged). `NavigationBuilder` names the enum only as a parameter type. No route, middleware, policy, query or model reads it, and no PHP file states a presentation literal. `ShellSeamContractTest` pins all of it.
- A guest keeps the Operational frame (an empty rail on `errors/403`), as before the resolver existed; three existing tests pin it.

### A1.3 Capability classification and the permanent drift guard

Recovered from the registered routes and the code against the 42 catalogue capabilities. **The approved P1 list is still exact: nothing was added, removed or reclassified.**

| Class | Count | Capabilities |
|---|---:|---|
| Customer defaults (`userDefaults()`) | 12 | `tickets.view`, `tickets.create`, `tickets.view_org`, `projects.view`, `projects.view_org`, `tasks.view_org`, `billing.view`, `billing.view_org`, `time.log`, `time.view_own`, `time.view_org`, `cms.view` |
| **Operational** (`OPERATIONAL`) | 11 | `tickets.assign`, `crm.manage`, `billing.manage`, `users.view`, `roles.view`, `settings.view`, `cms.edit`, `time.view_all`, `tasks.view_all`, `projects.manage`, `projects.admin` |
| **Presentation-neutral** (`PRESENTATION_NEUTRAL`, each with a written reason) | 19 | `users.invite`, `users.manage`, `users.admin`, `roles.manage`, `roles.admin`, `settings.manage`, `tickets.admin`, `projects.create`, `billing.create`, `billing.admin`, `time.manage`, `crm.view`, `crm.create`, `crm.admin`, `org.admin`, `org.invite`, `org.manage_roles`, `cms.publish`, `cms.admin` |

`settings.view` gates no surface today; it is kept as approved so the first Settings surface cannot silently render a customer top bar to someone whose only navigation is the rail.

`ShellPresentationDriftGuardTest` (43 tests) derives the gating capabilities from what is actually registered, not from the hand-written list:

- **R1** the catalogue is exactly the three disjoint sets, with the approved sizes 12 / 11 / 19 and a non-empty reason for every neutral capability.
- **R2** every `can:` / `permission:` ability on any registered route is a known, classified capability (an unknown one fails with "classify by hand").
- **R3** every GET/HEAD page gated by a non-default ability carries an Operational capability.
- **R4** every route under `operator/`, `admin/`, `crm/`, `organizations`, `invitations` and `billing/invoices` is capability-gated, with exactly two reasoned exceptions (`billing.invoices.pay`, `billing.invoices.pay.intent`: a customer paying their own invoice, authorized by `InvoicePolicy`).
- **R5** for each of the 30 non-default capabilities, an actor holding customer defaults plus it is compared with the baseline Operational navigation: a capability that changes the payload must be Operational, and every neutral one must leave it unchanged.
- **R6** sentinel fixtures feed each rule input the real routes never contain and prove R2–R5 can fail, plus a parser test for the middleware forms.

### A1.4 Focused navigation (§14)

`NavigationBuilder::build(Request, ShellPresentation = Operational)`. **The default Operational output is byte-identical**: `NavigationBuilderTest` is untouched and green.

- **Refinement of the approved metadata.** A workspace declares `focused: {label, order, item}`. It does **not** state a destination: `href` and `visit` are copied from the **surviving** capability-filtered context item named by `item` (or the workspace's own destination when `item` is null). The plan's `{label, href, order}` is therefore refined to `{label, order, item}` so the projection can only ever point at something the actor already survived filtering for.
- Entries: Home 10 (`/dashboard`, inertia), Support 20 (`helpdesk.requests`, `/tickets`, document), Projects 30 (`projects.all`, `/projects`, inertia), Invoices 40 (`finance.my-invoices`, `/my/invoices`, document; never the operator invoice surface), Resources 50 (`/pages`, document). Tasks, Time, Directory and System declare none (O1).
- A removed capability removes its entry; there are no placeholders; `context` is always `[]`; the only presentation hint is `{focused: {order}}`; no route, pattern, query or surface metadata is serialized; the workspace keys are exactly `key, label, icon, href, visit, isActive, context, presentation`. Active state is server-side (`billing.invoices.pay` marks Invoices current; Profile, Tasks and Time mark nothing).
- `FocusedNavigationTest` (112 tests): labels, order, hrefs and visit modes; capability removal; **Focused ⊆ Operational for 37 personas** (every operational and every neutral capability held on top of the customer defaults, each of four customer defaults removed in turn, the operator, and a user with no permissions), including that every Focused destination is one the actor is already offered Operationally; ≤5 entries; no operator/admin/CRM/organization href and no metadata keys at any depth; the active-state table; guest; the shared-prop allowlists (A1.12); a Focused customer is refused every operator surface (403) yet `/tasks` and `/time` stay reachable (hidden navigation is not authorization).

### A1.5 The shell component tree (React)

```
AppShell                       (the one branch point; the only reader of the presentation prop)
 └─ TimerProvider              (hoisted; enabled by time.log alone)
     ├─ CustomerShell          (Focused)
     │   ├─ SkipLink           (first focusable, targets main#main-content)
     │   ├─ header[data-shell-topbar]  60px
     │   │   ├─ brand          (BrandMark + aria-hidden wordmark; not a link)
     │   │   ├─ TopNav         nav[aria-label=Main]; ShellLink; aria-current + accent-line underline
     │   │   └─ [data-shell-topbar-end]
     │   │       ├─ CustomerMenuSheet   Radix Dialog; data-shell-sheet="focused"
     │   │       └─ AccountMenu placement="topbar"
     │   └─ [data-shell-column]  ≤1080px, centred
     │       ├─ FlashRegion
     │       ├─ main#main-content[tabindex=-1]
     │       └─ route announcer (usePageAnnouncement; [data-shell-announcer])
     └─ OperatorShell          (Operational; unchanged, minus its provider wrapper)
```

- No rail, drawer, utility bar, breadcrumb or global timer is ever rendered by `CustomerShell`; `trail` is accepted and ignored; the shell reads no presentation (`ShellSeamContractTest`: only `app-shell.tsx` contains the discriminator, and no page may import `CustomerShell`, `TopNav` or `CustomerMenuSheet`).
- **`CustomerMenuSheet`** reuses Radix `Dialog` (focus trap, Esc, outside click, focus return) exactly as the operator `NavSheet`; `NavSheet` is not refactored. **Its width is not a utility** (Revision 2): the content carries `data-shell-sheet="focused"`, and `app.css` holds `[data-shell-sheet='focused'] { width: min(20rem, 88vw); }`, keyed on the attribute alone because Radix portals the dialog to `<body>`, outside `[data-shell='focused']`. The Blade `<dialog>` matches the same rule. No design token was added for it.
- **`AccountMenu`** gains `placement: 'rail' | 'topbar'` (default `rail`; `OperatorShell` is unchanged). The top-bar trigger is a **static class string** (the circular avatar is the trigger; menu opens `side="bottom" align="end"`). The existing rail trigger is untouched.
- **Direction D addition: `Avatar size="account"`** (`h-8.5 w-8.5 text-sm`, 34px, Direction D §13.1) in `components/ui/avatar.tsx`, between the 28px rail mark and the 36px menu header. It is the only new variant; it lives in `ui/`, where `no-arbitrary-values` is off, and `h-8.5` is a scale value.
- **Focused CSS** (`app.css`, unlayered like the operational block): `[data-shell='focused']` flex column on `--ds-canvas`; `[data-shell-topbar]` sticky, 60px, hairline rule; `[data-shell-column]` max 1080px, centred, `min-width: 0`, **no padding** (the page frame keeps its own gutters); `[data-shell-topnav]` hidden below 900px (`display: none`, so it leaves the accessibility tree) and `[data-shell-sheet-trigger]` hidden from 900px.

### A1.6 `TimerProvider` seam, and the hook inventory (N7, §8.3)

`TimerProvider` moved from `OperatorShell` to `AppShell`, above the presentation branch, still `enabled` by `time.log`. `OperatorShell` lost only its wrapper (the pill is still rendered by its utility bar). `timer-provider.tsx`, `timer-pill.tsx`, `timer-tray.tsx` and `timer-control.tsx` are untouched; no timer API, permission, query or `AccessibleTimeContext` change; a Focused customer with `time.log` makes the same single `GET /time/timers/active` that they made under `OperatorShell`.

**Inventory of every shell-provided hook a page consumes** (the only React context in the application is `TimerContext`):

| Hook | Consumers (non-test) | Pages that reach them |
|---|---|---|
| `useTimers()` (throws outside a provider) | `pages/time/index.tsx`; `components/projects/task-time-panel.tsx`; `components/time/timer-control.tsx`, `timer-pill.tsx`, `timer-tray.tsx` | `/time`; `tasks/show`; `projects/tasks/show` |
| `useOptionalTimers()` | `components/tasks/task-list-actions.tsx` | `tasks/index`; `projects/tasks/index` |

Vitest (`app-shell.test.tsx`) proves a page gets `useTimers()` under both presentations; that the pill appears only in the Operational shell and never in the Focused one even with `time.log`; that the provider is disabled without `time.log` (no request, either shell); and that `/time` renders under the Focused shell.

### A1.7 `/time` Start → Stop preservation (Revision 1; F1 closed by plan)

WP1 removes the global pill and tray for a Focused customer, yet `/time` could start a timer and had no Stop control. This is **preservation required by the shell migration, not new Time product scope.** Only `pages/time/index.tsx` changed:

- A page-local `RunningTimers` in the existing "Start a timer" section, over `useTimers()` and the existing `TimerControl` in running mode. It renders nothing before the first read is confirmed (§15.1) and nothing with zero timers; each row is named by `context.label`, else `description`, else "Unassigned timer"; no `className` is passed to `TimerControl`; it reads no presentation.
- When a timer that was in the **confirmed** set disappears (stopped here, in the tray or in another tab), the page calls `router.reload({ only: ['entries', 'totalMinutes'] })`. A timer **appearing** reloads nothing, and the first confirmed read reloads nothing.
- Operators get the same list as a second, contextual way to stop the same timer, consistent with the pill because both read one provider.
- Tests: 7 Vitest cases (before-confirmation, empty, naming, stop + reload, no reload on appear, failure keeps the row, no timer chrome) and one Playwright journey on the `customer` persona (start, see "Running timers", stop, the entry appears, no `[data-shell-timer]`). **Cleanup is not brittle:** the journey uses the existing `E2eCleanup` fixture, which records the entry from the start response and deletes it over HTTP even when the test fails, and a `finally` stops whatever is still active through the application's own endpoints.

### A1.8 Blade parity (§9.3)

`layouts/app.blade.php` is the only Blade branch point: `@if ($shell['presentation'] === 'focused')` renders the customer frame, `@else` renders the Operational frame (its markup is the original; only the skip-link anchor became the shared `skip-link` partial, with identical output). New partials: `topbar`, `focused-sheet`, `skip-link`; `account-menu` gained `$placement` (rail output unchanged). `blade-shell.ts` widened only its root selector to `[data-shell][data-shell-renderer="blade"]`; `initPanel` finds nothing to control in the Focused frame, and `blade-timer.ts` already no-ops without timer markup. There is no Helpdesk logic in the shell (a test asserts it) and no new per-request disk read.

**Parity is measured, not byte-compared.** Pest (`BladeFocusedFrameTest`, 28 tests) owns the Blade half: the bar and sheet links equal the Focused payload (label, order, destination, current item) on the Helpdesk list, new request, own invoices, an own invoice, **invoice payment** (Stripe stubbed), resources and the 403 page; no rail, drawer, utility bar, breadcrumb or timer; skip link first; one `main`; the account trigger, menu items, hrefs and below-and-right-aligned menu; no operator/admin/CRM href; no panel stamped on the root; the shared bootstrap inlined once before any stylesheet; the Operational frame for the operator and for mixed-capability users. Vitest (`customer-shell.test.tsx`, `customer-menu-sheet.test.tsx`) owns the React half. Playwright (`customer-shell.spec.ts`) owns parity across the two renderers.

`BladeThemeGuardTest` gained an explicit Level B scan of `topbar`, `focused-sheet`, `skip-link` and `account-menu` **without growing the inventory (still exactly 59)**. `layouts/app.blade.php` is deliberately not scanned: its one finding is `outline-none` on `<main tabindex="-1">`, the skip link's programmatic focus target, identical to React's `main` and to the pre-existing Operational branch.

### A1.9 Customer Home (§5)

`DashboardController` asks the resolver; Focused renders `dashboard/customer-home`, Operational runs the existing dashboard unchanged. Props are `null` for any section whose capability the actor lacks: `waitingOnYou` (own `pending_user`, latest 5), `openRequests` (own `open`/`in_progress`, latest 5), `invoices` (existing own outstanding count), `projects` (existing `Project::visibleTo` active count), and `support` / `newRequest` route descriptors. Ticket rows are an **exact-key allowlist** (`id, number, title, createdAt, createdAtHuman, href, visit`, plus `status, statusLabel` for open rows): no requester, priority, SLA, assignee, company or time. The page has one `h1` "Home", the single ink-primary "New request", the reading order Waiting on you → Your open requests → Invoices → Your projects, and calm empty states. **Four data queries** (the two ticket lists, the outstanding-invoice count and the active-project count), asserted exactly. `CustomerHomeTest` also compares the request's **entire query list** (not only those four) at 1, 5 and 30 of each record, with tolerance 0 and tickets that carry an assignee, so a per-row query against any table the fixtures exercise would change it. What this proves is that nothing grows between those sizes; it does not prove boundedness for a relation the fixtures never populate. The two ticket lists are additionally capped by `limit(5)` and the two counts are single aggregates.

### A1.10 N6: the profile anchors

All three destinations already existed and are always rendered (a passwordless account shows an explanation inside the same section), so this was an id fix, not a missing-section case. `Section` accepts `id` and applies `scroll-mt-20` **only when `id` is present** (both layouts; Revision 3); `SectionPanel` passes it through; `profile/show` gains `#security` (on Password, the start of the security block), `#connected-accounts` and `#sessions`. No Profile redesign. Both shells use the same `AccountMenu` / Blade menu, and Inertia scrolls to the anchor on load. Focus is not moved, only scrolled. Playwright confirms Sessions lands on its section clear of the 60px bar, from both a React and a Blade page.

### A1.11 Personas and fixtures

`DevSeeder` (`firstOrCreate` by email, password `password`, idempotent, local/testing only): `e2e-customer@intechral.test` and `e2e-customer-other@intechral.test` (both plain `user`), and **`e2e-operational-member@intechral.test`** (`user` + a direct `projects.manage`). Also the read-only fixture **`TKT-E2E2`** (owned by `e2e-customer`, `pending_user`, titled `E2E fixture: …`, deliberately not `E2E-…`, so a later cleanup command that matches the `E2E-` prefix cannot touch it). `auth.ts` gained the three personas (sessions mint lazily; `customerOther` costs no login); `BrowserAuthContractTest` and `docs/testing/e2e-browser-suite.md` (identities, login budget, the "which shell" note) were updated.

### A1.12 Security and privacy invariants

| Invariant | Enforcing test |
|---|---|
| Presentation is never authorization: no route/policy/query/model reads it; no literal writer | `ShellSeamContractTest` (PHP readers, enum readers, literal guard, one Blade reader) |
| A user with any operator-surface capability is Operational; the capability sets are complete | resolver matrix (32) + drift guard R1–R6 (43) |
| A customer cannot reach an operator surface by presentation or by URL | `FocusedNavigationTest` (403 on 9 surfaces); Playwright 403-inside-the-Focused-frame |
| Hidden navigation is not authorization (`/tasks`, `/time` stay reachable) | `FocusedNavigationTest`; Playwright |
| No operator link reaches the Focused shell, React or Blade | `FocusedNavigationTest`, `BladeFocusedFrameTest` |
| Shared props are exact and minimal: top-level `errors, app, auth, shell, navigation, flash`; `auth.user` = `id, name, email, avatar`; `shell` = `presentation`; `flash.bulk` = null | `FocusedNavigationTest` (exact keys) |
| Blade and React render one navigation and one account menu | `BladeFocusedFrameTest`, `customer-shell.test.tsx`, Playwright parity |
| Presentation changes the next request after a role change | resolver test |
| The timer provider enables on `time.log` only; Focused draws no timer chrome | `app-shell.test.tsx`, `customer-shell.test.tsx`, Playwright |
| The customer Home leaks no operator field | `CustomerHomeTest` (exact keys; keys, not substrings, scanned at any depth) |

Ticket-time security (N1) is **not** touched and remains WP2's.

### A1.13 Accessibility and responsive results

Per-surface checks only; **no product-wide WCAG claim.** Vitest owns semantics; Playwright owns the keyboard and the browser. Verified: skip link first and targeting `main#main-content`; `header` containing a labelled `nav "Main"`, then one `main`; one `h1` on Home; `aria-current` in the bar and the sheet; the account trigger's name, `aria-haspopup` and `aria-expanded`; the sheet as a titled dialog with a focus trap, Esc, outside click and focus return (a native Blade `<dialog>` may cycle through the browser's own chrome but never lands on the page behind it); the visible 2px `focus` outline; the route announcement; reduced motion (the sheet fades in ≤120ms with no travel); and no operator-only control.

`customer-shell.spec.ts` (18 tests) drives `/dashboard`, `/profile`, `/projects` (React) and `/tickets`, `/my/invoices`, `/pages` (Blade) at **390, 768 and 1280 in light and dark** and asserts: no document overflow; a 60px bar; a column ≤1080px (exactly 1080 at 1280, centred); the 34px account trigger inside the viewport; the full nav only from 900px and the sheet trigger only below it; ≤5 entries in the server's order; the current entry's underline is 2px and resolves to `--ds-accent-line`; and no rail, drawer, utility bar, breadcrumb or timer.

### A1.14 Query and performance evidence

Warm total queries per request, measured on the starting tree and on the WP1 tree with the same probe:

| Request | Before | After |
|---|---:|---:|
| operator `GET /dashboard` | 15 | 15 |
| operator `GET /operator/tickets` (Blade) | 7 | 7 |
| operator `GET /projects` | 7 | 7 |
| user + `projects.manage` `GET /profile` (Operational both times) | 8 | 8 |
| plain user `GET /profile` (Operational before, **Focused after**) | 8 | **8** |
| plain user `GET /tickets` (Blade) | 6 | 6 |
| plain user `GET /projects` | 7 | 7 |
| plain user `GET /pages` | 6 | 6 |
| plain user `GET /dashboard` (operator dashboard before, **customer Home after**) | 12 | 10 |

The resolver adds **zero queries**: a plain user's `/profile` costs the same 8 before and after, and equals the Operational user's; Pest also asserts a hydrated `forUser()` and a second `forRequest()` issue none, and that a partial reload that does not ask for the shell issues no role or permission query and omits `shell` (with the control that asking for it returns it). The customer Home is bounded and constant (A1.9).

### A1.15 Lint baseline

Unchanged: **15 files / 31 suppressions, byte-identical**, SHA-256 `5bf90c80…bfbe688`. `npm run lint` is clean with `--max-warnings=0`; no rule was relaxed, scoped out or suppressed; no `eslint-disable` was added; no raw colour, unknown class or inline style. The new top-bar account trigger uses a static class string (so `account-menu.tsx` kept exactly its two baselined `require-static-classes` findings), and the one new primitive variant is `Avatar size="account"`. **Finding F-A:** the merged policy is `no-arbitrary-values: { allow: ['layout'] }`, so an arbitrary *width* is permitted in application code (the operator `NavSheet` carries `w-[min(20rem,88vw)]`). The approved plan did not use one and this implementation does not; the CSS hook is a design choice that also solves the portal, not a lint workaround, and the policy is not changed here.

### A1.16 Test-conversion ledger

Every historical assertion is kept; where the old test's subject was **Operational chrome** it moved to `operationalMember`, and where it was about **customer page content** it kept the customer and changed only the shell expectation. Each is marked `FLIPPED IN EPIC-017 WP1` in source.

| Test | Change |
|---|---|
| `InertiaFoundationTest` "renders an authenticated production inertia page…" | plain `user` now gets `dashboard/customer-home` and `focused`; the shared-prop assertions are unchanged |
| `DashboardInertiaTest` "renders scoped user dashboard data…" | actor is `user` + `projects.manage` (still own-records scoped, still the operator dashboard); every assertion unchanged. The plain customer is `CustomerHomeTest` |
| `BladeShellTest` (5 dataset rows, `stamps the pre-paint inputs…`, `never renders a destination…`) | member actor → `operational-member` (`user` + `projects.manage`); every assertion unchanged. Focused equivalents: `BladeFocusedFrameTest` |
| `ProjectDrawerSurfaceTest` ×2 | the customer holds `settings.view` (an Operational capability that gates nothing and adds no navigation) so the drawer-hint assertions keep their subject; **new** test: a Focused customer gets no drawer input or operational hint, and is still refused `projects.create` |
| `ProjectDashboardCountTest` ×2 | the helper reads `metrics` for Operational actors and `projects.active` for Focused ones (same `visibleTo` count), so "agrees with the index" keeps asserting |
| `ShellSeamContractTest`, `BladeThemeGuardTest`, `BrowserAuthContractTest` | extended (A1.2, A1.8, A1.11). The one-Blade-reader guard detects `$shell['presentation']` and `$shell["presentation"]` alike, with a positive and negative sample test for the detector |
| Playwright `shell.spec.ts` ("as a member"), `blade-shell.spec.ts` ("as a member"), `time-migration.spec.ts` (whole member group; now the single owner of the active-timer set) | persona → `operationalMember` |
| `tasks-migration.spec.ts` (drawer clamp) | the drawer assertions run as `operationalMember`; a plain-customer block asserts the same clamp where the Focused shell has no drawer and no Tasks entry |
| `projects-workspace.spec.ts`, `task-detail-wp5.spec.ts` (customer member pages) | one breadcrumb → none (the Focused shell owns no breadcrumb), one `h1` kept |
| `inertia-coexistence.spec.ts` | Profile is reached from the account menu (the Focused Home has no quick actions). The narrow-sheet test's subject is the Operational rail folding into its sheet, which a Focused actor has none of (its "Workspaces hidden" assertion would pass vacuously), so it moved to `operationalMember`; it is kept non-vacuous by a temporary run at 1280px, where the rail is visible and the assertion fails. The Focused sheet at the same widths is `customer-shell.spec.ts` |
| **New** | `ShellPresentationResolverTest` (32), `ShellPresentationDriftGuardTest` (43), `FocusedNavigationTest` (112), `BladeFocusedFrameTest` (28), `CustomerHomeTest` (14); Vitest `customer-shell`, `customer-menu-sheet`, `customer-home`, plus additions to `app-shell`, `account-menu`, `avatar`, `section`, `profile/show`, `pages/time`, `blade-shell`; `customer-shell.spec.ts` (18) |

Unchanged regression (not touched, still green): `NavigationBuilderTest`, `ShellContractTest`, `LoginTest`, every operator `BladeShellTest` case, the rail/drawer/skip-link/timer Vitest, and the member specs that assert page content only.

### A1.17 Validation evidence

Run on the **final, unchanged code tree**; this documentation edit is the only change made after them (no test reads the docs, and `git diff --check` was re-run).

- **Entry gate (starting tree):** `./dev doctor` no failures; `./dev check` green (Vitest 99 files / 1,687 tests; Pest 2,453 tests / 13,589 assertions).
- **A full Pest run mid-implementation** found failures only in the behaviours the ledger predicts (A1.16) and nothing else; each was converted, then the affected files re-ran green. The ledger names behaviours, not a case count. Counted as test cases, an independent re-run of the starting revision's Pest suite against the WP1 product code fails **13 cases in 5 files**: `BladeShellTest` 7 (five dataset rows plus the pre-paint and withheld-destination cases), `ProjectDashboardCountTest` 2, `ProjectDrawerSurfaceTest` 2, `DashboardInertiaTest` 1 and `InertiaFoundationTest` 1 (2,440 others pass). All 13 are in the A1.16 rows and all are converted. An earlier draft of this line said "six flips" and "the six files"; neither was the count of failing cases or files, and it is corrected here.
- **Focused Pest (final):** `ShellPresentationResolverTest` 32 · `ShellPresentationDriftGuardTest` 43 · `FocusedNavigationTest` 112 · `BladeFocusedFrameTest` 28 · `CustomerHomeTest` 14 (229 tests, all green), plus the extended `ShellSeamContractTest`, `BladeThemeGuardTest` (the Level B inventory still exactly 59) and `BrowserAuthContractTest`, and the converted `InertiaFoundationTest`, `DashboardInertiaTest`, `ProjectDashboardCountTest`, `ProjectDrawerSurfaceTest` and `BladeShellTest`.
- **Focused Vitest (final):** `customer-shell` 10 · `customer-menu-sheet` 7 · `customer-home` 8 · `pages/time` 24 (7 new) · and additions to `app-shell`, `account-menu`, `avatar`, `section`, `profile/show` and `blade-shell`.
- **Typecheck, `npm run lint` (`--max-warnings=0`), `npm run format:check`, `npm run build`:** clean (all inside `npm run check`).
- **Focused Playwright before the full run:** `customer-shell.spec.ts` 18/18 (two first-run failures were assumptions in my own assertions — a native `<dialog>` may cycle focus through the browser chrome, and Radix hides the open menu's trigger from role queries — and were corrected, not the product); the converted specs 74/74.
- **Full local Playwright, once, after re-seeding `DevSeeder`** (`./dev test:e2e`): **184 passed, 0 failed, 0 flaky, 0 skipped**; **3 workers**; **6.3 minutes**; product-data counts **before = after = `projects=2 tasks=1 time_entries=2`** ("unchanged"). The harness does not report HTTP 5xx / 419 / 429; the application log was not written during the run (F-D). (The single `✘` line in the output is `e2e-cleanup.spec.ts`'s deliberately failing fixture test, which Playwright counts as an expected pass.)
- **`./dev check`, once, on the final tree:** **All checks passed** — CLI self-tests PASS; `git diff --check` PASS; Pint PASS (333 files); `npm run check` PASS (**Vitest 102 files / 1,747 tests**); Pest PASS (**2,697 tests, 15,758 assertions**, 568 s).
- **Pre-commit remediation (after the independent review).** Test and documentation changes only, no product file: the customer Home query test, the Blade-reader guard and its detector test, the `inertia-coexistence.spec.ts` persona, and A1.9, A1.16 and A1.17. The Home test and the guard were each shown to fail when violated (a per-row `assignee` lookup: 12 → 20 queries; a second Blade view reading `$shell['presentation']` or `$shell["presentation"]`) and to pass once restored; the spec's Workspaces assertion was shown to fail at 1280px. Re-run: `./dev check` Pest **2,698 tests, 15,768 assertions** (a net +1 test and +10 assertions against the 2,697 / 15,758 above), Vitest unchanged at 102 files / 1,747 tests; `inertia-coexistence.spec.ts` 3/3. The first re-run's Pint step failed on a style issue in the new Home test (fixed); the Pest total above was measured just before that fix, and after it the two touched test files, Pint (all 333 files) and the frontend gate were re-run green.
- **Lint baseline** byte-identical (A1.15). **Hosted CI: not run** — it is claimed nowhere here.

### A1.18 Visual review

22 captures were generated with a temporary probe and **each was inspected**, then deleted: the Customer Home at 1280/768/390 in both themes (React), the Blade `/my/invoices` frame at the same six, the open menu sheet at 390 in both themes, the Blade account menu at 1280 in both themes, and the operator dashboard before (starting tree) and after at all six. Findings: **no overflow**; the right theme in every capture; the full nav at 1280 and the menu button at 768 and 390; a 60px bar; the accent-line underline on the current entry (teal in light, cyan in dark); the circular account trigger; the sheet anchored to the right and titled; the Blade frame matching the React frame pixel for pixel in the bar. **Operator regressions: none** — the six before/after pairs are identical apart from the relative "7 minutes ago" / "8 minutes ago" text. One cosmetic observation, not a WP1 regression: in the customer Home's empty "Your open requests", `EmptyState`'s own top rule sits just below the section rule (the same primitive does this on the operator Home).

### A1.19 Mutation and non-vacuity evidence

Each mutation was applied, the named check was confirmed to **fail**, and the file was restored and verified byte-identical (the suppression file and the whole tree were re-verified against checksums afterwards).

| # | Mutation | Result |
|---|---|---|
| 1 | `tickets.assign` moved from Operational to neutral | drift guard R3 and R5, the resolver test and the size check fail (R1's partition alone passes, as it should); removing it instead fails R1 |
| 2 | a new `operator/widgets` route gated by an unclassified ability | R2 and R3 fail |
| 3 | `focused:` declared on the Tasks workspace | `FocusedNavigationTest` fails (labels, count) |
| 4 | `TimerPill` rendered in `CustomerShell` | `customer-shell` and `app-shell` Vitest fail (3 tests) |
| 5 | `['presentation' => 'operational']` literal in `ShellComposer` | `ShellSeamContractTest` literal guard and the Blade frame tests fail |
| 6 | `'priority'` added to a customer Home row | `CustomerHomeTest` allowlist fails |
| 7 | `text-[13px]` in `CustomerMenuSheet`; `text-red-500` in `TopNav`; `className="font-bold"` on `<Status>` | `shadcn/no-arbitrary-values`, `no-raw-colors` and `no-restyle` fail |
| 8 | `scroll-mt-20` on every `Section` | the "no id → no scroll margin" tests fail |
| 9 | `/time` reloads whenever the timer set changes size | the "does not reload when a timer appears" test fails |

Two first attempts did **not** fail and were corrected rather than accepted: an arbitrary *width* (allowed by policy, F-A) and a string concatenated onto `buttonVariants()` on a plain `<a>` (not a primitive restyle).

### A1.20 Findings and deviations

| # | Finding | Disposition |
|---|---|---|
| F1 | Start without Stop on `/time` for a Focused customer | **CLOSED BY PLAN** and implemented (A1.7) |
| F2 | The `member` persona becomes Focused, which broke 11 timer tests and 3 rail tests whose subject is Operational chrome | the `operationalMember` persona (A1.11); recorded in A1.16 |
| F3 | §22.6 says Home shows Projects' "existing project list"; the dashboard computes only a count | Home shows the count (§5: no new queries) |
| F4 | §14's `{label, href, order}` | implemented as `{label, order, item}`, `href` derived from the survivor (A1.4) |
| F5 | A guest has no actor | stays Operational; already pinned by three tests |
| F6 | §8.2 "nothing reads the presentation" vs §5's controller choosing the Home | `DashboardController` is the one sanctioned component chooser; data stays capability-gated; statically guarded |
| F-A | the lint policy allows arbitrary layout values | A1.15; no change |
| F-B | Signing out from the Focused shell is covered by `auth-migration.spec.ts` (`e2e-login-flow` is a plain `user`, now Focused), not by `customer-shell.spec.ts`: sign-out destroys the worker's shared session row | no action |
| F-C | `./dev test:e2e` does not forward arbitrary environment variables into the container | tooling observation (it affected a throwaway probe only); no repository change |
| F-D | the harness does not report HTTP 5xx / 419 / 429 | the application log (`storage/logs/laravel.log`) was not written during the run |

**No owner decision is raised.**

### A1.21 Scope integrity

None of the following entered the tree: React ticket list/create/detail; requester ticket resources; the N1 ticket-time/privacy correction; the O2 reply lifecycle; ticket attachments; the ticket cleanup command; the operator ticket workspace, queue or reports; email changes; O3; Directory; Finance or Projects redesign; release engineering. The **only** Time-module change is `pages/time/index.tsx` (A1.7).

### A1.22 Lifecycle

EPIC-017 stays **Planned**; WP1 is implemented, uncommitted and awaiting independent review; WP2–WP5 are not started; hosted CI has not run. Entering WP2 needs WP1 reviewed, committed, merged and green on `main`.

---

## Amendment 2: WP2 Customer Helpdesk in React (PR 2)

> **Status (2026-10-11): WP2 is implemented and committed locally on `feature/epic-017-helpdesk-mvp-customer-shell` as G1 (`67d1f9d`), G2 (`1ff93f4`), G3-A (`8220deb`) and G3-B (`56cad95`), on top of WP1 (`6058dc1`). This amendment is G3-C, the WP2 evidence ledger; it closes WP2 *locally*.** Nothing has been pushed, no PR exists, and **hosted CI has not run for any EPIC-017 code**: it is claimed nowhere here. EPIC-017 stays **Planned**. WP3, WP4 and WP5 are not started and none of their work is in the tree. No owner ruling (O1–O3) or plan choice (P1–P6) was reopened. **The owner reviewed this amendment and accepted WP2's local closure on 2026-10-11** (the confirmed dispositions are marked *Owner-confirmed* below): the keyboard and viewport assignment (A2.10 follow-up 1), F5, F6, D-4, the PR packaging (D-1), the deferral of WP3/WP4 from the Release 0.9 critical path and the scope retained by WP5 (A2.14).

### A2.1 Starting point, method and evidence classes

- **Starting point.** HEAD `56cad95ae23f4209069967f5475e0706f3ca3865`; its parent chain is `8220deb`, `1ff93f4`, `67d1f9d`, `6058dc1`, then `8658960` = `main` = `origin/main`. The branch has **no remote ref**. Working tree clean at the start. The protected stash `663d0d6fd1afd544865074e61f82d2722f6d614e` was preserved.
- **Method.** Read-only inspection of the four WP2 commits and the tree; the ledger below is reconciled from them, from §37, from `docs/testing/e2e-browser-suite.md` and from the code. Focused checks were run (A2.11). The G3-B spec was run once unmodified as a control and then against six deliberate product mutations (A2.9); every mutated product file was restored with `git checkout` and `git diff -- src/app` was empty after each probe. **No product, test or configuration file changed in G3-C**; the only edits are documentation.
- **Evidence classes.** Every figure in this amendment is one of three, and is labelled:
  - **Verified in G3-C:** run or measured on 2026-10-11 against the committed tree.
  - **Reported:** measured by an earlier gate on the G3-B tree (identical in content to `56cad95`) and *not re-run here*.
  - **Outstanding:** requires hosted CI or a different machine; nothing in this amendment claims it.
- **Counts.** A number beside a Pest file name is its count of `it()` declarations; datasets expand some of them, so a run reports more. Run totals are quoted as run.

### A2.2 Gate map

| Gate | Commit | Scope | Independent review | Owner acts |
|---|---|---|---|---|
| WP1 | `6058dc1` | Customer shell foundation (Amendment 1) | recorded in Amendment 1 | none |
| **G1** | `67d1f9d` | Server contracts and security, no UI: N1 ticket-time eligibility, the attachment rule and atomic uploads, the O2 reply lifecycle under a row lock, requester search | WP2 G1 closure review (2026-10-08) | N1 existing-entry exception (2026-10-08) |
| **G2** | `1ff93f4` | Requester React pages, the presenter, P2, N2 continuity, deletion of the three customer Blade pages, the requester import boundary, the Inertia "Reply sent." contract | WP2 G2 review (2026-10-09): no Critical, High or Medium; F5–F9 and D-2 carried | N2 continuity (2026-10-08); F5 disposition |
| **G3-A** | `8220deb` | `e2e:cleanup-tickets`, `./dev test:e2e` lock, sweep, hand-off and reconciliation, `globalTeardown`, `#[DeleteWhenMissingModels]` | G3-A safety review: 1 High, 2 Medium, 3 Low, all corrected | G3-A rulings 1–8, Option A and Option C (2026-10-09) |
| **G3-B** | `56cad95` | `customer-helpdesk.spec.ts`: journeys 3–8, 3b, 5b and the stale closed-reply regression; the E2E doc | Opus 5.5 independent review: **SAFE TO COMMIT**, no remediation; 2 Low and 5 Info | none |
| **G3-C** | this amendment | Evidence ledger and WP2 closure; no product change | — | the three confirmations above |

### A2.3 WP2 completion matrix

Each row is a bullet of WP2's scope in §31. *Verified* is G3-C; *Browser* names the real-Chromium coverage, if any.

| WP2 scope | Where it lives | Evidence |
|---|---|---|
| React `pages/tickets/{index,create,show}` | `resources/js/pages/tickets/*.tsx`, `components/helpdesk/*` | Vitest requester set (A2.11); **Browser:** journeys 3–8, 3b, 5b, stale reply |
| Requester resources | `RequesterTicketPresenter` (every key written out), `RequesterTicketQuery` | `RequesterTicketPropsTest` (25: exact-key and sentinel allowlists, and the list and detail query budgets of §26) |
| Reply with attachments | `reply-section.tsx`, `attachment-input.tsx`; `AllowedTicketAttachment` on create and both reply routes | `TicketAttachmentPolicyTest` (16), `TicketReplyLifecycleTest` (18), `pages/tickets/show.test.tsx`. **Not exercised in a browser** (accepted gap, A2.12) |
| Customer status labels | `RequesterTicketPresenter::STATUS_LABELS`, `ticket-status.tsx` | props test, `ticket-status.test.tsx`; **Browser:** "Open", "Waiting on you", "In progress" (J4, 5b) |
| The hourglass in both renderers | `components/ui/status.tsx`, `components/ui/status.blade.php`, `tickets/_status_badge` | `status.test.tsx`; `blade-theme-controls.spec.ts` pins `hourglass` for `pending_user` |
| Search contract and F-4 | `RequesterTicketQuery`: own tickets, public text only, LIKE wildcards escaped (the operator queue too), a two-query page | `RequesterTicketSearchTest` (12); **Browser:** J4 (search and filter), J7 (an internal sentinel finds nothing), J8 |
| Exact attachment allowlist and atomic uploads (F-7) | `AllowedTicketAttachment` (extension + type detected from the bytes); `TicketService::storeAttachments` (written inside the transaction, deleted on rollback, notify after commit) | `TicketAttachmentPolicyTest` with real-byte fixtures; **Browser:** J3 (accepted), 3b (an HTML file named `notes.txt` refused, nothing created) |
| Company selector removal | `create.tsx`; `store()` still validates a forged `company_id` and never stores it | `create.test.tsx`, `TicketSubmissionTest`; **Browser:** J3 (no company field) |
| N1 closed on the server | `AccessibleTimeContext::canTimeTicket` (`tickets.assign` **and** `view`), `TimeEntryController::ensureTicketTimeEligible`; the presenter has no time key | `TicketTimeEligibilityTest` (17, including the four PRESERVED existing-entry paths and the billing-lock test), the flipped H9 tests (A2.6), exact-key props tests |
| N5 gone | the presenter carries no SLA or overdue key | "sends the member ticket page no Overdue signal, even for an overdue ticket" |
| O2 lifecycle | `TicketService::addReply`, `CUSTOMER_REPLY_TRANSITIONS`, row lock; a closed-ticket reply refused before any write | `TicketReplyLifecycleTest` (18), `TicketReplyRaceTest` (2, real multi-process); **Browser:** J5, 5b (`pending_user` → `in_progress`), stale closed reply. `resolved` → `open` is Pest only |
| P2 | `TicketController::show`: `authorize('view')` first, then `Inertia::location` to the operator page for a non-owner | `RequesterTicketPropsTest` (302 for an operator, 403 *without* a `Location` header for a stranger), the converted H9 tests. Reached in a browser only incidentally (A2.12 D-4) |
| Deletion of the customer Blade pages and customer-only partials | `tickets/{index,create,show}.blade.php` deleted in G2. The four `tickets/_*.blade.php` partials **remain**: the operator pages still include each, so none is customer-only | **Verified:** directory listing and `grep`; `BladeThemeGuardTest` |
| Guard inventory update | `BladeThemeGuardTest`, `BladeControlAdoptionTest`, `SemanticComponentsTest` | **Verified:** `BladeThemeGuardTest` green |
| The locked E2E cleanup command | G3-A | A2.7 |
| *Also in G2:* N2 continuity, "My requests" as an Inertia visit, the requester import boundary | `operator/tickets/show.blade.php`, `NavigationBuilder`, `eslint/requester-boundary.js` | `TicketEmbeddedTimeTrackerTest`, `NavigationBuilderTest`, `requester-import-guard*.test.ts` |

### A2.4 Exit criteria

**WP2's own exit criteria (§31).**

| Criterion | Status |
|---|---|
| No customer Helpdesk Blade view remains | **Met** (verified). The operator Blade pages and their four shared partials remain by design until WP3/WP4 |
| Every EPIC-010D target test is green, converted or kept | **Met locally.** The converted tests are named in A2.6; `tests/Feature/Tickets` and `tests/Feature/Console` passed in G3-C and the full Pest run (2,905) is reported |
| N1 is closed on the server and N5 is gone | **Met** (A2.3) |
| E2E runs return the tracked `tickets` count to baseline | **Met locally**: reconciled in every G3-C run (A2.9) and in the reported full run. **Hosted: Outstanding** |

**EPIC-017 §33 as WP2 leaves it.** WP2 does not complete any §33 criterion alone; this records what it contributed and who owns the rest.

| §33 | WP2's part | Remaining owner |
|---|---|---|
| 1 customers complete the flows keyboard-only at 390/768/1280, both themes | O2 lifecycle proven by Pest ✓. Flows work with a pointer; **keyboard-only and the width matrix for `/tickets/create` and `/tickets/{id}` are not browser-tested** | **WP5**, by the A2.10 assignment |
| 2 operators triage, work, time and report in React | none | WP3, WP4 |
| 3 no Helpdesk Blade view remains | customer pages deleted ✓ | WP3 (`operator/tickets/show`), WP4 (queue, reports) |
| 4 every EPIC-010D contract holds, none dropped | own-ticket visibility, internal-note boundaries, reply authorization first and ticket numbers kept or converted (A2.6) | WP3/WP4 for the operator-side contracts |
| 5 customer props carry no internal content, SLA, time, history actors or `company_id` | **Met** by the exact-key allowlists; the flipped H9 test is recorded | — |
| 6, 7 resolver, drift guard, Focused navigation | WP1 | — |
| 8 mail: per-recipient links, F-3, O3 | not started | **WP5** |
| 9 query budgets | customer list and detail ✓ (`RequesterTicketPropsTest`, `RequesterTicketSearchTest`) | WP3/WP4 (queue, workspace) |
| 10 `@shadcn/lint` clean, no growth | **Met**: `npm run lint` clean; `eslint-suppressions.json` byte-identical (15 files / 31, SHA-256 `5bf90c80…bfbe688`) | — |
| 11 NVDA pass recorded | not started | **WP5** |
| 12 `./dev check` and hosted CI green; counts return to baseline | local ✓. **Hosted: Outstanding** | WP5 (the hosted Playwright run) |
| 13 roadmap, index, EPIC-011 Phase F note, E2E identity docs | the E2E docs are updated; the roadmap and epics index carry a status note only | **WP5** closeout |

### A2.5 Owner rulings and ratifications carried

All are recorded in §37; this is the index, not a restatement.

| Item | Date | Where it is enforced |
|---|---|---|
| O1, O2, O3, P1–P6 | 2026-10-07 | §36, §37 |
| **N1 existing-entry exception** (stop, running-timer description, delete, move out) | 2026-10-08 | `TicketTimeEligibilityTest`, four `PRESERVED` paths; the owner also accepts the abandoned-timer edge case |
| **N2 continuity** (embedded tracker on the operator page until WP3; removal list in §37) | 2026-10-08 | `operator/tickets/show.blade.php`, `TicketEmbeddedTimeTrackerTest`, `RequesterTicketPropsTest` |
| **G2 F5 disposition** (preserve the requester-safe interface; record the edge persona) | 2026-10-09 | A2.12 F5 |
| **G3-A rulings 1–8** (suite-level cleanup, eligibility, no targeting, linked records refused, files first, recovery sweep, session isolation unchanged, EPIC-015 time unchanged) | 2026-10-09 | `CleanupE2eTickets`, `./dev test:e2e`, `globalTeardown` |
| **Option A**: the wrapper runs the cleanup as `portal` | 2026-10-09 | `scripts/dev/commands.sh`, `global-teardown.ts` |
| **Option C**: `#[DeleteWhenMissingModels]` on `TicketCreatedNotification` and `TicketRepliedNotification` | 2026-10-09 | `TicketNotificationMissingModelTest` |

### A2.6 Test-conversion ledger

Every replaced or renamed test is named (the §34 mitigation for "converting HTML assertions silently drops coverage"). Most are marked `FLIPPED` or `CONVERTED IN EPIC-017 WP2` in source. None was deleted without a named replacement.

| Test | Change |
|---|---|
| `TicketVisibilityCharacterizationTest` "TARGET H9: the agent opens the user show page and every attachment…" → "…reaches the ticket (P2: the operator page) and every attachment linked from it" | **P2.** A non-owner agent is redirected from `/tickets/{id}` to the operator page, which opens; both attachments still download |
| same, "…internal-note visibility no longer depends on the route…" → "…follows the capability; every operator sees notes on the operator page, the requester page never carries them" | **P2.** Operators see the note on the operator page; the owner's props are asserted note-free |
| same, "…the agent starts a timer on…; a customer still cannot use a foreign ticket" → "…no customer, the owner included, may time a ticket" (G1) | **FLIPPED, O1/N1 (the H9 test of §25.3 and §33.5).** The owner used to be allowed; now 422 on `ticket_id` with nothing written. The agent half is unchanged |
| `TicketAssignmentCharacterizationTest` "TARGET H9: an agent assignee can follow the reply mail link" | **P2.** The mail link stays `/tickets/{id}` and lands on the operator page (per-recipient links are WP5) |
| `TimeTrackingTest` "allows authorized embedded task and ticket timer contexts" | **FLIPPED.** The ticket half uses a user holding `tickets.assign`; the plain owner's refusal is asserted with nothing written; the task half is unchanged |
| `TimeTrackingTest` "identifies the context record by id on every timer context DTO" | The actor also holds `tickets.assign`; every DTO assertion is unchanged |
| `SemanticStateMappingTest` "draws the member ticket detail Overdue mark…" → "sends the member ticket page no Overdue signal, even for an overdue ticket" | **CONVERTED, N5.** The requester page is built without SLA or overdue |
| same, "draws the internal-note card with the same class string in both ticket views…" → "…in the operator ticket view…" | One of the two views no longer exists |
| `TargetAccessibleNamesTest` "names the status filters on the member ticket list and the invoice list" → "…on the invoice list" | The ticket list names ("Search requests", "Status") are pinned in `pages/tickets/index.test.tsx` |
| same, "names the new-ticket form fields, including the attachments input" | Removed; the field names and the attachment hint tie are pinned in `pages/tickets/{create,show}.test.tsx` |
| `TargetFormAccessibilityTest` dataset rows `tickets.index`, `tickets.create`, `tickets.show` (member) and the two failed-submission rows `tickets.create` and `tickets.show reply` | Removed from the Blade markup scan (those pages are React); labels, error links and focus are pinned in `pages/tickets/*.test.tsx` |
| same, "keeps the embedded ticket time tracker Start Timer name…" → "…on the operator ticket page" | Location only (N2) |
| `TicketEmbeddedTimeTrackerTest` "leaves the ticket time panel untouched…" → three **TEMPORARY (N2)** operator-page tests, plus the **permanent** "does not give the tracker to the requester… (G1)" | The temporary three are on the §37 removal list; the permanent one stays after WP3 |
| `TicketReplyTest` | **New:** a plain form post flashes "Reply added."; an Inertia reply flashes nothing, nor does a refused one |
| Playwright `blade-theme-controls.spec.ts` "my requests and the new-ticket form", "my requests, the new-ticket form, my invoices and the 403 page…" (narrowed to invoices and 403) | Those pages are React and held by `@shadcn/lint` and `pages/tickets/*.test.tsx`; the operator tracker tests moved to `/operator/tickets/{id}`; the status gallery now expects `hourglass` |
| Playwright `blade-shell`, `customer-shell`, `shell`, `projects-drawer` | The Blade-document leg moved from `/tickets` to `/my/invoices`, `/pages` or `/operator/tickets`; `/tickets` became a React leg of the Focused-frame matrix |
| Playwright `time-migration.spec.ts` | Reaches the fixture through `/operator/tickets?search=TKT-E2E1`; **adds** the F7 step (the tracker's own **Stop** is clicked). Both are on the §37 removal list |
| **New** | `RequesterTicketPropsTest` 25 · `RequesterTicketSearchTest` 12 · `TicketAttachmentPolicyTest` 16 · `TicketReplyLifecycleTest` 18 · `TicketReplyRaceTest` 2 · `TicketTimeEligibilityTest` 17 · `CleanupE2eTicketsTest` 39 · `CleanupE2eTicketsConcurrencyTest` 2 · `TicketNotificationMissingModelTest` 8 · Vitest `pages/tickets/{index,create,show}`, `components/helpdesk/*`, `requester-import-guard*` · `customer-helpdesk.spec.ts` 9 |

### A2.7 G3-A: cleanup infrastructure and its operational smoke

The rulings, the six review findings and the accepted limitations are in §37 and are not repeated. What G3-C adds:

- **Verified in G3-C.** `CleanupE2eTicketsTest`, `CleanupE2eTicketsConcurrencyTest`, `TicketNotificationMissingModelTest` and the task-deletion architecture guard pass (A2.11). `./dev test:e2e` was run seven times in G3-C (A2.9); every run swept first (0 eligible), cleaned once afterwards as `portal`, and **reconciled the `tickets` count (2 → 2) and the attachment-directory count (0 → 0)**. The mutated run that accepted an HTML upload (M4) created a fourth ticket and a second attachment directory and was cleaned the same way.
- **Reported, G3-A operational smoke (passed, with limitations).** The real wrapper hand-off was exercised end to end: tickets created inside a suite and deleted after it, database and attachment baselines restored.
- **The different-UID path is NOT verified.** Measured in G3-C: the host user is `1000:1000` and `portal` is `1000:1000` in the container, so the smoke test and every G3-C run executed with Playwright's UID equal to `portal`'s. What the smoke therefore proved is the *hand-off and the cleanup as `portal`*, not the situation of a hosted runner, whose UID differs and cannot read the `0700` attachment directories. The HIGH-1 scenario (the command run as a different UID refuses with exit 1 and keeps the row and file) *was* exercised in a disposable probe, but the wrapper hand-off in that configuration was not. See A2.13.

### A2.8 G3-B: browser journeys 3–8

`customer-helpdesk.spec.ts` (717 lines, 9 tests) is described in `docs/testing/e2e-browser-suite.md`, including what is browser-proven and what stays with Pest and Vitest. In brief: journeys 3–8 run serially on one disposable ticket; 3b, 5b and the stale closed-reply regression are independent. Every ticket is `E2E-` plus 16 random hex characters, addressed by the redirect URL or that title, never by position or id; there is no cleanup in the spec, and no time entry or task is linked. The spec reaches the existing seeded personas only (`customer`, `customerOther`, `operator`). **Verified in G3-C:** a control run of the unmodified spec passed 9/9 (A2.9). **Reported:** 9/9, 45/45 with `--repeat-each=5`, 76/76 related specs, 192/192 full.

### A2.9 Mutation and non-vacuity evidence (G3-C re-execution)

**Why this exists.** G3-B reported "six deliberate product mutations caught" but did not keep the list, and the E2E doc records only the refusal-focus probe. So G3-C did not reconstruct the original six from memory: it ran a **control** and then **six product mutations** against the committed spec, on one worker (`./dev test:e2e --workers=1 tests/Browser/customer-helpdesk.spec.ts`), 100 s apart so Fortify throttling could not be the cause of any result. Each mutation was a single temporary edit under `src/app`, restored with `git checkout`, with `git diff -- src/app` empty afterwards (and after the last run). The failure reason was read from each run's output; none is inferred.

**Control (unmutated, `56cad95`): 9/9 passed in 32.8 s.** The wrapper swept first (0 eligible), then deleted 3 tickets and 2 attachment files (1 directory) after the suite, and reported tickets 2 → 2 and attachment directories 0 → 0. So each failure below is the mutation, not the environment.

| # | Mutation | Test | Assertion that failed | Result |
|---|---|---|---|---|
| M1 | `TicketPolicy::view` returns `true` for every user | 8 (other customer) | spec line 516: the **public attachment** is `403` for the other customer | received `200`. The ticket-URL line (515) **still passed** with the policy broken, because P2 redirects the non-owner to the operator route, whose `can:tickets.assign` middleware answers 403 (A2.12 D-4) |
| M2 | `downloadAttachment` no longer checks internal visibility | 7 (internal note) | line 484: the **internal attachment** is `403` for the customer | received `200` |
| M3 | the requester detail serves internal replies (the controller's `is_internal = false` query and the presenter's filter both removed) | 7 | line 451: the customer's **"Attached files"** list holds exactly one link | received `2`: the internal note's file was listed. The test stops at its first failure, so the sentinel, serialized-props and Inertia-response assertions after it were not reached in this run |
| M4 | the attachment allowlist accepts every file | 3b (refused upload) | line 591: the **error under the attachments field** names `notes.txt` | element not found: the HTML file was accepted and a ticket created (the wrapper cleaned 4 tickets and 3 files in 2 directories, not 3 and 2) |
| M5 | O2: a customer reply on `pending_user` no longer moves the ticket | 5b | line 635: the header reads **"In progress"** after the reply | still "Waiting on you" |
| M6 | O2: a customer reply on a closed ticket is no longer refused | stale closed reply | line 682: the **"Reply refused"** group is visible | not found: the reply was accepted |

Every run: the intended test failed, the other tests behaved as expected (journey 8 did not run after M2/M3, as it follows 7 in the serial group), the cleanup exit and both reconciliations were `ok`, and there was no `429`. **Not repeated:** the refusal-focus `useEffect` removal, recorded in the E2E doc, because it needs an asset rebuild; it remains the seventh probe of record. **Not probed:** the P2 redirect and `resolved` → `open` (Pest-only, A2.3), and journey 4's Back restoration.



### A2.10 G3-B review follow-ups

| # | Follow-up | Disposition |
|---|---|---|
| 1 | **Keyboard-only Helpdesk flows and 390/768 viewport coverage** | **Assigned to WP5 (closeout), as the browser proof of §33.1, and tracked against §16 and §17.1.** Scope: (a) a keyboard-only journey (sign in → create with an attachment → list → search → open → reply → sign out) with Tab order matching visual order and visible focus; (b) `/tickets/create` and `/tickets/{id}` at 390, 768 and 1280 in both themes: no document overflow, the primary action ("New request", "Send reply") visible without horizontal scroll, one `h1`, a predictable `<title>`, no operator affordance. **Today:** `customer-shell.spec.ts` covers the Focused frame, overflow and the menu sheet for `/tickets` (the list) only; `customer-helpdesk.spec.ts` drives the pages with a pointer (one `Enter` on the search box). WP2 did not own it: §31 lists "Playwright journeys 3–8" as its key tests, and WP3/WP4 are operator-only. **Until WP5 delivers it, §33.1 is not met.** **Owner-confirmed (2026-10-11):** the checks belong to the **Release 0.9 customer-readiness scope**, associated with WP5 and with the existing EPIC-017 exit criteria (§33.1). (When this amendment was written "Release 0.9" was not yet defined in the repository documents; the Release 0.9 architecture document now carries it.) |
| 2 | **All six mutation probes, and the assertion that caught each** | **Done in G3-C** (A2.9). The G3-B handoff reported six probes; its individual list was **not retained** in the repository, the E2E doc (which records only the refusal-focus probe) or the session records available to G3-C, so the original six cannot be reproduced from evidence. The set in A2.9 was *re-executed* here against the committed spec. The refusal-focus `useEffect` removal is recorded in the E2E doc and was not repeated (it needs an asset rebuild) |
| 3 | **Fortify login throttling for repeated browser runs** | **Accepted limitation, recorded (owner-confirmed 2026-10-11); authentication rate limiting is unchanged and must stay so** (A2.12 L-1). Documented in `e2e-browser-suite.md` ("The ceiling is per minute, not per run") |
| 4 | **Hosted-CI cleanup across different filesystem UIDs** | **Kept open** (A2.13) |

### A2.11 Validation evidence

**Verified in G3-C (2026-10-11, committed tree `56cad95`, documentation edits only).**

- **Focused Pest** (testing DB): `tests/Feature/Tickets`, `tests/Feature/Console`, `BladeThemeGuardTest` and `TaskDeletionAuthorityTest`: **529 passed, 1,824 assertions** (138 s).
- **Vitest, requester set** (`pages/tickets`, `components/helpdesk`, both requester import-guard tests, `status.test.tsx`): **8 files, 111 tests passed**.
- **Vitest, full suite: 109 files, 1,847 tests.** Passed in **3 of 4** runs. **The first run reported 7 failed and 1,840 passed**; it started seconds after the Playwright probes ended with the machine's load average near 21, and its failing test names were not captured. It did not recur in three consecutive reruns (1,847 / 1,847 each). **Cause not established**; recorded as an observed intermittent (A2.12 L-2), not diagnosed here. Three clean reruns do not show what the cause was, and no explanation (including the machine load noted above, which is circumstance, not a finding) is claimed.
- **`npm run lint`** (`--max-warnings=0`) clean; `eslint-suppressions.json` byte-identical (15 files / 31 suppressions, SHA-256 `5bf90c80…bfbe688`). Strict `tsc` and Prettier are clean on `customer-helpdesk.spec.ts` (the browser specs are outside `npm run lint`, `typecheck` and `format:check`, so `./dev check` says nothing about them).
- **Playwright:** `--list` shows 192 tests in 26 files (183 + 9). The **control run, 9/9**, and the six probes (A2.9), seven `./dev test:e2e` runs in all, each reconciled to the `tickets` (2) and attachment-directory (0) baselines.
- **Scope and contract checks** (A2.3, A2.4, A2.15): directory listings, `grep` and `git diff`.
- **`git diff --check`:** clean on the final documentation edits.

**Reported by the G3-A and G3-B gates, not re-run here** (no product, test or configuration file has changed since; `56cad95` is the reviewed tree): full Playwright **192/192**, 3 workers, 7.6 minutes via `./dev test:e2e`; `customer-helpdesk.spec.ts` 9/9 and **45/45** with `--repeat-each=5`; related existing specs 76/76; full Pest **2,905 passed**; the full `./dev check`; the G3-A operational smoke test (passed, with the limitation in A2.7). No fresh full Pest or Playwright run was made: nothing those suites exercise has changed since the reported runs, and the EPIC names no WP2 closure gate that requires one beyond the hosted run below.

**Outstanding:** hosted CI (A2.13). It is the authoritative complete browser gate (§25.3).



### A2.12 Findings, discrepancies and accepted limitations

**Discrepancies and items for the owner (none blocks WP2's local closure).**

| # | Item | Disposition |
|---|---|---|
| D-1 | **PR packaging.** §32 says one PR per work package, and A1.22 says entering WP2 needs WP1 "merged and green on `main`". WP1 was never pushed or merged: WP1 and WP2 are five stacked commits on one unpushed branch | **Owner-confirmed (2026-10-11):** no push or PR is needed for WP2's local closure. When packaging becomes necessary, prefer **one integrated WP1+WP2 PR** to artificial stacked PRs. That is a **process exception to §32** (one PR per work package) and the PR description must say so. The first hosted CI run will cover both packages at once |
| F5 | **An operator who is also the requester** sees the customer composer's notice ("Replying reopens this request" on `resolved`, no composer on `closed`) while the operator contract applies no implicit transition and accepts a reply on any status | **Known edge-persona discrepancy; the documented G2 resolution is retained (owner-confirmed 2026-10-11):** the requester-safe interface and the server behaviour are unchanged. Option (b), making `reply.notice` depend on the viewer's `tickets.assign`, remains available to a later owner decision |
| F6 | **List page title.** §19.1 says `Title: "Support"`, `h1` "Your requests"; the page sets `<Head title="Your requests">` | **Resolved: the owner accepts the existing "Support" navigation label and the "Your requests" page `h1` and document `<title>` (2026-10-11).** No code change. This agrees with P3, which assigns "Support" to the customer label and "Your requests" to the page `h1`. §19.1's text is unchanged; its "Title" is read as the navigation label |
| D-2 | `import.meta.glob()` is not inspected by the requester import boundary | Optional hardening carried unchanged; no requester module uses it |
| F8, F9 | The list's exact-key test is not recursive (the row is flat); an actor with `tickets.assign` but not `tickets.view` is refused 403 by route middleware before the P2 redirect | Accepted as recorded in §37 |
| D-3 | The "Pending" label and the clock glyph remain on the Blade operator pages (the Blade spec pins "Pending" with the hourglass) | **Not a WP2 gap.** P4's operator label and the clock glyph are WP3 scope (§31) |
| D-4 | P2's redirect is not a browser journey. In G3-C probe M1 the ticket-URL assertion of journey 8 stayed green with `TicketPolicy::view` broken, because the 302 to the operator page lands on the operator route's `can:tickets.assign` middleware, which answers 403; the next line (the public attachment, 403 vs 200) caught it | **Informational test limitation (owner-confirmed 2026-10-11).** The direct `TicketPolicy::view` behaviour is covered by focused server tests (`RequesterTicketPropsTest`: 403 *without* a `Location` header for a stranger; the visibility characterization tests). **The redirect-based browser assertion in journey 8 does not independently detect every `TicketPolicy::view` regression and must not be cited as if it did.** `page.request.get(url, { maxRedirects: 0 })` would assert the policy's own answer if a browser pin is ever wanted; the reviewed spec is unchanged |
| D-5 | **The G3-B review's other findings.** *L-1* no keyboard or width coverage (follow-up 1). *L-2* a failed test restarts its worker, which logs its persona in again, so several failing units on one persona can exceed the Fortify bucket in one badly broken run (limitation L-1 below). *I-1* `E2E-<random per ticket>` is stricter than §25.3's `E2E-{runId}` and satisfies the cleanup contract, which needs only the `E2E-` prefix. *I-2* comment and selector hygiene (the J5 comment says "in order after the description" but asserts the first entry; the operator-side `div.rounded-lg` selectors depend on Blade classes WP3 replaces). *I-3* `support/auth.ts` still says `customerOther` is "first used … in the next package". *I-4* no mail coverage (CI starts neither the queue nor Mailpit). *I-5* the wrapper's reconciliation cannot independently show that 3b created nothing; 3b's own assertions do | Recorded; no remediation was required. I-2 and I-3 are hygiene, and the Blade selectors go with the operator page in WP3 |

**Accepted limitations.**

- **L-1, login throttling.** Fortify allows 5 `POST /login` per minute per email and IP. A single run keeps every persona within its worker cap (≤ 3). Runs started back to back share the limiter, and a failing test restarts its worker, which logs its persona in again, so a red run is the one most likely to be followed by a throttled one; a `429` from `mintSession` that disappears after a pause is this, not flakiness. Hosted CI starts from a fresh Redis and runs the suite once, so it is not exposed. **Mitigation is operational** (leave about a minute between `./dev test:e2e` invocations; the G3-C probes used 100 s). **Authentication rate limiting is not weakened and must not be.** Verified in G3-C: seven single-worker runs, spaced by 100 s, saw no throttling.
- **L-2, an intermittent full-Vitest failure under load** (A2.11): 7 failures in one of four full runs, not reproduced, names not captured, cause not established. It belongs with the known timing debt carried to Release 1 hardening (§28, §35); hosted CI will show whether it recurs.
- The G3-A limitations in §37 (non-transactional filesystem, single-suite constraint, interruption, lock waits, command and disk belong together, permission tests skipped as root) stand unchanged.
- Customer reply **attachments**, `resolved` → `open`, `closed` opened fresh, pagination past 20, the P2 redirect, writes by the other customer, mail content and the truly empty list are covered by Pest or Vitest only (the E2E doc lists them).

### A2.13 Outstanding hosted-CI obligations

Hosted CI has not run for WP1 or WP2, and nothing here substitutes for it.

1. **The first hosted `./dev test:e2e` over the whole branch** (192 tests locally). Compare the `tickets` and attachment-directory counts before and after.
2. **The different-UID cleanup (follow-up 4).** On a hosted runner Playwright runs as the runner's UID, the private attachment directories are `portal`'s `0700`, and the wrapper must run the cleanup as `portal` after Playwright exits. `customer-helpdesk.spec.ts` is the **first spec to create attachment directories in CI**: J3 and J7 create two files in one directory per run, so this is the first hosted exercise of the hand-off. Expected: the wrapper reports "Tickets deleted: 3", "Attachment files removed: 2 (in 1 directory)" and both baselines restored; any other result fails the run by design (nothing is deleted to make it pass). Do not push merely to learn this; it rides on the PR the owner decides in D-1.
3. **Timing of the heaviest tests** (journey 7 and the stale-reply test, which use the default 30 s timeout) on the hosted runner.
4. The existing note in `ci.md` that hosted CI is the authoritative leak check stands.

### A2.14 Deferred work (not cancelled)

**Owner-confirmed (2026-10-11):** WP3 and WP4 are **deferred from the Release 0.9 critical path, not cancelled**. WP5 **retains the essential early-access notification, security and accessibility work**; it is not deferred with them.

- **WP3, operator ticket workspace:** `pages/operator/tickets/show`, the Reply/Internal-note composer, status and assignment, the `TimerControl` and ticket-time summary (N2), the clock glyph, `ConfirmationDialog` for close, operator labels (P4), deletion of `operator/tickets/show` and `components/time-tracker` under the **§37 removal inventory** (which must pass unchanged where it says "Keep"), re-homing the `time-migration.spec.ts` ticket-timer test, journey 10. The F5 option (b) is available here.
- **WP4, operator queue, bulk and reports:** the queue with filters and bulk, reports and the CSV export, journey 9.
- **WP5, notification hardening and closeout:** per-recipient links, F-3 escaping, O3 (the "Waiting on you" and "Resolved" emails and the new-ticket alert to `HELPDESK_NOTIFY_ADDRESS`, with the RE-H13 production requirement), the cross-cutting route authorization review, search volume measurement, the NVDA + Firefox pass, **the keyboard-only and 390/768/1280 customer Helpdesk journeys (follow-up 1)**, the hosted Playwright run, and the docs closeout (roadmap, epics index, EPIC-011 Phase F note, `e2e-browser-suite.md`).
- **Optional hardening:** D-2; F5 (b) by owner act.

### A2.15 Scope integrity

Verified in G3-C. **No WP3–WP5 code is in the tree:** there is no React operator ticket page and no `ConfirmationDialog` use in tickets; `HELPDESK_NOTIFY_ADDRESS` is absent from `app/`, `config/` and `.env.example`; the only notification classes are the two existing ticket notifications (which G3-A touched for `#[DeleteWhenMissingModels]` only) and `InvitationNotification`; the embedded `x-time-tracker` is still on the operator page, as N2 continuity requires. **No schema change** (no migration since `main`), and none of `routes/`, `app/Policies`, `config/` or the permission catalogue changed in WP2. The only Time-module code touched is the N1 server rule (`AccessibleTimeContext`, `TimeEntryController`). No dependency was added.

### A2.16 Lifecycle and disposition

**WP2 is complete and closed locally**, with the limitations above, and with hosted CI and the different-UID cleanup still to come (A2.13). The owner accepted the local closure on 2026-10-11. EPIC-017 is **not** complete and stays **Planned**; WP3 and WP4 are deferred from the Release 0.9 critical path, not cancelled, and WP5 keeps its essential early-access notification, security and accessibility work (A2.14). G3-C changes documentation only and is committed locally. Nothing is pushed; no PR exists.
