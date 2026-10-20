# Architecture

This section documents the architectural decisions and system design for the Intechral Client Portal.

| Document | Description |
|----------|-------------|
| [System Overview](./system-overview.md) | High-level component diagram and data flow |
| [Database Schema](./database-schema.md) | Entity-relationship overview and key design decisions |
| [Docker Setup](./docker-setup.md) | Local dev environment configuration |
| [Authentication Flow](./auth-flow.md) | Invitation, local auth, and OpenID SSO flows |
| [RBAC Design](./rbac-design.md) | Role and permission system architecture |
| [Role Governance](./role-governance.md) | Built-in versus custom roles, the reviewed permission synchronization and the first-operator bootstrap (Release 0.9, H4) |
| [Customer Pilot Surface Gate](./customer-pilot-surface.md) | The Release 0.9 customer-surface modes (closed, pilot, legacy), explicit operator eligibility (`portal.operator`), the customer project overview, SSO deferral and the `roles:sync` deployment steps (R0.9-CS1; implemented, awaiting closure review) |
| [Notification Delivery](./notification-delivery.md) | Send-time eligibility of the queued mails, the bounded SMTP timeout, retries, the database-queue reservation window and the at-least-once caveat (Release 0.9, WP1) |
| [Database Profile](./database-profile.md) | The InnoDB, UTC-session and utf8mb4 profile the application states for itself because a shared host's server defaults differ, the read-only `database:verify`, the production migration guard and safe creation, import and restore (Release 0.9, WP3) |
| [Runtime Preflight](./runtime-preflight.md) | `runtime:preflight`: the production runtime shape (cached configuration, database cache/session/queue, delivering SMTP, `retry_after`), the live cache and lock probes and what they write (Release 0.9, WP3-A slice A1) |
| [ADRs](./adr/) | Architecture Decision Records |

See also [EPIC-011](../epics/EPIC-011-react-frontend-migration.md) for the React/Inertia frontend migration roadmap (decision record: [ADR-007](./adr/ADR-007-inertia-react-frontend.md)) and [EPIC-012](../epics/EPIC-012-document-generation.md) for the independent document-generation architecture discovery.
