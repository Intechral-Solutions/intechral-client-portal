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
| [Release 1.0: R1-2 Ticket organization attribution (D0 design)](./release-1.0-r1-2-ticket-attribution.md) | Implemented (2026-11-10, one PR, packages A-C, awaiting merge) from the owner-approved D0 design: durable customer organization and submitter on a Ticket, the creation-time authorization rule, no new visibility, how it fulfils MO-4A, owner decisions, the bounded plan and the security test plan. |
| [Release 1.0: R1-3 Organization-bound invitations (MO-2A2, design and implementation record)](./release-1.0-r1-3-organization-invitations.md) | Design reviewed and reconciled (Opus: safe after targeted revisions). **R1-3A** (foundation, O4 + R4 lock-order remediation) and **R1-3B** (registration, legacy retirement, credential protections) committed (`d49921b`, `886a241`) after independent review. **R1-3C** (the operator Invitations card and its issue/Resend/revoke routes on `manageMembership`, notification integration verified end to end, the 16-test browser journey that reads its mail from Mailpit, the narrow `e2e:cleanup-invitees`, the `organizations:preflight` and snapshot invitation reporting, documentation reconciliation) is implemented, **uncommitted**. The independent review (safe after targeted remediation) was accepted and its remediation is implemented (the invitation URL no longer reaches same-origin `Referer` headers: meta policy plus a conditional nginx default, proved from the browser; a 30-an-hour issuance limit; cross-organization Resend and cleanup-race coverage; CI readiness), awaiting the closure review (section 20.10). Nothing is pushed, merged or deployed. Covers the `organization_invitations` model with slot-holder uniqueness, hashed token with encrypted queued delivery (H10), atomic acceptance and the invitation-derived membership seam, table-based authority predicate and permanent revocation on issuer authority loss, lifecycle, concurrency invariants, the operator contract (section 20), deferred features, decision register D1 to D14, and the reconciliation map. |
| [Release 1.0 readiness defect: permission-cache epoch first use](./release-1.0-defect-permission-cache-epoch-first-use.md) | Pre-existing (R0.9-1E), found during R1-3A verification: `PermissionCacheEpoch::current()` throws inside a transaction when the epoch row is missing and another transaction creates it. Raw-SQL reproduction, effect of R1-3A, and the suggested minimal fix (not applied). |
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
