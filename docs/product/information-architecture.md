# Information Architecture

**Status:** Canonical target IA (adopted 2026-09-24). Screen-level detail is intentionally left to design and implementation epics.
**Companion documents:** [Platform Product & UX Direction](./platform-product-ux-direction.md) · [Product Roadmap](./product-roadmap.md)

This document defines the target navigation, workspace boundaries, and conceptual domain model. For every concept it separates:

- **Current** — what the live repository implements today (verified 2026-09-24)
- **Target** — the conceptual model we have chosen
- **Open** — modeling work that remains provisional and will be settled by the implementing epic

Nothing here is a database design. Where the target model differs from the schema, migration is future work.

---

## Contents

- [Top-level navigation](#top-level-navigation)
- [Operator vs customer surfaces](#operator-vs-customer-surfaces)
- [Core concepts](#core-concepts)
- [Relationships between concepts](#relationships-between-concepts)
- [Terminology migration](#terminology-migration)
- [Navigation placement](#navigation-placement)
- [Account menu](#account-menu)

---

## Top-level navigation

**Target baseline (operator view, full capability set):**

| Workspace | Purpose | Visible when (provisional capability basis) |
|-----------|---------|---------------------------------------------|
| **Home** | Role-aware starting point: my work, signals, items awaiting me | Every authenticated user |
| **Projects** | Planning, execution, monitoring, change control | `projects.view` |
| **Tasks** | My Tasks, All Tasks, cross-source work management | Every authenticated user with any task source; All Tasks capability-gated |
| **Helpdesk** | Tickets, Incidents, Knowledge | `tickets.view` (Tickets); operator capabilities for queue/Incidents |
| **Time** | Personal time, timers, allocation, reports | `time.log`; reports on `time.view_all` |
| **Directory** | People, Organizations, Relationships | Directory capability (today `crm.manage`) |
| **Finance** | Billing, invoices, retainers, rates, budgets | `billing.view` (own) / `billing.manage` (central) |
| **System** | Users, roles, pages/configuration, settings, integrations | Any administrative capability (`users.view`, `roles.view`, `settings.*`, `cms.edit` for configuration pages) |

**Capability-driven visibility.** A workspace appears only when the user holds a capability that gives it at least one useful surface. No user is forced to see every item. Workspaces with a single permitted surface may link straight to it. The server-side `NavigationBuilder` (Current) remains the single source of navigation truth for both renderers; its output shape will change to express workspaces and sub-navigation.

**Current navigation** (for contrast): a flat primary list — Tickets, Projects, Tasks, Time, Billing, CRM, Pages — plus a "Manage" group — Ticket Queue, Time Reports, Organizations, CMS Pages, Users, Roles. There is no Home item; the dashboard is reached through the brand link.

The capability names in the table above are the closest existing permissions, used to show intent. Final capability names are **Open** and belong with the shell epic; renaming permissions is not implied.

## Operator vs customer surfaces

**Shared:** shell, design system, navigation mechanics, account menu and account pages, themes, accessibility rules, component library.

**Differentiated by capability and relationship:**

| Aspect | Operator surfaces | Customer surfaces |
|--------|-------------------|-------------------|
| Workspaces shown | Full capability set, typically all eight | A subset; plausibly Home, Projects, Tasks, Helpdesk, Finance (own) |
| Home | Operational: assigned work, queues, timers, approvals, health signals | Personal: my projects' status, my requests, decisions awaiting me, invoices |
| Projects | Planning, execution, monitoring, change control, finance context | Stakeholder view: health, progress, milestones, tasks involving me, approvals |
| Helpdesk | Queue, triage, assignment, internal notes, Incidents, Knowledge authoring | My requests, replies, customer-visible Knowledge |
| Finance | Central workspace and contextual views | My invoices, payments, (Future) retainer/budget status |
| Directory | Full Directory | Not shown; at most, their own organization's people (Future, Open) |
| System | Where permitted | Not shown |

**Rules:**

- Surfaces are selected by **capability and relationship**, never by a mode toggle.
- The same object (a project, a ticket) may have **different layouts** for operator and customer; both are built from shared components and backed by the same server authorization.
- Room is preserved for role-specific dashboards, cross-role collaboration on shared artifacts, and a Future read-only "view as customer" preview.
- **Open:** whether the customer workspace uses the same routes with capability-aware rendering or a distinct route namespace. Recommended default: same routes, capability-aware pages; revisit only if divergence makes that awkward. This does not block the design brief.

## Core concepts

### User

- **Current:** `users` table; authentication via Fortify (local credentials, 2FA) and Socialite SSO (Google, Microsoft); invitation-only onboarding; Spatie roles (`operator` with all permissions, `user` for clients, plus custom roles); membership in `organizations` via `organization_members` (admin/member). Customers are Users. `TicketPolicy` tests the hard-coded `operator` role name, while most other checks use permissions.
- **Target:** a User is an **account identity** that can log in. It links to exactly one Person. Authorization is capability-based; role names are groupings of capabilities, not checks in code.
- **Open:** User ↔ Person linkage mechanics; whether invitations target a Person; retiring hard-coded role-name checks (a hardening or shell-epic task).

### Person

- **Current:** no Person entity. Human records exist in two unconnected places: `users` (people who log in) and `crm_contacts` (first/last name, email, phone, job title, optional CRM company). There is no link between a contact and a user.
- **Target:** a **Person** is a human directory record that may or may not have a User account. Many People never log in. A Person can relate to several Organizations.
- **Open:** whether Person evolves from `crm_contacts`, is introduced alongside it, or both are consolidated; de-duplication of existing contacts and users.

### Organization

- **Current:** two overlapping concepts. `crm_companies` is the directory-style record ("Company" in CRM) with an optional `organization_id`. `organizations` is the tenancy/membership record (owner, members with admin/member role), created by "promoting" a CRM company. Tenant scoping keys on `crm_companies.organization_id` and `organization_members` ([ADR-005](../architecture/adr/ADR-005-organization-multitenancy.md)).
- **Target:** a single conceptual **Organization** (company, nonprofit, group, agency, etc.). People relate to Organizations. Whether an Organization has portal access/tenancy is a property or capability, not a separate entity type.
- **Open:** consolidating `crm_companies` and `organizations`; how tenant scoping is expressed afterwards. Tenant-isolation guarantees (EPIC-010B) must be preserved through any migration.

### Relationship

- **Current:** no explicit Relationship. Relationships are implied by: a CRM company existing, organization membership, `project_company` links (visibility metadata only, EPIC-011E D2), `projects.client_id`, `invoices.client_id → users`, and the `user` role being "intended for clients".
- **Target:** a **Relationship** records the business/operational relationship between Intechral and a Person or Organization, carrying one or more classifications, a status, and (Future) dates, owners, and notes. Relationships between directory entities (e.g. a partner's client) are possible later.
- **Open:** cardinality (one Relationship with many classifications vs one per classification); whether Person–Organization affiliation is itself modelled as a Relationship or a separate affiliation record (recommended: separate affiliation, keep Relationship for Intechral's business relationship).

### Customer/Partner classification

- **Current:** "customer" is implicit (a User with the `user` role, or a CRM company). "Partner" does not exist.
- **Target:** customer, partner, prospect, vendor, subcontractor, referral partner, and others are **classifications of a Relationship**, applicable to People and/or Organizations. They drive views ("Customers" is a filtered Directory view), defaults, and possibly capabilities; they are never entity types.
- **Open:** the initial classification list and whether it is fixed or configurable.

### Billing / Commercial Account

- **Current:** none. Invoices bill a User (`invoices.client_id`); projects carry an optional `client_id` and a `budget`.
- **Target (Future):** a **Billing / Commercial Account** identifies the responsible billing entity, billing contacts, payment terms, rates, retainers, and tax/commercial information, and points at a Person or Organization without redefining it. "Customer" does not mean "the entity we invoice."
- **Open:** whether invoices migrate from User clients to Billing Accounts, and when.

### Project

- **Current:** `projects` with members (`project_members`, the sole access path besides `projects.admin`), configurable board columns, milestones, tasks, checklists, comments, dependencies, `project_company` links, optional `client_id`, a single `budget`, start/target dates. React UI (EPIC-011E).
- **Target:** four areas — Planning, Execution, Monitoring, Change control ([direction](./platform-product-ux-direction.md#project-direction)). Stakeholders are People/Organizations with a role on the project. Customer stakeholders get a stakeholder view.
- **Open:** outcomes/objectives model, estimate model, baseline and tolerance model, SOW linkage, how customer stakeholders gain access (membership vs relationship-derived).

### Task

- **Current:** one `tasks` table, three kinds: **board tasks** (project + column; column-authoritative status), **standalone tasks** (own `status`), **ticket tasks** (`ticket_id`; nothing in application code creates them). Unified `/tasks` list with mine/org tabs. Completing a board task means moving it to the Done column.
- **Target:** a first-class Tasks workspace (My Tasks, All Tasks) across sources, explicit Complete/Reopen, assignment, search/sort/filter, contextual time ([direction](./platform-product-ux-direction.md#task-direction)). Board tasks stay column-authoritative internally.
- **Open:** "designated Done column" per project; Reopen policy; whether ticket-derived tasks are created and by whom.

### Time

- **Current:** `time_entries` linked to project, optional task, optional ticket, optional invoice; billable/billed flags; server-authoritative timers (multiple concurrent); 15-minute allocation blocks; billed-entry immutability; operator reports; `time.view_own` exists but is not enforced (see [RBAC design](../architecture/rbac-design.md)).
- **Target:** time captured where work happens (Tasks, Projects, Tickets), a friendlier timer, and time feeding project monitoring and Finance (estimates vs actual, budget consumption).
- **Open:** rates and cost derivation (depends on Finance/Billing Account); the fate of `time.view_own`.

### Helpdesk

- **Current:** the workspace does not exist by name. "Tickets" (user) and "Ticket Queue" (operator) are separate navigation items.
- **Target:** **Helpdesk** workspace containing Tickets, Incidents, and Knowledge ([direction](./platform-product-ux-direction.md#helpdesk-direction)).
- **Open:** sub-navigation order and customer-facing label (customers may see "Help" or "Support"; provisional).

### Ticket

- **Current:** `tickets` (TKT number, title, description, category, priority, status, assignee, owner, optional `company_id` → CRM company), replies with `is_internal`, attachments (ticket-level or reply-level), status history, CSV reports, bulk actions, reply notifications. Blade. Access: owner or an `operator`-role user with `tickets.view`; the list additionally includes same-company tickets for `tickets.view_org`, which the show policy then denies. **Known authorization/integrity defects exist** — see [Product Roadmap → Critical Helpdesk hardening](./product-roadmap.md#critical-helpdesk-hardening).
- **Target:** one requester's request/conversation, linked to a Person (requester) and, where relevant, an Organization; optionally linked to an Incident, Knowledge, Tasks, and Time.
- **Open:** requester as Person (non-User requesters, e.g. email-originated); organization-level ticket visibility for customers; category/priority/SLA model.

### Incident

- **Current:** none.
- **Target:** a service-affecting event linking many Tickets, People, and Organizations; managed centrally by operators while each requester keeps their own Ticket.
- **Open:** Incident lifecycle; whether customers see Incident status (e.g. a status notice on linked Tickets).

### Knowledge

- **Current:** none.
- **Target:** simple authored articles — title, body, status, visibility, linked Tickets/Incidents, author/reviewer metadata. Shares content infrastructure with CMS where sensible.
- **Open:** visibility levels (internal / customer / public); editor and storage format; tool/library choice (deferred decision).

### CMS / Page

- **Current:** `cms_pages` (slug, title, content, draft/published). Viewer navigation label "Pages"; operator label "CMS Pages".
- **Target:** CMS remains a separate domain from Knowledge, sharing infrastructure. Operator page management sits under **System → Pages/configuration**; viewer-facing pages are reached where they are relevant (Future: public publishing).
- **Open:** whether current CMS content is portal documentation (closer to Knowledge) or site/page content (true CMS); this decides where each existing page lands.

### Finance

- **Current:** operator invoices (create/edit/send/status), line items, manual payments, Stripe payment, client invoice list; navigation label "Billing".
- **Target:** **Finance** workspace (Billing, Invoices, Retainers, Rates, Budgets) plus contextual Finance views on Project, Person, and Organization.
- **Open:** Billing Account model; rate model; retainer model.

### System

- **Current:** Users (index/show) and Roles (index/create/edit) under "Manage"; `settings.view` / `settings.manage` permissions exist in the catalogue.
- **Target:** **System** workspace: Users, Roles, Pages/configuration, Settings, Integrations (Future), audit/system controls where appropriate.
- **Open:** which settings exist; whether invitations are managed from System → Users or from Directory → People (Person without a User → invite).

### Approval / Review

- **Current:** none.
- **Target (Future):** a reusable platform capability — a request for a decision on an artifact, the decision, and a recorded outcome with provenance. Consumers: SOWs, proposals, change requests, budget changes, acceptance, content/Knowledge publication, cross-role review.
- **Open:** entirely; shaped by its first two real consumers.

### External Integration / API

- **Current:** inbound integrations are SSO (Google, Microsoft) and Stripe (payments and webhooks). No external/public API; the browser uses Inertia. Notifications exist for invitations, ticket creation, and ticket replies.
- **Target (Future):** an extensible integration/API platform (create Tickets, submit requests, propose Projects, exchange workflow events, query portal state, interact with approvals).
- **Open:** entirely; internal domain contracts mature first.

## Relationships between concepts

Conceptual map. The **Current** column states what exists in the database today; do not read Target rows as implemented.

| Relationship | Target meaning | Current |
|--------------|----------------|---------|
| Person ↔ User | A User is the login identity of exactly one Person; most People have no User | No Person; Users and CRM contacts are unconnected |
| Person ↔ Organization | Many-to-many affiliation (employee, contractor, board member…) | `crm_contacts.crm_company_id` (one company); `organization_members` for Users |
| Person/Organization ↔ Relationship | Intechral's business relationship with the entity, carrying classifications | None explicit |
| Relationship ↔ Billing Account | A commercial relationship may have one or more Billing Accounts | None |
| Project ↔ Person/Organization | Stakeholders with roles (sponsor, approver, contact); client organization | `project_members` (Users); `project_company` (metadata only); `projects.client_id` |
| Project ↔ Tasks | Execution work of the project | `tasks.project_id` + `column_id` |
| Project ↔ Milestone | Planned checkpoints | `project_milestones` |
| Project ↔ SOW | Planning commitments linked to outcomes, milestones, estimates | None (SOW documents: [EPIC-012](../epics/EPIC-012-document-generation.md)) |
| Project ↔ Change request | Baseline changes with approval | None |
| Task ↔ Time | Effort captured against a task | `time_entries.task_id` |
| Task ↔ Ticket | Work derived from a request | `tasks.ticket_id` (no creation path) |
| Ticket ↔ Person/Organization | Requester (Person) and affected Organization | `tickets.user_id` (User), `tickets.company_id` (CRM company) |
| Ticket ↔ Time | Support effort | `time_entries.ticket_id` |
| Ticket ↔ Incident | Many Tickets to one Incident | None |
| Ticket ↔ Knowledge | Articles used in or drafted from a resolution | None |
| Incident ↔ Knowledge | Articles describing the event/workaround | None |
| Incident ↔ Organization | Affected organizations | None |
| Finance ↔ Person/Organization/Project | Invoices, retainers, rates, budgets in context | `invoices.client_id` (User), `invoices.project_id`, `projects.budget` |
| Time ↔ Finance | Billable time becomes invoice lines; budget consumption | `time_entries.invoice_id`, `billable`, `billed` |
| Approval ↔ domain artifacts | One approval capability referenced by SOWs, change requests, budgets, content, Knowledge | None |
| Knowledge ↔ CMS | Shared content infrastructure, separate objects | CMS only |

## Terminology migration

**Provisional** labels are marked. Renaming in the UI happens as each area is redesigned; code and permission names are not renamed by this document.

| Current term (UI or code) | Target conceptual term | Target navigation home | Notes |
|---------------------------|------------------------|------------------------|-------|
| CRM | Directory | **Directory** | Locked |
| Company (`crm_companies`) | Organization | Directory → Organizations | Consolidation with `organizations` is Open |
| Organization (`organizations`, "Manage → Organizations") | Organization (portal access/tenancy as a property) | Directory → Organizations; access management possibly System | Open |
| Contact (`crm_contacts`) | Person | Directory → People | |
| Customer / client (implicit; `client_id`; `user` role) | Relationship classification "customer" | Directory → Relationships / customer view | Not an entity type |
| Partner | Relationship classification "partner" | Directory → Relationships | New |
| Client (as invoice recipient) | Billing / Commercial Account (Future) | Finance | "Customer" ≠ "entity we invoice" |
| Tickets / Ticket Queue | Helpdesk → Tickets | **Helpdesk** | Customer-facing label may be "Help" or "Support" (Provisional) |
| Billing | Finance → Billing / Invoices | **Finance** | Locked |
| Pages (viewer) / CMS Pages (operator) | CMS Page; some content may become Knowledge | System → Pages (management); viewer placement Open | |
| Manage (nav group) | Split into System and workspace-level operator surfaces | — | Retired as a concept |
| Dashboard | Home | **Home** | |
| Time Reports | Time → Reports | **Time** | |
| Users / Roles | System → Users / Roles | **System** | |

## Navigation placement

Only the placement is defined; screens that do not exist yet are not specified.

### Directory

- **People** — Person records, with or without User accounts
- **Organizations** — organizational records
- **Relationships / relationship views** — e.g. Customers, Partners, Prospects, Vendors as filtered views over Relationships (Provisional presentation)

### Helpdesk

- **Tickets** — operator queue and, for customers, "my requests"
- **Incidents** — operator-facing; customer exposure Open
- **Knowledge** — authoring (operator) and reading (customer-visible articles)
- Reports — operator ticket reporting (Current: `operator/tickets/reports`) lands here

### Finance

- **Billing** — billable time, billing runs (Future)
- **Invoices**
- **Retainers** (Future)
- **Rates** (Future)
- **Budgets** (Future; contextual on Projects)

### System

- **Users**
- **Roles**
- **Pages / configuration**
- **Settings**
- **Integrations** (Future)
- Audit/system controls where appropriate (Future)

### Projects, Tasks, Time, Home

- **Projects** — project list; per-project workspace with Planning / Execution (board, list, milestones) / Monitoring / Change control sections as they arrive
- **Tasks** — My Tasks; All Tasks (capability-gated)
- **Time** — my time and timers; allocation; reports (capability-gated)
- **Home** — role-aware; no fixed sub-navigation

## Account menu

The account menu is **about the signed-in person's own account** and nothing else.

**Target contents:**

- Profile
- Security / MFA
- Connected Accounts
- Sessions
- Appearance
- Notifications (Future)
- Sign Out

**Boundary:** administrative functions (Users, Roles, settings, configuration) belong under **System**, even when the current user is an administrator. The account menu never grows admin shortcuts.

**Current:** the React account menu contains Profile and Sign out; the theme toggle is a separate header button. The single Profile page holds profile information, password, two-factor, sessions, and connected (social) accounts (EPIC-011B). The target splits these into account sections without changing the underlying Fortify behavior.
