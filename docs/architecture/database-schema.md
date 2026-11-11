# Database Schema Overview

> **Production data (2026-10-10).** For the Release 0.9 production database, tables hold authoritative data from the first genuine record. The lifecycle rules, foreign-key changes, migration contract and backup requirements are in [Release 0.9: Scope and Architecture Gate](../product/release-0.9-scope-and-architecture.md). The corrections below record where this overview described behaviour the code does not have. *No authoritative production data exists yet (owner ruling 2026-11-09; see the [R1-0 ruling](../product/release-1.0-notes.md#data-deployment-and-recovery-ruling-r1-0)).*

## Core Tables

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | varchar(255) | Display name |
| email | varchar(255) unique | |
| password | varchar(255) nullable | null for SSO-only users |
| email_verified_at | timestamp nullable | Not set by invitation acceptance and not used for access decisions today; reset to null when the email is changed |
| two_factor_secret | text nullable | Encrypted TOTP secret |
| two_factor_recovery_codes | text nullable | Encrypted recovery codes |
| two_factor_confirmed_at | timestamp nullable | |
| invited_by | bigint FK users | Operator who invited this user |
| invitation_id | bigint FK invitations | |
| remember_token | varchar(100) nullable | |
| created_at / updated_at | timestamps | |
| disabled_at | timestamp nullable | Reversible account suspension (decision H3). The column exists (R0.9-1B); nothing reads or writes it yet, so enforcement (login, sessions, candidate lists, notifications) and the suspend/reinstate action are R0.9-1C |

There is no `deleted_at` column and no soft delete.

### `invitations`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(255) | Invitee email |
| token | varchar(64) unique | Random 64-character token, currently stored in plaintext |
| invited_by | bigint FK users nullable | |
| status | enum | `pending`, `accepted` or `expired` |
| accepted_at | timestamp nullable | |
| expires_at | timestamp | Set to +48 hours when issued |
| created_at / updated_at | timestamps | |

### `social_accounts`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK users | |
| provider | varchar(50) | google, microsoft, etc. |
| provider_id | varchar(255) | Provider's user ID |
| token | text nullable | OAuth access token, stored in plaintext (no `encrypted` cast) |
| refresh_token | text nullable | OAuth refresh token, stored in plaintext (no `encrypted` cast) |
| token_expires_at | timestamp nullable | |
| created_at / updated_at | timestamps | |

### `password_histories`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK users | |
| password | varchar(255) | Previous hashed password |
| created_at | timestamp | |

## RBAC Tables (Spatie)
Standard Spatie tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

## Organization Tables

### `organizations`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | varchar(255) | Copied from the CRM company when promoted |
| slug | varchar(255) unique | URL-safe identifier |
| owner_id | bigint FK users | Provenance only: the user who created or promoted the organization. It is **not** an authorization grant; access follows `organization_members` and the tenant scopes |
| managed_by_organization_id | bigint unsigned FK organizations, nullable | Release 1.0 (MO-1A): the partner organization that administratively manages this one. Self-reference, `ON DELETE RESTRICT`, indexed. NULL for every existing row |
| can_manage_customers | boolean, default 0 | Release 1.0 (MO-1A): the organization is designated a partner |
| created_at / updated_at | timestamps | |

**Managed-organization relationship (Release 1.0, MO-1A).** An administrative link only: it grants nobody access to tickets, projects, time or invoices, and no authorization decision reads it. The manager must be a partner, a partner cannot itself be managed (CHECK `organizations_partner_not_managed_check`), nothing manages itself (enforced by the service; MariaDB cannot put a CHECK on `id`), there is one level and one manager, and both ends must have exactly one CRM company. `crm_companies.organization_id` is UNIQUE (`crm_companies_organization_id_unique`; NULLs allowed). Written only by `App\Services\OrganizationRelationships`, by operators holding both `portal.operator` and `crm.manage`. See [the MO-1A record](../product/release-1.0-managed-organizations-mo-1a.md).

### `organization_members`
Pivot: `user_id` + `organization_id` + `role` (`admin` or `member`). The pair is unique; a user may belong to multiple organizations.

| Column | Type | Notes |
|--------|------|-------|
| admin_approved_at | datetime, nullable | Release 1.0 (MO-2A1): when an operator approved this membership as an administrator. NULL for every historical row |
| admin_approved_by | bigint unsigned FK users, nullable | The approving operator. `ON DELETE RESTRICT` |

**`role = admin` grants nothing; approval is separate.** An *approved administrator* is a membership with `role = admin`, a **complete** approval record (`admin_approved_at` **and** `admin_approved_by` both set; one without the other counts for nothing), an active account, and no operator eligibility (defined once, in `App\Services\OrganizationAuthority`). Approval is an explicit, audited operator act (`App\Services\OrganizationMembership`); promoting never approves and demoting always clears it. There is deliberately **no CHECK** tying approval to `role = admin`: older code can change a role without knowing the approval, so a database may hold an approval on a non-administrator row. Such a row is ignored (it never counts) and **fails `organizations:preflight`** (as does a half-written record, or a membership whose `updated_at` is later than its `admin_approved_at`). An operator revokes an approval on the organization page. See [the MO-2A1 record](../product/release-1.0-managed-organizations-mo-2a1.md).

> **Note:** A separate `organization_roles` table is planned but not yet built. Org role management is currently handled via the `role` column on `organization_members`.

> **Data scoping:** `crm_companies.organization_id` is the direct tenant key. Organizations are scoped through `organization_members`, and CRM contacts are scoped through their company. Since R1-2 (2026-11-10) `tickets.organization_id` is a direct attribution column, but it is **not** a tenant read scope: no `OrganizationScope` applies to `Ticket`, and Ticket visibility is still the requester or an operator holding `tickets.assign` (ADR-005 Amendment 2). Projects, invoices, and time entries do not contain `organization_id`; they retain their existing ownership, membership, client, and policy boundaries. Platform operators bypass tenant scopes. `Project` visibility specifically is `project_members` membership or `projects.admin`; `project_company` is visibility metadata only and never grants access on its own (EPIC-011E D2).

## Module Tables

### Tickets Module

> **Direction (2026-11-09):** `Ticket` remains the existing support-record model. Service Request is its first intended classification, without requiring a type column today. Incident is a future, **separate operational entity**, not a Ticket subtype. No schema change or speculative relationship is required for Release 1.0; see revised [ADR-009](./adr/ADR-009-ticket-specialization.md).

- `tickets` — id, user_id (the requester), organization_id (NOT NULL, FK `organizations` RESTRICT; the customer organization the Ticket is for, R1-2), submitted_by_id (NOT NULL, FK `users` RESTRICT; the authenticated actor who submitted it, R1-2; both columns immutable after creation, see [ADR-005 Amendment 2](./adr/ADR-005-organization-multitenancy.md#amendment-2-r1-2-2026-11-10-ticket-scoping-is-policy-based-with-a-direct-attribution-column-no-organizationscope)), ticket_number (the unique public reference `TK-XXXXX-XXXXX`, random and immutable, [ADR-008](./adr/ADR-008-ticket-public-reference.md); the numeric id stays internal), title, description, category, priority, status, assignee_id
- `ticket_replies` — ticket_id, user_id, body, is_internal
- `ticket_attachments` — ticket_id, reply_id nullable, path, filename, size, mime_type
- `ticket_status_histories` — ticket_id, user_id, old_status, new_status

### Projects Module
- `projects` — id, client_id nullable, name, description, status, budget, start_date, target_date, created_by
- `project_members` — project_id, user_id pivot
- `project_columns` — project_id, name, position (configurable Kanban columns)
- `tasks` — id, project_id, column_id, milestone_id nullable, title, description, assignee_id, due_date, priority, labels
- `task_checklist_items` — task_id, label, completed
- `task_comments` — task_id, user_id, body
- `project_milestones` — project_id, name, due_date
- `project_company` — project_id, crm_company_id pivot (company-linked projects)
- `task_dependencies` — task_id, depends_on_task_id pivot

### Billing Module
- `invoices` — id, client_id, project_id nullable, invoice_number, status, issued_at, due_at, notes, subtotal, tax, total
- `invoice_items` — invoice_id, description, qty, unit_price, tax_rate, line_total
- `invoice_payments` — invoice_id, amount, date, method, reference, stripe_payment_intent_id

### Time Tracking Module
- `time_entries` — id, user_id, project_id, task_id nullable, ticket_id nullable, invoice_id nullable, date, duration_minutes (unsigned integer), description, billable, billed, timer_started_at, stopped_at
- `time_entry_blocks` — id, time_entry_id, user_id, block_date, block_number (0–95, 15-minute UTC slots), allocation_pct (share of the slot; a user's blocks in one slot sum to 100 except where billed history holds part of it), is_overridden (the user's explicit choice for the slot's current members; cleared when an entry joins or leaves it)

### CRM Module
- `crm_companies` — id, organization_id nullable, created_by, name, website, phone, address, notes
- `crm_contacts` — id, crm_company_id nullable, created_by, first_name, last_name, email, phone, job_title, notes

### CMS Module
- `cms_pages` — id, slug, title, content, status (draft/published), published_at, meta fields

## Design Decisions

1. **No soft deletes; business records are protected from user deletion** — no model uses soft deletes. Since R0.9-1B (decisions H1 and H2) the 13 business-record foreign keys to `users` (`tickets.user_id`, `ticket_replies.user_id`, `ticket_attachments.user_id`, `time_entries.user_id`, `task_comments.user_id`, `tasks.created_by`, `projects.created_by`, `organizations.owner_id`, `crm_companies.created_by`, `crm_contacts.created_by`, `cms_pages.created_by`, `invoices.client_id`, `invoices.created_by`) and `project_company.crm_company_id` are `ON DELETE RESTRICT`: the database refuses to delete a user or company those rows reference. R1-2 adds `tickets.submitted_by_id` (to `users`) and `tickets.organization_id` (to `organizations`) with the same RESTRICT rule, so an organization or a submitting user that a Ticket names cannot be deleted either; the count of 13 is the R0.9-1B set and does not include them. Credential and membership children (`password_histories`, `social_accounts`, `project_members`, `organization_members`) and derived rows (`time_entry_blocks.user_id`) still cascade, and nullable pointers (assignees, `invited_by`, `invitation_id`, status-history actor, milestone `completed_by`, CMS `updated_by`, `crm_contacts.crm_company_id`) still clear to NULL (`tickets.company_id` was in this list until R1-2 dropped the column). See [the rehearsal documentation](../testing/migration-rehearsal.md#release-09-preservation-migrations-r09-1b).
2. **Audit trail** — `activity_log` table (via Spatie Activity Log) records significant model events.
3. **Encrypted sensitive data** — 2FA secrets and recovery codes are encrypted by Fortify with the application key (`APP_KEY`). OAuth tokens and invitation tokens are currently plaintext.
4. **Foreign key constraints** — Most relationships are enforced by InnoDB foreign keys. Exceptions with no database constraint: `sessions.user_id`, the Spatie role and permission pivot `model_id` columns, the polymorphic `activity_log` columns, and `projects.client_id`.
5. **Organization scoping** — scopes follow the actual relationship path: direct company tenant key, organization membership pivot, or contact-to-company. Models without `organization_id` are never given the direct-column scope.
