# Product Direction

This section holds the canonical product, information-architecture, and roadmap documents for the Intechral Client Portal. They were introduced on 2026-09-24 as a deliberate re-baseline after EPIC-011E: renderer migration no longer sets product priority on its own.

| Document | Role |
|----------|------|
| [Platform Product & UX Direction](./platform-product-ux-direction.md) | The product north star: purpose, principles, domain direction, UX and design-system direction, representative design surfaces, non-goals |
| [Information Architecture](./information-architecture.md) | Target navigation, workspace boundaries, core concepts (current vs target vs open), relationships, terminology migration |
| [Product Roadmap](./product-roadmap.md) | The governing strategic sequence (NOW / NEXT / LATER / FUTURE / FINAL HARDENING), dependencies, and the immediate stabilization package |

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
