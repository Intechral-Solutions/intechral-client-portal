# Platform Product & UX Direction

**Status:** Canonical direction (adopted 2026-09-24)
**Companion documents:** [Information Architecture](./information-architecture.md) · [Product Roadmap](./product-roadmap.md)

This is the product north star for the Intechral Client Portal. It is directional, not an implementation epic: it records what the platform is becoming and the principles that decide trade-offs. Implementation detail belongs in epics.

Wording discipline used throughout: **Current** means implemented today; **Target** means chosen and intended; **Future** means plausible later and not committed. Nothing described as Target or Future exists unless it is explicitly marked Current.

---

## Contents

- [Purpose](#purpose)
- [Where the platform stands today](#where-the-platform-stands-today)
- [Product principles](#product-principles)
- [Shared shell, differentiated workspaces](#shared-shell-differentiated-workspaces)
- [Product domains](#product-domains)
- [Project direction](#project-direction)
- [Task direction](#task-direction)
- [Helpdesk direction](#helpdesk-direction)
- [Directory direction](#directory-direction)
- [Finance direction](#finance-direction)
- [Time UX direction](#time-ux-direction)
- [Knowledge and CMS direction](#knowledge-and-cms-direction)
- [System direction](#system-direction)
- [Approval and review direction](#approval-and-review-direction)
- [API and integration direction](#api-and-integration-direction)
- [Other cross-cutting platform capabilities](#other-cross-cutting-platform-capabilities)
- [Delivery-engineering direction](#delivery-engineering-direction)
- [UX principles](#ux-principles)
- [Full-viewport philosophy](#full-viewport-philosophy)
- [Contextual density](#contextual-density)
- [Theme philosophy](#theme-philosophy)
- [Responsive philosophy](#responsive-philosophy)
- [Accessibility philosophy](#accessibility-philosophy)
- [Design context for the Claude Design brief](#design-context-for-the-claude-design-brief)
- [Representative design surfaces](#representative-design-surfaces)
- [Intentional non-goals and deferred concepts](#intentional-non-goals-and-deferred-concepts)

---

## Purpose

Intechral delivers technology services: project work, ongoing support, and advisory engagements. The portal exists so that this work is **run** and **seen** in one place:

- **Operators** (Intechral staff) plan, execute, support, track, and bill client work efficiently.
- **Customers and other stakeholders** understand what is happening on their behalf, ask for help, approve what needs approving, and see what they are paying for, without needing to learn Intechral's internal tooling.

The platform is becoming a **professional services operating system with a client-facing window**: a work-management and service-management tool first, with finance, directory, and knowledge woven through it, rather than a set of loosely related admin screens.

## Where the platform stands today

**Current**, verified against the live repository on 2026-09-24:

- Laravel 13 / PHP 8.3 / MariaDB, server-authoritative authorization (Spatie permissions plus policies), membership-based tenant scoping ([ADR-005](../architecture/adr/ADR-005-organization-multitenancy.md)).
- Inertia 3 + React 19 + TypeScript foundation ([ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)). Auth, invitations, dashboard, profile, time, timer, projects, board, milestones, project task detail, and the unified `/tasks` list are React ([EPIC-011A–E](../epics/README.md)).
- Tickets, operator ticket queue/reports, billing/invoices/Stripe payment, CRM companies/contacts, organizations, CMS, users, and roles are still Blade.
- A single navigation model (`NavigationBuilder`) serves both renderers: a flat primary list (Tickets, Projects, Tasks, Time, Billing, CRM, Pages) plus a "Manage" group (Ticket Queue, Time Reports, Organizations, CMS Pages, Users, Roles), filtered by permission.
- A strong local quality gate (`./dev check`: Pint, Wayfinder, tsc, ESLint, Prettier, Vitest, production build, full Pest; `./dev test:e2e` for Playwright). **There is no CI.** Deployment targets cPanel-compatible hosting and is manual.

The owner's assessment after exercising the platform: the foundation is sound, but **the UI needs a full overhaul** and several domains (Tasks, Projects, Helpdesk, Time UX, Directory, Finance, Knowledge) need real product evolution, not a faithful re-render of the legacy screens.

## Product principles

These decide trade-offs. When two principles conflict, the earlier one usually wins.

1. **Security and integrity before surface.** Known authorization or data-integrity defects are fixed before the affected area receives new product or UX work.
2. **Explicit server authority.** Laravel owns authorization, tenant scoping, state transitions, and money. The UI renders permitted state and requests changes; it never decides them.
3. **Capability-aware surfaces, not modes.** What a person sees and can do follows from their permissions and relationships, never from a client-side "operator mode" toggle.
4. **Operator efficiency.** Operators do repetitive, high-volume work. Their surfaces optimise for throughput: dense where it helps, keyboard-friendly, few clicks to the common action, context kept in view.
5. **Customer clarity.** Customers see their own world in plain language: what is happening, what is needed from them, what it costs. Calm beats comprehensive.
6. **Progressive complexity.** Start with the simplest model that is genuinely useful (a Ticket, a Knowledge article, an approval) and grow it when real use demands, without painting the model into a corner.
7. **Cross-module consistency.** The same concept looks and behaves the same everywhere: a person chip, a status, a timer control, a filter bar, an approval request.
8. **Strong provenance and history.** Who did what, when, and why should be recoverable for anything that matters commercially or contractually (status changes, scope changes, approvals, billed time).
9. **Reusable platform capabilities.** Approvals, notifications, search, audit/history, and integrations are built once as platform services, not reinvented per module.
10. **Extensibility without speculative abstraction.** Keep domain boundaries clean enough that APIs and integrations can be extracted later, but do not build generic engines before two real consumers exist.
11. **Polished by default.** A professional tool earns trust through craft. Loading, empty, error, and edge states are part of the feature, not polish for later.
12. **Accessible by design.** Keyboard operation, semantic structure, focus management, contrast, and reduced-motion support are built in from the design system up.
13. **Incremental, independently useful slices.** No big-bang rewrite. Every slice should leave the product better and shippable.

## Shared shell, differentiated workspaces

**Target.** One design system and one application shell; workspaces that are free to diverge.

**Shared across everyone:**

- visual language, semantic tokens, typography, and themes (Light/Dark)
- navigation mechanics (how nav, breadcrumbs, context switching, and search behave)
- the account experience (account menu, profile, security, appearance)
- component library and interaction patterns
- accessibility rules

**Free to differ by capability and relationship:**

- which workspaces appear at all
- the Home experience (an operator's work queue versus a customer's "what's happening for me")
- information density
- available tools, bulk actions, and administrative controls
- contextual actions on the same underlying object

The goal is **not** one interface with controls hidden. An operator looking at a project and a customer looking at the same project may see materially different layouts because their jobs differ. Both are built from the same components.

**Operators** get tools shaped to their role: queues, triage, assignment, bulk actions, time capture, monitoring, finance, and system administration where permitted.

**Customers** get a calmer workspace focused on their own projects, tasks that involve them, help requests, knowledge, approvals, invoices, and relevant collaboration.

**Architectural guardrails:**

- **No "operator mode" / "customer mode" authorization primitive.** Surfaces are selected by capability. (Current debt: `TicketPolicy` tests a hard-coded `operator` role name; target is capability-based checks. See [Information Architecture → User](./information-architecture.md#user).)
- Keep room for **role-specific dashboards**, **cross-role collaboration** (operators and customers acting on the same artifact, e.g. an approval), and a possible **Future** "view as customer" preview for operators. "View as" must be a read-only rendering aid, never an impersonation that bypasses authorization.

## Product domains

| Domain | One-line role | Current state (summary) |
|--------|---------------|--------------------------|
| **Projects** | Plan, deliver, and monitor client engagements; flagship experience | React; board, milestones, tasks, members, company links, budget field |
| **Tasks** | First-class personal and team work management across sources | React unified `/tasks` list (mine / org tabs); board-column-authoritative status for project tasks |
| **Helpdesk** | Support conversations, service incidents, and reusable knowledge | Blade "Tickets" + operator queue; no Incidents; no Knowledge |
| **Time** | Capture effort in context; feed billing and project monitoring | React time page, allocation, timer bar, operator reports; billed-entry locking |
| **Directory** | People, Organizations, and Intechral's Relationships with them | Blade "CRM" (companies, contacts) + organizations; customer-as-user model |
| **Finance** | Commercial truth: billing, invoices, retainers, rates, budgets | Blade invoices, payments, Stripe; no rates or retainers |
| **Knowledge** | Authored, reusable support and resolution content | Not implemented |
| **CMS** | Authored pages for the portal and possibly public publishing | Blade CMS pages (draft/published) |
| **System** | Platform administration | Blade users and roles |

## Project direction

Projects should become one of the most useful parts of the platform for both operators and stakeholders. **Target** conceptual model, organized around four areas:

| Area | What it covers |
|------|----------------|
| **Planning** | Objectives, outcomes, scope, deliverables, SOW commitments/items, estimates, milestones, responsibilities, acceptance criteria, planned time and budget |
| **Execution** | Tasks, Kanban, list views, timeline-style views (Future), assignments, collaboration, time, artifacts, milestones |
| **Monitoring** | Project health, progress toward outcomes, milestones, estimated vs actual time, budget consumption, burn/variance, risks/issues where useful, stakeholder-friendly summaries |
| **Change control** | Baseline scope, actual/proposed variance, tolerated scope creep, thresholds, proposed adjustment/change request, cost and schedule impact, customer approval, revised baseline |

**Current:** execution only (board, milestones, tasks, checklists, comments, members, time links) plus a single `budget` field.

**Scope creep, intelligently.** A **Future** project may carry a baseline and a tolerance: variance within the tolerance is absorbed and visible; variance beyond it triggers a formal adjustment (change request) with cost/schedule impact and customer approval, producing a revised baseline. The exact tolerance model is deliberately undecided.

**SOW linkage (Future, advanced Projects/Finance capability).** Planning commitments should eventually connect to execution so the platform can answer: *what was proposed, what was approved, what was delivered, what it took, and whether scope materially changed.* Candidate chain: SOW items → objectives/outcomes → milestones → estimates → project tasks → actual time → customer-approved changes. Not modelled now. Document generation for SOWs remains with [EPIC-012](../epics/EPIC-012-document-generation.md).

**Frameworks.** Do not copy PMBOK, PRINCE2, ITIL, or any framework wholesale. Research industry-standard concepts and adopt them selectively where they genuinely improve clarity, expectations, accountability, stakeholder communication, financial management, or change control.

**Stakeholder views.** A customer stakeholder should be able to understand health, progress, upcoming milestones, and anything awaiting their decision without reading a Kanban board.

## Task direction

Tasks become a **first-class workspace**, not a by-product of project boards. The current `/tasks` behavior (mine/org tabs, kind-aware status) is a migration artifact and **does not constrain** the future Task product.

**Target:**

- **My Tasks** and **All Tasks** (the latter capability-gated).
- Project tasks appear in Tasks; ticket-derived tasks appear where appropriate (Future; nothing in application code creates ticket tasks today).
- **Explicit Complete / Reopen.** Nobody should have to drag a card to finish a task.
- Assignment, search, sorting, filtering, and useful bulk/keyboard interactions.
- Likely filters: assignee/person, project, organization/relationship, status/state, priority, due date, source/kind, milestone, completion.
- Contextual time tracking (see [Time UX direction](#time-ux-direction)).

**Completion semantics (direction, not final design).** Project-board tasks remain **column-authoritative** internally (EPIC-011E §15). An explicit Complete action invokes the canonical project-task service and moves the task to the project's designated Done column; Reopen returns it according to a defined policy (e.g. previous column or the project's first open column). Standalone and ticket tasks continue to use `tasks.status`. The "designated Done column" concept and the reopen policy need design before implementation.

## Helpdesk direction

**Helpdesk** is the product and workspace name for support. It replaces "Tickets" / "Ticket Queue" as the workspace label.

**Target conceptual areas:**

| Area | Meaning |
|------|---------|
| **Ticket** | One request/conversation, typically one requester asking for or reporting something |
| **Incident** | A broader service-affecting event that may relate to many Tickets, People, and Organizations. Each requester keeps their own Ticket conversation; operators manage the Incident centrally |
| **Knowledge** | Reusable authored support/resolution content (see [Knowledge and CMS direction](#knowledge-and-cms-direction)) |

**Current:** Tickets (user list/create/show; operator queue/show/status/assign/bulk/reports/CSV), public replies, internal notes, attachments, status history, reply notifications, embedded Blade time tracker. No Incidents, no Knowledge, no SLA engine.

**Before any Helpdesk product work:** the known Ticket authorization and integrity defects are fixed as a separate, small hardening package. See [Product Roadmap → Critical Helpdesk hardening](./product-roadmap.md#critical-helpdesk-hardening).

**ITIL posture.** The long-term direction may evolve toward fuller ITIL-inspired capability, but the starting point is deliberately small. **Future** concepts, introduced only when operational need appears: Problem, Change, SLA, service catalog, escalation, routing, automation, service ownership.

**Operator vs customer.** Operators get a queue, triage, assignment, internal notes, time, and linkage to Incidents/Knowledge. Customers get their own requests, clear status, a simple reply experience, and relevant Knowledge, never internal notes or internal attachments.

## Directory direction

The current "CRM" model (companies, contacts, organizations, and customers-as-users) is **not** the desired long-term conceptual model.

**Target conceptual primitives:**

| Concept | Meaning |
|---------|---------|
| **User** | An authentication/account identity that can log in |
| **Person** | A human directory record. A Person may or may not have a User account |
| **Organization** | A company, nonprofit, group, agency, or other organizational entity. People may relate to several |
| **Relationship** | The business/operational relationship between Intechral and a Person or Organization (and potentially between directory entities later) |

**Customer, partner, prospect, vendor, subcontractor, referral partner** are **relationship classifications** (roles, views, or properties of a Relationship), not entity types. The same Organization can be a customer and a referral partner.

**Commercial responsibility** is separate again. A **Future** Billing Account / Commercial Account may identify the responsible billing entity, billing contacts, payment terms, rates, retainers, and tax/commercial information, without redefining the Person or Organization. "Customer" does not mean "the entity we invoice."

No database redesign is decided here. Current implementation, target model, and migration work are distinguished in [Information Architecture → Core concepts](./information-architecture.md#core-concepts).

## Finance direction

**Finance** is the locked top-level name for the business/commercial workspace.

**Target responsibilities:** Billing, Invoices, Retainers, Rates, project budgets, commercial tracking, billable time, budget consumption, financially relevant project adjustments, payment state.

**Shape:** a centralized Finance workspace for operators, plus **contextual Finance views** on Project, Person, and Organization pages. Customers see their own invoices, payment, and (Future) retainer/budget status.

**Current:** invoices, line items, manual payments, Stripe payment, client invoice list, billed time-entry locking ([EPIC-010C](../epics/EPIC-010C-billed-time-entry-locking.md)). No rates, retainers, or budget tracking beyond `projects.budget`. Invoice clients are User records (`invoices.client_id → users`).

"Operations" may be considered as a Future rename only if the area grows substantially beyond finance/commercial responsibility. For now, **Finance** is locked.

## Time UX direction

**Current:** server-authoritative timers (multiple concurrent supported), reload recovery, allocation into 15-minute blocks, billing locks, operator reports, a persistent React timer bar on React pages and a Blade tracker on Blade pages. Technically sound.

**Target (important UX workstream, not designed here):**

- a friendlier running-timer interface with clear task/project/ticket context
- easier switching between timers and contexts
- fewer confusing edge states; improved editing and recovery
- stronger integration into Tasks and Projects: start/stop, current state, and time today where the work is, and **Future** estimates vs actual and budget impact
- a polished, compact global presence; the global timer should not carry every interaction
- richer contextual controls where useful

## Knowledge and CMS direction

Knowledge and CMS should eventually **share appropriate content/authoring infrastructure** (storage, rendering, sanitization, revisions) while remaining **different domain objects with different UIs**.

**Target first Knowledge system (deliberately simple):** title, body, status, visibility, linked Tickets/Incidents, author/reviewer metadata; tags/categories later.

**Future:** revisions, approvals, richer editor, search, related articles, analytics, publishing workflows; resolving a Ticket can optionally draft a Knowledge article; later Tickets link to it; Knowledge connects to Incidents; selected Knowledge is exposed to customers and optionally published publicly; related infrastructure serves CMS/public pages.

**Current:** CMS pages (slug, title, content, draft/published). No Knowledge.

**Tooling decision deferred.** No third-party wiki, CMS, or editor is chosen. Evaluate external tools/libraries once authoring requirements are concrete (a later decision record).

## System direction

**System** is the platform-administration workspace: Users, Roles, Pages/configuration, system settings, integrations/configuration (Future), and audit/system controls where appropriate.

Administrative functions do **not** go into the personal account menu merely because the current user is an administrator. See [Information Architecture → Account menu](./information-architecture.md#account-menu).

## Approval and review direction

**Target platform capability, Future implementation.** A reusable Review/Approval capability so modules do not invent incompatible approval mechanisms.

Likely consumers: SOW approvals, proposals, project adjustments/change requests, budget changes, customer acceptance, content publication, Knowledge approval, operator cross-role review.

Cross-role collaboration (operator and customer acting on the same request) is an important long-term direction. No schema is designed here. The first module that genuinely needs approval should shape a minimal shared contract (request → decision → recorded outcome with provenance) that the second consumer then validates.

## API and integration direction

**Future.** An extensible API/integration platform through which other enterprise systems might create Tickets, submit feedback, propose Projects, submit structured requests, exchange workflow events, query relevant portal state, and interact with approvals.

**Now:**

- Internal domain contracts (services, DTOs, policies) should mature before a large public API is exposed.
- Avoid designs that make later extraction unnecessarily hard: keep business rules in services rather than controllers or React, keep authorization in policies, keep identifiers and state transitions explicit.
- Browser UI stays on Inertia; no Sanctum/SPA API is introduced for the browser ([ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)).

## Other cross-cutting platform capabilities

Recorded so they are built once, deliberately, rather than ad hoc: **approvals/reviews, notifications, global search, audit/history, integrations, external API, observability, backups, operational readiness, CI, deployment/release engineering.** Sequencing is in the [Product Roadmap](./product-roadmap.md#future--platform-capabilities).

Note on audit/history: the architecture docs describe a Spatie activity log, but no activity-log implementation exists in application code today. Ticket status history and time-entry records are the only current provenance. Audit/history is therefore a Target platform capability, not a Current one.

## Delivery-engineering direction

| Concern | Direction | When |
|---------|-----------|------|
| **CI** | Lightweight, blocking baseline: clean checkout/environment, full Pest, frontend typecheck/lint/format/unit/build, critical Playwright suite. No elaborate matrices, deployment flows, or release promotion | Early: introduced alongside or immediately after the first merged slice of the new shell/design system, and required before the Tasks overhaul starts |
| **Deployment / release engineering** | Reproducible production builds, guarded migrations with automatic pre-migration backups, smoke/health checks, rollback, maintenance mode, queue/worker handling, secrets, release and backup retention, staging→production promotion if useful, hosting compatibility | Later, triggered by concrete conditions (see [Product Roadmap → Deployment / release engineering](./product-roadmap.md#future--deployment--release-engineering)) |

CI's purpose is clean-checkout reproducibility, regression protection, independent verification, and removing "works in my long-lived container" risk. `./dev check` is the local equivalent and the natural basis for it. Deployment engineering must not distract from the product/UX rebase.

## UX principles

1. **Clarity over decoration.** Hierarchy, typography, and spacing carry the design; ornament does not.
2. **The next action is obvious.** Every surface makes its primary action and the user's current state unmistakable.
3. **Context stays in view.** Switching between a task, its project, its time, and its conversation should not lose place.
4. **Predictable patterns.** One way to filter, one way to confirm destructive actions, one way to show status.
5. **Honest state.** Loading, empty, partial, stale, error, and permission-denied states are designed, not defaulted.
6. **Calm feedback.** Subtle motion that explains change (a card moving, a row updating); never motion for its own sake; respect reduced-motion preferences.
7. **Keyboard-first for operators.** Common operator actions reachable without a pointer.
8. **Language matches the audience.** Operators may see operational vocabulary; customers see plain language.

**Visual personality (Target):** a modern, polished enterprise work tool with restrained Intechral-specific character. Professional, contemporary, calm, high information clarity, strong typography, restrained depth, subtle motion, low clutter. Distinctive enough to feel like Intechral, never decorative, and explicitly **not** a generic admin template.

## Full-viewport philosophy

The **shell uses the full browser viewport**. That does **not** mean every block of content stretches edge to edge; each surface controls its own optimal width.

| Wide / full-canvas candidates | Constrained-width candidates |
|-------------------------------|------------------------------|
| Kanban | Forms |
| Task tables | Conversations |
| Project monitoring | Knowledge articles |
| Timelines | Account and security |
| Dashboards | Focused detail content |
| Operational queues | |
| Analytics | |

A single page may mix both: a Ticket detail sits inside a full-width shell, with the conversation at a reading width and operator context in a side region.

## Contextual density

| Spacious | More compact |
|----------|--------------|
| Shell and headers | Task tables |
| Forms | Helpdesk queue |
| Project summaries | Finance tables |
| Detail pages | Operational lists |
| Dialogs | |

"Spacious" never means inefficient: generous where people read and decide, compact where they scan and act. Density is a property of the surface, set by the design system, not an ad hoc per-page choice. A user-level density preference is Future at most.

## Theme philosophy

- **Initial themes: Light and Dark.** Both are first-class and designed together.
- Build on **semantic tokens** (surface, text, border, accent, status, focus, elevation) so later themes can be introduced without rewriting components. No additional themes are designed now.
- **Current:** a Light/Dark toggle with no-flash pre-paint persistence exists for both renderers (EPIC-011A). The redesign replaces the visual system but should keep the no-flash contract.

## Responsive philosophy

- The platform is **responsive, not desktop-only**. Build sensible responsive structure throughout: navigation that collapses, tables that degrade to lists or scroll intentionally, dialogs that become sheets.
- Operator-heavy surfaces (Kanban, dense tables, monitoring) are desktop-optimised but must remain usable on smaller screens.
- Customer surfaces should work well on a phone.
- **Deferred:** exhaustive real-device testing and the full device matrix run once during [final hardening](./product-roadmap.md#final-hardening). Exhaustive mobile QA does not block near-term product work.

## Accessibility philosophy

- Accessible by design: semantic structure, full keyboard operation, visible focus, managed focus in dialogs and drawers, accessible names, sufficient contrast in both themes, reduced-motion support, non-pointer alternatives for drag (EPIC-011E's keyboard Move menu is the precedent).
- Per-slice verification continues at the level EPIC-011E established: keyboard and accessibility-tree checks in a real browser, with a screen-reader smoke test where available.
- **Deferred:** the full screen-reader matrix (NVDA, JAWS, VoiceOver) and assistive-technology/device matrix run once during final hardening, against the finished surface.

## Design context for the Claude Design brief

The next deliverable after these documents is a dedicated Claude Design brief. This section gives it grounding; it is not the brief itself.

**Design objectives**

1. Establish the new shell: full viewport, capability-driven navigation, account menu, workspace layouts.
2. Establish the design system: semantic tokens, typography, spacing, surfaces, tables, dialogs, forms, alerts, status language, motion, Light/Dark, responsive rules.
3. Prove that one system can serve both dense operator work and calm customer surfaces.
4. Give Intechral a distinctive, restrained identity.

**Key design-system challenges**

- Density range: the same components must work in a compact queue and a spacious summary.
- Mixed-width pages: full-canvas regions and reading-width regions on one screen.
- Status vocabulary shared across Tasks, Projects, Helpdesk, Finance, and approvals, without colour as the only signal.
- Time presence: a compact global timer plus contextual controls, without duplication or confusion.
- Relationship-rich detail pages (Directory, Projects) where many linked domains must be navigable without clutter.
- Light and Dark with equal care; tokens that will survive future themes.
- Customer surfaces that feel related to, but calmer than, operator surfaces.

**Major operator/customer distinctions to design for**

| Aspect | Operator | Customer |
|--------|----------|----------|
| Home | Work queue: my tasks, assigned tickets, running timers, approvals, signals | What's happening for me: my projects, open requests, items awaiting my decision, invoices |
| Density | Compact where scanning | Spacious, plain language |
| Actions | Triage, assign, bulk, log time, internal notes | Ask, reply, approve, pay, read |
| Navigation | Full capability-driven set | A subset, potentially a differently arranged workspace |

## Representative design surfaces

These five surfaces are recommended as the first Claude Design exploration set.

| # | Surface | What it exercises |
|---|---------|-------------------|
| 1 | **Operator Home** | Shell and navigation; role-aware work; tasks; approvals; timers; project and helpdesk signals; operational summaries |
| 2 | **Project Workspace / Monitoring** | Project context navigation; outcomes; milestones; project health; tasks; time and budget; wide workspace; stakeholder views |
| 3 | **All Tasks** | Dense operational table/list; search; filtering; sorting; assignment; status; contextual actions; time interactions |
| 4 | **Helpdesk Ticket Detail** | Conversation; assignment and status; operator controls; internal notes; attachments; time; related Incident/Knowledge concepts; focused reading width inside the full shell |
| 5 | **Directory Person / Organization Detail** | Identity; relationships; linked projects, Helpdesk, and Finance; related People/Organizations; contextual navigation |

**Why these five are representative**

- **Coverage of layout modes.** Home (dashboard composition), Project Monitoring (wide canvas), All Tasks (dense table), Ticket Detail (reading width inside a full shell), Directory Detail (relationship hub). Together they span every width and density class in this document.
- **Coverage of the design system.** Between them they need navigation, headers, tabs, tables, filters, chips, status, progress/health indicators, conversation, forms, dialogs, timer controls, and empty/loading states: most of the component inventory.
- **Coverage of domains.** Projects, Tasks, Time, Helpdesk, Directory, and Finance all appear; approvals and Knowledge appear as signals and links.
- **Coverage of the operator/customer question.** Home, Project Workspace, and Ticket Detail each have natural customer counterparts, so the exploration can show where the workspaces diverge on shared components.
- **Coverage of hard problems.** Timer presence, cross-domain context, mixed widths, and density switching all arise naturally rather than being contrived.
- **Grounded in real near-term work.** Tasks and Projects are the NEXT implementation targets; Helpdesk and Directory are LATER. Designing them early prevents the shell from being shaped only by the first module built on it.

## Intentional non-goals and deferred concepts

Not being done now, deliberately:

- Full ITIL implementation (Problem, Change, SLA engine, service catalog, routing automation)
- A public/external API or Sanctum-based browser API
- A general-purpose approval/workflow engine
- Wholesale adoption of PMBOK, PRINCE2, or any other framework
- Additional themes beyond Light and Dark
- Exhaustive mobile, screen-reader, and assistive-technology matrices before final hardening
- Choosing a third-party wiki/CMS/editor
- A final Directory database redesign
- Faithful re-creation of legacy Blade screens for their own sake
- Elaborate CI matrices, deployment orchestration, or release promotion
- An "operator mode" toggle as an authorization mechanism
