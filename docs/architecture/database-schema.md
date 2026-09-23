# Database Schema Overview

## Core Tables

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | varchar(255) | Display name |
| email | varchar(255) unique | |
| password | varchar(255) nullable | null for SSO-only users |
| email_verified_at | timestamp nullable | Set on invitation acceptance |
| two_factor_secret | text nullable | Encrypted TOTP secret |
| two_factor_recovery_codes | text nullable | Encrypted recovery codes |
| two_factor_confirmed_at | timestamp nullable | |
| invited_by | bigint FK users | Operator who invited this user |
| invitation_id | bigint FK invitations | |
| remember_token | varchar(100) nullable | |
| created_at / updated_at | timestamps | |
| deleted_at | timestamp nullable | Soft delete |

### `invitations`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(255) | Invitee email |
| token | varchar(64) unique | Signed invitation token |
| invited_by | bigint FK users | |
| role_id | bigint FK roles nullable | Pre-assigned role |
| accepted_at | timestamp nullable | |
| expires_at | timestamp | Default +48 hours |
| created_at / updated_at | timestamps | |

### `social_accounts`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK users | |
| provider | varchar(50) | google, microsoft, etc. |
| provider_id | varchar(255) | Provider's user ID |
| token | text nullable | OAuth access token (encrypted) |
| refresh_token | text nullable | OAuth refresh token (encrypted) |
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
| owner_id | bigint FK users | User who owns the organization |
| created_at / updated_at | timestamps | |

### `organization_members`
Pivot: `user_id` + `organization_id` + `role` (`admin` or `member`). The pair is unique; a user may belong to multiple organizations.

> **Note:** A separate `organization_roles` table is planned but not yet built. Org role management is currently handled via the `role` column on `organization_members`.

> **Data scoping:** `crm_companies.organization_id` is the direct tenant key. Organizations are scoped through `organization_members`, and CRM contacts are scoped through their company. Tickets, projects, invoices, and time entries do not contain `organization_id`; they retain their existing ownership, membership, client, and policy boundaries. Platform operators bypass tenant scopes. `Project` visibility specifically is `project_members` membership or `projects.admin`; `project_company` is visibility metadata only and never grants access on its own (EPIC-011E D2).

## Module Tables

### Tickets Module
- `tickets` — id, user_id, company_id nullable, ticket_number, title, description, category, priority, status, assignee_id
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

1. **Soft deletes** — `deleted_at` on all user-facing models. Nothing is permanently destroyed.
2. **Audit trail** — `activity_log` table (via Spatie Activity Log) records significant model events.
3. **Encrypted sensitive data** — OAuth tokens and 2FA secrets stored with Laravel's `encrypted` cast.
4. **Foreign key constraints** — All FK relationships enforced at the DB level (MariaDB InnoDB).
5. **Organization scoping** — scopes follow the actual relationship path: direct company tenant key, organization membership pivot, or contact-to-company. Models without `organization_id` are never given the direct-column scope.
