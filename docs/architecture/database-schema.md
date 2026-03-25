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

## RBAC Tables (Spatie)
Standard Spatie tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

## Organization Tables

### `organizations`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| company_id | bigint FK companies | 1:1 — the CRM company this org represents |
| name | varchar(255) | Copied from company for denormalization |
| slug | varchar(255) unique | URL-safe identifier |
| settings | json nullable | Org-level configuration |
| created_at / updated_at | timestamps | |
| deleted_at | timestamp nullable | Soft delete |

### `organization_users`
Pivot: `user_id` + `organization_id`. A user belongs to at most one organization.

### `organization_roles`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| organization_id | bigint FK organizations | Scoped to this org |
| name | varchar(255) | e.g., `org_admin`, `org_member`, custom |
| permissions | json | Array of org-scoped permission strings |
| is_system | boolean | true for `org_admin` / `org_member` (undeletable) |
| created_at / updated_at | timestamps | |

### `organization_role_users`
Pivot: `user_id` + `organization_role_id` + `organization_id`.

> **Data scoping:** All resource tables (`tickets`, `projects`, `invoices`, `time_entries`) include an `organization_id` nullable FK. Platform operators see all records; organization members see only their org's records via an Eloquent global scope.

## Module Tables (sketched — detailed in epic docs)

### Tickets Module
- `tickets` — core ticket record
- `ticket_replies` — threaded replies
- `ticket_attachments` — file uploads
- `ticket_categories` — configurable categories

### Projects Module
- `projects` — project record
- `project_members` — user-project assignments
- `tasks` — task cards
- `task_comments` — comments on tasks
- `task_attachments` — file uploads
- `milestones` — project milestones

### Billing Module
- `invoices` — invoice header (includes `organization_id`)
- `invoice_items` — line items
- `payments` — payment records (includes Stripe `payment_intent_id`)
- `tax_rates` — configurable tax rates

### Time Tracking Module
- `time_entries` — individual time log entries

### CRM Module
- `companies` — client companies
- `contacts` — individual contacts
- `crm_notes` — notes / activity log

### CMS Module
- `cms_pages` — content pages
- `cms_blocks` — content blocks
- `cms_revisions` — revision history
- `media` — media library

## Design Decisions

1. **Soft deletes everywhere** — `deleted_at` column on all user-facing models. Nothing is permanently destroyed.
2. **Audit trail** — An `activity_log` table (via Spatie Activity Log) records all significant model events.
3. **ULIDs for public-facing IDs** — Where records are exposed in URLs, ULIDs are used instead of auto-increment integers to prevent enumeration.
4. **Encrypted sensitive data** — OAuth tokens, 2FA secrets stored with Laravel's `encrypted` cast.
5. **Foreign key constraints** — All FK relationships enforced at DB level (MariaDB InnoDB).
