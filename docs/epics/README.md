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
| [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) | Helpdesk Security and Integrity Hardening | **Verified** |
| [EPIC-011](./EPIC-011-react-frontend-migration.md) | React Frontend Migration | **In Progress** |
| [EPIC-011A](./EPIC-011A-react-foundation-coexistence.md) | React Foundation and Coexistence Contract (EPIC-011 Phase A) | **Implemented** |
| [EPIC-011B](./EPIC-011B-dashboard-profile.md) | Dashboard and Profile Migration (EPIC-011 Phase B) | **Implemented** |
| [EPIC-011C](./EPIC-011C-authentication-invitations.md) | Authentication and Invitation Migration (EPIC-011 Phase C) | **Verified** |
| [EPIC-011D](./EPIC-011D-time-tracking-timer.md) | Time Tracking and Persistent Timer Migration (EPIC-011 Phase D) | **Verified** |
| [EPIC-011E](./EPIC-011E-projects-kanban.md) | Projects and Kanban Migration (EPIC-011 Phase E) | **Verified** |
| [EPIC-012](./EPIC-012-document-generation.md) | Document Generation and PDF Architecture | **Planned / Discovery** |
| [EPIC-013](./EPIC-013-direction-d-shell-design-system.md) | Direction D Application Shell and Design System Foundation | **Done** |
| [EPIC-014](./EPIC-014-tasks-workspace-overhaul.md) | Tasks Workspace Overhaul | **Done** |

## Epic Lifecycle

```
Planned → In Progress → Implemented → Verified → Done
```

- **Planned** — scope and architecture are documented, but implementation has not started
- **In Progress** — implementation has begun
- **Implemented** — code committed; acceptance criteria not yet formally verified
- **Verified** — all acceptance criteria checked and passing tests
- **Done** — merged to `main` via PR with review; DoD fully met

## Notes

All 9 product epics reached **Implemented** status by 2026-03-27. EPIC-010A (2026-06-26) restored the full test suite by switching from SQLite to MariaDB. EPIC-010B (2026-06-30) corrected membership-based tenant scoping and added regression coverage. EPIC-010C (2026-06-30) made billed and invoice-linked time entries immutable across ordinary mutation paths. EPIC-010D (2026-09-24) closed the Helpdesk/Ticket authorization and integrity defects (reply authorization, internal-note attachment and search boundaries, owner-only customer visibility, assignee eligibility, safe CSV export, collision-free Ticket numbers); it is **Verified** (full Pest suite and concurrency probe green; the read-only production preflight is a first-production-release gate, since no customer production data exists yet), and `Done` follows the normal merge lifecycle. The current phase is formal hardening: closing the remaining product-level gaps documented in each epic file.

Major milestone records are tracked in [docs/progress/](../progress/).

As of 2026-09-24, strategic sequencing comes from the [Product Roadmap](../product/product-roadmap.md); epics remain the implementation contracts for the work it sequences. EPIC-013 (2026-09-25) is the implementation contract for the roadmap's paired *New application shell* and *Design system* items, built against the approved [Direction D design system](../design/direction-d-design-system.md). EPIC-014 (planned 2026-09-29) is the implementation contract for the roadmap's *Tasks overhaul* item.
