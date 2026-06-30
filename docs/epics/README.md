# Epics Overview

Development is organized into epics that build the platform iteratively from foundation to full product. Each epic has a dedicated document with user stories, acceptance criteria, implementation notes, and known gaps.

| Epic | Title | Status |
|------|-------|--------|
| [EPIC-001](./EPIC-001-foundation.md) | Project Foundation & Dev Environment | **Implemented** |
| [EPIC-002](./EPIC-002-authentication.md) | Authentication & User Onboarding | **Implemented** |
| [EPIC-003](./EPIC-003-rbac.md) | Roles, Permissions & Authorization | **Implemented** |
| [EPIC-004](./EPIC-004-tickets.md) | Ticket Management | **Implemented** |
| [EPIC-005](./EPIC-005-projects.md) | Project Management | **Implemented** |
| [EPIC-006](./EPIC-006-billing.md) | Billing & Invoicing | **Implemented** |
| [EPIC-007](./EPIC-007-time-tracking.md) | Time Tracking | **Implemented** |
| [EPIC-008](./EPIC-008-crm.md) | CRM | **Implemented** |
| [EPIC-009](./EPIC-009-cms.md) | CMS & Documentation | **Implemented** |
| [EPIC-010A](./EPIC-010A-mariadb-test-parity.md) | MariaDB Test Parity | **Implemented** |
| [EPIC-010B](./EPIC-010B-tenant-scoping.md) | Tenant Scoping Correctness & Regression Coverage | **Implemented** |
| [EPIC-010C](./EPIC-010C-billed-time-entry-locking.md) | Billed Time-Entry Locking | **Implemented** |

## Epic Lifecycle

```
Pending → In Progress → Implemented → Verified → Done
```

- **Implemented** — code committed; acceptance criteria not yet formally verified
- **Verified** — all acceptance criteria checked and passing tests
- **Done** — merged to `main` via PR with review; DoD fully met

## Notes

All 9 product epics reached **Implemented** status by 2026-03-27. EPIC-010A (2026-06-26) restored the full test suite by switching from SQLite to MariaDB. EPIC-010B (2026-06-30) corrected membership-based tenant scoping and added regression coverage. EPIC-010C (2026-06-30) made billed and invoice-linked time entries immutable across ordinary mutation paths. The current phase is formal hardening: closing the remaining product-level gaps documented in each epic file.

Major milestone records are tracked in [docs/progress/](../progress/).
