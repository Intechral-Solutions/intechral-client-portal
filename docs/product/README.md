# Product Direction

This section holds the canonical product, information-architecture, and roadmap documents for the Intechral Client Portal. They were introduced on 2026-09-24 as a deliberate re-baseline after EPIC-011E: renderer migration no longer sets product priority on its own.

| Document | Role |
|----------|------|
| [Platform Product & UX Direction](./platform-product-ux-direction.md) | The product north star: purpose, principles, domain direction, UX and design-system direction, representative design surfaces, non-goals |
| [Information Architecture](./information-architecture.md) | Target navigation, workspace boundaries, core concepts (current vs target vs open), relationships, terminology migration |
| [Product Roadmap](./product-roadmap.md) | The governing strategic sequence (NOW / NEXT / LATER / FUTURE / FINAL HARDENING), dependencies, and the immediate stabilization package |
| [Release 0.9: Scope and Architecture Gate](./release-0.9-scope-and-architecture.md) | The invitation-only early-access release on durable production data: ratified decisions, scope, data lifecycle, migration and rollback contract (historical; the rollback obligations were retired 2026-11-09), and open decisions. Supersedes the roadmap's disposable-data assumptions for the Release 0.9 production database. |
| [Release 1.0: Managed Organizations, MO-1A](./release-1.0-managed-organizations-mo-1a.md) | The operator-managed relationship between a partner organization and its customer organizations: decision, data model, invariants, authorization, migration (the rollback section is historical), preflight, and the remaining MO packages. |
| [Release 1.0: identity and release notes](./release-1.0-notes.md) | How Release 1.0 is identified (the build's `RELEASE` file and a git tag), what changed since Release 0.9, the owner data and recovery ruling, the deployment facts and the gates. |
| [Release 1.0: R1-2 Ticket organization attribution (D0 design)](./release-1.0-r1-2-ticket-attribution.md) | Design approved with owner rulings (2026-11-10); R1-0 has landed and R1-2 is in implementation (package A first): durable customer organization and submitter on a Ticket, the creation-time authorization rule, no new visibility, how it fulfils MO-4A, owner decisions, the bounded plan and the security test plan. |
| [Release 1.0: Managed Organizations, MO-2A1](./release-1.0-managed-organizations-mo-2a1.md) | Operator-only membership hardening and explicit administrator approval: role versus approval, the canonical authority predicate, the last-approved-administrator rule and its exceptional revocation, audit, migration, revocation and approval integrity checks (the rollback refusal is historical), and the mandatory MO-2B security requirement. |

## How these relate to the rest of the documentation

- **Product docs (here)** decide *what* the platform should become and in *what order*.
- **Epics** ([docs/epics/](../epics/README.md)) remain the implementation contracts. New implementation epics should cite the roadmap bucket they deliver.
- **EPIC-011** ([React Frontend Migration](../epics/EPIC-011-react-frontend-migration.md)) remains the renderer-transition record and technical foundation roadmap. Its remaining phases are now sequenced by the [Product Roadmap](./product-roadmap.md), not by their letter order.
- **Architecture** ([docs/architecture/](../architecture/README.md)) and ADRs remain authoritative for technical decisions already taken.

## Status vocabulary used in these documents

| Label | Meaning |
|-------|---------|
| **Current** | Implemented in the live repository today |
| **Target** | Direction chosen and intended to be built |
| **Future** | Plausible later capability; not committed |
| **Provisional** | A working name or shape that may change without reopening strategy |
