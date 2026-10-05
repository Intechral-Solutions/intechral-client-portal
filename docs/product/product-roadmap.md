# Product Roadmap

**Status:** Governing strategic sequence (adopted 2026-09-24)
**Companion documents:** [Platform Product & UX Direction](./platform-product-ux-direction.md) · [Information Architecture](./information-architecture.md)

This roadmap is organized around **product outcomes**, not Blade→React migration order. It supersedes the letter order of [EPIC-011](../epics/EPIC-011-react-frontend-migration.md)'s remaining phases as the source of priority. It uses sequencing buckets, not dates.

Each item becomes one or more implementation epics before work begins, consistent with the existing epic process ([docs/epics/](../epics/README.md)).

---

## Contents

- [Roadmap principles](#roadmap-principles)
- [Sequence at a glance](#sequence-at-a-glance)
- [NOW — Stabilize and establish direction](#now--stabilize-and-establish-direction)
- [NEXT — Product/UX foundation](#next--productux-foundation)
- [NEXT — Core work management](#next--core-work-management)
- [LATER — Helpdesk MVP](#later--helpdesk-mvp)
- [LATER — Directory](#later--directory)
- [LATER — Finance](#later--finance)
- [LATER — Advanced Projects](#later--advanced-projects)
- [LATER — Knowledge/CMS evolution](#later--knowledgecms-evolution)
- [FUTURE — Platform capabilities](#future--platform-capabilities)
- [FUTURE — Deployment / release engineering](#future--deployment--release-engineering)
- [FINAL HARDENING](#final-hardening)
- [Dependencies](#dependencies)
- [Relationship to EPIC-011 and its remaining phases](#relationship-to-epic-011-and-its-remaining-phases)

---

## Roadmap principles

1. **Classify every item.** Each roadmap item is one of:

   | Class | Meaning | Example |
   |-------|---------|---------|
   | **Security / integrity** | Fixes a defect that exposes data or corrupts state | Ticket reply authorization |
   | **UX foundation** | Shell, design system, patterns every module inherits | New application shell |
   | **Platform capability** | Cross-cutting service built once | CI, approvals, notifications |
   | **Product functionality** | User-visible domain capability | Tasks overhaul, Helpdesk MVP |
   | **Later optimization** | Performance, polish, exhaustive QA | Final hardening |

2. **Security/integrity first.** A known defect in an area is fixed before new product work in that area.
3. **No giant rewrite.** Favor incremental, independently useful slices that leave the product shippable.
4. **Redesign and renderer migration travel together.** Remaining Blade modules move to React as part of their product redesign, on the new shell, rather than as faithful re-creations first.
5. **Design before build for new surfaces.** A surface covered by the design exploration is implemented after its design direction is agreed.
6. **Foundations earn their keep.** Platform capabilities are built when a real consumer needs them, with the second consumer validating the shape.
7. **Deferred QA is scheduled, not forgotten.** Exhaustive device/AT/cross-browser work is concentrated in final hardening.
8. **Data policy around the first production release.**
   - **Before the first customer production release,** development and testing business data is disposable unless explicitly marked otherwise. It may be cleared, reseeded, rebuilt or normalized whenever that materially simplifies development, migrations, fixtures or validation; do not spend engineering effort preserving arbitrary development history (Tickets included).
   - **After the first production release,** production data is authoritative history. Migrations must preserve it unless explicitly reviewed otherwise; production Ticket numbers must be preserved; destructive resets are not an acceptable migration strategy; and release preflights and backups become operational requirements (see [Deployment / release engineering](#future--deployment--release-engineering)).

## Sequence at a glance

| Bucket | Items | Class |
|--------|-------|-------|
| **NOW** | Critical Helpdesk hardening · Product/UX rebase (this) · Claude Design brief and exploration | Security/integrity · Direction · UX foundation |
| **NEXT** | New application shell · Design system · Lightweight CI baseline (Done) | UX foundation · Platform capability |
| **NEXT** | Tasks overhaul · Timer UX improvement · Projects UX expansion | Product functionality |
| **LATER** | Helpdesk MVP · Directory · Finance · Advanced Projects · Knowledge/CMS evolution | Product functionality (+ first platform-capability consumers) |
| **FUTURE** | Reusable approvals, notifications, global search, integrations, external API, observability, audit/history, automation · Deployment/release engineering (trigger-based) | Platform capability |
| **FINAL HARDENING** | Accessibility, screen-reader, real-device, cross-browser, performance, security, consistency, deployment and operational readiness | Later optimization |

LATER items are listed in their recommended order but are separable; see [Dependencies](#dependencies).

---

## NOW — Stabilize and establish direction

### Critical Helpdesk hardening

**Class:** Security / integrity. **Scope:** small, backend-only, on the existing Blade Ticket module. **Not** Helpdesk product or UX work.

**Provenance.** The earlier Ticket/Helpdesk discovery audit is not stored in this repository. The register below was **re-verified by reading the live code on 2026-09-24** and is the authoritative starting list. The implementing epic should reconcile it against the original audit register if the owner still has it, and add any further high-impact findings from that audit.

**Vehicle:** [EPIC-010D: Helpdesk Security and Integrity Hardening](../epics/EPIC-010D-helpdesk-security-hardening.md) (**Verified** 2026-09-24), following the EPIC-010A–C pattern with characterization tests first and Pest regression coverage for each finding. EPIC-010D re-verifies H1–H6 below, adds H7–H9 (CSV formula injection, Ticket-number collision, role-name policy check), and locks the visibility and assignment decisions; it is the authoritative register from here on.

*The register below is the original pre-fix record; all findings in it are now fixed (EPIC-010D).*

| ID | Severity (provisional) | Finding | Evidence (live code) |
|----|------------------------|---------|----------------------|
| **H1** | Critical | **Any authenticated user can post a public reply to any ticket.** The user-facing reply route carries only `auth` middleware and `TicketReplyController::store` never authorizes the ticket. Tickets bind by sequential integer ID, so every ticket is reachable. The reply also triggers an email notification to the ticket owner and assignee, so a user of one tenant can inject content into, and trigger mail from, another tenant's ticket thread | `routes/web.php` (`tickets.replies.store`, `Route::middleware('auth')`); `app/Http/Controllers/Operator/TicketReplyController.php` (no `authorize`); `app/Services/TicketService.php::addReply` (notifications) |
| **H2** | High | **Internal-note attachments are downloadable by non-operators.** `downloadAttachment` authorizes `view` on the parent ticket only and never checks whether the attachment belongs to an internal reply. The ticket owner can download internal attachments by (sequential) attachment ID even though the show page hides them | `app/Http/Controllers/TicketController.php::downloadAttachment`; `TicketAttachment::reply()` / `TicketReply.is_internal` |
| **H3** | High | **Internal-note search oracle.** `Ticket::scopeSearch` matches `replies.body` without excluding internal replies, and the user-facing ticket index uses it. A customer can probe for terms in internal notes by observing which of their tickets match | `app/Models/Ticket.php::scopeSearch`; `TicketController::index` |
| **H4** | Medium | **List/policy mismatch.** The user ticket index includes same-company tickets for `tickets.view_org`, but `TicketPolicy::view` allows only the owner or an operator, so listed tickets 403 on open. Already flagged for EPIC-011F as EPIC-011E finding C8. Resolution requires a decision on organization-level ticket visibility (grant it in the policy, or remove it from the list) | `TicketController::index`; `app/Policies/TicketPolicy.php`; [EPIC-011E C8](../epics/EPIC-011E-projects-kanban.md) |
| **H5** | Medium | **Assignment integrity.** Single and bulk assignment validate `assignee_id` only as `exists:users,id`, so a ticket can be assigned to any user, including a customer account, which then receives assignee notifications | `Operator\TicketController::assign`; `Operator\TicketBulkController::update` |
| **H6** | Low | **Bulk status without a status errors.** `action=status` with no `status` passes `null` into `safeTransition(string $newStatus)`, a `TypeError` (HTTP 500) rather than a validation error | `Operator\TicketBulkController::update` / `safeTransition` |

**Related debt (resolved for Tickets):** `TicketPolicy` used to test the hard-coded `operator` role name while operator routes gate on `tickets.assign`. EPIC-010D fixed this (H9): Ticket authorization is now capability-based ([Information Architecture → User](./information-architecture.md#user)). Role-name checks outside Ticket code (the tenancy scopes) remain and were not in the package.

**Exit criteria (direction):** H1–H3 fixed with regression tests proving the negative cases; H4 resolved by an explicit visibility decision (made: own Tickets only, EPIC-010D D1); H5–H6 fixed or explicitly deferred with rationale; no UI redesign; full Pest suite green.

**Status (2026-09-24):** EPIC-010D is **Verified** and critical Helpdesk hardening is **complete**: H1–H9 are fixed with regression tests and the full Pest suite passes (`Done` follows the normal merge lifecycle). Because there is no customer production data yet, the read-only Ticket preflight in the epic's [Closeout](../epics/EPIC-010D-helpdesk-security-hardening.md#first-production-release--deployment-gate) is a **release-safety gate for the first production release** (or any populated production deployment), not an open verification item. The remaining NOW and NEXT work (Claude Design exploration, new application shell, design system) may proceed. The [Helpdesk MVP](#later--helpdesk-mvp) and any Helpdesk product redesign remain later work.

**Why first:** Helpdesk is a customer-facing, multi-tenant surface with a cross-tenant write path (H1) and two confidentiality leaks (H2, H3). These are independent of every design decision below and should not wait for them.

### Product/UX rebase

**Class:** Direction. The three documents in [docs/product/](./README.md) plus the strategic note on EPIC-011. Complete when approved and committed.

### Claude Design brief and exploration

**Class:** UX foundation. After these documents are approved: write a dedicated Claude Design brief grounded in [Design context for the Claude Design brief](./platform-product-ux-direction.md#design-context-for-the-claude-design-brief), and explore the five [representative design surfaces](./platform-product-ux-direction.md#representative-design-surfaces) in Light and Dark. Output: an agreed visual direction and enough design-system decisions to start the shell.

**Depends on:** rebase approval. Can run in parallel with Critical Helpdesk hardening.

---

## NEXT — Product/UX foundation

### New application shell

**Class:** UX foundation.

**Vehicle:** [EPIC-013: Direction D Application Shell and Design System Foundation](../epics/EPIC-013-direction-d-shell-design-system.md) (**Done** — Verified 2026-09-28, merged to `main` via [PR #1](https://github.com/Intechral-Solutions/intechral-client-portal/pull/1); planned 2026-09-25) delivers this item together with [Design system](#design-system) below, against the approved [Direction D design contract](../design/direction-d-design-system.md).

- Full-viewport shell; workspace-based navigation (Home, Projects, Tasks, Helpdesk, Time, Directory, Finance, System) driven by the server `NavigationBuilder`
- Workspace layout primitives: wide canvas, reading width, split/side regions
- Account menu per [Information Architecture → Account menu](./information-architecture.md#account-menu)
- Capability-aware structure; Home as a role-aware landing surface (initial version may be modest)
- Global timer presence redesigned to fit the shell (full timer UX work follows under [Timer UX improvement](#timer-ux-improvement))

**Coexistence (superseded in part).** This item originally proposed that remaining Blade pages keep the legacy Blade layout, adopt shared semantic tokens only where cheap, and receive **no** new Blade shell work. The later [Direction D design contract](../design/direction-d-design-system.md) (approved 2026-09-25, §19 step 5) instead **requires Blade shell parity** — the same rail, utility bar, token system and navigation data — so the product does not look like two unrelated applications during migration. Direction D governs: Blade receives new *shell chrome* work in [EPIC-013](../epics/EPIC-013-direction-d-shell-design-system.md). The provisional note still holds for Blade **page bodies**, which are not redesigned until their module's product epic. Navigation between renderers stays a full document visit, as today.

### Design system

**Class:** UX foundation.

**Vehicle:** [EPIC-013](../epics/EPIC-013-direction-d-shell-design-system.md), jointly with [New application shell](#new-application-shell) above — the token layer and the shell are built as one foundation because neither is verifiable without the other.

Semantic tokens; typography; spacing; surfaces and elevation; tables (compact and comfortable); dialogs and drawers; forms; alerts and toasts; status language; motion; Light/Dark; responsive rules; empty/loading/error states. Built on the existing shadcn/ui + Tailwind 4 foundation ([ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)), restyled, not replaced wholesale. Existing React pages (dashboard, profile, time, projects, tasks) are migrated onto the new system as their redesign slices land.

### Lightweight CI baseline

**Class:** Platform capability.

**Status:** **Done** (2026-09-29) — merged to `main` via [PR #2](https://github.com/Intechral-Solutions/intechral-client-portal/pull/2) (merge commit `e8743f2`), following [EPIC-013 → CI handoff](../epics/EPIC-013-direction-d-shell-design-system.md#30-ci-handoff). Both configured triggers (`pull_request` targeting `main`, `push` to `main`) have passed on GitHub Actions. The baseline is live: every PR and every push to `main` now runs it. Full detail — gates, environment, triggers, caching, security, known limitations — is authoritative at [`docs/testing/ci.md`](../testing/ci.md); this entry only records that the roadmap item is complete.

**Timing:** introduced **alongside or immediately after the first merged slice of the shell/design system**, and **required before the Tasks overhaul begins**. Early enough to protect the redesign; late enough that the build it verifies has settled.

**Scope, deliberately simple:**

- clean checkout and clean environment
- full Pest suite against MariaDB
- frontend: Wayfinder generation, typecheck, lint, format check, unit tests, production build
- critical Playwright suite
- blocking pass/fail gate on the branch/PR

**Not in scope:** build matrices, deployment, release promotion, production orchestration. `./dev check` defines the checks; CI proves they pass from a clean checkout.

---

## NEXT — Core work management

These are the first product slices on the new shell. They exercise the design system on the domains already in React.

### Tasks overhaul

**Class:** Product functionality. My Tasks; All Tasks (capability-gated); project tasks included; explicit Complete/Reopen (board tasks move to the project's designated Done column through the canonical project-task service); search, sort, filter; assignment; contextual time. See [Task direction](./platform-product-ux-direction.md#task-direction).

**Depends on:** shell, design system, CI baseline (all three Done — dependency satisfied). **Needs design:** designated Done column and Reopen policy — resolved by owner decision Q2 in EPIC-014 (Q1 locks who may Complete/Reopen).

**Vehicle:** [EPIC-014: Tasks Workspace Overhaul](../epics/EPIC-014-tasks-workspace-overhaul.md) (**Planned** 2026-09-29; **In Progress** since WP1 merged 2026-09-30; **Verified** 2026-10-02 at WP7 (PR #14), every required exit criterion met including PR CI; **Done** 2026-10-02 on merge, commit `18b6f2e`, merge CI green). The optional enhancements (row timer control, bulk assign, peek inspector, Home "My work", shortcut sheet; EPIC-014 WP6) were **deferred** by owner decision and remain future work. It locks the Complete/Reopen authorization, the Done-column and Reopen rules, `tasks.view_all` for All Tasks, the standalone-task lifecycle (superseding EPIC-011E D3) and the retirement of the "My organization" view in favour of an organization filter; ticket-task product UX stays Future.

### Timer UX improvement

**Class:** Product functionality. Friendlier running-timer controls; clear task/project/ticket context; easier switching; editing and recovery; active-state clarity; contextual controls in Tasks and Projects. Server timer contracts (EPIC-011D) remain authoritative.

**Depends on:** shell (global presence); best delivered with or right after the Tasks overhaul.

### Projects UX expansion

**Class:** Product functionality. Redesign the project workspace around Planning / Execution / Monitoring using data that exists or is cheap to add: milestones, tasks, members, time, budget. Add list view alongside the board; a first project-health/monitoring summary; stakeholder-friendly presentation. Outcomes, SOW linkage, baselines, and change control are [Advanced Projects](#later--advanced-projects).

**Depends on:** shell, design system; benefits from Tasks overhaul components.

**Vehicle:** [EPIC-015: Projects UX Expansion](../epics/EPIC-015-projects-ux-expansion.md) (**Planned** 2026-10-02). It locks derived project health, explicit milestone completion, budget metadata visible only with effective Settings/Edit access, the Overview as the canonical project landing, a project-scoped Tasks list on the canonical task query, one project-time definition, and the stale board-assignee time fix. Dedicated stakeholder/customer presentation is deferred (EPIC-015 §22 records the missing customer-product roadmap item).

> **Forward note (2026-10-05).** EPIC-015 is **Verified** (2026-10-05): WP1–WP5 are merged (the optional WP5 Board Complete/Reopen, per-surface drawer defaults and Project Time tab included), every §19 criterion is satisfied, and the WP6 closeout PR #22 is green on both hosted jobs (head `78b1aab`) but not yet merged. It moves to Done when that package merges with green `main` CI. The customer-product gap (EPIC-015 §22) and a post-EPIC global design-token / UI consistency audit (EPIC-015 Amendment 6) are still unplaced.

---

## LATER — Helpdesk MVP

**Class:** Product functionality. Renderer migration of Tickets (EPIC-011 Phase F) happens here, as part of the redesign.

- Secure Tickets on the new shell (requires [Critical Helpdesk hardening](#critical-helpdesk-hardening) complete)
- Operator queue: filtering, sorting, assignment, bulk actions
- Replies and internal notes with clear visual separation
- Attachments with visibility matching their reply
- Time on tickets using the redesigned timer
- Reporting (successor to the current CSV reports)
- **Incident baseline:** create an Incident, link Tickets, central status
- **Knowledge baseline:** simple articles (title, body, status, visibility, links), possibly delivered together with the first [Knowledge/CMS evolution](#later--knowledgecms-evolution) slice

No full ITIL. SLA, routing, and automation remain Future.

## LATER — Directory

**Class:** Product functionality. Renderer migration of CRM/Organizations (EPIC-011 Phase H) happens here.

- People (Person records with or without User accounts)
- Organizations (consolidating the CRM company and tenancy organization concepts; approach Open)
- Relationships and classifications (customer, partner, prospect, vendor…)
- Account linking (Person ↔ User; inviting a Person)
- Person/Organization detail as a relationship hub (projects, Helpdesk, Finance)

**Risk:** this likely includes the platform's most consequential data migration (contacts, companies, organizations, tenancy keys). It must preserve EPIC-010B tenant-isolation guarantees and is a trigger for formal release engineering (below).

## LATER — Finance

**Class:** Product functionality. Renderer migration of Billing/Stripe (EPIC-011 Phase G) happens here.

- Centralized Finance workspace
- Billing, Invoices (redesigned authoring; server-authoritative totals unchanged)
- Retainers, Rates
- Project budgets and budget consumption
- Project/Person/Organization commercial views
- Billing / Commercial Account model (depends on Directory)

PDF/document generation remains with [EPIC-012](../epics/EPIC-012-document-generation.md) and may land in or before this bucket.

## LATER — Advanced Projects

**Class:** Product functionality (first real consumer of approvals).

- Outcomes/objectives
- SOW linkage (proposed → approved → delivered → effort → change)
- Estimates and planned budgets
- Multiple views (list, board, timeline)
- Monitoring: estimated vs actual, burn/variance, health
- Change control: baseline, tolerance, change requests, cost/schedule impact, customer approval, revised baseline

**Depends on:** Projects UX expansion; Finance (budgets, rates); a minimal approval contract (see Future platform capabilities); EPIC-012 for SOW documents.

## LATER — Knowledge/CMS evolution

**Class:** Product functionality + platform capability. Renderer migration of CMS (EPIC-011 Phase I) happens here.

- Shared content infrastructure (storage format, rendering, sanitization)
- Knowledge publishing with visibility (internal / customer / public, provisional)
- Ticket → Knowledge workflow (draft an article from a resolution; link later tickets)
- Revisions, search, and approval later
- Tool/library evaluation as an explicit decision record once authoring requirements are concrete

---

## FUTURE — Platform capabilities

**Class:** Platform capability. Each is built when a real consumer needs it; the second consumer validates the shape.

| Capability | Likely first consumer |
|------------|----------------------|
| Reusable approvals/reviews | Advanced Projects change requests or SOW approval |
| Notifications (in-app + email, preferences) | Helpdesk MVP; account-menu Notifications |
| Global search | Directory + Helpdesk + Tasks, once all are on the new shell |
| Audit/history | Finance and change control (provenance of commercial decisions) |
| Integrations framework | Ticket creation from external systems |
| External API | After internal domain contracts mature; first real external consumer |
| Observability | With release engineering |
| Advanced automation | Helpdesk routing/escalation, project thresholds |

System → Users/Roles renderer migration (EPIC-011 Phase J) is folded into the shell/System workspace work whenever administration is redesigned; it has no dependency beyond the shell.

## FUTURE — Deployment / release engineering

**Class:** Platform capability. Deliberately later than CI and must not distract from the product/UX rebase.

**Trigger.** Formalize deployment/release engineering **before the first production release that ships a redesigned-shell module**, and begin planning it once all of these readiness conditions hold:

- the new shell and design system are stable (at least one redesigned workspace is complete on them)
- the production build architecture is settled (Vite/Wayfinder build output and hosting target confirmed)
- queue/background-job requirements are clear (notifications, document generation)
- the document generation strategy is settled ([EPIC-012](../epics/EPIC-012-document-generation.md) decision gate)

**Hard gate regardless of the above:** no production release containing a transformational data migration (for example the Directory/Organization consolidation, or moving invoices from User clients to Billing Accounts) ships without guarded migrations, an automatic pre-migration backup, and a tested rollback path.

**Then address:** staging environment; staging→production promotion if useful; reproducible production builds; guarded migrations and automatic backups; rollback; maintenance mode; smoke/health checks; queue and worker handling; environment and secrets management; release retention; backup retention; hosting compatibility (cPanel-compatible target today).

---

## FINAL HARDENING

**Class:** Later optimization. Runs once against the finished platform surface, successor to EPIC-011 Phase K.

- Exhaustive accessibility review
- Screen-reader matrix (NVDA, JAWS, VoiceOver), including items deferred from EPIC-011E
- Real-device mobile (iOS Safari, Android Chrome)
- Cross-browser validation
- Performance and bundle audit
- Security review
- Cross-module consistency review
- Removal of remaining Blade pages and migration-only code; documented intentional Blade exceptions
- Deployment readiness
- Operational readiness (backups, monitoring, runbooks)

---

## Dependencies

| Item | Depends on | Enables |
|------|------------|---------|
| Critical Helpdesk hardening | — | Helpdesk MVP |
| Claude Design brief/exploration | Rebase approval | Shell, design system |
| New application shell | Design direction | Everything on the new UI |
| Design system | Design direction | Everything on the new UI |
| Lightweight CI baseline (**Done**) | First shell/design-system slice | Tasks overhaul and all later product work |
| Tasks overhaul | Shell, design system, CI | Timer UX, Projects UX, Helpdesk (ticket tasks) |
| Timer UX improvement | Shell; best with Tasks | Contextual time everywhere |
| Projects UX expansion | Shell, design system | Advanced Projects |
| Helpdesk MVP | Critical hardening, shell, design system | Knowledge (Ticket → Knowledge), Incidents |
| Directory | Shell, design system | Finance (Billing Account), Person-based Helpdesk requesters, customer stakeholders |
| Finance | Directory (for Billing Account); EPIC-012 for PDFs | Advanced Projects (budgets, rates) |
| Advanced Projects | Projects UX, Finance, minimal approvals | Change control, SOW linkage |
| Knowledge/CMS evolution | Helpdesk MVP (for Ticket → Knowledge) | Customer/public knowledge |
| Release engineering | Trigger conditions above | Safe production delivery of LATER work |
| Final hardening | All intended surfaces redesigned | Platform "done" |

**Recommended LATER order:** Helpdesk MVP → Directory → Finance → Advanced Projects, with Knowledge/CMS evolution joining Helpdesk or following it. Helpdesk leads because it is customer-facing, still Blade, and already hardened by then; Directory precedes Finance because the Billing Account model depends on it. The order may change if business need shifts; the dependencies above may not.

## Relationship to EPIC-011 and its remaining phases

[EPIC-011](../epics/EPIC-011-react-frontend-migration.md) remains the renderer-transition record and technical foundation roadmap. Phases A–E are complete (E Verified 2026-09-23). The remaining phases are **absorbed into product work** rather than executed in letter order:

| EPIC-011 phase | Now delivered by |
|----------------|------------------|
| F — Tickets | [Critical Helpdesk hardening](#critical-helpdesk-hardening) (security, no UI) then [Helpdesk MVP](#later--helpdesk-mvp) |
| G — Billing and Stripe | [Finance](#later--finance) |
| H — CRM and Organizations | [Directory](#later--directory) |
| I — CMS | [Knowledge/CMS evolution](#later--knowledgecms-evolution) |
| J — Administration | Shell/System workspace work ([Future platform capabilities](#future--platform-capabilities) note) |
| K — Decommission and hardening | [Final hardening](#final-hardening) |

EPIC-011's migration principles (Laravel authority, Inertia-first data flow, no dead scripts, per-phase inventory) continue to apply to every slice that moves a Blade page to React. EPIC-011F has not begun and no EPIC-011F document exists; Ticket sequencing is now governed by this roadmap.
