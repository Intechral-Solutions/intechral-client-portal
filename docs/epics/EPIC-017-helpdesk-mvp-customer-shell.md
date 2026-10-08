# EPIC-017: Helpdesk MVP and Customer Shell

**Status:** **Planned** (2026-10-07). WP0, this document, is the design gate. **Owner review is complete (2026-10-07):**
- the architecture is approved;
- owner decisions O1–O3 are **approved** ([§37](#37-owner-decisions));
- plan choices P1–P6 are **approved** ([§36](#36-plan-level-choices-approved));
- the E2E cleanup command is locked ([§25.3](#253-playwright)).

No implementation has started, and no implementation branch exists.
**Class:** Product functionality + UX foundation (Product Roadmap [Release 1 → Helpdesk](../product/product-roadmap.md#helpdesk-release-1) and [Customer product and customer shell](../product/product-roadmap.md#customer-product-and-customer-shell); Release 1 Phase 2)
**Renderer record:** This is the implementation contract for [EPIC-011 Phase F (Tickets)](./EPIC-011-react-frontend-migration.md#phase-f-tickets). No separate EPIC-011F document is created.
**Design contract:** [Direction D](../design/direction-d-design-system.md): §7 customer shell, §10.4 ticket statuses, §13 account menu, §14 focus and keyboard, §15 states.
**Security baseline:** [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) (Verified), its [final security and integrity contract](./EPIC-010D-helpdesk-security-hardening.md#final-security-and-integrity-contract) and locked decisions D1–D5.
**Shell seam:** [EPIC-013 §23](./EPIC-013-direction-d-shell-design-system.md#23-audience-presentation-and-the-shell-seam): the presentation discriminator, `AppShell` as the single branch point, and chrome-agnostic pages.
**Prerequisites:**
- [EPIC-013](./EPIC-013-direction-d-shell-design-system.md), [EPIC-014](./EPIC-014-tasks-workspace-overhaul.md), [EPIC-015](./EPIC-015-projects-ux-expansion.md) and [EPIC-016](./EPIC-016-direction-d-blade-theme-control-adoption.md): all Done.
- The `@shadcn/lint` adoption ([PR #29](https://github.com/Intechral-Solutions/intechral-client-portal/pull/29), head `e0cc0bf671d6deb6bef9b54d012eaa5c99647113`, both PR CI jobs green). **At the last verification it was open and not merged**: the GitHub API reported `merged: false` on 2026-10-08 00:10 UTC, and `origin/main` was still `2fd0918`. Its merge is therefore **still the entry condition of WP1** ([§31](#31-work-packages)), the only remaining one besides committing WP0. When it merges, record the merge SHA and the merge-triggered `main` CI here, and remove this condition ([§38](#38-restart-and-handoff-notes)).

**Planning baseline:** `main` @ `2fd0918e17f6378442d566cd6b0703ec1c7a3ff9`, the merge of PR #28. It was equal to `origin/main`, with a clean working tree. Merge-triggered `main` CI run 37694314651 was green. Verified 2026-10-07 and **re-verified 2026-10-08 during the owner-review remediation**: `origin/main` had not advanced.

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
| Branch / HEAD | `main` @ `2fd0918e17f6378442d566cd6b0703ec1c7a3ff9`, equal to `origin/main`, clean |
| Latest `main` CI | Run 37694314651 (merge of PR #28): **green**. The earlier red run 37661096171 (`docs: approve shadcn lint adoption`) was the Finance fixture collision that PR #28 fixed. |
| Open PRs | **#29 `Adopt shadcn lint for Direction D React styling`** (`tooling/shadcn-lint-adoption`, head `e0cc0bf`): open, mergeable, both CI jobs green, **not merged**. It is tooling only and explicitly excludes Helpdesk and customer-shell work. It is not unrelated product work, so planning proceeds; its merge is a WP1 entry condition. **Re-verified 2026-10-08 00:10 UTC:** still open, `merged: false`. |
| Helpdesk branches / partial work | None. No `EPIC-017` reference, no Helpdesk or customer-shell branch, and no React Ticket page or type exists. |
| Local branches | `tooling/shadcn-lint-adoption` (PR #29). Remote `origin/*` feature branches are historical, already merged. |

**Correction to the planning premise.** Both the handoff prompt for this design gate and the later owner-review remediation brief stated that the `@shadcn/lint` adoption was merged. The repository and the GitHub API show that it is green but **not merged** (re-verified 2026-10-08):

- `main` has no `@shadcn/lint`, no `AGENTS.md` and no `src/eslint-suppressions.json`.
- This document plans against the policy in PR #29 (its `AGENTS.md`, the ESLint configuration and the `docs/testing/ci.md` additions), because the owner has approved that adoption ([roadmap forward note, 2026-10-07](../product/product-roadmap.md#release-1-sequence)).

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
- **Props:** a paginator of `{id, number, title, status, statusLabel, priority, createdAt, href}` only.

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

The owner-approved policy (PR #29: its `AGENTS.md`, `eslint.config.js` and `docs/testing/ci.md`) is authoritative for all React work here.

At the last verification (2026-10-08) PR #29 was green but **not yet merged**, which is why it remains WP1's entry condition. WP1 starts from the merged policy and re-reads it at start, because the baseline may evolve.

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
  - **Harness.** The E2E harness calls it during or after teardown (`E2eCleanup` teardown and the `./dev test:e2e` post-run). `tickets` joins the tracked product-data counts, which must return to baseline.
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
  - the `@shadcn/lint` adoption (PR #29) merged on `main`. At the last verification (2026-10-08) it was green but not yet merged ([header](#epic-017-helpdesk-mvp-and-customer-shell)). This condition drops away once it merges.
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
| PR #29 merges late or is reworked before merge | Low | It is WP1's entry condition; re-read the policy at WP1 start |
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
- user-selectable shell presentation (EPIC-013 §31.1).

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

**Not raised as owner decisions** (settled by evidence or existing rulings):
- the routing topology;
- the organization switcher;
- the Directory dependency;
- the renderer strategy (the Release 1 boundary already requires the operator React migration);
- Incidents (B, default Post-v1);
- Knowledge (C);
- schema changes (none);
- customer selection of Critical priority (preserved existing behaviour, owned by the future SLA-policy design, [§13](#13-status-priority-and-internal-note-semantics)).

## 38. Restart and handoff notes

- **Read first:** this document §1–§9, §31, and §36–§37; the roadmap [Release 1](../product/product-roadmap.md#release-1-re-orientation-2026-10-06); Direction D §7, §10.4, §13 and §14; EPIC-010D's [final contract](./EPIC-010D-helpdesk-security-hardening.md#final-security-and-integrity-contract); EPIC-013 §23.
- **Before WP1:**
  1. Fetch, fast-forward `main` to `origin/main`, and check that the tree is clean and the latest `main` CI is green.
  2. Confirm WP0 is committed on `main`.
  3. Confirm the `@shadcn/lint` adoption is on `main`: `src/eslint-suppressions.json` and `AGENTS.md` are present. At the last verification (2026-10-08) PR #29 was still open. **If it has merged,** record its merge SHA and merge CI run in this document's header and drop the condition there and in WP1.
  4. Create `feature/epic-017-helpdesk-mvp-customer-shell` from current `main`.
  5. Run `./dev doctor` and `./dev check`.
  6. **Re-inventory** the Helpdesk views and shell hooks against this document's §4 (EPIC-011 L484: inventories are planning baselines).
  7. **Re-read the `@shadcn/lint` baseline** (`AGENTS.md`, `eslint.config.js`, `src/eslint-suppressions.json`), because it may evolve.
- **Do not assume:**
  - that the line numbers in §4 are still exact;
  - that `@shadcn/lint`'s suppression baseline is unchanged;
  - that RE-0 has chosen a host.
- **The owner rulings are settled:** O1–O3 and P1–P6 are approved (§36, §37). Revisiting one is an explicit owner act, recorded with a date.
- **Parallel tracks, never blockers:** RE-0 host discovery, EPIC-012 discovery and the Directory data-model ADR may run alongside. Directory implementation follows this epic. No other epic may touch the customer shell or the shared `Status` vocabulary while EPIC-017 is in progress (roadmap [Parallelism](../product/product-roadmap.md#parallelism)).
