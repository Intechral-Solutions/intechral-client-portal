# Release 0.9: Scope and Architecture Design Gate (R0.9-0)

**Status:** **Design gate closed (2026-10-10)** by owner ratification of decisions H1 to H4. The gate itself implemented nothing (R0.9-1A, R0.9-1B and R0.9-1C, the packages implemented since, are recorded in [§11](#11-work-packages-and-parallel-hosting-discovery)). This document adds no code, migration, test, release flag or deployment infrastructure.
**Package progress (2026-10-12):** R0.9-1A, R0.9-1B, R0.9-1C and R0.9-1D are implemented and each passed independent review (Opus 5.5). **R0.9-1C** enforces H3 account suspension and reactivation and the last-active-administrator protection. **R0.9-1D** implements H4 role governance and the controlled, previewable role synchronization, the command-line-only initial operator bootstrap, and the CRM linked-company deletion refusal; its independent review (Opus 5.5) concluded **SAFE TO COMMIT** (no Critical, High or Medium findings), and its bounded Low and Info corrections were applied before the local commit. **R0.9-1E** (permission-cache epoch fencing, which closes the cache race that review recorded) is implemented and passed its independent security review (Opus 5.5, no Critical, High or Medium findings); hosted CI and hosted Playwright for it are outstanding. The first hosted CI run ([run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30) executed R0.9-1B to R0.9-1D and the EPIC-017 work: all gates passed except one dev-tooling lint, fixed in a follow-up commit whose hosted verification is recorded on the PR ([CI record](../testing/ci.md#first-hosted-run-of-the-release-09-foundations-2026-10-12)). That is a checkpoint, not release approval. **R0.9-CS1 (Customer Pilot Surface Gate)** implements the owner-approved early-access contract as a fail-closed, server-enforced customer boundary with three modes (`closed` by default, `pilot`, and `legacy` for local and testing only) and an explicit operator-eligibility capability, `portal.operator`, that **requires a reviewed `roles:sync` before any operator can use a closed or pilot installation**. It is **implemented and remediated after two independent reviews (Opus 5.5: NEEDS REMEDIATION twice); the final focused closure review returned SAFE TO COMMIT; it is integrated through a pull request, with hosted CI recorded there, and is not merged or deployed** ([contract, inventory and deployment steps](../architecture/customer-pilot-surface.md)). It was first labelled R0.9-2A; that designation stays with the separate hosting-discovery item below. **Release 0.9 as a whole is not ready:** the release-readiness obligations in [§5.3](#53-h3-account-suspension) and [§6](#6-migration-and-rollback-contract) and the open decisions in [§10](#10-open-decisions) remain.
**Reconciled (2026-10-11)** with the EPIC-017 WP2 closure ([Amendment 2](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#amendment-2-wp2-customer-helpdesk-in-react-pr-2)); see [§2.1](#21-epic-017-sequencing-dispositions-owner-directed-2026-10-11).
**Class:** Release scope and data architecture. It is a planning record, not an epic.
**Inputs:** the Release 0.9 readiness assessment, the R0.9-0 scope and architecture gate, and the independent data architecture review of 2026-10-10 (Opus 5.5).
**Governs:** the Release 0.9 production data and its migrations. It supersedes the pre-release disposable-data assumptions in the [Product Roadmap](./product-roadmap.md) for that database (see [§12](#12-supersession-record)).
**Companions:** [Product Roadmap](./product-roadmap.md) · [EPIC-017](../epics/EPIC-017-helpdesk-mvp-customer-shell.md) · [Database Schema](../architecture/database-schema.md)

## Contents

1. [Status vocabulary](#1-status-vocabulary)
2. [Ratified decision ledger](#2-ratified-decision-ledger)
3. [Release 0.9 scope](#3-release-09-scope)
4. [Production data durability principles](#4-production-data-durability-principles)
5. [Data lifecycle rules (H1 to H4)](#5-data-lifecycle-rules-h1-to-h4)
6. [Migration and rollback contract](#6-migration-and-rollback-contract)
7. [Backup consistency, APP_KEY and ticket-number continuity](#7-backup-consistency-app_key-and-ticket-number-continuity)
8. [Migration rehearsal contract](#8-migration-rehearsal-contract)
9. [Corrected Directory and Finance migration seams](#9-corrected-directory-and-finance-migration-seams)
10. [Open decisions](#10-open-decisions)
11. [Work packages and parallel hosting discovery](#11-work-packages-and-parallel-hosting-discovery)
12. [Supersession record](#12-supersession-record)
13. [Evidence basis](#13-evidence-basis)

## 1. Status vocabulary

| Label | Meaning |
|---|---|
| **Ratified** | Approved by the owner on the date shown. Binding. Changing it is an explicit, dated owner act. |
| **Owner-directed** | A design correction or process direction recorded at the owner's instruction, on the date shown. It corrects an earlier assumption or sets sequencing. It is **not** a formal policy ratification and is not in the ratified table. Its mechanism may still be open. |
| **Recommended** | Proposed by the readiness assessment or the independent review. **Not** ratified. It may change before ratification. |
| **Open** | A decision no one has made. |

Nothing below is ratified unless it is in [§2](#2-ratified-decision-ledger) as **Ratified**. Implementation details of a ratified decision remain subject to review and rehearsal.

## 2. Ratified decision ledger

| # | Decision (2026-10-10) | Status | Binding content |
|---|---|---|---|
| **H1** | Business record preservation | **Ratified** | The 13 user-referencing business foreign keys listed in [§5.1](#51-h1-business-record-preservation) change from `CASCADE` to `RESTRICT`, **subject to rehearsal**. Normal user offboarding never hard-deletes a user or cascade-deletes business records. **Suspension is the default.** A controlled legal-erasure process is a separate, later design decision (H7, open). |
| **H2** | CRM company relationship preservation | **Ratified** | `project_company.crm_company_id` changes from `CASCADE` to `RESTRICT`. Meaningful company-to-project associations survive for the future Directory migration. |
| **H3** | Account suspension | **Ratified** | Nullable `users.disabled_at`; reversible suspension; sessions and remember tokens revoked; login and continued authenticated access denied; disabled users excluded from the relevant assignment and membership candidate lists; ordinary product notifications to disabled accounts suppressed; historical business records preserved; **the last active operator cannot be suspended or demoted, including under concurrent modification.** Implementation details remain subject to review. |
| **H4** | Code-owned built-in roles | **Ratified** | Built-in roles are owned by the application. Ordinary UI editing of built-in role definitions is prohibited. Custom roles are the customization mechanism. Production permission synchronization is deterministic and idempotent, offers a dry-run/preview, and revokes existing grants only with explicit authorization, never incidentally. `RoleSeeder` is not an uncontrolled production synchronization mechanism. |

**Design corrections (owner-directed)** incorporated into the gate when it closed. They correct earlier assumptions and are **not additional formal policy ratifications**: H1 to H4 are the only decisions in this document ratified by the owner. Each correction's mechanism is specified in the section cited and, like any design, remains subject to review and rehearsal.

| # | Correction | Where |
|---|---|---|
| C1 | Production data is authoritative from the first genuine production record. No `migrate:fresh`, destructive reseeding or assumed future reset. Local and test environments stay disposable and are kept distinct. | [§4](#4-production-data-durability-principles) |
| C2 | `users.email` is never a permanent join key. Durable relationships use immutable identifiers. Normalized email may support matching; ambiguity requires review. | [§9](#9-corrected-directory-and-finance-migration-seams) |
| C3 | Prefer additive and expand-contract migrations with N-1 application compatibility. Ordinary rollback is prior application code against a compatible current schema. Database restoration is exceptional recovery. Redeploying previous code does not by itself imply losing newly written data. | [§6](#6-migration-and-rollback-contract) |
| C4 | A database dump and the private attachment files must be a **demonstrably** restorable set. Consistency is defined as requirements, not assumed from copy order. `APP_KEY` recovery is part of the set. | [§7](#7-backup-consistency-app_key-and-ticket-number-continuity) |
| C5 | Rehearsal fixtures work against the schema that exists **before** a candidate migration. `disabled_at` is never seeded before the migration that introduces it. | [§8](#8-migration-rehearsal-contract) |
| C6 | Previously communicated `TKT-` numbers must never be reissued after disaster recovery. The durable mechanism is **not yet designed**, and mail logs alone are not a sufficient guarantee. | [§7.3](#73-ticket-number-continuity) |

### 2.1 EPIC-017 sequencing dispositions (owner-directed, 2026-10-11)

Stated by the owner when the WP2 closure (Amendment 2) was accepted. They are sequencing and process directions: not data-architecture decisions, not formal policy ratifications, and they do not alter H1 to H4.

| # | Disposition | Where |
|---|---|---|
| E1 | EPIC-017 WP2 is complete locally; the first hosted run and the different-UID cleanup check passed ([CI record](../testing/ci.md#first-hosted-run-of-the-release-09-foundations-2026-10-12)). Merge and release approval remain owner decisions. | EPIC-017 [A2.13](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a213-outstanding-hosted-ci-obligations) |
| E2 | WP3 and WP4 are deferred from the Release 0.9 critical path, not cancelled. | [§3.2](#32-excluded-from-release-09-owner-approved) |
| E3 | WP5 retains the **essential early-access notification, security and accessibility work.** The exact slice is not yet specified or ratified. | [§10.2](#102-other-recommendations-not-yet-ratified) |
| E4 | The keyboard-only and 390/768 customer Helpdesk checks belong to the Release 0.9 **customer-readiness** scope, associated with WP5 and EPIC-017 §33.1. | EPIC-017 [A2.10](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a210-g3-b-review-follow-ups) |
| E5 | When pull-request packaging becomes necessary, prefer **one integrated WP1+WP2 PR** to artificial stacked PRs, documenting the process exception to EPIC-017 §32. No push or PR is required for local closure. | EPIC-017 [A2.12 D-1](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a212-findings-discrepancies-and-accepted-limitations) |
| E6 | Development proceeds as **one sequential track**: no parallel workstreams or branches. | [§11](#11-work-packages-and-parallel-hosting-discovery) |

**Owner-approved for early access (2026-10-13), implemented in R0.9-CS1 (final closure review: SAFE TO COMMIT):** customers get the Helpdesk, the projects they are authorized to see, a customer-appropriate project overview and customer-appropriate milestone names and dates; customer finance and invoices, payments, time tracking, task boards and details, project and task comments and checklists, internal project activity and CMS administration are excluded; SSO is deferred. This is enforced on the server by the customer surface boundary ([contract and the open points it leaves](../architecture/customer-pilot-surface.md#8-remaining-owner-decisions-and-limitations)). For this pass project descriptions stay operator-only, milestone completion is withheld, the published-content viewer stays outside the pilot, and account essentials stay available; none of that ratifies H5 to H10 or any other policy. The separately recommended release-flag mechanism for *operator* finance and the other items below are not decided by it.

**Not ratified.** Decisions H5 to H10 ([§10.1](#101-independent-review-decisions-h5-to-h10)) and every item in [§10.2](#102-other-recommendations-not-yet-ratified): among them the overview-only project visibility contract, the operator-finance gating and release-flag mechanism, and all hosting and recovery values.

## 3. Release 0.9 scope

Release 0.9 is an invitation-only customer support and read-only project-visibility pilot on real, durable production data. It precedes Release 1 and does not change the [Release 1 boundary](./product-roadmap.md#release-1-boundary).

### 3.1 Approved scope (owner-approved)

- Invitation-only customer access, authentication and the customer Home.
- Customer Helpdesk with attachments, and customer/operator ticket communication.
- Secure customer project visibility.
- Essential operator support.
- Permanent production data.
- Invoicing and payments handled **externally** during the pilot.

### 3.2 Excluded from Release 0.9 (owner-approved)

- Customer invoicing, Stripe and online payments.
- The full Directory and Finance rewrites.
- Nonessential EPIC-017 WP3 and WP4 UI migrations.
- Advanced project management features.

Deferred features keep viable migration paths ([§9](#9-corrected-directory-and-finance-migration-seams)). Deferral is not cancellation: EPIC-017 WP3 and WP4 are **deferred from the Release 0.9 critical path** and stay planned. Release 0.9 does not require complete Directory, Finance, WP3 or WP4 implementation.

### 3.3 What the approved scope does not yet specify (Recommended, not ratified)

The following came from the readiness assessment and the R0.9-0 gate. Each needs ratification before it is built.

- **Project visibility contract.** The existing project code has no internal/public marker on projects, tasks, comments, checklists or milestones, and a customer who is a project member can read all of them (including operator comments and assignee names) and write comments and checklist toggles that every member sees. The recommended contract is *overview-only*: project list, Overview, and milestone names, due dates and status, with the Board, Tasks, task detail, comments, checklists and project Time tab server-gated, and milestone descriptions and completer names withheld by an allowlist presenter. The alternatives are read-only task titles (which exposes unflagged operator-authored titles and assignee names) and curated per-task visibility (which needs a schema change and recomputed aggregates, and is a later release). Project name and description remain operator-authored but customer-readable, which is an open question. **The contract is not ratified.**
- **Finance exclusion mechanism.** The recommendation is a fail-closed, config-backed release feature gate (default off) returning 404 for the customer invoice, pay and webhook routes, leaving permissions, policies, roles and data untouched, so that re-enabling is a flag change. Whether operator-side finance screens stay available is open.
- **Other surfaces.** Gating customer Tasks and Time, Resources (CMS) and SSO for the pilot, and the pilot constraint that ticket visibility is owner-only.

Hiding a navigation link does not prevent direct URL access. Any exclusion in [§3.2](#32-excluded-from-release-09-owner-approved) or [§3.3](#33-what-the-approved-scope-does-not-yet-specify-recommended-not-ratified) must be enforced on the server.

## 4. Production data durability principles

1. **Authoritative from the first record.** From the first genuine production record, production data is authoritative history. Nothing in the repository may assume it can be cleared, reseeded or rebuilt.
2. **Forbidden against production:** `migrate:fresh`, `db:wipe`, destructive reseeding, re-running a seeder that rewrites authoritative rows, restoring an older backup over newer data without an explicit exceptional-recovery decision, and any migration whose only rollback is losing data.
3. **Disposable environments stay disposable.** Local, test, E2E and rehearsal databases may be reset. They are separate from production and guarded by name and environment, as the testing database already is (`TestDatabaseSafety`).
4. **No hard deletes of business records in ordinary operation.** Users, tickets, replies and attachments, and time entries are never hard-deleted by an ordinary workflow. See [§5](#5-data-lifecycle-rules-h1-to-h4).
5. **Permanent identifiers.** Primary keys are never reused or renumbered. Ticket numbers are `TKT-` plus the ticket id zero-padded to at least four digits (for example `TKT-0081`) and are permanent once communicated ([§7.3](#73-ticket-number-continuity)).
6. **Reversibility by design, not by `down()`.** Safety comes from additive changes, tightening that the previous code does not violate, and a verified backup, not from reverse migrations ([§6](#6-migration-and-rollback-contract)).
7. **Retained is not enabled.** Tables for excluded features (invoices, items, payments) stay in the schema and keep their data. Excluded features are switched off on the server, never by deleting or resetting records, and the finance tests and authorization contracts are not weakened.

## 5. Data lifecycle rules (H1 to H4)

### 5.1 H1: business record preservation

Change from `ON DELETE CASCADE` to `ON DELETE RESTRICT` (subject to rehearsal):

| Table.column | References |
|---|---|
| `tickets.user_id` | `users` |
| `ticket_replies.user_id` | `users` |
| `ticket_attachments.user_id` | `users` |
| `time_entries.user_id` | `users` |
| `task_comments.user_id` | `users` |
| `tasks.created_by` | `users` |
| `projects.created_by` | `users` |
| `organizations.owner_id` | `users` |
| `crm_companies.created_by` | `users` |
| `crm_contacts.created_by` | `users` |
| `cms_pages.created_by` | `users` |
| `invoices.client_id` | `users` |
| `invoices.created_by` | `users` |

Unchanged by H1: credential and membership children keep `CASCADE` (`password_histories`, `social_accounts`, `project_members`, `organization_members`), derived rows keep `CASCADE` (`time_entry_blocks.user_id`), and nullable pointers keep `SET NULL` (assignees, `invited_by`, `invitation_id`, status-history actor, milestone `completed_by`, CMS `updated_by`).

Known consequence: several existing tests and one browser test delete users directly and rely on the cascade to clean up their records. They must be updated as part of the schema package, in coordination with the EPIC-017 work that owns some of them.

### 5.2 H2: company relationship preservation

`project_company.crm_company_id` changes to `RESTRICT`. A company that projects still reference can no longer be deleted by the CRM company delete path. That path currently authorizes at the route only and deletes with no object-level guard; it needs one (see H6 for the related project policy).

### 5.3 H3: account suspension

`users.disabled_at` is a nullable timestamp. Who suspended an account, when and why is recorded in the existing activity log, not in extra columns. The enforcement points identified for review are: credential login (including the two-factor path), the SSO login paths, remembered sessions and existing sessions (there is no session-validity middleware today), password reset, invitation acceptance, notification recipients (queued notifications must re-check at send time), and the assignee and project-member candidate lists. The last-operator protection applies to suspension **and** role removal and must hold under concurrent changes. Mechanisms are implementation details for review.

**Release-readiness requirements from the R0.9-1C implementation** (recorded with its independent review; details in [Account Lifecycle](../architecture/account-lifecycle.md#release-09-deployment-and-rollback-requirements)). They do not block the code commit. They must be met before customer accounts are onboarded:

1. `SESSION_DRIVER=database` is a deployment prerequisite. Suspension and reactivation refuse to run with any other session driver rather than leave sessions alive.
2. Queue workers are restarted on deployment (`php artisan queue:restart`), so the send-time suspension check is loaded.
3. No ordinary rollback to a build before R0.9-1C while any account is suspended ([§6 item 9](#6-migration-and-rollback-contract)). A safe recovery or forward-fix procedure for that case must be established before customer onboarding.

### 5.4 H4: code-owned built-in roles

`RoleSeeder` currently resets both built-in roles on every run, `operator` to *all permissions currently in the database* (including stale rows), and `user` to the catalogue defaults. The role controller also allows editing the built-in roles in the UI. Under H4 the permission catalogue and role definitions in code are the source of truth. The synchronization is a purpose-built, deterministic and idempotent operation with a preview, and it revokes only under an explicit authorization. A permission missing from the database denies silently (the permission library fails closed), so a deployment that skips the synchronization degrades quietly. The first-operator bootstrap must establish the operator role without invoking `RoleSeeder`.

**R0.9-1D implementation (implemented; independent review SAFE TO COMMIT; details in [Role Governance](../architecture/role-governance.md)).** `App\Shared\Permissions\BuiltInRoles` is the authoritative catalogue; ordinary administration refuses to change or delete `operator` and `user` (HTTP 409 for JSON, back with an error for a browser) while custom roles keep full management; `php artisan roles:sync` is a dry run by default, applies only a plan named by its fingerprint, rebuilds that plan under the last-administrator lock (a stale approval is refused) and needs `--allow-revocations` for any revocation; `RoleSeeder` resets the built-in roles only in local and testing environments and refuses elsewhere; and `php artisan operator:bootstrap` creates the first administrator of a new installation, at the server's command line only, never elevating an existing account and never running once any administrator exists. Disaster recovery for a locked-out installation is **not** implemented and is an open owner decision.

## 6. Migration and rollback contract

1. **Forward only.** Production schema changes are forward migrations. `down()` methods exist for development and are not a production recovery mechanism. Several are lossy or fail on populated tables.
2. **Additive and expand-contract.** Add, backfill, switch reads and writes, and only in a later release remove. A destructive or narrowing change ships at least one release after the code stopped depending on the old shape.
3. **N-1 application compatibility.** When planning a change to an existing production schema, the previous release's code must keep working against the new schema, so that code rollback is available. Concretely: no rename or drop in the release that stops using a column; a new column becomes `NOT NULL` only after a backfill in an earlier release; a tightening (a `RESTRICT`, a unique key) is allowed only when the previous code cannot violate it and a preflight proves existing rows comply.
4. **Ordinary rollback.** Redeploy the previous application code against the current, compatible schema. Data written since the deploy is retained. This is valid **only** if every migration since that release satisfied [item 3](#6-migration-and-rollback-contract). Release notes state, per release, whether ordinary rollback is available. When it is not, the options are a forward fix or the exceptional path below, and the release notes must say so.
5. **Exceptional recovery.** Restoring the database and files from a verified backup is for a failed or unsafe migration, corruption or loss. It discards data written after the backup, so risky migrations run under maintenance mode immediately after a fresh verified backup. It also requires the ticket-number safeguard of [§7.3](#73-ticket-number-continuity).
6. **Classification (Recommended).** Each migration is labelled additive, tightening, transforming or destructive, with the class recorded in the release notes. Transforming and destructive migrations require a verified backup, a populated rehearsal and post-migration verification queries.
7. **Hygiene.** Never edit a migration after it has shipped, keep timestamps unique, and test each tightening against `information_schema`, as the time-entry foreign-key migration test already does.
8. **The previous release does not exist yet.** The N-1 check can only be run once a 0.9.x release exists.
9. **Schema compatibility is not security compatibility.** Ordinary rollback must also not reopen access that the current release closes. In particular, `users.disabled_at` (R0.9-1B) is ignored by every build before R0.9-1C: redeploying such a build lets every suspended account sign in again, although the schema is compatible. Ordinary rollback below R0.9-1C is therefore not available while any `users.disabled_at` is set. In that case the options are a forward fix or a recovery procedure that keeps those accounts locked out. That procedure is **not yet established** and must be before customer onboarding ([Account Lifecycle](../architecture/account-lifecycle.md#release-09-deployment-and-rollback-requirements)). Release notes state this per release, as for item 4.

## 7. Backup consistency, APP_KEY and ticket-number continuity

### 7.1 A demonstrably restorable set

Backing up the database and `storage/app/private/tickets/` as separate copies is not, by itself, a restorable set. The requirements are:

1. **One identified set.** The dump and the file copy belong to one backup id with recorded start and end times.
2. **A consistent database image.** The dump is taken as a single consistent snapshot.
3. **Files cover the snapshot.** For every attachment row in the dump, the corresponding file exists in the copy. How that is achieved (ordering, quiescing writes, a filesystem snapshot, or reconciliation) is a design choice, but the choice must be justified against concurrent uploads.
4. **A stated precondition, tested.** A database-first, files-second copy is sufficient only while committed attachment files are never deleted or overwritten after they are written. That is true of the current ordinary workflow, but it is an assumption that must be guarded by a test and kept true, not relied on silently.
5. **Verified by restoration.** A set counts as restorable only when a restore into a clean environment passes the checks in [§8](#8-migration-rehearsal-contract): every attachment row has its file, no ticket directory lacks a row, and the invariant snapshot matches.

### 7.2 APP_KEY

The application key encrypts authentication data that lives in the database and backups: two-factor secrets and recovery codes (Fortify's encrypter), encrypted session payloads when configured, and any encrypted queued payload. A restore without the original key leaves those unreadable and locks out two-factor users. The key and `.env` are therefore part of recovery and are held **separately** from the backup set. Who escrows and rotates the key is open (H9).

### 7.3 Ticket-number continuity

Ticket numbers are `TKT-` plus the auto-increment identifier zero-padded to at least four digits (for example `TKT-0081`). After a restore from an older backup the sequence can fall below numbers already sent to customers by email and quoted in conversation, and those numbers would then be reissued to different tickets. Recovery must therefore guarantee the next number exceeds the highest number ever communicated. **The durable high-water-mark mechanism is not yet designed.** Mail logs and operator memory are not a sufficient guarantee. The design must be settled and rehearsed before the first production record.

## 8. Migration rehearsal contract

1. **Where.** A dedicated rehearsal database with its own name and environment guard. It must not be the development `portal` database or the shared testing database, because MariaDB DDL commits implicitly.
2. **Fixtures match the pre-migration schema.** Fixtures are written against the schema that exists **before** the candidate migration. A fixture must not use a column the candidate introduces (in particular `disabled_at` is not seeded before its migration). When a migration lands, later fixtures may use its columns only for subsequent candidates.
3. **Coverage.** The fixture set must cover the stated production-data models: users (operators, customers, an SSO-only user with no password, a custom role), invitations in each state, tickets in every status with replies, internal notes, status history and real attachment files, projects with customer members, every task kind, comments, checklists, dependencies and milestones, time entries (running, stopped and billed-locked), retained invoices with items and payments, organizations and CRM metadata, and activity-log rows.
4. **Procedure.** Migrate to the baseline, seed, take an invariant snapshot, apply the candidate migration, take a second snapshot, and compare. Only the intended differences may appear. Then perform a backup and restore round trip and confirm the snapshot is identical.
5. **Invariant snapshot.** Per-table row counts; orphan checks including columns without a foreign key (`projects.client_id`, `sessions.user_id`, role and permission pivots, the activity log); attachment file existence in both directions; `ticket_number` equals `TKT-` plus the zero-padded id for every numeric ticket (the seeded `TKT-E2E1` and `TKT-E2E2` fixtures are non-numeric and exempt); time-entry and allocation consistency; the EPIC-010D preflight queries; the project integrity audit; and role and permission grants.
6. **Production data in rehearsal** is not decided (H8). Until it is, rehearsal uses fixtures only.

## 9. Corrected Directory and Finance migration seams

Neither rewrite is designed or ratified here. These are the constraints Release 0.9 must keep so that both remain possible as forward migrations on authoritative data. The roadmap's "reseed rather than transform" plan no longer applies to the production database.

**Current facts these seams rely on (verified in the repository):**
- `tickets.company_id` is nullable provenance and is never written by ticket creation.
- `projects.client_id` has no foreign key and is not used.
- `project_company` is visibility metadata, not access control.
- Business tables reference users by `users.id`; SSO accounts link by provider id, not by email.

**Constraints for Release 0.9:**
1. **Immutable identifiers.** Durable relationships use ids. `users.email` is a credential and a matching hint, never a permanent join key. A future Person link is made once, by an auditable match, and stored as an identifier. Normalized (trimmed, lower-cased) email may propose candidates; **ambiguity goes to review**; no query joins on email.
2. **No new foreign keys into** `crm_companies`, `organizations` or `organization_members`.
3. **Keep** `tickets.company_id` nullable and unwritten, and `projects.client_id` unused and without a foreign key.
4. **Directory consolidation** is expand-contract: add the new structures and nullable references, backfill with a mapping that records old to new ids, switch reads and writes, then contract in a later release. `project_company` rows are the only live company link holding real data, which is why H2 protects them.
5. **Billing Account** is expand-contract: add a nullable billing-account reference to invoices and backfill it from the existing, immutable `client_id` (a user id). Invoice tables are retained and, if no invoices are written in 0.9, may hold no rows to move.
6. **Do not delete** a company or organization that tickets, projects or members reference.

## 10. Open decisions

### 10.1 Independent review decisions H5 to H10

**Not ratified.** The review's recommendation is shown for context only and carries no authority.

| # | Decision | Review recommendation (not binding) |
|---|---|---|
| **H5** | `time_entries.ticket_id` from `SET NULL` to `RESTRICT`. It would extend the existing rule that recorded time is never nulled, but EPIC-017 G3-A kept `nullOnDelete` unchanged. | Needs reconciliation with that ruling before any change. |
| **H6** | Project hard-delete policy. Today a project with no recorded time can be deleted and its tasks and comments cascade. | Allow only when the project is empty; archive otherwise; require an object-level guard on company delete. |
| **H7** | Legal erasure and anonymization. | A separate design; anonymize in place and never cascade. Needs the owner's legal view. |
| **H8** | Use of production data in rehearsal environments. | Fixtures only until decided; any production-derived data needs an anonymization rule. |
| **H9** | Ownership of `APP_KEY` escrow and rotation. | Escrow separately from backups; name an owner. |
| **H10** | Invitation-token delivery. Hashing the stored token requires the queued invitation mail to carry the plaintext token safely. | An encrypted queued notification carrying the token, or synchronous sending. |

### 10.2 Other recommendations not yet ratified

- The overview-only project visibility contract, and whether project name and description are customer-readable by rule.
- The release feature gate (default off), operator-finance availability, and gating of customer Tasks and Time, Resources and SSO.
- Hosting and recovery values: host class, queue, cache and session drivers, cron and worker model, mail provider and sender domain, backup location and retention, recovery time and data-loss targets, upload limits, error-tracking tool.
- Email-change protection, and whether operators must use two-factor authentication.
- The exact Release 0.9 slice of EPIC-017 WP5. That WP5 retains the essential early-access notification, security and accessibility work is owner-directed ([E3](#21-epic-017-sequencing-dispositions-owner-directed-2026-10-11)); *which items* form it is not. The review recommends notification additions, mail escaping, per-recipient links and a route authorization review; that is unratified.
- The pilot constraint that ticket visibility is owner-only, so colleagues of one customer do not see each other's requests.
- Disaster recovery for an installation whose administrators cannot sign in (lost credentials or two-factor device). The R0.9-1D bootstrap is initial setup only and refuses once any administrator exists; no break-glass path exists or was invented ([Role Governance](../architecture/role-governance.md#initial-setup-is-not-disaster-recovery)). Not decided.
- Whether customer-side project managers may see that a fellow project member's account is suspended. The R0.9-1C member lists and assignee menus flag a suspended member so they are not offered as a new choice (review item Info-5, [Account Lifecycle](../architecture/account-lifecycle.md#independent-review-findings-r09-1c)). Recorded for release readiness; not owner-approved.
- Whether other work may touch the customer shell while EPIC-017 is in progress. (EPIC-017 F5 is retained as documented and F6 is resolved, both confirmed 2026-10-11; see EPIC-017 [A2.12](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a212-findings-discrepancies-and-accepted-limitations). They are no longer open.)

## 11. Work packages and parallel hosting discovery

**Status: Recommended sequence, not ratified.** Boundaries are proposed here and are finalized in the planning of each package. **R0.9-1A is implemented** (the migration rehearsal harness, [documented here](../testing/migration-rehearsal.md)) and its independent review (Opus 5.5) concluded **SAFE TO COMMIT**, with follow-ups recorded in the [rehearsal documentation](../testing/migration-rehearsal.md#review-follow-ups). **Hosted CI has not yet verified it.**

**R0.9-1B is implemented** ([documented here](../testing/migration-rehearsal.md#release-09-preservation-migrations-r09-1b)): M1 adds the nullable `users.disabled_at` (schema only, no enforcement); M2 changes the 13 H1 user business-record foreign keys of [§5.1](#51-h1-business-record-preservation) from `ON DELETE CASCADE` to `RESTRICT`; M3 changes `project_company.crm_company_id` from `CASCADE` to `RESTRICT` (H2). Constraint names, `ON UPDATE` rules and nullability are unchanged. The populated migration rehearsal passed, including the backup and restore round trip, and the rehearsal-harness follow-ups J-1 to J-4 were resolved. Its independent review (Opus 5.5) concluded **SAFE TO COMMIT**. **Hosted CI has not yet verified it**, and the regular development database had not been migrated at the time of that review. Not part of R0.9-1B despite the table below: H5 was not ratified and is not included, and the ticket-number high-water mark ([§7.3](#73-ticket-number-continuity)) is **still undesigned**.

**R0.9-1C is implemented** ([documented here](../architecture/account-lifecycle.md)), on top of R0.9-1B and without a new migration:

- Suspension and reactivation are enforced at sign-in, on every authenticated request and for existing sessions; a suspended user's existing authenticated access is denied, and suspension signs the user out everywhere.
- Last-active-administrator protection is active on every application path that can remove an account administrator (suspension, user role changes, role-permission changes, role deletion), serialized under a lock.
- A failed Eloquent user deletion no longer partially removes the user's role and permission grants (the R0.9-1B L1 follow-up).
- Account lifecycle audit entries and eligibility enforcement (assignee and recipient candidates, validation, notifications) are implemented.
- The independent review (Opus 5.5) returned NEEDS REMEDIATION for one Medium finding (M-1, permission-cache invalidation order); M-1, L-1 (reset-token revocation) and L-2 (session-driver fail-closed) were remediated and a focused closure review (Opus 5.5) concluded **SAFE TO COMMIT**, including the Redis-backed commit-order tests (7 passed, 0 skipped).
- Hosted CI now runs the Redis variant of the commit-order test (a no-skip step); it passed in the first hosted run. The residual cache race below is not addressed by that.
- R0.9-1D followed it; see below.

**Obligations that remain open after R0.9-1C** (details: [§5.3](#53-h3-account-suspension), [§6 item 9](#6-migration-and-rollback-contract) and [Account Lifecycle](../architecture/account-lifecycle.md#release-09-deployment-and-rollback-requirements)): `SESSION_DRIVER=database`; queue workers restarted on deployment; no ordinary rollback below R0.9-1C while any account is suspended, with a safe recovery or forward-fix procedure required before customer onboarding; Info-5 (whether customer-side managers may see that a fellow member is suspended) remains an open owner question, not approved. (`RoleSeeder` and the H4 permission synchronization were R0.9-1D's; see below.)

**R0.9-1D is implemented and passed independent review (Opus 5.5: SAFE TO COMMIT; no Critical, High or Medium findings)** ([documented here](../architecture/role-governance.md)); it adds no migration and is committed locally on the feature branch. Release 0.9 is **not** production-ready:

- H4 role governance: the built-in roles (`operator`, `user`) are defined in code and cannot be edited or deleted through ordinary administration; custom roles are unchanged; their names are reserved.
- A deterministic, previewable, idempotent synchronization (`roles:sync`): dry run by default, application only with the reviewed plan's fingerprint, revocations only with an explicit approval, all under the last-administrator guard and lock with the post-commit permission-cache clear of R0.9-1C.
- `RoleSeeder` refuses outside local and testing; `DatabaseSeeder` seeds only the additive permissions elsewhere.
- A command-line-only first-operator bootstrap (`operator:bootstrap`) for a new installation.
- The CRM company-delete refusal (R0.9-1B L2).
- **Not included:** email normalization on write, removing `client_id` from mass assignment, invitation-token handling (H10) and project and company delete policy (H6); they remain in the R0.9-1D row of the table below and need a decision on whether they follow here.
- **Open owner decisions it surfaces:** a disaster-recovery policy for a locked-out installation (no break-glass path was invented), whether operators must use two-factor authentication ([§10.2](#102-other-recommendations-not-yet-ratified)), and where `roles:sync` runs in the production pipeline (R0.9-2a).
- Hosted CI ran it once (first hosted run, all passed, 0 skipped); the Redis variants of its cache tests ran on a real Redis server.
- **Residual cache risk (inherited from R0.9-1C, recorded from the R0.9-1D review):** a concurrent reader could load old permissions before a commit and store them in the shared Redis cache after the post-commit invalidation, keeping a revoked permission for up to 24 hours. **R0.9-1E (implemented; independent security review passed) addresses it** with a database-backed epoch that fences the cache key and changes inside the writer's own transaction; no migration. It closes the confirmed race for the supported current topology (one primary database, no read replica); an asynchronous read replica is unsupported until permission loading is routed to the primary. Hosted CI and hosted Playwright for it are outstanding, and Release 0.9 is not customer-ready. New deployment constraints it adds: no role-permission change while old and new versions coexist, and `php artisan permission:cache-reset` after a deployment ([Role Governance](../architecture/role-governance.md#permission-cache-generation-fencing-r09-1e)).
- **Deployment preflight:** `APP_ENV=production` must be confirmed on production, because the disposable-environment seeders are gated on it; and existing installations whose built-in roles were customized must review that drift, and keep intended customization in custom roles, before approving a revoking synchronization.

**Obligations unchanged by R0.9-1D (all still open):** the permission-cache operating rules above (R0.9-1E: hosted CI and Playwright outstanding); `SESSION_DRIVER=database`; queue worker restart on deployment; no ordinary rollback below R0.9-1C while any account is suspended, with a safe recovery or forward-fix procedure required before customer onboarding; Info-5 remains an open owner question; H5 to H10 remain unratified; the ticket-number high-water-mark design ([§7.3](#73-ticket-number-continuity)) is still undesigned. A release deployment must now also run the reviewed `roles:sync` (preview, then apply) after migrating.

**R0.9-1E (implemented; independent security review passed; hosted CI and Playwright outstanding; [documented here](../architecture/role-governance.md#permission-cache-generation-fencing-r09-1e)).** A bounded security-hardening package: the shared permission cache key carries a random epoch stored in the existing `cache` table and replaced inside the writer's own transaction, so a reader that loaded the old mapping before a revocation can only fill an obsolete key. No migration, no change to role policy, account lifecycle, built-in role definitions or the bootstrap. Deployment consequences are recorded above and in the linked section: no role-permission change while old and new versions run together; `php artisan permission:cache-reset` after a deployment; queue workers restarted; rolling back to code that ignores the epoch restores the old race, and the R0.9-1C suspension rollback restriction is unchanged.

**R0.9-1B review follow-ups:**

- **L1, resolved in R0.9-1C.** An attempted Eloquent user deletion could strip the user's roles and direct permissions before the database refused the `DELETE` (the permission library detaches them in a `deleting` hook, outside any transaction). R0.9-1C prevents the partial mutation and covers it with regression tests.
- **L2, addressed in R0.9-1D (implemented; independent review SAFE TO COMMIT).** Deleting a CRM company that projects still reference ended in a database error (HTTP 500); the records and associations were intact. The application now refuses first (HTTP 409 for JSON, back with an error for a browser) and translates a late database refusal into the same answer. It is only the refusal: H6 is not ratified and no company or project deletion policy was decided.
- **L3, rehearsal harness.** The general schema snapshot does not detect foreign-key or index name changes, or a duplicate index on the same columns. For M2 and M3 their own post-migration verification and tests cover it.
- **J-5, before any data-transforming migration.** A declared data transformation must not be able to conceal unexpected row loss.

**Execution mode (owner-directed, [E6](#21-epic-017-sequencing-dispositions-owner-directed-2026-10-11)).** Development runs as one sequential track. The "Depends on" column and the remark below about 1D show what the dependencies *permit*, not how the work is run: unless the owner says otherwise, the packages are done in turn, on the working branch.

| Package | Content | Depends on |
|---|---|---|
| **R0.9-1A Rehearsal harness and baseline** | Dedicated, guarded rehearsal database; baseline fixture seeder written against the current schema (no `disabled_at`); invariant snapshot; the migrate, snapshot, candidate, snapshot, compare procedure; a backup and restore round trip with attachment verification. No schema change. | This gate |
| **R0.9-1B Preservation schema** | The H1 and H2 foreign-key changes and `users.disabled_at`, each with an `information_schema` test and a populated rehearsal; update the tests and browser cleanup that rely on user cascades; include H5 only if ratified; design the ticket-number high-water mark ([§7.3](#73-ticket-number-continuity)). | 1A; coordination with EPIC-017 for shared test files |
| **R0.9-1C Account lifecycle** | H3 enforcement points, the operator suspend and reinstate action, last-operator protection with concurrency tests. | 1B (the column) |
| **R0.9-1D Roles and identity foundations** | The H4 permission synchronization (preview, idempotent, explicit revocation) and locked built-in roles; a first-operator bootstrap that does not use `RoleSeeder`; identity hygiene per [§9](#9-corrected-directory-and-finance-migration-seams) (email normalization on write, removing `client_id` from mass assignment); invitation-token handling once H10 is decided; project and company delete guards once H6 is decided. | 1A; the bootstrap follows the synchronization |

1D does not need `disabled_at`, so it may run in parallel with 1B and 1C after 1A. Both 1C and 1D touch code that EPIC-017 also touches (ticket recipients, notifications), so branching and rebasing are coordinated with that work.

**R0.9-2a, hosting discovery (Recommended; not started by this record).** Evidence gathering for the production host. It needs no application code and is not blocked by R0.9-1, but it is information gathering, not a development workstream, and nothing here starts it. It extends RE-0 and covers: PHP version and extensions; SSH, Composer and dump-tool availability; `proc_open` policy; cron granularity and persistent-worker capability; whether Redis exists or is needed (the application does not require it); docroot and symlink support; TLS and proxy topology and `APP_URL`; mail transport and deliverability; writable private storage, quota and upload ceilings; backup target, retention and off-host copy; error alerting; and `APP_KEY` custody. It follows the EPIC-017 RE handoff list (RE-H1 to RE-H13). The D4 ruling stands: cPanel compatibility is the baseline constraint and no host is selected. Any host shape or driver choice is a recommendation until ratified.

## 12. Supersession record

This section supersedes earlier text by dated addendum. Earlier text is not edited.

- **Roadmap principle 8, first half** (development data is disposable "before the first customer production release"), **owner ruling D3** (clean install plus a scripted seed), and every statement that follows from them ("reseed rather than transform", "while data is still disposable", the Directory and Billing Account ordering rationale) do not apply to the Release 0.9 production database. Release 0.9 is the first customer production release. Release 1 is then an upgrade of authoritative data, not a clean install.
- **EPIC-010D** records that the platform has no customer production data. That remains true until the first production record exists.
- **EPIC-017 section 3** states that development tickets are disposable. That describes development data only.
- **Unchanged:** the EPIC-017 owner rulings and Amendment 2 ledger, the EPIC-017 G3-B implementation requirements, and the Release 1 boundary table. Roadmap owner ruling D4 (host not preselected) is unchanged.

## 13. Evidence basis

Facts above were read in the repository during R0.9-0 and the independent review: the foreign-key matrix from all migrations, the application's delete paths, `RoleSeeder` and the role controllers, the invitation and SSO code, the Fortify setup, ticket creation, and the vendored Fortify, Spatie, Laravel notification and Stripe code.

The assessment and review reports themselves are conversation records and are not stored in the repository. Some of their statements were delegated or inferred and are **not** established by this document: the full set of customer-visible project and task properties, the finance test-impact list, Markdown handling in notification mail, how the browser client renders server error pages, and the count of tests that assign the built-in `user` role. Where this document depends on them it says Recommended or Open.
