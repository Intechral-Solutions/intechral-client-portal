# Release 0.9: Scope and Architecture Design Gate (R0.9-0)

**Status:** **Design gate closed (2026-10-10)** by owner ratification of decisions H1 to H4. The gate itself implemented nothing (R0.9-1A and R0.9-1B, the packages implemented since, are recorded in [§11](#11-work-packages-and-parallel-hosting-discovery)). This document adds no code, migration, test, release flag or deployment infrastructure.
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
| E1 | EPIC-017 WP2 is complete **locally**. Hosted CI and the different-UID cleanup check remain outstanding. | EPIC-017 [A2.13](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a213-outstanding-hosted-ci-obligations) |
| E2 | WP3 and WP4 are deferred from the Release 0.9 critical path, not cancelled. | [§3.2](#32-excluded-from-release-09-owner-approved) |
| E3 | WP5 retains the **essential early-access notification, security and accessibility work.** The exact slice is not yet specified or ratified. | [§10.2](#102-other-recommendations-not-yet-ratified) |
| E4 | The keyboard-only and 390/768 customer Helpdesk checks belong to the Release 0.9 **customer-readiness** scope, associated with WP5 and EPIC-017 §33.1. | EPIC-017 [A2.10](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a210-g3-b-review-follow-ups) |
| E5 | When pull-request packaging becomes necessary, prefer **one integrated WP1+WP2 PR** to artificial stacked PRs, documenting the process exception to EPIC-017 §32. No push or PR is required for local closure. | EPIC-017 [A2.12 D-1](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a212-findings-discrepancies-and-accepted-limitations) |
| E6 | Development proceeds as **one sequential track**: no parallel workstreams or branches. | [§11](#11-work-packages-and-parallel-hosting-discovery) |

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

### 5.4 H4: code-owned built-in roles

`RoleSeeder` currently resets both built-in roles on every run, `operator` to *all permissions currently in the database* (including stale rows), and `user` to the catalogue defaults. The role controller also allows editing the built-in roles in the UI. Under H4 the permission catalogue and role definitions in code are the source of truth. The synchronization is a purpose-built, deterministic and idempotent operation with a preview, and it revokes only under an explicit authorization. A permission missing from the database denies silently (the permission library fails closed), so a deployment that skips the synchronization degrades quietly. The first-operator bootstrap must establish the operator role without invoking `RoleSeeder`.

## 6. Migration and rollback contract

1. **Forward only.** Production schema changes are forward migrations. `down()` methods exist for development and are not a production recovery mechanism. Several are lossy or fail on populated tables.
2. **Additive and expand-contract.** Add, backfill, switch reads and writes, and only in a later release remove. A destructive or narrowing change ships at least one release after the code stopped depending on the old shape.
3. **N-1 application compatibility.** When planning a change to an existing production schema, the previous release's code must keep working against the new schema, so that code rollback is available. Concretely: no rename or drop in the release that stops using a column; a new column becomes `NOT NULL` only after a backfill in an earlier release; a tightening (a `RESTRICT`, a unique key) is allowed only when the previous code cannot violate it and a preflight proves existing rows comply.
4. **Ordinary rollback.** Redeploy the previous application code against the current, compatible schema. Data written since the deploy is retained. This is valid **only** if every migration since that release satisfied [item 3](#6-migration-and-rollback-contract). Release notes state, per release, whether ordinary rollback is available. When it is not, the options are a forward fix or the exceptional path below, and the release notes must say so.
5. **Exceptional recovery.** Restoring the database and files from a verified backup is for a failed or unsafe migration, corruption or loss. It discards data written after the backup, so risky migrations run under maintenance mode immediately after a fresh verified backup. It also requires the ticket-number safeguard of [§7.3](#73-ticket-number-continuity).
6. **Classification (Recommended).** Each migration is labelled additive, tightening, transforming or destructive, with the class recorded in the release notes. Transforming and destructive migrations require a verified backup, a populated rehearsal and post-migration verification queries.
7. **Hygiene.** Never edit a migration after it has shipped, keep timestamps unique, and test each tightening against `information_schema`, as the time-entry foreign-key migration test already does.
8. **The previous release does not exist yet.** The N-1 check can only be run once a 0.9.x release exists.

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
- Whether other work may touch the customer shell while EPIC-017 is in progress. (EPIC-017 F5 is retained as documented and F6 is resolved, both confirmed 2026-10-11; see EPIC-017 [A2.12](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#a212-findings-discrepancies-and-accepted-limitations). They are no longer open.)

## 11. Work packages and parallel hosting discovery

**Status: Recommended sequence, not ratified.** Boundaries are proposed here and are finalized in the planning of each package. **R0.9-1A is implemented** (the migration rehearsal harness, [documented here](../testing/migration-rehearsal.md)) and its independent review (Opus 5.5) concluded **SAFE TO COMMIT**, with follow-ups recorded in the [rehearsal documentation](../testing/migration-rehearsal.md#review-follow-ups). **Hosted CI has not yet verified it.**

**R0.9-1B is implemented** ([documented here](../testing/migration-rehearsal.md#release-09-preservation-migrations-r09-1b)): M1 adds the nullable `users.disabled_at` (schema only, no enforcement); M2 changes the 13 H1 user business-record foreign keys of [§5.1](#51-h1-business-record-preservation) from `ON DELETE CASCADE` to `RESTRICT`; M3 changes `project_company.crm_company_id` from `CASCADE` to `RESTRICT` (H2). Constraint names, `ON UPDATE` rules and nullability are unchanged. The populated migration rehearsal passed, including the backup and restore round trip, and the rehearsal-harness follow-ups J-1 to J-4 were resolved. Its independent review (Opus 5.5) concluded **SAFE TO COMMIT**. **Hosted CI has not yet verified it**, and the regular development database had not been migrated at the time of that review. Not part of R0.9-1B despite the table below: H5 was not ratified and is not included, and the ticket-number high-water mark ([§7.3](#73-ticket-number-continuity)) is **still undesigned**. **R0.9-1C has not started.**

**R0.9-1B review follow-ups** (recorded, not implemented):

- **L1, for R0.9-1C.** An attempted Eloquent user deletion can strip the user's roles and direct permissions before the database refuses the `DELETE` (the permission library detaches them in a `deleting` hook, outside any transaction). No application path deletes a user today. The account lifecycle must make offboarding non-destructive, prevent this partial mutation, and add regression coverage for it.
- **L2, for R0.9-1D.** Deleting a CRM company that projects still reference now ends in a database error (HTTP 500); the records and associations are intact. Add a safe business-level refusal with a test. This is needed whatever is decided for H6, which is not ratified.
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
