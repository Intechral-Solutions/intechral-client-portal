# Epics Overview

Development is organized into epics that build the platform iteratively from foundation to full product. Each epic has a dedicated document with user stories, acceptance criteria, and a definition of done.

| Epic | Title | Status | Branch |
|------|-------|--------|--------|
| [EPIC-001](./EPIC-001-foundation.md) | Project Foundation & Dev Environment | In Progress | `epic/001-foundation` |
| [EPIC-002](./EPIC-002-authentication.md) | Authentication & User Onboarding | Pending | `epic/002-authentication` |
| [EPIC-003](./EPIC-003-rbac.md) | Roles, Permissions & Authorization | Pending | `epic/003-rbac` |
| [EPIC-004](./EPIC-004-tickets.md) | Ticket Management | Pending | `epic/004-tickets` |
| [EPIC-005](./EPIC-005-projects.md) | Project Management | Pending | `epic/005-projects` |
| [EPIC-006](./EPIC-006-billing.md) | Billing & Invoicing | Pending | `epic/006-billing` |
| [EPIC-007](./EPIC-007-time-tracking.md) | Time Tracking | Pending | `epic/007-time-tracking` |
| [EPIC-008](./EPIC-008-crm.md) | CRM | Pending | `epic/008-crm` |
| [EPIC-009](./EPIC-009-cms.md) | CMS & Documentation | Pending | `epic/009-cms` |

## Epic Lifecycle

```
Pending → In Progress → Review → Done
```

An epic moves to **In Progress** when its first story branch is created. It moves to **Review** when all stories are implemented and all tests pass. It moves to **Done** when merged to `main`.
