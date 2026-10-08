# Product Roadmap

**Status:** Governing strategic sequence (adopted 2026-09-24; **re-oriented around Release 1 on 2026-10-06**, see [Release 1](#release-1-re-orientation-2026-10-06))
**Companion documents:** [Platform Product & UX Direction](./platform-product-ux-direction.md) · [Information Architecture](./information-architecture.md)

This roadmap is organized around **product outcomes**, not Blade→React migration order. It supersedes the letter order of [EPIC-011](../epics/EPIC-011-react-frontend-migration.md)'s remaining phases as the source of priority. It uses sequencing buckets and phases, not dates.

Each item becomes one or more implementation epics before work begins, consistent with the existing epic process ([docs/epics/](../epics/README.md)).

> **Returning after a pause? Start at [Development pause and restart checkpoint](#development-pause-and-restart-checkpoint).**

---

## Contents

- [Roadmap principles](#roadmap-principles)
- [Release 1 (re-orientation 2026-10-06)](#release-1-re-orientation-2026-10-06)
  - [Development pause and restart checkpoint](#development-pause-and-restart-checkpoint)
  - [Owner rulings for Release 1](#owner-rulings-for-release-1)
  - [Program inventory](#program-inventory)
  - [Release 1 boundary](#release-1-boundary)
  - [Dependency graph](#dependency-graph)
  - [Release 1 sequence](#release-1-sequence)
  - [Module scope for Release 1](#module-scope-for-release-1)
  - [Customer product and customer shell](#customer-product-and-customer-shell)
  - [Platform capabilities for Release 1](#platform-capabilities-for-release-1)
  - [Release-engineering track](#release-engineering-track)
  - [Release CLI direction](#release-cli-direction)
  - [Versioning direction](#versioning-direction)
  - [Deployment model and open gates](#deployment-model-and-open-gates)
  - [High-risk migration invariant](#high-risk-migration-invariant)
  - [Release 1 hardening](#release-1-hardening)
  - [Parallelism](#parallelism)
  - [Post-v1](#post-v1)
  - [Owner rulings D1–D4](#owner-rulings-d1d4)
- [Sequence at a glance](#sequence-at-a-glance)
- [NOW — Stabilize and establish direction](#now--stabilize-and-establish-direction)
- [NEXT — Product/UX foundation](#next--productux-foundation)
- [NEXT — Core work management](#next--core-work-management)
- [LATER — Helpdesk MVP](#later--helpdesk-mvp)
- [LATER — Directory](#later--directory)
- [LATER — Finance](#later--finance)
- [LATER — Advanced Projects](#later--advanced-projects)
- [LATER — Knowledge/CMS evolution](#later--knowledgecms-evolution)
- [FUTURE — Platform capabilities](#future--platform-capabilities)
- [FUTURE — Deployment / release engineering](#future--deployment--release-engineering)
- [FINAL HARDENING](#final-hardening)
- [Dependencies](#dependencies)
- [Relationship to EPIC-011 and its remaining phases](#relationship-to-epic-011-and-its-remaining-phases)

---

## Roadmap principles

1. **Classify every item.** Each roadmap item is one of:

   | Class | Meaning | Example |
   |-------|---------|---------|
   | **Security / integrity** | Fixes a defect that exposes data or corrupts state | Ticket reply authorization |
   | **UX foundation** | Shell, design system, patterns every module inherits | New application shell |
   | **Platform capability** | Cross-cutting service built once | CI, approvals, notifications |
   | **Product functionality** | User-visible domain capability | Tasks overhaul, Helpdesk MVP |
   | **Later optimization** | Performance, polish, exhaustive QA | Final hardening |

2. **Security/integrity first.** A known defect in an area is fixed before new product work in that area.
3. **No giant rewrite.** Favor incremental, independently useful slices that leave the product shippable.
4. **Redesign and renderer migration travel together.** Remaining Blade modules move to React as part of their product redesign, on the new shell, rather than as faithful re-creations first.
5. **Design before build for new surfaces.** A surface covered by the design exploration is implemented after its design direction is agreed.
6. **Foundations earn their keep.** Platform capabilities are built when a real consumer needs them, with the second consumer validating the shape.
7. **Deferred QA is scheduled, not forgotten.** Exhaustive device/AT/cross-browser work is concentrated in final hardening.
8. **Data policy around the first production release.**
   - **Before the first customer production release,** development and testing business data is disposable unless explicitly marked otherwise. It may be cleared, reseeded, rebuilt or normalized whenever that materially simplifies development, migrations, fixtures or validation; do not spend engineering effort preserving arbitrary development history (Tickets included).
   - **After the first production release,** production data is authoritative history. Migrations must preserve it unless explicitly reviewed otherwise; production Ticket numbers must be preserved; destructive resets are not an acceptable migration strategy; and release preflights and backups become operational requirements (see [Deployment / release engineering](#future--deployment--release-engineering)).

---

## Release 1 (re-orientation 2026-10-06)

**Release 1** is the first customer production release of the portal. From 2026-10-06 it is this roadmap's governing milestone. Every known item is classified against it ([Release 1 boundary](#release-1-boundary)), and work is sequenced in phases toward it ([Release 1 sequence](#release-1-sequence)). The NOW / NEXT / LATER / FUTURE sections further down are kept as the **historical record and detailed item descriptions**. Where their bucket or ordering conflicts with this section, this section governs.

**Baseline.** Re-oriented on `main` @ `88e15d7` (PR #24 merged, `main` CI green, equal to `origin/main`, working tree clean), from the committed roadmap, epics EPIC-010D to EPIC-016, EPIC-012, Direction D, the architecture docs, `docs/testing/ci.md` and the `./dev` CLI. Documentation only: no product code changed.

### Development pause and restart checkpoint

> **Forward note (2026-10-07, Helpdesk MVP planned).** The Helpdesk MVP, the **next product epic**, now has its design gate: [EPIC-017: Helpdesk MVP and Customer Shell](../epics/EPIC-017-helpdesk-mvp-customer-shell.md), **Planned**.
> - **Owner review is complete:** the architecture, owner decisions O1–O3 and plan choices P1–P6 are approved (EPIC-017 §36–§37). No owner decision remains open.
> - **WP1 is the dedicated customer shell** (D1).
> - The `@shadcn/lint` adoption ([PR #29](https://github.com/Intechral-Solutions/intechral-client-portal/pull/29)) is owner-approved and green. At the last verification (2026-10-08) it was **not yet merged**, so its merge remains WP1's only external entry condition.
> - No implementation branch exists yet.
> - The checkpoint table below is otherwise unchanged and remains the record of the EPIC-016 close.

> **EPIC-016 IS DONE (2026-10-07).** Release 1 Phase 1's epic is complete and merged. The immediate next step is the `@shadcn/lint` evaluation, then the Helpdesk MVP. Development may pause between epics by owner choice; no product or technical blocker is implied. The owner rulings D1–D4 are locked ([Owner rulings D1–D4](#owner-rulings-d1d4)). Nothing needs owner input before work resumes.

**Checkpoint (2026-10-07, EPIC-016 Done).** The 2026-10-06 pause checkpoint (integrated at `88e15d7`, WP2–WP4 not started) is superseded by this one; it remains in the git history.

| Item | State |
|---|---|
| Integrated code checkpoint | `af36b03`: merge of PR #27 (EPIC-016 WP4). Merge-triggered `main` CI run 37651408249 is green (`./dev check` gates and Playwright 166 / 166). The EPIC-016 closeout is documentation only on top of it. |
| Closeout marker | The documentation-only commit `docs: close EPIC-016`, directly on top of `af36b03`. Its SHA is deliberately not written into the file itself; find it with `git log --oneline -1 --grep="close EPIC-016"`. |
| Release 1 roadmap | Committed (this section) |
| Last completed epic | [EPIC-016](../epics/EPIC-016-direction-d-blade-theme-control-adoption.md): **Done** (2026-10-07). No epic is active. |
| EPIC-016 packages | WP0 **complete** (design gate, `d07384d`) · WP1 **merged / verified** (PR #23, `6dfc115`) · Delete Role hotfix **merged / verified** (PR #24, `88e15d7`; A1.19; not an EPIC-016 package) · WP2 **merged / verified** (PR #25, `9555430`) · WP3 **merged / verified** (PR #26, `8db03b7`) · WP4 **merged / verified** (PR #27, `af36b03`) |
| **Next step** | **Evaluate `@shadcn/lint`** (a time-boxed adopt-or-decline decision, recorded; React/Tailwind tooling, EPIC-016 §22.1; **not installed**). Then the **Helpdesk MVP**, whose first package is the dedicated customer shell. |
| Open PRs | None |
| Branches | `feature/epic-016-blade-theme-control-adoption` is merged and may be deleted (local and remote); `origin/main` is the only authority. Other local branches are historical. |
| Earlier completed epics | EPIC-013 (shell + design system), EPIC-014 (Tasks), EPIC-015 (Projects UX): all **Done** |

**First action on return**

1. Fetch `origin`.
2. Compare `main` with `origin/main`, and read what landed since the pause marker.
3. List the open PRs.
4. Check hosted CI on current `main`.
5. Fast-forward local `main`.
6. Branch the next vehicle from current `main` (no epic branch exists after EPIC-016).
7. Read this Release 1 section, EPIC-016's status and Amendment 4, and the relevant Direction D sections (the [minimum document set](#documents-to-read-on-return)).
8. Verify that the `@shadcn/lint` evaluation has not already been decided: check for a recorded decision and open PRs.
9. Bring the dev stack up (`./dev doctor`, `./dev check`). Then run the `@shadcn/lint` evaluation, and plan the Helpdesk MVP with its customer shell as the first package.

<a id="documents-to-read-on-return"></a>
**Documents to read on return (minimum set, in order)**

1. This section and the rest of [Release 1](#release-1-re-orientation-2026-10-06).
2. [EPIC-016](../epics/EPIC-016-direction-d-blade-theme-control-adoption.md): header status, §16–§17 (the retirements and the permanent guard), §20, Amendment 4.
3. [Direction D design system](../design/direction-d-design-system.md): §9–§10 (status semantics), §19–§20.
4. [`docs/testing/ci.md`](../testing/ci.md) and the `./dev help` output.
5. [Epics overview](../epics/README.md) for the lifecycle states.

**Things NOT to assume after a long pause**

- **That CI is still green.** Re-check it. Runner images, dependency resolution and the hosted Chromium can drift while the code stands still.
- **That feature-branch remote pointers are current.** Local and remote feature branches may be stale. `origin/main` is the only authority.
- **That no dependency or security update occurred.** Laravel, Inertia, Tailwind, shadcn, Playwright and Stripe may have new releases or advisories. Triage them separately; do not upgrade as part of resuming work unless a security advisory demands it.
- **That hosting assumptions remain valid.** Nothing has been deployed. Host capabilities (PHP, Redis, queue, cron, backups) are **unproven** ([Deployment model](#deployment-model-and-open-gates)).
- **That a production host has been selected.** It has not ([D4](#owner-rulings-d1d4)). RE-0 decides.
- **That the EPIC-012 renderer decision has been made.** It has not. EPIC-012 is still Planned / Discovery.
- **That today's ordering survived later owner decisions.** Check this file's history for anything newer than this section.
- **That an epic exists for the next vehicle.** The Helpdesk MVP has no epic number until it is planned. Older NEXT / "next planned work" statements in epic forward notes or in the historical buckets below are superseded by this section.
- **That the Blade theme state still holds.** `BladeThemeGuardTest` enforces EPIC-016's final census on every `./dev check`, so drift in the target Blade workspaces fails the gate; re-measure anything outside its scope (CMS, React).

<a id="verification-commands"></a>
**Restart commands (run from the repository root)**

```bash
git fetch origin --prune
git switch main
git pull --ff-only origin main
git status --short                               # expect empty
git log -1 --oneline                             # the pause marker, or later documented commits
git log --oneline -10 origin/main                # what landed since the pause?
gh pr list --state open                          # expect none, or explain each
gh run list --branch main --limit 5              # latest main CI green?
git log --oneline main..origin/feature/epic-016-blade-theme-control-adoption   # unmerged branch work? expect none
git switch feature/epic-016-blade-theme-control-adoption
git merge --ff-only main                         # bring the implementation branch to current main
./dev doctor                                     # local stack health (read-only)
./dev check                                      # full local gate (~5+ min)
```

### Owner rulings for Release 1

Recorded 2026-10-06 as current owner direction.

1. **Knowledge/CMS is Post-v1.** Full Knowledge/CMS product evolution does not block Release 1 unless a hard dependency is discovered; none was found. The existing CMS pages (`/pages`, `operator/cms`) stay operational as they are ("maintain existing capability"). Building Knowledge is out of scope for Release 1.
2. **Release 1 product intent.** Subject to dependency review, Release 1 includes:
   - finished foundation work, including EPIC-016;
   - Helpdesk MVP;
   - Directory;
   - Finance;
   - Advanced Projects;
   - the customer-facing work those modules require;
   - EPIC-012 document generation where required;
   - shared platform capabilities only where a Release 1 feature needs them;
   - release engineering;
   - final pre-release hardening.

   This re-orientation recovered the dependencies among them and recommends the sequence below. It does not simply preserve the order in which they were listed.
3. **Release engineering is mandatory.** Release 1 does not ship without a supported tooling path for:
   - version inspection and explicit release versioning;
   - build preparation;
   - production deployment;
   - environment and config preflight;
   - guarded migration orchestration, with a backup before risky migrations;
   - rollback and recovery;
   - cache and build operations;
   - post-deploy health checks;
   - deployment diagnostics;
   - release notes;
   - production operational checks.

   This **supersedes** the trigger-based placement under [FUTURE — Deployment / release engineering](#future--deployment--release-engineering).
4. **D1–D4 are locked** (2026-10-06): customer shell, Advanced Projects scope, starting data and production host. See [Owner rulings D1–D4](#owner-rulings-d1d4).

### Program inventory

| State | Item | Vehicle / evidence |
|---|---|---|
| **Done** | Platform foundation and original modules | EPIC-001 to EPIC-009 (Implemented 2026-03-27) |
| **Done** | Integrity hardening: MariaDB test parity, tenant scoping, billed-time locking | EPIC-010A, EPIC-010B, EPIC-010C |
| **Done (Verified)** | Critical Helpdesk security and integrity hardening, H1–H9 | EPIC-010D. Its read-only production preflight is a **Release 1 gate**. |
| **Done** | React foundation, dashboard/profile, auth/invitations, time/timer, Projects/Kanban | EPIC-011A to EPIC-011E (EPIC-011 stays the open renderer-transition record) |
| **Done** | Direction D shell and design system | EPIC-013 |
| **Done** | Lightweight CI baseline | PR #2; [`docs/testing/ci.md`](../testing/ci.md) |
| **Done** | Tasks overhaul | EPIC-014 (optional WP6 enhancements deferred) |
| **Done** | Projects UX expansion | EPIC-015 |
| **Done** | Blade theme and control adoption | EPIC-016 (Done 2026-10-07): WP0–WP4 merged, permanent Blade theme guard |
| **Planned / Discovery** | Document generation and PDF architecture | EPIC-012: no renderer chosen; DOMPDF is installed but unused |
| **Roadmap item, no epic yet** | Helpdesk MVP, Directory, Finance, Advanced Projects, customer product / customer shell, release engineering, Release 1 hardening, Timer UX improvement, Knowledge/CMS evolution, platform capabilities | This section. No epic numbers are assigned until each is planned. |

### Release 1 boundary

Every known item has exactly one class: **A** Required · **B** Conditional (dependency- or consumer-driven) · **C** Post-v1 · **D** Unresolved, owner decision required. **No item is class D any more:** D1–D4 were ruled on 2026-10-06.

| Item | Class | Why |
|---|---|---|
| EPIC-016 (Done 2026-10-07) | **A** | Completed foundation. It gives every Blade surface that survives into Release 1 (System, CMS-adjacent, and any module page not yet re-rendered) accessible controls, semantic status and theme parity, plus the permanent guard. Delivered as the locked four-PR plan (O4). |
| Helpdesk MVP core: operator queue and ticket workspace on the new shell, React renderer migration, customer request/reply flow, attachments by visibility, ticket time, reporting at least at today's CSV parity | **A** | Customer-facing, the most-used support surface, already hardened. See [Helpdesk](#helpdesk-release-1). |
| Helpdesk Incident baseline | **B** | Include only if Helpdesk MVP planning shows day-one operational need. Default: Post-v1. |
| Helpdesk Knowledge baseline | **C** | Knowledge/CMS is Post-v1 (owner ruling 1). |
| Directory core: one Organization model (consolidating `crm_companies` and `organizations` with tenancy preserved), Person records with User linking and invitation, the customer relationship classification, renderer migration | **A** | Hard prerequisite of Finance's Billing Account. Doing it **before** the first production release turns the riskiest migration on the roadmap into a change on disposable data (principle 8). See [Directory](#directory-release-1). |
| Directory enrichment: full relationship hub, the partner/vendor/prospect classification set, non-User Helpdesk requesters | **C** | No Release 1 consumer. |
| Finance core: Billing Account, invoice authoring and lifecycle on the new shell, Stripe payment, customer invoice list and pay, issued invoice PDF, renderer migration | **A** | Customers must be invoiced and able to pay. Invoices currently bill Users (`invoices.client_id`), which must move to the Billing Account while data is disposable. See [Finance](#finance-release-1). |
| Finance: Rates and project budget consumption | **B** | Required only to the extent the approved Advanced Projects core consumes them (planned budgets, estimate-vs-actual). Anything beyond that is not Release 1. |
| Finance: Retainers | **C** | No Release 1 consumer recovered. Promote it if business need appears before Finance planning. |
| Advanced Projects core: estimates, planned budgets, estimate-vs-actual reporting | **A** ([D2](#owner-rulings-d1d4)) | Owner ruling. It builds on EPIC-015's Monitoring V1 and on Finance's budgets and rates. |
| Advanced Projects: timeline view, outcomes/objectives | **C** | Not in the D2 core. Promote only on product evidence. |
| Advanced Projects: change control (baseline, tolerance, change requests), SOW linkage, customer approval workflows | **C** ([D2](#owner-rulings-d1d4)) | Later by default. Promote only if repository or product evidence proves one is genuinely required for Release 1. |
| Customer shell (Direction D §7, "Focused" presentation) | **A** ([D1](#owner-rulings-d1d4)) | Owner ruling. It is the first package of Helpdesk MVP, and Finance and Projects customer surfaces adopt it as their modules evolve. See [Customer product](#customer-product-and-customer-shell). |
| Customer stakeholder view of projects | **B** | A calm customer Project page on the customer shell, where Projects' Release 1 product scope requires it. Not a separate stakeholder product. |
| Multi-organization customer switcher | **B** | Tied to Directory and customer-identity requirements, **not** to the shell's existence. Required only if Release 1 customers belong to more than one organization. |
| EPIC-012: discovery, ADR, invoice PDF | **A** | Finance issues invoice documents. The renderer choice depends on host facts (RE-0). |
| EPIC-012: SOW and other documents | **C** | Only needed by SOW linkage, which is later by default (D2). |
| Approvals platform | **B** | **Not** a Release 1 platform dependency. It is built only if a named Release 1 consumer proves it needs approvals; none does under D2. |
| Notifications: existing email notifications (ticket created/replied, invitation) | **A** (maintain) | Already built and queued. Release 1 needs a working production queue and mail path. |
| Notifications: in-app centre and preferences | **B** | Only if Helpdesk MVP or customer shell planning names it as a requirement. Default: Post-v1. |
| Search: module-local (ticket, task, directory, invoice filters) | **A** (within modules) | Part of each module's list surface. |
| Global search / command palette | **C** | No Release 1 consumer requires cross-module search. |
| Audit: invoice and payment provenance on the existing `spatie/laravel-activitylog` | **B** | Finance is the named consumer. It decides the minimum during its planning. |
| Integrations framework, external API | **C** | Stripe stays the only (existing) integration. |
| Observability minimum: persistent logs, error alerting, health endpoint, version inspection | **A** | Part of the release-engineering track. Full observability is Post-v1. |
| Automation beyond the existing `tickets:auto-close` | **C** | No consumer. The existing scheduled command must keep running in production. |
| Release engineering, RE-0 to RE-4 | **A** | Owner ruling 3. See the [track](#release-engineering-track). |
| Release 1 hardening | **A** | See [Release 1 hardening](#release-1-hardening). |
| EPIC-010D production preflight | **A** | Release gate (EPIC-010D Closeout). |
| Existing CMS pages, maintained as is | **A** (maintain) | No product work. EPIC-016 does not theme `cms/*`; this is acceptable as a documented Blade exception. |
| Knowledge/CMS evolution | **C** | Owner ruling 1. |
| System / administration renderer migration (EPIC-011 Phase J) | **C** | System stays Blade, themed by EPIC-016. |
| Removal of every remaining Blade page | **C** | Release 1 accepts themed Blade (System, CMS) as documented exceptions. |
| Timer UX improvement and deferred timer conveniences (Stop all, long-running warning, quick-start search, row timer, `T` shortcut) | **C** | The timer is functionally sound (EPIC-011D, EPIC-013). |
| EPIC-014 WP6 enhancements (bulk assign, peek inspector, Home "My work", shortcut sheet) | **C** | Owner-deferred. |
| Shell presentation unification (EPIC-013 §31.1) | **C** | Its own entry conditions are not met. |
| "System" theme option; density preference | **C** | Polish. |
| `@shadcn/lint` evaluation | **B** (tooling) | Placed in Phase 1, before Helpdesk's React work. See [Release 1 sequence](#release-1-sequence). |
| Release 1 starting data: clean install plus a small scripted seed | **A** ([D3](#owner-rulings-d1d4)) | Owner ruling. Importing a legacy production dataset is **not** part of Release 1. |
| Production host selection | **A**, via RE-0 ([D4](#owner-rulings-d1d4)) | cPanel/shared-hosting compatibility is the baseline constraint. The actual host is **not preselected**: RE-0 recommends it. |

### Dependency graph

**H** hard dependency · **S** soft sequencing preference · **P** can run in parallel · **–** not needed for Release 1.

| Item | Depends on | Notes |
|---|---|---|
| EPIC-016 | **H** WP1 (done) | **Done** (2026-10-07). |
| Helpdesk MVP | **H** EPIC-010D (done), shell and design system (done) · **S** EPIC-016 Done · its customer surfaces are built on the customer shell, its first package (D1) | **Not** dependent on Directory: EPIC-010D D1 locked owner-only customer visibility, so `tickets.company_id` is provenance only. No dependency on a notification or search platform (existing email notifications and queue search are kept). |
| Customer shell foundation | **H** shell foundation (done); routing-topology decision (Direction D §20 Q1, default: same routes with capability-aware pages) · first package of Helpdesk MVP (D1) | The multi-org switcher is driven by Directory and customer-identity requirements, not by the shell. |
| Directory | **H** shell and design system · **S** after Helpdesk MVP (the customer shell exists) · **P** Directory data-model ADR during Helpdesk | **H** prerequisite of Finance's Billing Account. Under D3 (clean install plus seed), it may reseed rather than transform. Its migrations must still be reversible in development. |
| EPIC-012 | **H** RE-0 host evidence (renderer viability) · **P** with Helpdesk and Directory | **H** prerequisite of Finance's invoice-issuance package. It does not need to precede the rest of Finance. |
| Finance | **H** Directory (Organization, then Billing Account) · **H** EPIC-012 ADR and invoice PDF (for issued invoices) · **S** customer shell (customer invoice surfaces adopt it) | Audit uses the existing activitylog. Stripe is unchanged in kind. |
| Advanced Projects core | **H** Finance budgets and rates (cost side of estimate-vs-actual) · **H** EPIC-015 (done) · **S** Directory (stakeholders as People) | Approvals **–** (D2). |
| Approvals | **H** a named Release 1 consumer, and none exists under D2 | Post-v1 by default. It is not a platform dependency of Release 1. |
| Notifications platform | consumer-driven | Release 1 needs only the existing email path working in production. |
| Global search, integrations, external API, automation | – | Post-v1. |
| RE-0 host discovery | none | **Start early**: it gates EPIC-012, queue and scheduler design, and every later RE package. |
| RE-1 / RE-2 | **H** RE-0 ADR | **P** with Directory. |
| RE-3 / RE-4 | **H** RE-1, RE-2 | **P** with Finance. A staging deploy must exist before Advanced Projects completes. |
| Release 1 hardening | **H** all Release 1 surfaces feature-complete · **H** RE-3/RE-4 usable (it rehearses with them) | |

Questions answered:
- **Finance still depends on Directory / Billing Account?** Yes, hard. Moving invoices from User clients to a Billing Account is far cheaper before production data exists.
- **Advanced Projects still depends on Finance and approvals?** On Finance (budgets, rates), yes. On approvals, no: change control and customer approval are later by default (D2).
- **EPIC-012 placement?** Discovery shares evidence with RE-0. The ADR is settled before Finance implementation depends on it. The invoice PDF exists before any Release 1 invoice is issued. EPIC-012 never blocks unrelated Helpdesk work.
- **Which Helpdesk features need notifications or search?** None needs a new platform. Existing queued email notifications and the module's own search suffice.
- **Customer shell before Helpdesk and Finance customer surfaces?** Yes (D1). It is the first package of Helpdesk MVP; later modules adopt it.
- **When does release engineering start?** RE-0 starts at the end of EPIC-016 or during Helpdesk planning, not after the product work.

### Release 1 sequence

Phases are numbered (not lettered) so they are not confused with EPIC-011's lettered phases. Only EPIC-016 and EPIC-012 have epic numbers. Every other vehicle gets its number when it is planned.

**In short:**
1. EPIC-016 — **Done** (2026-10-07).
2. Evaluate `@shadcn/lint` (**next**).
3. Helpdesk MVP, starting with the customer shell.
4. Directory.
5. Finance, with the invoice PDF capability.
6. Advanced Projects core.
7. Release-engineering convergence and rehearsals.
8. Release 1 hardening.
9. Release candidate (`1.0.0-rc.N`).
10. `v1.0.0`.

The release-engineering track (RE-0 to RE-5) runs **alongside** the product steps, not after them.

| Phase | Purpose | Depends on | Likely epic / package | Gate unlocked |
|---|---|---|---|---|
| **1. Complete the current foundation** | **EPIC-016 is Done (2026-10-07).** What remains is a time-boxed `@shadcn/lint` evaluation (an adopt-or-decline decision, recorded), to run before substantial Helpdesk React work. It is React/Tailwind tooling, complements EPIC-016's Blade guard, is not part of EPIC-016, and is not yet installed. | – | EPIC-016 (Done); small tooling follow-up | Every Blade target themed and guarded (met); React lint posture decided before Helpdesk's substantial React work |
| **2. First customer-facing module** | Helpdesk MVP, beginning with the customer shell foundation (D1). In parallel: **RE-0** host discovery and deployment ADR, the **EPIC-012** discovery spike on the same host evidence, and the **Directory data-model ADR** (docs only). | Phase 1 (soft) | Helpdesk MVP epic (renderer migration = EPIC-011 Phase F) | Customer shell exists; host facts known; Directory model decided |
| **3. Data and customer foundation** | Directory core and its schema consolidation, while data is still disposable. In parallel: **RE-1** CLI and version foundation, **RE-2** build/package/versioning, **EPIC-012 ADR** and invoice PDF implementation. | Phase 2 ADRs | Directory epic (EPIC-011 Phase H); RE foundation epic; EPIC-012 implementation | Organization and Person model final; release artifacts reproducible; PDF renderer chosen |
| **4. Finance** | Billing Account, invoices, Stripe, customer invoices, issued PDFs, audit minimum. In parallel: **RE-3** deployment and **RE-4** safety/recovery, and a **first staging deployment** from the release tooling. | Phase 3 | Finance epic (EPIC-011 Phase G); RE deployment epic | Customers can be invoiced and pay; the application deploys and rolls back on the real host stack |
| **5. Advanced work management** | Advanced Projects core (scope per D2). Every merge from here deploys to staging through the release tooling, as continuous rehearsal. | Phase 4 | Advanced Projects epic | Release 1 feature-complete |
| **6. Release-engineering convergence** | **RE-5** production readiness: clean-install and upgrade rehearsal, backup/restore drill, operator runbook, monitoring and alerting minimum. It overlaps the end of Phase 5. | RE-0–4 | RE-5 (may fold into hardening) | Deployment and recovery proven by rehearsal |
| **7. Release 1 hardening** | Release-scoped hardening with the exit gates in [Release 1 hardening](#release-1-hardening). It is exercised on `1.0.0-rc.N` candidates deployed to staging. | Phases 5, 6 | Release 1 hardening epic (successor of EPIC-011 Phase K, scoped) | An accepted release candidate |
| **8. Release 1** | Tag `v1.0.0`, run the EPIC-010D preflight, deploy, verify health, then post-release watch. | Phase 7 | Release procedure, no epic | Production; principle 8's second half takes effect |

> **Forward note (2026-10-07): `@shadcn/lint` evaluation. Outcome: ADOPT `@shadcn/lint`, approved for tooling implementation.** Evaluated on `main` @ `73909ac` against `@shadcn/lint` **0.2.0** (2026-09-22) with a dry run from an isolated scratch install; **nothing is installed yet**.
> - **Compatibility:** compatible as-is. Node 22, ESLint 10, typescript-eslint 8.70 and Tailwind 4.3 meet its requirements. **ESLint is chosen over Oxlint** (Oxlint's plugin API is alpha; no second linter), through the existing `npm run lint`.
> - **Six rules evaluated:** `no-raw-colors`, `no-unknown-classes`, `no-restyle`, `no-arbitrary-values`, `no-inline-styles`, `require-static-classes`. Dry run over the 246 files `npm run lint` covers: 0, 0 (application source), 66 (19 under a Direction D policy), 22, 6 and 4 respectively.
> - **Owner rulings (2026-10-07):**
>   - **R1, adoption mode:** selected rules run as **error** with a checked-in ESLint bulk-suppression baseline for existing findings, so new violations fail immediately. Warnings are not used (the repo lints with `--max-warnings=0`), and no rule is blanket-disabled for existing debt.
>   - **R2, typography:** callers do not own primitive typography. No broad `typography` allowance; `text-*`, `font-*`, `leading-*` and `tracking-*` stay visual on governed primitives. Special cases get a variant or a narrow documented suppression.
>   - **R3, React danger Button:** a shared non-solid danger treatment on the Button primitive (secondary surface, danger text and boundary) for destructive triggers; the solid `destructive` stays the confirmation treatment (P4). The Project edit trigger consumes it.
>   - **R4, 11px labels:** the drawer and nav-sheet 11px labels move to the 12px Direction D scale. **The spec-defined 10px rail label stays 10px** (a narrow documented suppression if flagged).
> - **Other decisions:** pin exactly `0.2.0` (no caret or tilde; the package is young, so upgrades are deliberate and re-baselined). Composite contracts for `page-frame`, `page-header`, `section` and `pagination` are deferred until evidence supports governing them as primitives.
> - The Helpdesk MVP is not blocked by this work.

> **Forward note (2026-10-07): Phase 2 vehicle planned.** The Helpdesk MVP is [EPIC-017](../epics/EPIC-017-helpdesk-mvp-customer-shell.md) (**Planned**; design gate approved by the owner, O1–O3 and P1–P6 recorded).
> - **Packages:** WP1 customer shell foundation (first, per D1); WP2 customer Helpdesk in React; WP3 operator ticket workspace; WP4 operator queue, bulk and reports (completing EPIC-011 Phase F); WP5 notification hardening and closeout.
> - **Architecture:** same routes with capability-aware pages (Direction D §20 Q1), and no organization switcher (§20 Q3).
> - **Prerequisites:** no Directory dependency, no schema change.
> - **RE-0** (with the EPIC-012 discovery spike and the Directory data-model ADR) runs **in parallel** and is **not** a Helpdesk blocker. EPIC-017 §23 lists the Helpdesk runtime assumptions RE-0 must verify.
> - **Order unchanged:** Helpdesk MVP → Directory → Finance → Advanced Projects.
> - **Precondition:** the `@shadcn/lint` adoption (PR #29) must be on `main` before WP1. It was still open at the last verification (2026-10-08).

**Why this order and not another.**
- **Helpdesk still leads.**
  - It has no hard dependency left.
  - It is customer-facing and already hardened.
  - It is the natural first consumer of the customer shell.
  - It gives RE-0 and EPIC-012 discovery time to run in parallel without blocking anything.
- **Directory-first was considered and rejected.** Helpdesk is decoupled from the Organization model by EPIC-010D's D1 ruling (owner-only ticket visibility; not the Release 1 ruling D1). Directory-first would delay the first customer-visible gain and the customer shell, and would gain nothing structurally.
- **Directory precedes Finance** because the Billing Account depends on it.
- **Advanced Projects is last** because it consumes Finance.

### Module scope for Release 1

<a id="helpdesk-release-1"></a>
**Helpdesk.** Hardening already done: EPIC-010D (H1–H9: authorization, internal-note boundaries, owner-only visibility (EPIC-010D D1), assignee eligibility, CSV safety, Ticket numbers). EPIC-016 themes the Blade views.

Release 1 scope:
- operator queue (filter, sort, assign, bulk) and ticket workspace on the new shell, in React (EPIC-011 Phase F);
- customer "my requests", reply and attachments on the customer shell (D1);
- internal notes clearly separated;
- ticket time through the existing timer contracts;
- reporting at least at today's CSV parity.

The epic absorbs EPIC-016's deferred Helpdesk items:
- `confirm()` replaced by dialogs;
- the page frame and `DataTable` density;
- "All Statuss" / "All Prioritys" copy;
- bulk-bar error placement;
- the customer label "Waiting on you";
- the hourglass glyph through the shared `Status` vocabulary.

**Company-level visibility stays owner-only (EPIC-010D D1, locked).** Organization-level ticket visibility is Post-v1 and depends on Directory. Incidents are conditional (default Post-v1). Knowledge is Post-v1; SLA, routing and automation remain Future.

**Is Helpdesk still the best next major product epic? Yes**, on the evidence above. Under owner ruling D1 it also delivers the customer shell, as its first package.

> **Forward note (2026-10-07).** The implementation contract for this scope is [EPIC-017](../epics/EPIC-017-helpdesk-mvp-customer-shell.md) (Planned). Its §10 classifies every Helpdesk capability, and its §28 disposes of the absorbed EPIC-016 items listed above.
>
> It also records a security/privacy defect found while planning, a **Release 1 requirement**: the customer ticket page lists operators' time entries, because the built-in `user` role holds `time.log`.
> - WP2 closes it on the server: requester props carry no time data, and ticket-context time is operator-only (owner ruling O1).
> - The owner-approved customer lifecycle (O2) and the email additions (O3) are recorded there too. O3 includes a new-ticket operator alert whose production destination must be verified before Release 1.

<a id="directory-release-1"></a>
**Directory.**
- **Current state.** Two overlapping concepts:
  - `crm_companies` (CRM, optional `organization_id`);
  - `organizations` (tenancy and membership).

  Customers are implicit (`user` role, `client_id`). Projects carry `client_id` and `project_company` metadata; tickets carry `company_id`.
- **Release 1 must deliver:**
  - one Organization concept, with tenancy as a property and the EPIC-010B isolation guarantees preserved and re-tested;
  - Person records with or without a User, Person ↔ User linking and invitation;
  - the "customer" relationship classification;
  - Organization and Person detail pages as relationship hubs, limited to the modules that exist in Release 1;
  - the renderer migration (EPIC-011 Phase H).
- **Later enrichment:** the broader classification set, non-User requesters, the full hub and relationship analytics.
- **Migration risk.** The consolidation is the most consequential schema change on the roadmap. Release 1 is a clean install plus a small scripted seed (D3), and development data is disposable (principle 8). The consolidation should therefore exploit the pre-production state: reseed rather than transform. Its migrations must still be reversible and rehearsed, because they become part of the Release 1 schema baseline. The scripted seed (initial organizations, people and users) must be idempotent. Once releases begin, migrations between portal versions follow the [release-safety invariant](#high-risk-migration-invariant).

<a id="finance-release-1"></a>
**Finance.**
- **Current:** invoices with line items, manual payments, Stripe payment with a signature-verified webhook (`/webhooks/stripe`), a client invoice list (`/my`), and billed time-entry locking (EPIC-010C). Invoices bill Users.
- **Release 1:**
  - Billing Account linked to Directory Organizations, with invoices moved to it;
  - redesigned authoring with totals that stay server-authoritative;
  - lifecycle and payment states;
  - Stripe (outbound HTTPS and a public webhook in production);
  - customer invoice list and pay on the customer shell;
  - **issued invoice PDFs** frozen at issue (EPIC-012 lifecycle);
  - the invoice and payment audit minimum;
  - the renderer migration (EPIC-011 Phase G).

  It also absorbs the arrow and clock glyphs that EPIC-016 deferred (P11).
- **Conditional:** rates and budget consumption, as far as the Advanced Projects core needs them (planned budgets, estimate-vs-actual).
- **Post-v1:** retainers, tax engines, accounting and general-ledger features.

**Advanced Projects.** EPIC-015 delivered the workspace UX: Overview, derived health, Monitoring V1, the Tasks list, milestones and budget metadata. "Advanced Projects" is what remains:
- estimates and planned budgets;
- estimate vs actual, burn and variance;
- multiple views (timeline);
- outcomes;
- SOW linkage;
- change control with customer approval.

**Release 1 core (owner ruling D2):**
- estimates;
- planned budgets;
- estimate-vs-actual reporting.

**Later by default:** change control, SOW linkage, customer approval workflows and a generalized approvals platform, together with the timeline view and outcomes. Any of these is promoted only if later repository or product evidence proves it genuinely required for Release 1. Approvals are not a Release 1 platform dependency merely because Advanced Projects could eventually use them.

### Customer product and customer shell

**Resolved (owner ruling D1, 2026-10-06).** The customer-product gap recorded in EPIC-015 §22, Direction D §19 step 9 and EPIC-013 §31 is no longer open:
- **Release 1 includes the dedicated customer shell.**
- **Helpdesk MVP owns its first implementation, as its first package.**
- **Later customer-facing modules (Finance, Projects) adopt it** as they evolve.

Details:

- **Today.** Customers use the capability-filtered operator shell. EPIC-015 made the project surfaces safe for them.
- **Release 1 customer surfaces:**
  - Home (what's happening for me);
  - Support (my requests, reply);
  - Invoices (view, pay);
  - Projects (a calm read of status, milestones and their own time).

  This is the Direction D §7 navigation, without Knowledge.
- **Delivery (D1).** The **first package of Helpdesk MVP** builds `CustomerShell`, `TopNav`, the customer Home, and the routing topology decided in that epic's WP0 (default: same routes with capability-aware pages). It is not a separate epic, because it needs a real consumer (principle 6). Finance and Projects adopt it for their customer pages as their modules evolve.
- **Organization switching is not built merely because the shell exists.** It stays tied to Directory and customer-identity requirements, and is required only if a Release 1 customer belongs to several organizations.
- **Still open, not blocking (settled inside Helpdesk MVP planning):** the multi-org switcher behaviour (Direction D §20 Q3); customer-facing labels ("Support" vs "Help").

### Platform capabilities for Release 1

Principle 6 holds: a capability is built from a real consumer, not speculatively.

| Capability | Release 1 | Named consumer / reason |
|---|---|---|
| Approvals | Post-v1 by default; built only if a named Release 1 consumer proves it needs approvals | None under D2 (change control and customer approval are later by default) |
| Notifications | Required: the existing **email** path working in production · Only if demanded: in-app centre and preferences | Helpdesk, invitations |
| Search | Required: module-local search · Post-v1: global search | – |
| Audit | Only if a consumer demands it | Finance invoice and payment provenance, on the existing activitylog |
| Integrations | Post-v1 (Stripe maintained) | – |
| External API | Post-v1 | – |
| Observability | Required: the minimum (logs, error alerting, health, version) · Post-v1: full | Release engineering |
| Automation | Post-v1 (existing `tickets:auto-close` maintained) | – |

### Release-engineering track

Release engineering is a **staged track that starts during Phase 2**, not a final phase. The packages are conceptual IDs, not epic numbers. Recommended vehicles:
- **RE foundation epic:** RE-0, RE-1, RE-2;
- **RE deployment epic:** RE-3, RE-4;
- **RE-5:** its own package, or folded into Release 1 hardening.

| Package | Content | Starts | Must finish before |
|---|---|---|---|
| **RE-0 Production host and environment discovery; deployment decision** | Evidence from candidate hosts, gathered once and shared with the EPIC-012 host spike where practical:<br>• PHP version and extensions; SSH, Composer, `mysqldump`/`mariadb-dump` availability;<br>• cron granularity; docroot layout (`public/` vs `public_html`) and symlink support (release directories);<br>• writable `storage/` and disk quota; mail transport; TLS; upload limits;<br>• Node absent (assets prebuilt, ADR-006/007).<br>**Redis, cache, session and queue assumptions** (`.env.example` sets `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis`; Docker runs Redis) must be verified:<br>• whether Redis is available on each candidate host;<br>• whether the application actually **requires** Redis or only defaults to it;<br>• whether cache, session and queue can safely use alternative supported drivers;<br>• queue-worker persistence and restart behaviour;<br>• scheduler behaviour;<br>• the operational implications.<br>Redis is neither removed nor required ahead of this evidence.<br>Output: **ADR-008, deployment model**. It recommends the host shape (compatible cPanel hosting, VPS or another supported shape) under [D4](#owner-rulings-d1d4). It may recommend a VPS if queue, scheduler, deployment, rollback, observability or operational needs make that materially safer or simpler. | End of Phase 1 / Helpdesk planning | EPIC-012 ADR; RE-1 |
| **RE-1 Release CLI and version foundation** | The release CLI entry point and its safety contract ([CLI direction](#release-cli-direction)); application-level commands: version, environment detection, preflight (config, PHP extensions, writable paths, DB connectivity, pending migrations, queue and scheduler expectations); the single version source. **Design gate:** the explicit **code/schema compatibility contract** ([Versioning direction](#versioning-direction)) and the **high-risk migration threshold** ([invariant](#high-risk-migration-invariant)). | Phase 3 | RE-3 |
| **RE-2 Build and package correctness** | Deterministic production build (`composer install --no-dev`, Wayfinder, `vite build`, caches); a build manifest (version, commit, build time, release metadata, checksums); a changelog and release-notes mechanism; tags. Optional CI job to produce the artifact on tag.<br>**Named Release 1 item: the Blade bootstrap disk read.** The Blade root views read `resources/js/shell/bootstrap.js` from disk on every request and inline it (EPIC-013 deployment note). Design question: should runtime rendering ever read built source or assets from disk per request, or should the build produce a stable, versioned, manifest-backed artifact? Release 1 must **either eliminate the per-request read, or prove that the chosen production implementation is intentional, safe and performant**. It is not solved in this roadmap. | Phase 3 | RE-3 |
| **RE-3 Deployment orchestration** | Upload and activate (atomic release directory + symlink if the host allows, otherwise a documented in-place sequence); maintenance mode; guarded migrations; config, route, view and event caches; queue restart; storage link; scheduler check; post-deploy health verification; deployment status. | Phase 4 | first staging deploy |
| **RE-4 Safety and recovery** | Enforcing the [release-safety invariant](#high-risk-migration-invariant): migration risk classification; verified backups where the risk model requires them (plus a `storage/` uploads backup policy); guarded execution; post-migration verification; rollback of code (previous release) and data (restore); enforcement of the code/schema compatibility contract on deploy and rollback; partial-failure recovery; deployment diagnostics; retention for releases and backups. | Phase 4 | any deploy onto authoritative data; Release 1 |
| **RE-5 Production readiness** | Rehearsals (clean install **and** upgrade from the previous release candidate); a backup/restore drill; the operator runbook; monitoring, logging and alerting minimum; the EPIC-010D preflight wired into the release checklist. | Phase 6 | Release 1 |

**Begin before the product epics finish:** RE-0 (end of Phase 1 / Helpdesk planning, running through Phase 2); RE-1 and RE-2 (Phase 3); RE-3 and RE-4 (Phase 4). A staging environment receiving every Phase 5 merge turns the final rehearsal into a confirmation rather than a first attempt.

### Release CLI direction

**Recommendation: a first-class release CLI, separate from `./dev`, sharing its conventions.**

- **Keep `./dev` as it is.** It is a host-only Bash developer CLI whose safety contract is "Docker stack, development and testing databases only" (`resolve_or_die` / `enforce_contract`). Adding production targets to it would weaken that contract.
- **Add two layers for release work:**
  1. **Application-level Artisan commands** (`app:version`, `app:preflight`, `app:health`, guarded-migrate and backup commands). These run wherever PHP runs: on the production host over SSH, from cPanel cron, and in CI. They need no shell beyond PHP, so they work on shared hosting.
  2. **A host-level orchestrator** (a `./release` entry point in Bash, reusing `scripts/dev/lib.sh` idioms). It is run from the operator's workstation or CI: build, package, verify, upload, activate, roll back. It calls the Artisan layer remotely.
- **Destructive operations** (migrate, restore, activate, rollback) must:
  - name the target explicitly (`--env=production`), never inferred from the command name;
  - check that the remote `APP_ENV` and database match the target;
  - default to dry-run or plan output;
  - require a typed confirmation of the environment name;
  - refuse a high-risk migration or deployment action without a verified backup;
  - log every action to a deployment record.

  The CLI is not implemented by this roadmap.
- **Host-level vs application-level access.** These need host access: file upload and activation, release-directory symlinks, cron and worker setup, database dumps (unless done from PHP), and maintenance-mode toggling before code exists. Version, preflight, health, migrations and cache operations need only application-level access.

**Design-gate questions (RE-0/RE-1 WP0):**
1. Does the chosen host allow SSH with Composer and `mysqldump`? If not, is the artifact fully prebuilt (vendor included) and the dump done through PHP?
2. Are symlinked release directories possible under the host's docroot?
3. Queue: is a persistent worker possible, or `queue:work --stop-when-empty` from cron, or the `database` driver? Is Redis available and actually needed (RE-0)?
4. Where do backups live (off-host?), and for how long?
5. Is there a staging environment on the same host class?
6. Should CI build the release artifact (reproducibility) or the operator's workstation?
7. What is the minimum audit record of a deployment (who, when, version, migrations run, backup id)?
8. What mechanism enforces the code/schema compatibility contract (see [Versioning direction](#versioning-direction))?
9. What is the risk model for migrations: which migrations count as high-risk, and which safety steps each class requires?
10. How is the Blade `bootstrap.js` per-request read resolved (RE-2)?

### Versioning direction

Use conventional **Semantic Versioning**:

- **PATCH:** backward-compatible fixes.
- **MINOR:** backward-compatible features and product capability.
- **MAJOR:** intentional incompatible changes to the application, an API or a data contract.

**Release-safety properties are not version semantics.** The major version is **not** bumped merely because:
- an operator action is required;
- a database migration runs;
- a backup is required;
- redeploying the previous version alone cannot reverse the database state.

Those properties are tracked **separately**, as release metadata for every release:
- migration risk;
- required operator actions;
- backup requirement;
- rollback and recovery characteristics;
- schema compatibility;
- where applicable, the minimum and maximum compatible code or schema version.

**Mechanics** (deliberately light; RE-1 settles the details):
- **Source version:** one authoritative application version source, planned as a `VERSION` file at the repository root, or the repository-native equivalent RE-1 settles. It feeds `app:version`, the build manifest and the UI.
- **Pre-release:**
  - `0.y.z` during pre-release development (tags may start at RE-2, so staging deploys are identifiable);
  - `1.0.0-rc.N` for Release 1 candidates;
  - **`1.0.0` for Release 1.**
- **Build identity:** `1.0.0+<shortsha>` (SemVer build metadata) in the build manifest, with build time and release metadata.
- **Code/schema compatibility (safety intent; mechanism deferred).** Release engineering must define and enforce an explicit code/schema compatibility contract. It may eventually rely on a schema generation or version, the migration ledger state, release metadata, compatibility ranges, or another proven mechanism. Whatever the mechanism, the outcome must be:
  - incompatible code/schema combinations are detected before or during deploy;
  - a rollback cannot silently start code against an incompatible newer schema;
  - operators get a clear diagnostic.

  The concrete mechanism belongs to the RE-1 design gate and is not designed here.
- **Deployed-version inspection:** `app:version` on the host. The health endpoint reports the version to authorized callers only. The UI shows the version and build to operators (for example in System or the account menu), not to customers.
- **Git:** annotated tags `vX.Y.Z` on `main`, with a GitHub Release per tag carrying notes from `CHANGELOG.md` (Keep a Changelog format).
- **Rollback identification:** every deployment record names the previous release's version, commit and backup id, so "roll back" always has a concrete target.

### Deployment model and open gates

**Already recorded (proven in development, documented as the production intent):**
- Laravel 13 on PHP 8.3, MariaDB 10, with cPanel/shared-hosting **compatibility** as the baseline constraint (ADR-001, `system-overview.md`; EPIC-012 mentions a Namecheap host). Compatibility does not mean "must deploy to cPanel": the actual host is not preselected (D4);
- assets prebuilt by Vite, no Node and no SSR on the server (ADR-006, ADR-007);
- `SESSION_DRIVER=database` required (`docker-setup.md`);
- the scheduler runs by cron (`tickets:auto-close` daily at 02:00);
- queued notifications (`TicketCreated`, `TicketReplied`, `Invitation`), so a production queue consumer is required;
- Stripe needs outbound HTTPS and a publicly reachable webhook;
- the Blade shell reads `resources/js/shell/bootstrap.js` from disk on every request (a named Release 1 item under RE-2);
- `.env.example` defaults cache and queue to Redis (verified under RE-0);
- file uploads (ticket attachments, `spatie/laravel-medialibrary`) live on the `local` disk and need backing up.

**Unknown, and a gate before a production deployment system is considered safe:**
1. The production host shape (compatible cPanel hosting, VPS or another supported shape), recommended by RE-0 under D4.
2. Redis: whether it is available on the candidate host, whether the application requires it, and whether alternative supported cache, session and queue drivers are safe (RE-0).
3. The queue-consumer model on the host (cron-driven vs persistent) and its overlap and timeout behaviour.
4. SSH, Composer and dump-tool availability, and the PHP `exec`/`proc_open` policy (also EPIC-012).
5. Docroot layout and symlink support (atomic activation).
6. The mail transport and its deliverability.
7. Backup storage location and retention, for the database and `storage/`.
8. Whether a staging environment exists.
9. The secrets and `.env` management procedure.
10. Logging destination and rotation, and how errors alert a human.
11. Maintenance-mode behaviour, including the Stripe webhook during maintenance.

### High-risk migration invariant

The roadmap's Directory-specific hard gate is **generalized** into a release-safety invariant for every release, not only Directory:

> **Any high-risk production migration requires appropriate:**
> 1. preflight;
> 2. a verified backup;
> 3. guarded migration execution;
> 4. a tested recovery or rollback path;
> 5. post-migration verification.

**The exact threshold for "high-risk" is settled in the release-engineering design contract (RE-1/RE-4), not here.** Indicative examples of high-risk work:
- transforming or moving existing rows;
- dropping or renaming data-bearing columns or tables;
- rewriting keys or tenancy;
- anything `migrate:rollback` cannot reverse without data loss.

The future risk model may define lighter treatment for low-risk migrations. **Not every trivial migration is promised a full backup.** Classification is recorded per migration (part of each release's [release-safety metadata](#versioning-direction)), and RE-4's tooling enforces it.

**Before Release 1**, development data is disposable (principle 8), and Release 1 is a clean install plus a scripted seed (D3). Directory and the Billing Account move may therefore reseed instead of transform. The invariant applies to the **Release 1 install itself** and to **every migration between portal versions after it**.

### Release 1 hardening

This scopes the historical [FINAL HARDENING](#final-hardening) to Release 1. It verifies a finished surface. It is not where features are completed.

| Area | Exit gate (meaningful, not exhaustive) |
|---|---|
| Accessibility | Every Release 1 surface: keyboard-only completion of the core flows (create, reply to and resolve a ticket; issue and pay an invoice; manage an organization and person; update a project); visible focus; automated axe-class checks clean or allowlisted |
| Screen reader | NVDA + Firefox or Chrome on the core flows (operator and customer). VoiceOver iOS on the customer flows. JAWS is best effort. EPIC-011E's deferred items are closed or explicitly carried forward. |
| Browser and device | Current Chrome, Firefox, Safari and Edge on desktop; iOS Safari and Android Chrome on real devices for the customer surfaces; 390px with no horizontal overflow |
| Security | Authorization review of every Release 1 route (policy and tenant isolation, including the Directory consolidation); dependency audit; secrets review; Stripe webhook verification; upload handling; CSP and headers decision |
| Performance | Query budgets on the main list and detail pages (no N+1 on queues and lists); bundle-size check against the EPIC-013 baseline; acceptable response times on the real host class |
| Deployment | Clean install **and** upgrade rehearsed on staging with the release CLI; health verification passes |
| Backup and rollback | Backup/restore drill (DB + `storage/`) completed, timed and documented; a code rollback rehearsed |
| Operations | Queue consumer and scheduler verified on the host; logs persistent and rotated; error alerting reaches a person; production config preflight passes |
| Failure recovery | A runbook for failed migration, failed activation, a stuck queue and a payment webhook outage |
| Smoke tests | A scripted post-deploy smoke run of operator and customer journeys |
| Data gates | EPIC-010D preflight recorded; the clean install and scripted seed (D3) verified on staging |

### Parallelism

**Safe in parallel** (each pair lives on different files or is documentation-only):
- Helpdesk MVP ∥ RE-0 ∥ EPIC-012 discovery ∥ the Directory data-model ADR;
- Directory ∥ RE-1/RE-2 ∥ EPIC-012 implementation;
- Finance ∥ RE-3/RE-4;
- Advanced Projects ∥ RE-5 and continuous staging deploys.

**Not in parallel:**
- EPIC-016 with Helpdesk MVP: both rewrote or would rewrite Helpdesk views. EPIC-016 is Done, so the conflict no longer applies; the permanent guard now protects the themed Helpdesk views the MVP builds on.
- Directory with Finance's Billing Account: it needs the final Organization model.
- Two module epics touching the shared `Status` vocabulary or the customer shell at once.

### Post-v1

- Knowledge/CMS evolution (Knowledge baseline, Ticket → Knowledge, CMS renderer migration, the `cms/*` theme).
- Helpdesk Incidents (if not in Release 1), SLA, routing, organization-level visibility, non-User requesters.
- Advanced Projects change control, SOW linkage, customer approval workflows, outcomes and the timeline view, together with a generalized approvals platform and SOW documents (EPIC-012). These are later by default under D2.
- Retainers and richer Finance features.
- Deferred platform capabilities: in-app notifications, global search and command palette, integrations, external API, automation, full observability.
- Timer UX improvement and the deferred timer conveniences; EPIC-014 WP6 enhancements.
- System renderer migration; removal of the remaining Blade pages.
- Design-system evolution: shell presentation unification (EPIC-013 §31.1), the "System" theme, the density preference.

<a id="open-owner-decisions"></a>
### Owner rulings D1–D4

**Locked by the owner on 2026-10-06.** They were raised as open decisions in the first draft of this re-orientation and are no longer unresolved. Revisiting one is an explicit owner act, recorded here with a date. These Release 1 rulings are not the same as EPIC-010D's decisions D1–D3; references to the EPIC-010D ones always name that epic.

| # | Ruling | Consequences |
|---|---|---|
| **D1** | **Release 1 includes the dedicated customer shell** (Direction D §7). It is delivered as the **first package of Helpdesk MVP**; Finance and Projects customer surfaces adopt it as their modules evolve. | Organization switching is **not** built merely because the shell exists. It stays tied to Directory and customer-identity requirements. |
| **D2** | **The Advanced Projects core for Release 1 is estimates, planned budgets and estimate-vs-actual reporting.** | Change control, SOW linkage, customer approval workflows and a generalized approvals platform are later by default. They are promoted only if later repository or product evidence proves one is genuinely required for Release 1. Approvals are not a Release 1 platform dependency. |
| **D3** | **Release 1 assumes a clean install plus a small scripted seed.** | Exploit the pre-production state, particularly for the Directory consolidation (reseed rather than transform). Release 1 is not designed around importing a legacy production dataset unless this ruling is explicitly revisited. Proper migrations between portal versions are still required once releases begin. |
| **D4** | **cPanel/shared-hosting compatibility remains the baseline constraint, but the Release 1 production host is not preselected.** | RE-0 determines whether compatible cPanel hosting, a VPS or another supported deployment shape best serves the real requirements. It may recommend a VPS if queue, scheduler, deployment, rollback, observability or operational needs make that materially safer or simpler. "cPanel-compatible" does not mean "must deploy to cPanel". |

No other owner decision currently blocks the Release 1 plan.

---

## Sequence at a glance

> **Historical snapshot (2026-09-24 to 2026-10-05).** The table below is the pre-Release 1 bucket view, kept for history. The governing sequence is now [Release 1 sequence](#release-1-sequence).

| Bucket | Items | Class |
|--------|-------|-------|
| **NOW** | Critical Helpdesk hardening · Product/UX rebase (this) · Claude Design brief and exploration | Security/integrity · Direction · UX foundation |
| **NEXT** | New application shell · Design system · Lightweight CI baseline (Done) · Blade workspace theme and control adoption (EPIC-016, Done 2026-10-07) | UX foundation · Platform capability · Hardening |
| **NEXT** | Tasks overhaul · Timer UX improvement · Projects UX expansion | Product functionality |
| **LATER** | Helpdesk MVP · Directory · Finance · Advanced Projects · Knowledge/CMS evolution | Product functionality (+ first platform-capability consumers) |
| **FUTURE** | Reusable approvals, notifications, global search, integrations, external API, observability, audit/history, automation · Deployment/release engineering (trigger-based) | Platform capability |
| **FINAL HARDENING** | Accessibility, screen-reader, real-device, cross-browser, performance, security, consistency, deployment and operational readiness | Later optimization |

LATER items are listed in their recommended order but are separable; see [Dependencies](#dependencies).

---

## NOW — Stabilize and establish direction

### Critical Helpdesk hardening

**Class:** Security / integrity. **Scope:** small, backend-only, on the existing Blade Ticket module. **Not** Helpdesk product or UX work.

**Provenance.** The earlier Ticket/Helpdesk discovery audit is not stored in this repository. The register below was **re-verified by reading the live code on 2026-09-24** and is the authoritative starting list. The implementing epic should reconcile it against the original audit register if the owner still has it, and add any further high-impact findings from that audit.

**Vehicle:** [EPIC-010D: Helpdesk Security and Integrity Hardening](../epics/EPIC-010D-helpdesk-security-hardening.md) (**Verified** 2026-09-24), following the EPIC-010A–C pattern with characterization tests first and Pest regression coverage for each finding. EPIC-010D re-verifies H1–H6 below, adds H7–H9 (CSV formula injection, Ticket-number collision, role-name policy check), and locks the visibility and assignment decisions; it is the authoritative register from here on.

*The register below is the original pre-fix record; all findings in it are now fixed (EPIC-010D).*

| ID | Severity (provisional) | Finding | Evidence (live code) |
|----|------------------------|---------|----------------------|
| **H1** | Critical | **Any authenticated user can post a public reply to any ticket.** The user-facing reply route carries only `auth` middleware and `TicketReplyController::store` never authorizes the ticket. Tickets bind by sequential integer ID, so every ticket is reachable. The reply also triggers an email notification to the ticket owner and assignee, so a user of one tenant can inject content into, and trigger mail from, another tenant's ticket thread | `routes/web.php` (`tickets.replies.store`, `Route::middleware('auth')`); `app/Http/Controllers/Operator/TicketReplyController.php` (no `authorize`); `app/Services/TicketService.php::addReply` (notifications) |
| **H2** | High | **Internal-note attachments are downloadable by non-operators.** `downloadAttachment` authorizes `view` on the parent ticket only and never checks whether the attachment belongs to an internal reply. The ticket owner can download internal attachments by (sequential) attachment ID even though the show page hides them | `app/Http/Controllers/TicketController.php::downloadAttachment`; `TicketAttachment::reply()` / `TicketReply.is_internal` |
| **H3** | High | **Internal-note search oracle.** `Ticket::scopeSearch` matches `replies.body` without excluding internal replies, and the user-facing ticket index uses it. A customer can probe for terms in internal notes by observing which of their tickets match | `app/Models/Ticket.php::scopeSearch`; `TicketController::index` |
| **H4** | Medium | **List/policy mismatch.** The user ticket index includes same-company tickets for `tickets.view_org`, but `TicketPolicy::view` allows only the owner or an operator, so listed tickets 403 on open. Already flagged for EPIC-011F as EPIC-011E finding C8. Resolution requires a decision on organization-level ticket visibility (grant it in the policy, or remove it from the list) | `TicketController::index`; `app/Policies/TicketPolicy.php`; [EPIC-011E C8](../epics/EPIC-011E-projects-kanban.md) |
| **H5** | Medium | **Assignment integrity.** Single and bulk assignment validate `assignee_id` only as `exists:users,id`, so a ticket can be assigned to any user, including a customer account, which then receives assignee notifications | `Operator\TicketController::assign`; `Operator\TicketBulkController::update` |
| **H6** | Low | **Bulk status without a status errors.** `action=status` with no `status` passes `null` into `safeTransition(string $newStatus)`, a `TypeError` (HTTP 500) rather than a validation error | `Operator\TicketBulkController::update` / `safeTransition` |

**Related debt (resolved for Tickets):** `TicketPolicy` used to test the hard-coded `operator` role name while operator routes gate on `tickets.assign`. EPIC-010D fixed this (H9): Ticket authorization is now capability-based ([Information Architecture → User](./information-architecture.md#user)). Role-name checks outside Ticket code (the tenancy scopes) remain and were not in the package.

**Exit criteria (direction):** H1–H3 fixed with regression tests proving the negative cases; H4 resolved by an explicit visibility decision (made: own Tickets only, EPIC-010D D1); H5–H6 fixed or explicitly deferred with rationale; no UI redesign; full Pest suite green.

**Status (2026-09-24):** EPIC-010D is **Verified** and critical Helpdesk hardening is **complete**: H1–H9 are fixed with regression tests and the full Pest suite passes (`Done` follows the normal merge lifecycle). Because there is no customer production data yet, the read-only Ticket preflight in the epic's [Closeout](../epics/EPIC-010D-helpdesk-security-hardening.md#first-production-release--deployment-gate) is a **release-safety gate for the first production release** (or any populated production deployment), not an open verification item. The remaining NOW and NEXT work (Claude Design exploration, new application shell, design system) may proceed. The [Helpdesk MVP](#later--helpdesk-mvp) and any Helpdesk product redesign remain later work.

**Why first:** Helpdesk is a customer-facing, multi-tenant surface with a cross-tenant write path (H1) and two confidentiality leaks (H2, H3). These are independent of every design decision below and should not wait for them.

### Product/UX rebase

**Class:** Direction. The three documents in [docs/product/](./README.md) plus the strategic note on EPIC-011. Complete when approved and committed.

### Claude Design brief and exploration

**Class:** UX foundation. After these documents are approved: write a dedicated Claude Design brief grounded in [Design context for the Claude Design brief](./platform-product-ux-direction.md#design-context-for-the-claude-design-brief), and explore the five [representative design surfaces](./platform-product-ux-direction.md#representative-design-surfaces) in Light and Dark. Output: an agreed visual direction and enough design-system decisions to start the shell.

**Depends on:** rebase approval. Can run in parallel with Critical Helpdesk hardening.

---

## NEXT — Product/UX foundation

### New application shell

**Class:** UX foundation.

**Vehicle:** [EPIC-013: Direction D Application Shell and Design System Foundation](../epics/EPIC-013-direction-d-shell-design-system.md) (**Done** — Verified 2026-09-28, merged to `main` via [PR #1](https://github.com/Intechral-Solutions/intechral-client-portal/pull/1); planned 2026-09-25) delivers this item together with [Design system](#design-system) below, against the approved [Direction D design contract](../design/direction-d-design-system.md).

- Full-viewport shell; workspace-based navigation (Home, Projects, Tasks, Helpdesk, Time, Directory, Finance, System) driven by the server `NavigationBuilder`
- Workspace layout primitives: wide canvas, reading width, split/side regions
- Account menu per [Information Architecture → Account menu](./information-architecture.md#account-menu)
- Capability-aware structure; Home as a role-aware landing surface (initial version may be modest)
- Global timer presence redesigned to fit the shell (full timer UX work follows under [Timer UX improvement](#timer-ux-improvement))

**Coexistence (superseded in part).** This item originally proposed that remaining Blade pages keep the legacy Blade layout, adopt shared semantic tokens only where cheap, and receive **no** new Blade shell work. The later [Direction D design contract](../design/direction-d-design-system.md) (approved 2026-09-25, §19 step 5) instead **requires Blade shell parity** — the same rail, utility bar, token system and navigation data — so the product does not look like two unrelated applications during migration. Direction D governs: Blade receives new *shell chrome* work in [EPIC-013](../epics/EPIC-013-direction-d-shell-design-system.md). The provisional note still holds for Blade **page bodies**, which are not redesigned until their module's product epic. Navigation between renderers stays a full document visit, as today.

### Design system

**Class:** UX foundation.

**Vehicle:** [EPIC-013](../epics/EPIC-013-direction-d-shell-design-system.md), jointly with [New application shell](#new-application-shell) above — the token layer and the shell are built as one foundation because neither is verifiable without the other.

Semantic tokens; typography; spacing; surfaces and elevation; tables (compact and comfortable); dialogs and drawers; forms; alerts and toasts; status language; motion; Light/Dark; responsive rules; empty/loading/error states. Built on the existing shadcn/ui + Tailwind 4 foundation ([ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)), restyled, not replaced wholesale. Existing React pages (dashboard, profile, time, projects, tasks) are migrated onto the new system as their redesign slices land.

### Blade workspace theme and control adoption

**Class:** Hardening / design-system adoption.

**Vehicle:** [EPIC-016: Direction D Theme and Control Adoption for Blade Workspaces](../epics/EPIC-016-direction-d-blade-theme-control-adoption.md) (**Planned** 2026-10-05; **In Progress** 2026-10-06, WP1 merged; **Verified** and **Done** 2026-10-07, WP4 merged).

The Blade page bodies of Helpdesk, Directory, Finance and System style themselves inline: the legacy indigo `--accent` on actions, legacy gray variables for text, borders and surfaces, and hex status pills. They use no Direction D semantic utilities, 44 of their field sites have no visible keyboard focus, and their statuses ignore the theme.

EPIC-016 makes these workspaces theme-first, as a presentation-system migration. It establishes:
- **shared Blade semantic controls** that mirror the Direction D React primitives (ink primary actions, `control-edge` fields, the `focus` outline);
- the **accessibility fixes** (focus, labels, errors, names);
- **semantic statuses** (glyph + label);
- **theme-driven colour normalization**: every application colour in those workspaces becomes a semantic Tailwind utility or a documented exception;
- **retirement** of the legacy accent aliases;
- a permanent **architecture guard**.

It is delivered in four PRs: controls and accessibility; status; theme normalization; retirement and guard.

It does **not** migrate renderers, change routes or redesign the modules: that stays with [Helpdesk MVP](#later--helpdesk-mvp), [Directory](#later--directory) and [Finance](#later--finance) (roadmap principle 4).

**Depends on:** shell and design system (EPIC-013, Done); uses Projects/Tasks (EPIC-014, EPIC-015) as the visual reference.

> **Forward note (2026-10-06, historical).** The System Delete Role form hotfix carried out of the WP1 review (EPIC-016 A1.19) merged as PR #24 (`88e15d7`), with `main` CI green. **WP2 is the next unstarted package; WP3 and WP4 follow.** EPIC-016 is Phase 1 of the [Release 1 sequence](#release-1-sequence). Development may pause here by owner choice; no blocker is implied.
>
> **Forward note (2026-10-07).** EPIC-016 is **Done**: WP2 (PR #25), WP3 (PR #26) and WP4 (PR #27, merge `af36b03`) merged with `main` CI green. The statement above that WP2 is next is historical. The Blade workspaces are theme-first, retired aliases are gone and pinned absent, and a permanent two-level guard (`BladeThemeGuardTest`) is active. The next step is the `@shadcn/lint` evaluation, then the Helpdesk MVP and its customer shell.

### Lightweight CI baseline

**Class:** Platform capability.

**Status:** **Done** (2026-09-29) — merged to `main` via [PR #2](https://github.com/Intechral-Solutions/intechral-client-portal/pull/2) (merge commit `e8743f2`), following [EPIC-013 → CI handoff](../epics/EPIC-013-direction-d-shell-design-system.md#30-ci-handoff). Both configured triggers (`pull_request` targeting `main`, `push` to `main`) have passed on GitHub Actions. The baseline is live: every PR and every push to `main` now runs it. Full detail — gates, environment, triggers, caching, security, known limitations — is authoritative at [`docs/testing/ci.md`](../testing/ci.md); this entry only records that the roadmap item is complete.

**Timing:** introduced **alongside or immediately after the first merged slice of the shell/design system**, and **required before the Tasks overhaul begins**. Early enough to protect the redesign; late enough that the build it verifies has settled.

**Scope, deliberately simple:**

- clean checkout and clean environment
- full Pest suite against MariaDB
- frontend: Wayfinder generation, typecheck, lint, format check, unit tests, production build
- critical Playwright suite
- blocking pass/fail gate on the branch/PR

**Not in scope:** build matrices, deployment, release promotion, production orchestration. `./dev check` defines the checks; CI proves they pass from a clean checkout.

---

## NEXT — Core work management

These are the first product slices on the new shell. They exercise the design system on the domains already in React.

### Tasks overhaul

**Class:** Product functionality. My Tasks; All Tasks (capability-gated); project tasks included; explicit Complete/Reopen (board tasks move to the project's designated Done column through the canonical project-task service); search, sort, filter; assignment; contextual time. See [Task direction](./platform-product-ux-direction.md#task-direction).

**Depends on:** shell, design system, CI baseline (all three Done — dependency satisfied). **Needs design:** designated Done column and Reopen policy — resolved by owner decision Q2 in EPIC-014 (Q1 locks who may Complete/Reopen).

**Vehicle:** [EPIC-014: Tasks Workspace Overhaul](../epics/EPIC-014-tasks-workspace-overhaul.md) (**Planned** 2026-09-29; **In Progress** since WP1 merged 2026-09-30; **Verified** 2026-10-02 at WP7 (PR #14), every required exit criterion met including PR CI; **Done** 2026-10-02 on merge, commit `18b6f2e`, merge CI green). The optional enhancements (row timer control, bulk assign, peek inspector, Home "My work", shortcut sheet; EPIC-014 WP6) were **deferred** by owner decision and remain future work. It locks the Complete/Reopen authorization, the Done-column and Reopen rules, `tasks.view_all` for All Tasks, the standalone-task lifecycle (superseding EPIC-011E D3) and the retirement of the "My organization" view in favour of an organization filter; ticket-task product UX stays Future.

### Timer UX improvement

**Class:** Product functionality. Friendlier running-timer controls; clear task/project/ticket context; easier switching; editing and recovery; active-state clarity; contextual controls in Tasks and Projects. Server timer contracts (EPIC-011D) remain authoritative.

**Depends on:** shell (global presence); best delivered with or right after the Tasks overhaul.

### Projects UX expansion

**Class:** Product functionality. Redesign the project workspace around Planning / Execution / Monitoring using data that exists or is cheap to add: milestones, tasks, members, time, budget. Add list view alongside the board; a first project-health/monitoring summary; stakeholder-friendly presentation. Outcomes, SOW linkage, baselines, and change control are [Advanced Projects](#later--advanced-projects).

**Depends on:** shell, design system; benefits from Tasks overhaul components.

**Vehicle:** [EPIC-015: Projects UX Expansion](../epics/EPIC-015-projects-ux-expansion.md) (**Planned** 2026-10-02). It locks derived project health, explicit milestone completion, budget metadata visible only with effective Settings/Edit access, the Overview as the canonical project landing, a project-scoped Tasks list on the canonical task query, one project-time definition, and the stale board-assignee time fix. Dedicated stakeholder/customer presentation is deferred (EPIC-015 §22 records the missing customer-product roadmap item).

> **Forward note (2026-10-05).** EPIC-015 is **Done** (2026-10-05; Planned 2026-10-02, In Progress 2026-10-04, Verified 2026-10-05): WP1–WP6 are merged (the optional WP5 Board Complete/Reopen, per-surface drawer defaults and Project Time tab included), all 16 §19 criteria are satisfied, and WP6 merged as PR #22 (merge commit `76be9cd`) with green PR CI and green `main` CI. The customer-product gap (EPIC-015 §22) and a post-EPIC global design-token / UI consistency audit (Helpdesk, Directory, Finance, System; EPIC-015 A6.16) are still unplaced and not started.
>
> **Forward note (2026-10-05, later).** The consistency audit has run (on `d87b5b1`) and is placed: its remediation is [Blade workspace theme and control adoption](#blade-workspace-theme-and-control-adoption), vehicle [EPIC-016](../epics/EPIC-016-direction-d-blade-theme-control-adoption.md) (Planned at the time; **Done** 2026-10-07). The customer-product gap remains unplaced.
>
> **Forward note (2026-10-06).** This forward note's "remains unplaced" is **superseded**: the customer-product gap is resolved by owner ruling D1. Release 1 includes the dedicated customer shell, Helpdesk MVP owns its first implementation, and later customer-facing modules adopt it. See [Customer product and customer shell](#customer-product-and-customer-shell).

---

> **Release 1 re-orientation (2026-10-06).** The LATER, FUTURE and FINAL HARDENING sections below remain the detailed item descriptions. Their **classification and order** are now governed by [Release 1 boundary](#release-1-boundary) and [Release 1 sequence](#release-1-sequence). In particular:
> - the Helpdesk Knowledge baseline and Knowledge/CMS evolution are **Post-v1**;
> - release engineering is a **Release 1 Required** staged track;
> - final hardening is scoped as [Release 1 hardening](#release-1-hardening).

## LATER — Helpdesk MVP

**Class:** Product functionality. Renderer migration of Tickets (EPIC-011 Phase F) happens here, as part of the redesign.

- Secure Tickets on the new shell (requires [Critical Helpdesk hardening](#critical-helpdesk-hardening) complete)
- Operator queue: filtering, sorting, assignment, bulk actions
- Replies and internal notes with clear visual separation
- Attachments with visibility matching their reply
- Time on tickets using the redesigned timer
- Reporting (successor to the current CSV reports)
- **Incident baseline:** create an Incident, link Tickets, central status
- **Knowledge baseline:** simple articles (title, body, status, visibility, links), possibly delivered together with the first [Knowledge/CMS evolution](#later--knowledgecms-evolution) slice

No full ITIL. SLA, routing, and automation remain Future.

## LATER — Directory

**Class:** Product functionality. Renderer migration of CRM/Organizations (EPIC-011 Phase H) happens here.

- People (Person records with or without User accounts)
- Organizations (consolidating the CRM company and tenancy organization concepts; approach Open)
- Relationships and classifications (customer, partner, prospect, vendor…)
- Account linking (Person ↔ User; inviting a Person)
- Person/Organization detail as a relationship hub (projects, Helpdesk, Finance)

**Risk:** this likely includes the platform's most consequential data migration (contacts, companies, organizations, tenancy keys). It must preserve EPIC-010B tenant-isolation guarantees and is a trigger for formal release engineering (below).

## LATER — Finance

**Class:** Product functionality. Renderer migration of Billing/Stripe (EPIC-011 Phase G) happens here.

- Centralized Finance workspace
- Billing, Invoices (redesigned authoring; server-authoritative totals unchanged)
- Retainers, Rates
- Project budgets and budget consumption
- Project/Person/Organization commercial views
- Billing / Commercial Account model (depends on Directory)

PDF/document generation remains with [EPIC-012](../epics/EPIC-012-document-generation.md) and may land in or before this bucket.

## LATER — Advanced Projects

**Class:** Product functionality (first real consumer of approvals).

- Outcomes/objectives
- SOW linkage (proposed → approved → delivered → effort → change)
- Estimates and planned budgets
- Multiple views (list, board, timeline)
- Monitoring: estimated vs actual, burn/variance, health
- Change control: baseline, tolerance, change requests, cost/schedule impact, customer approval, revised baseline

**Depends on:** Projects UX expansion; Finance (budgets, rates); a minimal approval contract (see Future platform capabilities); EPIC-012 for SOW documents.

## LATER — Knowledge/CMS evolution

> **Post-v1 (owner ruling, 2026-10-06).** No Release 1 dependency was found. The existing CMS pages stay operational without product work.

**Class:** Product functionality + platform capability. Renderer migration of CMS (EPIC-011 Phase I) happens here.

- Shared content infrastructure (storage format, rendering, sanitization)
- Knowledge publishing with visibility (internal / customer / public, provisional)
- Ticket → Knowledge workflow (draft an article from a resolution; link later tickets)
- Revisions, search, and approval later
- Tool/library evaluation as an explicit decision record once authoring requirements are concrete

---

## FUTURE — Platform capabilities

**Class:** Platform capability. Each is built when a real consumer needs it; the second consumer validates the shape.

| Capability | Likely first consumer |
|------------|----------------------|
| Reusable approvals/reviews | Advanced Projects change requests or SOW approval |
| Notifications (in-app + email, preferences) | Helpdesk MVP; account-menu Notifications |
| Global search | Directory + Helpdesk + Tasks, once all are on the new shell |
| Audit/history | Finance and change control (provenance of commercial decisions) |
| Integrations framework | Ticket creation from external systems |
| External API | After internal domain contracts mature; first real external consumer |
| Observability | With release engineering |
| Advanced automation | Helpdesk routing/escalation, project thresholds |

System → Users/Roles renderer migration (EPIC-011 Phase J) is folded into the shell/System workspace work whenever administration is redesigned; it has no dependency beyond the shell.

## FUTURE — Deployment / release engineering

> **Superseded in placement (2026-10-06).** Release engineering is no longer trigger-based FUTURE work. It is a **Release 1 Required** staged track (RE-0 to RE-5) starting during the Helpdesk MVP; see [Release-engineering track](#release-engineering-track). The hard gate below is generalized as the [high-risk migration invariant](#high-risk-migration-invariant). The "Then address" list remains a valid checklist for that track.

**Class:** Platform capability. Deliberately later than CI and must not distract from the product/UX rebase.

**Trigger.** Formalize deployment/release engineering **before the first production release that ships a redesigned-shell module**, and begin planning it once all of these readiness conditions hold:

- the new shell and design system are stable (at least one redesigned workspace is complete on them)
- the production build architecture is settled (Vite/Wayfinder build output and hosting target confirmed)
- queue/background-job requirements are clear (notifications, document generation)
- the document generation strategy is settled ([EPIC-012](../epics/EPIC-012-document-generation.md) decision gate)

**Hard gate regardless of the above:** no production release containing a transformational data migration (for example the Directory/Organization consolidation, or moving invoices from User clients to Billing Accounts) ships without guarded migrations, an automatic pre-migration backup, and a tested rollback path.

**Then address:** staging environment; staging→production promotion if useful; reproducible production builds; guarded migrations and automatic backups; rollback; maintenance mode; smoke/health checks; queue and worker handling; environment and secrets management; release retention; backup retention; hosting compatibility (cPanel-compatible target today).

---

## FINAL HARDENING

**Class:** Later optimization. Runs once against the finished platform surface, successor to EPIC-011 Phase K.

- Exhaustive accessibility review
- Screen-reader matrix (NVDA, JAWS, VoiceOver), including items deferred from EPIC-011E
- Real-device mobile (iOS Safari, Android Chrome)
- Cross-browser validation
- Performance and bundle audit
- Security review
- Cross-module consistency review
- Removal of remaining Blade pages and migration-only code; documented intentional Blade exceptions
- Deployment readiness
- Operational readiness (backups, monitoring, runbooks)

---

## Dependencies

| Item | Depends on | Enables |
|------|------------|---------|
| Critical Helpdesk hardening | — | Helpdesk MVP |
| Claude Design brief/exploration | Rebase approval | Shell, design system |
| New application shell | Design direction | Everything on the new UI |
| Design system | Design direction | Everything on the new UI |
| Lightweight CI baseline (**Done**) | First shell/design-system slice | Tasks overhaul and all later product work |
| Tasks overhaul | Shell, design system, CI | Timer UX, Projects UX, Helpdesk (ticket tasks) |
| Timer UX improvement | Shell; best with Tasks | Contextual time everywhere |
| Projects UX expansion | Shell, design system | Advanced Projects |
| Helpdesk MVP | Critical hardening, shell, design system | Knowledge (Ticket → Knowledge), Incidents |
| Directory | Shell, design system | Finance (Billing Account), Person-based Helpdesk requesters, customer stakeholders |
| Finance | Directory (for Billing Account); EPIC-012 for PDFs | Advanced Projects (budgets, rates) |
| Advanced Projects | Projects UX, Finance, minimal approvals | Change control, SOW linkage |
| Knowledge/CMS evolution | Helpdesk MVP (for Ticket → Knowledge) | Customer/public knowledge |
| Release engineering | Trigger conditions above | Safe production delivery of LATER work |
| Final hardening | All intended surfaces redesigned | Platform "done" |

> **Updated 2026-10-06.** The table above is the historical dependency view. The Release 1 view, which distinguishes hard and soft dependencies and adds release engineering, EPIC-012 and the customer shell, is [Dependency graph](#dependency-graph).

**Recommended LATER order (historical; confirmed for Release 1 except Knowledge/CMS, now Post-v1):** Helpdesk MVP → Directory → Finance → Advanced Projects, with Knowledge/CMS evolution joining Helpdesk or following it. Helpdesk leads because it is customer-facing, still Blade, and already hardened by then; Directory precedes Finance because the Billing Account model depends on it. The order may change if business need shifts; the dependencies above may not.

## Relationship to EPIC-011 and its remaining phases

[EPIC-011](../epics/EPIC-011-react-frontend-migration.md) remains the renderer-transition record and technical foundation roadmap. Phases A–E are complete (E Verified 2026-09-23). The remaining phases are **absorbed into product work** rather than executed in letter order:

| EPIC-011 phase | Now delivered by |
|----------------|------------------|
| F — Tickets | [Critical Helpdesk hardening](#critical-helpdesk-hardening) (security, no UI) then [Helpdesk MVP](#later--helpdesk-mvp) |
| G — Billing and Stripe | [Finance](#later--finance) |
| H — CRM and Organizations | [Directory](#later--directory) |
| I — CMS | [Knowledge/CMS evolution](#later--knowledgecms-evolution) |
| J — Administration | Shell/System workspace work ([Future platform capabilities](#future--platform-capabilities) note) |
| K — Decommission and hardening | [Final hardening](#final-hardening) |

EPIC-011's migration principles (Laravel authority, Inertia-first data flow, no dead scripts, per-phase inventory) continue to apply to every slice that moves a Blade page to React. EPIC-011F has not begun and no EPIC-011F document exists; Ticket sequencing is now governed by this roadmap.
