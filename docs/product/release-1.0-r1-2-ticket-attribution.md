# Release 1.0: R1-2 Ticket organization attribution (D0 design record)

**Status:** **implemented in one PR, packages A, B and C (2026-11-10), merged as PR #61**; this record began as the D0 design (R1-2-D0, 2026-11-10), written against `origin/main` at `e88b1a8`. **Owner rulings of 2026-11-10 are incorporated (section E, "Ruling" lines):
E1, E2, E3, E6 approved, E4 default accepted, E5 accepted with the explicit risk record in section I.** Nothing was implemented, migrated or deployed when it was written; Release 0.9
staging was untouched, and still is. **R1-0 has since landed (PR #60, `79340a9`, 2026-11-10) and R1-2 was built on `feature/r1-2-ticket-attribution`** (see the implementation notes, sections J to L, at the end). Sections A to H are the approved design as written against `e88b1a8`; where R1-0 or the build changed a fact, the implementation notes say so and this record is not rewritten.

R1-2 gives a Ticket (the Service Request) a durable **customer organization** and a durable **submitter**, and makes the server, not the browser,
the authority for both. It grants no new access to anyone. It is the foundation MO-4A asked for; it is not a competing implementation (section D).
Revised [ADR-009](../architecture/adr/ADR-009-ticket-specialization.md) governs: `Ticket` stays the support record, there is no `type`, and Incident
does not shape this schema.

Terms: *requester* = who needs the support (`tickets.user_id`, unchanged). *Submitter* = the authenticated actor who created the row.
*Customer organization* = the `organizations` row the request belongs to. *Authorization* = who may see or act on it, decided separately.

## A. Verified current state

Checked against code at `e88b1a8`, not against planning documents. Paths are under `src/` unless noted.

**Attribution.** `tickets` has `user_id` (NOT NULL, RESTRICT, `database/migrations/2026_10_12_000002_restrict_user_business_record_deletes.php:39`)
and `company_id` (nullable FK to `crm_companies`, SET NULL; `2026_03_26_120006_add_company_id_to_tickets.php`). There is no `organization_id` and no
`submitted_by_id`. **Nothing writes `company_id`**: `TicketController::store` validates it with `AccessibleCrmCompany` and discards it
(`app/Http/Controllers/TicketController.php:60-68`); `TicketService::create` writes only `user_id`, title, description, category, priority, status and
`sla_due_at` (`app/Services/TicketService.php:67-75`). Pinned as baseline F-1 by `tests/Feature/Tickets/TicketVisibilityCharacterizationTest.php:108-127`.

**One production creation path.** Route `POST /tickets` (`auth`, `can:tickets.create`, `routes/web.php:89-92`) → `TicketController::store` →
`TicketService::create` inside `storeAttachments()`'s transaction → `createWithReference()` (public, takes an arbitrary attribute array,
`TicketService.php:425-440`). Other writers are test and fixture code only: `database/factories/TicketFactory.php`, `database/seeders/DevSeeder.php:195`
(two `TK-E2E00-*` fixtures; DevSeeder creates **no** organizations or memberships), `app/Support/Rehearsal/BaselineFixtures.php:217-223` (five raw
inserts), `tests/Support/ticket_number_worker.php:72`. No import, API, CRM or console path creates a ticket.

**Visibility is requester-or-operator and nothing else.** `TicketPolicy::view` delegates to `viewAsRequesterOrOperator`: `user_id === $user->id ||
worksTickets($user)`, where `worksTickets` = `tickets.assign` and not a pilot-restricted customer (`app/Policies/TicketPolicy.php:18-33,94-97`).
R1-1 made that named ability the one reused by `TaskPolicy` (`:49`) and `AccessibleTimeContext` (`app/Rules/AccessibleTimeContext.php:108-112`), and
`tests/Feature/Pilot/AuthorizationBaselineHardeningTest.php:510-573` proves a widened `view` does not widen ticket tasks or ticket time. `view` also
gates reply, attachment download, reply-mail recipients and status-mail recipients (`TicketService.php:411,461`; both notifications re-check at send
time). `viewInternal` and `adjustDueDate` require `worksTickets`. The requester list is `Ticket::forUser` = `where user_id` (`app/Models/Ticket.php:178-181`,
`app/Queries/RequesterTicketQuery.php`). `Ticket` has no global scope. Hand-written own-ticket queries also exist in `DashboardController.php:81,152-156`.
Operator index, reports, CSV export and bulk are capability-gated and unscoped (`app/Http/Controllers/Operator/*`); **`TicketBulkController` has no
per-ticket `authorize`** (`:41`). That is fine while every operator sees every ticket, and is an R1-4 obligation, not an R1-2 change.

**Organization infrastructure.**
- `organization_members(organization_id, user_id)` is unique per pair, **not** per user, so a user may belong to several organizations
  (`2026_03_26_095959_create_organization_members_table.php:18`; `OrganizationPreflight` counts them as a warning). Memberships are written only by
  operator acts: `OrganizationMembership::add`, and `CrmService::promoteToOrganization`, which attaches the **promoting operator** as an unapproved `admin` member (`app/Services/CrmService.php:187-188`),
  so an operator who promoted one or more companies holds one or more memberships. An invitation creates a customer with **no** organization.
- `crm_companies.organization_id` is nullable, UNIQUE, SET NULL (`2026_11_06_120100_unique_company_organization_link.php`): an organization has zero or
  one company. `promoteToOrganization` is the only path that creates an organization and always creates the pair; a later company delete can leave an
  organization without a company.
- Managed customer = `organizations.managed_by_organization_id`; partner = `can_manage_customers` (`2026_11_06_120000_add_organization_management_relationship.php`).
  **Management grants nothing**: only `CrmService::deleteCompany` reads it (`app/Services/OrganizationRelationships.php:12-15`).
- `role = admin` grants nothing; "approved administrator" (`OrganizationAuthority::approvedAdmins`, `:89-98`) is read by no customer-facing authorization.
- **There is no "current organization"** (no session key, middleware or Inertia prop; `app/Http/Middleware/HandleInertiaRequests.php:25-80`).
- No organization lifecycle column (no suspended/archived/deleted), no soft deletes, and no code path deletes an organization. Memberships CASCADE, so
  membership rows cannot be the proof of who belonged when a ticket was filed (`OrganizationMembership::remove()` ignores tickets, `:177-192`).
- `OrganizationScope` (`app/Shared/Scopes/OrganizationScope.php`) is a **read filter** with an operator bypass, applied only to `CrmCompany`. It cannot decide
  "may file for". `tickets.view_org`, `tasks.view_org` and `User::orgCompanyIds()` are read by no code (catalogue debt).

**Release contracts that a `tickets` migration meets.**
- InnoDB, `utf8mb4_unicode_ci`, `database:verify` after migrate, `foreign-keys.json` (currently carries `tickets.company_id` and `tickets.user_id`;
  update with `UPDATE_DATABASE_PROFILE_MANIFEST=1`), exact-map pin `tests/Feature/Database/PreservationSchemaTest.php:34-72`,
  `PreservationDeletionTest`, `tests/Support/CommittedUsers.php`. Precedent for re-runnable, shape-asserting, refuse-and-list migrations:
  `2026_11_06_120000_*` and `2026_11_06_120100_*`.
- **RE-C1 rollback tooling is still live and would fail on this migration.** `tests/Unit/Database/RollbackCompatibilityTest.php:70-120,201-227` pin
  `database/profile/rollback/0.9.json` to exactly four post-0.9 migrations, forbid `data` declarations and a NOT NULL column without default; and
  `tests/Unit/Rehearsal/PreservationRehearsalTest.php:52-69` pins the candidate list. The owner's 2026-11-09 re-scope (no production data, staging
  disposable, no 0.9 rollback) is **not recorded in any repository document**, and R1-0 (record and retire RE-C1) has not landed (`git log`: RE-C1 is
  PR #54; nothing retires it). Docs still read N-1 (`release-0.9-scope-and-architecture.md:167`).
- Rehearsal baseline is 0.9-shaped with five tickets (owners with zero or one membership), no multi-membership user
  (`app/Support/Rehearsal/BaselineFixtures.php:177-181,217-223`).

**Stale statements this work will make false** (to correct in the implementation, section F): `database-schema.md:88,96`, `rbac-design.md:184`,
`EPIC-010B-tenant-scoping.md:23,70`, `product-roadmap.md:270,392`, `information-architecture.md:136,206`, and EPIC-017 §20 ("needs no migration",
`:827`) plus the "wait for Directory" decisions (`:143,:819`). EPIC-010D **D3** ("whether new Tickets persist organization context belongs to the
Helpdesk/Directory design", `EPIC-010D-helpdesk-security-hardening.md:581-583`) is answered by this record; **D1 (owner-only visibility) is not reopened.**

## B. Recommended attribution model

Two columns, no new table, no subclass, nothing for a future type or Incident.

| Column | Meaning | Definition |
|---|---|---|
| `tickets.organization_id` | The customer organization that owns the request | `BIGINT UNSIGNED NOT NULL`, FK `organizations.id`, **ON DELETE RESTRICT, ON UPDATE RESTRICT**, indexed (the FK index) |
| `tickets.submitted_by_id` | The authenticated actor who created the row | `BIGINT UNSIGNED NOT NULL`, FK `users.id`, **RESTRICT/RESTRICT** (H1 rule for business records; `ticket_replies.user_id` already follows it) |
| `tickets.user_id` | Requester/contact | **Unchanged** (NOT NULL, RESTRICT). Semantics and every consumer stay as they are |
| `tickets.company_id` | (dead) | **Dropped** in the same migration (decision E3) |

**Invariants.**
1. `organization_id` and `submitted_by_id` are set once, in the same INSERT that issues the `TK-` reference, and never change. A model `updating` guard throws
   `LogicException` if either is dirty (same pattern as `sla_due_at` and `ticket_number`, `app/Models/Ticket.php:55-78`). They are **not** `$fillable`; the
   service assigns them through the one creation path.
2. **The submitter is never request input.** It is `$actor->id`. In R1-2 it always equals `user_id`; the columns can differ only after R1-5 (partner-originated
   requests with a named contact), and R1-2 must not add any path that makes them differ. No CHECK `submitted_by_id = user_id` (that would block R1-5);
   the service and the test matrix hold the invariant.
3. The organization is resolved and authorized **server-side, inside the creation transaction** (section C), never taken on trust from the request.
4. Attribution is independent of CRM company linkage. `organization_id` points at `organizations`, not `crm_companies`; an organization with no company is
   a valid owner. A ticket never derives its organization through `company_id`, and the company link is not needed to display a name.
5. History survives membership changes: both values are columns on the row. Removing a member, demoting an admin, ending a management relationship or
   suspending an account changes nothing about an existing ticket's attribution. RESTRICT means an organization or a user with tickets cannot be deleted
   (no path exists today; accounts are suspended, not deleted).
6. Reference, status history, due-date override, replies, attachments, notifications and auto-close are untouched. The attribution is resolved **once**,
   before `createWithReference()`'s collision-retry loop, so a retry cannot re-decide it; a rollback (reference exhaustion, attachment failure, lost
   membership) leaves no row, file, mail or job, exactly as today (`TicketService.php:57-91`).
7. `createWithReference()` stops being a general attribute pass-through for these two columns: it receives a small immutable value (organization id,
   submitter id) produced only by the resolver, so no caller can invent attribution.

**Legacy and ambiguous data: no inference.** No existing ticket has any provable organization: `company_id` was never written, and current membership
proves nothing about the past (zero memberships for invited users and most operators, one or several for others, and memberships CASCADE). So there is nothing to
backfill from and nothing may be invented (EPIC-010D D3, MO-1A "no inferred attribution"). The migration therefore **checks `tickets` first, before any schema
alteration (no column, key, index or drop has happened yet), and refuses a populated table with diagnostics: the total count, counts by status, the number of
distinct requesters, how many of those have zero / one / several current memberships (information only, never used to attribute), the number carrying a legacy
`company_id`, and the first N ticket references. It changes no record and no schema** (the `2026_11_06_120100` refuse-and-list pattern, with the check as the very first step of `up()`). Fresh installs,
CI databases and the planned 1.0 fresh-install release candidate pass trivially. Any database that holds tickets must be rebuilt or have them removed
deliberately first (dev: `migrate:fresh` before the seed, because `e2e:cleanup-tickets` removes only `E2E-` tickets and leaves the two seeded `TK-E2E00-*` fixtures; staging: its own rebuild, not part of D0). If authoritative tickets ever exist before this ships,
the safe fallback is a separate, owner-approved, explicit attribution step; D0 recommends against building it speculatively (decision E1).

**Authorized corrections.** There is deliberately no edit path in R1-2. If UAT shows that a multi-organization user files under the wrong organization,
the future correction is a dedicated operator-only `TicketService` method that records a history row (as `TicketDueDateChange` does) and refuses on
closed tickets; it is not designed here (decision E5). Raw SQL edits are outside the contract.

## C. Authorization contract

**R1-2 adds one authorization decision, "may this actor file a request for organization O?", and widens no read.** Visibility stays exactly as R1-1 left it.

Who can do what after R1-2:

| Actor | File for org O | See / reply / download a given ticket | Internal notes, due date, bulk, reports |
|---|---|---|---|
| Requester (`user_id`) | Only if an **active member of O now** and holds `tickets.create` | Own tickets, **unchanged, including after membership ends** (E5) | No |
| Other member of the same organization | No access to others' tickets | **None** | No |
| Org `role = admin`, approved or not | Same as any member; the role/approval adds nothing | **None** | No |
| Partner/manager org personnel (UNISA) | Member only of their own org; management is irrelevant | **None** for customer orgs' tickets | No |
| Operator (`portal.operator` + `tickets.assign`, custom roles included) | Only if also a member of O (files as themselves) | All tickets, **unchanged** | Unchanged |
| Operator/anyone with zero memberships | **Refused** | n/a | n/a |
| Suspended, guest, role-less, restricted customer with a stray grant | Refused as today | As today | No |

**Prohibited, now and by this record:** treating membership role, admin approval, a CRM link or a management relationship as a Helpdesk grant; any
organization-wide visibility; partner on-behalf-of submission (R1-5); letting a request choose the submitter; deriving organization from CRM company; exposing
organization or submitter ids in customer-visible props beyond the actor's own eligible options; widening `viewAsRequesterOrOperator`; registering
`OrganizationScope` on `Ticket`.

**Creation rule** (one place: a new small final class `App\Services\ServiceRequestAccess`, the name the R1-4 plan already uses for the partner-view half):
`attributionFor(User $actor, ?int $requestedOrganizationId): TicketAttribution`, called by `TicketService::create` **inside** the transaction, reading the
tables directly and **unscoped** (the repo has been bitten by scoped reads undercounting, `OrganizationRelationships.php:31-34`; `OrganizationScope` is a read filter with an operator bypass):
1. Actor active and holds `tickets.create` (route middleware already requires the latter; keep as defence in depth).
2. **Lock, then read current state.** Inside the creation transaction, in the same order `OrganizationMembership::transact()` uses (organization row first, `:334`, then
   membership), take a **shared locking read** (`sharedLock()`, `LOCK IN SHARE MODE`) of the organization row, then a **shared locking read of that actor's membership row**.
   InnoDB locking reads return the **latest committed** row, not the REPEATABLE READ snapshot, so a removal that committed while creation waited is seen and the request is
   refused; a removal that has not committed blocks on the lock and cannot slip in between the check and the INSERT. The membership decision must never come from a plain (snapshot)
   `SELECT` or from an Eloquent relation loaded earlier. When no id is requested, a plain read may *nominate* the candidate organization(s) (needed to know which row to lock first),
   but nothing is granted until the locked re-read of that organization's membership succeeds; a stale nomination can only produce a refusal or an explicit-choice error, never a
   wrong attribution. Same lock order as the remover means no deadlock. The real-database test for this is in G.
3. Requested id absent: exactly one membership → that organization; none → refuse; several → refuse with a field error (no default, no "last used"). Requested id present:
   must be one of the actor's memberships, otherwise the **same** 422 on `organization_id` whether the id does not exist or belongs to someone else (no existence oracle).
4. Nothing else is consulted: not role, approval, company, partner/managed status. (R1-4 will add `organizations.disabled_at` and the support grant to this one class.)

Why a small dedicated class rather than extending the policy/query seams: the policy answers per-ticket questions and the query seam answers per-user list
questions; creation needs a locking, unscoped, per-organization decision that neither provides. Putting it in `TicketPolicy` would mix a creation-time
locking read into an authorization method called at send time by notifications; putting it in the controller would let the next caller bypass it. One class
also gives R1-4 the single "which organizations may this actor act for" source the five hand-written visibility sites lack, without R1-2 touching them.
No generic authorization framework, no new global scope, no interface.

**Where each rule is enforced:** server (`ServiceRequestAccess` in `TicketService::create`) is authoritative; `TicketController::store` validates `organization_id` as a
nullable integer only (never `exists:`, to avoid the oracle) and passes it on; the create page receives the actor's own eligible options as a page prop, not a shared
prop; the `Ticket` model guard and the NOT NULL FKs are the backstops.

**Ticket-linked surfaces (nothing inherits breadth):** ticket tasks (dormant; no creator exists) and ticket time stay on `viewAsRequesterOrOperator` / `viewInternal`;
attachments keep `tickets/{ticket id}/` storage and the `view` + internal-note rule; replies and notes unchanged (`is_internal` still downgraded without
`viewInternal`); reply and status mail still go to the requester (and assignee for replies) re-checked with `view` at send time; no submitter notification in R1-2
(identical to requester). `TicketBulkController` gets no change now; R1-4 must keep partner support personnel out of every `can:tickets.assign` route.

## D. MO-4A reconciliation

The roadmap names MO-1A → MO-2 → MO-3 → MO-4A/B (`product-roadmap.md:342`); the owner's 2026-11-09 plan uses R1-0…R1-8. **Neither is renamed.** The numbering
below is recorded here because no repository document defines R1-2…R1-8 (ADR-009 mentions R1-2 only to withdraw a checkpoint).

| Existing item | Disposition |
|---|---|
| MO-1A (links, no access) | Foundation, unchanged: organizations canonical, pair of org + company, management grants nothing |
| MO-2A1 (operator membership + approval) | Foundation, unchanged: membership is the proof of "member now"; approval stays unread |
| **MO-4A** "Tickets carry a visible, explicit organization" (`mo-1a.md:180`) | **Fulfilled by R1-2.** "Visible" is defined as: durable, shown to operators, shown to the requester as their own organization name (E4) |
| MO-4A "guarded company delete" | **Already satisfied.** MO-1A guards company delete for partner/managed organizations; after R1-2 no ticket references `crm_companies`, so a company delete cannot alter attribution, and the organization FK is RESTRICT. No `CrmService` change |
| MO-1A "next release: `tickets.company_id` to RESTRICT" (`mo-1a.md:182`) | **Superseded**: the column is dropped |
| MO-4B / R1-4 partner support grant | **Not R1-2.** Needs its own customer-specific grant and design review. R1-2 only guarantees: organization durable, `view` not widened, `viewAsRequesterOrOperator` untouched |
| R1-5 partner-originated requests with named contact | Not R1-2. First time `submitted_by_id ≠ user_id`; the column exists so no data migration is needed |
| MO-2A2 / R1-3 (org-bound invitations), MO-2B, MO-3 | Untouched |

**Inconsistent or overlapping commitments to resolve (record, do not rework):**
1. **MO-4B says "`TicketPolicy::view` unchanged, separate ability and routes"; the R1-1 policy comment anticipates *widening* `view` for partner support
   (`TicketPolicy.php:23-33`); the owner plan says "extend `view` through `ServiceRequestAccess`".** R1-2 is neutral and cannot foreclose either. R1-4's design must choose one before code.
2. EPIC-017 §20 "needs no migration" and "wait for Directory" (`:143,:819,:827`), EPIC-010D D3, and the roadmap line that organization visibility "depends on Directory"
   (`:376`) predate MO. R1-2 needs a dated amendment to each (organizations are canonical now and Directory keeps `organizations.id` as the surviving key, so there is no data
   Directory must transform; D1 is not reopened).
3. MO docs and the release notes still require backup/rollback gates and N-1 migrations for an "authoritative environment"; the owner re-scope says there is none. That ruling must be written
   into the repository by R1-0 before R1-2's migration can pass its own gates (section E6/G).
4. Customer props are pinned to exclude company data (`RequesterTicketPropsTest`, EPIC-017 `:927,:1254`); MO-4A's "visible" requires a deliberate, reviewed key addition (E4).
5. ADR-009 names "R1-2" and the roadmap names "MO-4A": same work, two names; this record is the bridge. Index it in `docs/product/README.md` and link it from the roadmap forward note
   at implementation time.

## E. Owner decisions

Only decisions that change what gets built. **Blocking** = cannot start coding without it.

**E1. Legacy tickets and NOT NULL (blocking).** *Recommend:* both columns NOT NULL with the empty-table-or-refuse migration in B; no backfill, no sentinel.
Alternatives: (a) nullable columns plus a paired CHECK, a creating guard and a permanent "unattributed" branch in every query and display (keeps any legacy row, weakens the DB
guarantee, and pays a permanent branch for data the owner says does not exist); (b) a sentinel "Unattributed" organization (creates a fake tenant that policy code must special-case; invents ownership).
*Consequence:* every database holding tickets (dev, 0.9 staging) must be rebuilt before migrating; DevSeeder, `TicketFactory`, the rehearsal baseline and E2E personas gain organizations; ~70 test files stay
green via one factory default.
**Ruling (2026-11-10): APPROVED.** The migration refuses a populated table before any schema alteration, with the diagnostics in B, and modifies no record. The 1.0 target has no authoritative customer data to preserve; disposable pre-customer environments may be rebuilt, but no reset or deployment is authorized by this record.

**E2. Operators who file requests (blocking).** Today an operator holding `tickets.create` can file a request owned by themselves; most operators have no membership, though one who promoted a company is an admin member of it. *Recommend:* the membership rule applies to operators exactly as to customers: refused unless
also a member of the target organization (one who promoted several companies must choose explicitly), with a clear message; intake on behalf of a customer is R1-5 work. Alternatives: let operators pick any organization (the requester would be the operator while the
organization is a customer: false provenance); exempt operators via nullable columns (contradicts E1). *Consequence:* a small, intentional behaviour change; verified that none of the browser specs
(`operator-due-date`, `operator-composer-controls`, `customer-helpdesk`) creates tickets as an operator.
**Ruling: APPROVED.** Operators get no bypass because of operational capabilities; on-behalf-of intake stays R1-5. The lock strategy in C is retained with the explicit requirement that eligibility is read from current database state after the lock (locking reads, not a stale snapshot), and concurrent membership removal is covered by a real MariaDB concurrency test (G).

**E3. Schema scope (blocking the migration text).** *Recommend:* add `submitted_by_id` now and drop `company_id` now. `submitted_by_id` costs one column while the table is empty and avoids R1-5 doing the repo's first
data migration; the alternative (add it in R1-5 with the provable backfill `= user_id`) is acceptable and smaller today. Dropping `company_id` is lossless under E1, removes a misleading "provenance" column, an unused relation
and a validated-but-discarded input; keeping it leaves dead data and an open MO-1A item. *Consequence of dropping:* update `IntegritySnapshot`, `OrganizationPreflight` and the pins in A.
**Ruling: APPROVED.** Add `submitted_by_id` and remove `company_id` now. The MO-1A deletion protections for CRM companies in managed-organization relationships (`CrmService::deleteCompany`) are preserved unchanged. Every active company-attribution dependency is updated, and no safety check is silently dropped: each obsolete check is **replaced** by one that validates the new model (the replacement list is in F, R1-2A).

**E4. Customer-facing organization display (non-blocking, default applies).** *Recommend:* create form shows a required selector only when the actor has several organizations, a read-only "Requesting for: <name>"
line when one, and a blocked state ("your account is not linked to an organization; contact Intechral") when none; request list row and detail show the organization name (never ids other than the actor's own option
values). Alternatives: selector/blocked state only, organization nowhere else; or no customer display at all. *Consequence:* a reviewed, deliberate update of the exact-key presenter tests.
**Ruling: default accepted**, subject to existing Ticket authorization; organization-wide Ticket visibility is not expanded.

**E5. Deliberate non-changes (non-blocking).** *Recommend:* (a) a requester keeps access to their own tickets after leaving the organization (operators end access by suspension; attribution and authorization stay separate);
(b) no correction path in R1-2. Alternative to (a): require current membership to view/reply, which would be the first org-conditioned read and would need the R1-4 machinery. *Risk accepted:* a former member of a managed organization
can still read their own earlier threads until suspended.
**Ruling: accepted for R1-2 with the explicit risk record in section I.** Requester-based access is a distinct authorization basis from relationship-derived access. No general requester-revocation mechanism and no organization-correction workflow in R1-2.

**E6. Sequencing and the design gate (blocking).** *Recommend:* R1-0 lands first (small: record the owner re-scope in the docs, retire RE-C1's `0.9.json` and rollback tests, and state what the preservation rehearsal means for 1.0:
retire the 0.9 baseline or declare R1-2's candidate as "refuses on a populated baseline"). MO-4A's "Opus design gate" is satisfied by this D0 plus the single post-A Opus review in F, instead of a separate
pre-implementation review. Alternative: fold only the minimum R1-0 edits into R1-2 (more review surface in one PR).
**Ruling: APPROVED.** R1-0 lands first and separately. R1-2 stays one branch and one PR with three gated packages (R1-2A/B/C). One focused independent Opus security review after R1-2A replaces a separate MO-4A design review; it must address organization selection, authorization, concurrency, provenance, migration integrity and tenant isolation. R1-2B's UI, reporting and notification changes get a targeted differential review only if they introduce new security-sensitive behaviour; no repeated full architecture review.

## F. Bounded implementation plan

One branch, one PR, **three packages with gates; no package is mergeable alone** (A without B leaves multi-organization users with a server refusal and no selector; B without A has nothing to show).

**R1-2A. Contract and storage** (server only).
Migration (`add_ticket_organization_attribution`, MO-1A style: named constants, re-runnable, shape-asserting, refuse-and-list on a populated `tickets`); `Ticket` guard, `$fillable` and relations (`organization()`, `submittedBy()`; remove `company()`);
`ServiceRequestAccess` + `TicketAttribution`; `TicketService::create` and `createWithReference`; `TicketController::store` (drop `company_id` validation, accept `organization_id`); `TicketFactory` (member-aware default) and a shared Pest "customer in organization"
builder; `DevSeeder` personas with organizations and memberships; `BaselineFixtures`/`IntegritySnapshot`/`OrganizationPreflight` (replace, do not drop: the `company_id`-attributed-ticket warning becomes a tickets-per-organization report plus a "requester is no longer a member" count (information only, E5); the snapshot check becomes "no ticket with a missing or dangling organization or submitter"; `CompanyDeletionSafetyTest` keeps every MO-1A refusal case and gains "deleting a company never alters ticket attribution"); manifests and pins (`foreign-keys.json`, `PreservationSchemaTest`, `PreservationDeletionTest`, `CommittedUsers`, rehearsal candidate per E6);
characterization tests F-1 rewritten with explicit intent. **Gate A:** targeted Pest (Tickets, Crm, Pilot, Database), `database:verify` on a throwaway MariaDB, security matrix and concurrency tests below green (the membership-removal race on a real MariaDB, not a mock), mutation probes recorded.
**Independent Opus security review happens here, once** (migration, resolver, immutability, no-oracle, concurrency, no widened read).

**R1-2B. Surfaces.** Requester create page (selector / read-only line / blocked state), list/detail organization name, presenter and `types/helpdesk.ts` key additions; operator Blade list/show organization and submitter, optional organization filter, CSV (add Organization and Submitted by; relabel the
current "Submitter" column, which is the requester, `TicketReportController.php:78,87`), new-ticket alert body gains the organization name; Playwright updates. **Gate B:** `cd src && npm run lint`, Blade theme guard, browser suite on a rebuilt dev stack, no suppression growth in
`eslint-suppressions.json`. Review only the delta if A's authorization code changed.

**R1-2C. Closure.** Dated amendments listed in A (ADR-005 "Ticket scoping is policy-based with a direct attribution column; no `OrganizationScope`", `database-schema.md`, `rbac-design.md`, EPIC-010B/010D/017, roadmap forward note, `docs/product/README.md` row), `./dev check`, hosted CI. **Gate C:** green hosted CI; no further full architecture review.

## G. Security test plan

Reuse the existing patterns: dataset-driven matrix with a registered-route coverage guard (`ProjectAuthorizationMatrixTest`), the "grants nothing" observe-before/after closure (`OrganizationRelationshipsTest.php:508-555`,
`OrganizationMembershipTest.php:635-677`), the `HardeningWiderTicketPolicy` stand-in (must stay green), no-oracle ordering (`TicketSecurityCharacterizationTest.php:129-152`), the second-connection race (`OrganizationMembershipConcurrencyTest`) and the worker-process race (`ticket_number_worker.php`). Run in **pilot** mode as well as legacy; one suite at a time (shared testing DB).

- **Creation matrix** (actors × org cases): one-membership member (implicit and explicit same id), multi-membership (absent → 422, each own id → ok, foreign id → 422), zero membership, operator with and without membership, org without CRM company, managed customer member, partner member naming a customer org, approved admin, unapproved admin, suspended, guest.
- **Forgery:** non-member id, non-existent id (identical response), id of a managed customer named by its partner's member, `submitted_by_id`, `user_id`, `company_id` and `organization` keys in the payload (ignored/refused; row shows actor and eligible org); nothing written, stored or mailed on every refusal.
- **Provenance:** `submitted_by_id == user_id == actor` for every created ticket; the two columns and the immutability guard refuse updates (model and a source scan for query-builder updates); membership removal, role demotion, approval revocation, management unlink and partner designation changes leave attribution unchanged.
- **No widening:** after attribution exists, same-org peer, org admin, approved admin, partner member and partner admin still get 403/404 on show, reply, attachment download and list/search; operator behaviour identical; observe-before/after over views, lists, search results, dashboard counts, time context and notification recipients.
- **Isolation of private content:** internal notes and their attachments invisible to requesters regardless of organization; attachment of ticket X unreachable via ticket Y.
- **Atomicity/concurrency (real MariaDB, committed data, no mocks):** (i) a second connection starts `OrganizationMembership::remove()` and holds the organization lock while creation begins: creation waits, and after the removal commits it refuses with no row/file/mail (proves the locked read sees the latest committed state, not a snapshot; include a case where creation's transaction already issued an ordinary read before the lock); (ii) the reverse order, creation holds its shared lock and removal waits, ticket commits with valid attribution; (iii) the worker-process race over `ticket_number_worker.php` with removal in a third process; (iv) no deadlock under either order. Outcome of each: a consistent ticket or nothing: either a consistent ticket or no row/file/mail; attachment failure and reference exhaustion leave nothing; two simultaneous creations by a multi-membership user keep each one's chosen organization.
- **Schema:** populated-`tickets` migration refuses with offender list and changes nothing; empty passes; re-run is safe; FK actions RESTRICT/RESTRICT; deleting an organization or user with tickets is refused; `database:verify` clean.
- **Existing behaviour:** requester list/detail/reply/attachments, operator queue/status/assign/bulk/reports, notifications, auto-close, due-date override, time and ticket tasks unchanged (existing suites green).
- **Mutation probes** (manual, recorded in a table as in `docs/testing/e2e-browser-suite.md:174-190`): trust request organization without membership check; drop the lock; allow submitter from input; widen `view`; drop immutability guard; use scoped `Organization::query()` in the resolver.

## H. Readiness verdict

**Owner rulings received 2026-11-10 (E1-E6). R1-2A may begin once R1-0 has merged with green post-merge CI and this record is brought onto current `main`.** No code blocker exists in the Ticket, policy or organization design; the single creation path, the existing R1-1 split and the MO foundations make the change small.
The **remaining blocker** is (1) **R1-0 has not landed**: RE-C1's `0.9.json`, `RollbackCompatibilityTest` and the rehearsal candidate pin fail on any new tickets migration, and the owner re-scope exists only in memory notes, not in the repository; (E1/E2 are now ruled).

**Remaining risks.** (a) A former member keeps access to their own tickets (E5a). (b) The new-ticket alert still goes to one mailbox carrying requester name and a preview across organizations; per-organization routing is a later product decision. (c) The 0.9-shaped rehearsal baseline cannot pass a
refusing migration, so what the rehearsal proves for 1.0 must be settled in R1-0. (d) Hosted E2E is legacy mode on a fresh `migrate --seed`; DevSeeder persona grants and organizations must be right or browser jobs fail (known from R1-1). (e) `TicketBulkController` and operator lists remain
capability-only: safe now, an explicit R1-4 obligation. (f) Time entries keep displaying a ticket's number and title to their owner after ticket access changes (existing behaviour, unchanged).

## I. Risk record and standing rules from the owner rulings (2026-11-10)

**Former-member requester access (deliberate product-security question).** After R1-2, a requester keeps access to their own tickets (view, reply, public attachments, requester mail) after their organization membership ends.
This is a *requester-based* basis (`tickets.user_id`), distinct from any *relationship-derived* basis (organization-derived or partner-support access). It is accepted for R1-2 only. **Revisit before customer production onboarding**
(candidate answers: suspend on removal as an operator procedure, require current membership for reply on organization-attributed tickets, or time-box access). Do not build a general requester-revocation mechanism or an organization-correction workflow in R1-2.

**Standing rule for R1-4 and later:** removing an organization membership or a partner support grant must revoke the corresponding relationship-derived authorization **immediately**. Such access is therefore evaluated from current
membership/grant rows at request time and at notification send time (as `view` already is), is never cached on the ticket, and never copied into `organization_id`/`submitted_by_id` (which are provenance, not authorization).

## J. Implementation notes (R1-2A, 2026-11-10)

Written against `origin/main` at `79340a9` (R1-0 merged). Nothing in A to I is reopened; these are the facts that moved and the choices the approved design left to the implementation.

**What R1-0 changed for this record.** The "RE-C1 rollback tooling would fail on this migration" bullet in A, the rehearsal-candidate item in F and risk (c) in H are **settled**: `database:rollback-assessment`, `rollback/0.9.json` and its tests are gone, and `MigrationSet` no longer requires a migration to be named in `database/rehearsal/candidates.php`. The R1-2 migration is therefore **not** a rehearsal candidate: the 0.9-shaped rehearsal baseline never runs it (it still has `tickets.company_id`, five tickets and `BaselineFixtures` untouched), the SHA-256-pinned baseline and candidate entries are unchanged, and fresh-install plus `database:verify` carry the schema proof. `IntegritySnapshot` is schema-aware instead: its `company_id` fact exists only while that column does, and it gains `tickets:without_existing_organization` / `tickets:without_existing_submitter` once the new columns exist (replacement, not removal, of the company check).

**Choices inside the approved design.**
- `TicketService::createWithReference()` is now **private**; its only caller is `create()`. Together with the `creating` guard on the model and the new NOT NULL columns, the application has one place that writes a ticket (pinned by a source-scan test).
- `create(User, array, array, ?int $organizationId = null)`: the requested organization is an explicit argument, never a key of the data array.
- `ServiceRequestAccess` also re-reads the actor's suspension from the `users` table and re-checks `tickets.create`, so the service boundary does not depend on the route. A caller with no open transaction gets a `LogicException` (a lock outside the INSERT's transaction would mean nothing).
- Explicit organization: the organization row and the membership row are locked directly (no snapshot nomination). Implicit organization: an ordinary read only nominates the single candidate; the locked reads decide.
- `Ticket::organization()` is read without the organization membership scope on purpose (the scope would return `null` for a requester who has left their organization).
- `tickets.company_id` is dropped with its foreign key in a **second** statement after one statement adds both new columns and both keys, so a ticket that races in after the emptiness check makes the first statement fail whole and nothing is half built. How a run that stopped between the two statements resumes is in "Review remediation" below.
- Development seeding: `DevSeeder` creates three fixed organizations (`e2e-customer-org`, `e2e-other-org`, `e2e-operator-org`), each with its persona as a plain `member`, and attributes the two fixture tickets to them. The browser specs that create tickets all use the `e2e-customer` persona; no spec files a request as an operator.
- `OrganizationPreflight` reports either shape: before the migration, the ticket count and legacy company links (and that the migration will refuse); after it, tickets per organization and the number of requesters who are no longer members (information only, E5). The JSON key `attributedTickets` became `ticketAttribution`.
- Stale pins updated, not dropped: `PreservationSchemaTest` (company key removed, `tickets.submitted_by_id` added), `foreign-keys.json`, `CommittedUsers` (submitter), `CompanyDeletionSafetyTest` (every MO-1A refusal kept; the company-linked-ticket case now asserts a deleted company never alters a ticket's attribution or deletes its organization), `TicketVisibilityCharacterizationTest` F-1 (rewritten with explicit intent), `RequesterTicketPropsTest` (the sentinel organization name must still not reach a customer until R1-2B).

**Not done in R1-2A (by design):** customer organization selector and display, operator list/show/CSV/alert presentation (R1-2B); dated amendments to ADR-005, `database-schema.md`, `rbac-design.md`, EPIC-010B/010D/017 and the roadmap (R1-2C).

### Review remediation (independent security review, 2026-11-10: SAFE AFTER TARGETED REMEDIATION)

**M-1, migration resumability.** The first version refused any populated `tickets` table, including one whose attribution columns were already in place, so a run interrupted between its two statements could not be resumed once the application had created a ticket. Now the refusal of a populated legacy table happens **only when the attribution columns do not yet exist**, before any DDL. When both columns exist the migration first **proves their shape** (each is an unsigned bigint, NOT NULL, with no default or generated value, and has exactly one single-column foreign key to `organizations.id` / `users.id` with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`; the key is found by column, not by name, because a restored dump keeps its own names) and then, if `company_id` is still there, drops it. A wrong shape, exactly one of the two columns, or a `company_id` that still carries a value stops it with nothing changed (nothing has ever written that column; dropping a value would destroy data nobody asked to lose). A schema that already looks finished is proven the same way before the migration returns. `down()` finds foreign keys by column too.

**R-1, what the retry actually does** (verified against the installed Laravel 13.36). `TicketService::create()` runs `storeAttachments()` (the transaction, the one file-deletion path) inside `retryingDeadlock()`. It retries, up to `CREATE_ATTEMPTS` (3) in all, **only** when InnoDB error **1213** appears anywhere in the exception chain, and **only** when the creation was the outermost transaction (`DB::transactionLevel() === 0` after the rollback). Why the chain: Laravel rethrows a driver error that happens inside a nested transaction (the savepoint around the reference INSERT) as `Illuminate\Database\DeadlockException`, a `PDOException` whose code is 0 for a string SQLSTATE and whose `previous` is the driver's `QueryException`; and its concurrency detector treats a **lock wait timeout (1205)** the same way, so neither the wrapper's class nor its message separates a deadlock from a timeout. The driver's error number (`errorInfo[1]` on a `PDOException` in the chain) does. A 1205, a collision-exhaustion, a validation or authorization refusal and every other error are **not** retried. A retry starts from nothing: the lost attempt's files were already deleted by the rollback path, attribution is decided again from current membership under fresh locks, a new reference is drawn, and the notification hand-offs still happen once, after commit. Inside a caller's transaction there is no retry and the error propagates as before.

**T-1, the source scans are tripwires.** The scans in `TicketAttributionIntegrityTest` read application source with comments removed and notice a **new** direct ticket writer (a second `Ticket::create`/`insert`/`upsert`/`make` form, `new Ticket(`, a query-builder insert on `tickets`, raw `insert into tickets`), an object assignment to either column, or an update that names one. They are regression tripwires, not enforcement: they cannot see dynamic code, a table name in a variable, a macro, a runtime-built SQL string, and they do not read tests, seeders or migrations. The guarantees are the database (NOT NULL, RESTRICT keys), the model guards, and `createWithReference()` being private; **direct SQL or a query-builder UPDATE of the two columns is not prevented by anything in PHP**, and no database trigger exists (accepted, section I). Audit of every production write path to `tickets` at this commit: the only creator in `app/` is `TicketService::create()`; the other writers update columns unrelated to attribution (`TicketService` assignment through the model and the auto-close builder update of `status`/`closed_at`, `tickets:rereference` through the model's `ticket_number` window, `e2e:cleanup-tickets` deletes); `DevSeeder` (outside `app/`) sets both columns explicitly; none touches `organization_id` or `submitted_by_id` after creation.

**T-2, concurrency evidence added.** `TicketAttributionConcurrencyTest`: T7 two creations for the **same** organization, the first holding its shared locks, proceed side by side (the second connection has a one-second lock wait, so any wait would fail it); T8 a **real** InnoDB deadlock inside the reference INSERT's savepoint, produced against a plain PDO peer process (`tests/Support/ticket_deadlock_peer.php`, no production test hooks): the peer holds the requester's `users` row (which the INSERT must share-lock for its foreign key) and is the heavier transaction, and on a signal asks for the organization row exclusively while the creation holds it shared. The test asserts the database's own `Innodb_deadlocks` counter rose by exactly one, the creation ran twice, the peer acquired the lock (so the creation was the victim), and exactly one ticket, one attachment, one file and one mail exist; T9 a lock wait **timeout** in the same place is wrapped by Laravel in the same class but is not retried (one attempt, no deadlock counted, nothing written); T6 the two exception shapes through the real transaction path.

**Unrelated test fix.** `TicketReferenceIssuingTest` asserted that a random reference does not contain `"<id>-"`, which fails by chance about one run in thirty when the first random group happens to end in that digit (a pre-existing flake, nothing to do with R1-2). It now asserts the reference does not contain the zero-padded id, which is what the retired id-derived format looked like.

## K. Implementation notes (R1-2B, 2026-11-10)

R1-2B makes the attribution of section B usable. It changes **no authorization**: `TicketPolicy`, the requester queries and the task, time, attachment and internal-note rules are untouched, and the server still decides which organization a request belongs to inside the creation transaction (section C). Everything below is presentation, plus the one read the create page needs.

- **Create page** (`resources/js/pages/tickets/create.tsx`, `TicketController::create`). The page receives `organizations`: the actor's **own current memberships**, id and name, ordered by name, from `ServiceRequestAccess::eligibleOrganizations()` (an ordinary read of `organization_members` joined to `organizations`; no scope, role, relation or relationship; display only, because `attributionFor()` decides again under lock). One organization is **stated** ("Requesting for <name>") and sent explicitly, so a membership that changed while the page was open is refused rather than silently re-pointed. Several are a **required select with no default** (no first-in-list, no "last used"). **None** shows an explanation and no form ("Your account isn't linked to an organization yet… Contact Intechral"), naming no organization. A server refusal (`organization_id`) is shown beside the field and takes focus. The temporary R1-2A error line is gone.
- **Requester list and detail** (`RequesterTicketPresenter`). Rows and the detail carry `organization`, the **name** only, owner decision E4. Never an id, never a list. The list reads the name by a correlated subselect in the existing list query, so the pinned query budget is unchanged (an eager load added a query to every page). A former member keeps seeing the name on their own tickets (E5: attribution is provenance, access is the requester basis).
- **Operator surfaces** (Blade). The queue shows the organization under each ticket and gains an **organization filter**: by id, whole positive numbers only (text, a list, zero and negatives are ignored and never echoed; the view gets the validated value, which fixed an "Array to string" 500 that `?organization[]=` produced in the first draft). Its options are the organizations that have at least one ticket, which a `tickets.assign` holder already sees on every ticket. The ticket page shows the organization and, only when it differs from the requester, **Submitted by** (differs only after R1-5).
- **CSV export.** `Organization` and `Submitted by` are **appended** (columns 12 and 13), so every earlier column keeps its position; the column previously called `Submitter`, which is the requester, is relabelled `Requester`. Both new cells are formula-neutralized.
- **New-ticket alert.** One more line, `**Organization:** <name>`, escaped like every other user-authored line (the alert goes only to the helpdesk mailbox). The requester's own mails are unchanged.
- **Seeding and cleanup.** `DevSeeder` adds `e2e-customer-multi` (two organizations) and `e2e-customer-no-org` (none). The multi persona is added to `e2e:cleanup-tickets`' owner allowlist so its disposable `E2E-` requests are removed like the others (every other guard of that command is unchanged); the no-org persona creates nothing.
- **Tests.** Pest: `TicketOrganizationSurfacesTest` (create-page options and their disclosure limits, operator row/filter/escaping, CSV), `TicketCreationMatrixTest` (every actor and organization case of section G through the real route in **legacy and pilot** mode, plus the page and the server agreeing), `RequesterTicketPropsTest` (exact keys; name not id; no other organization's name), alert escaping in `MailTextRenderingTest`. Vitest: the three create-page states, the row and the detail. Playwright (run against an isolated stack, see below): journeys 9-11 in `customer-helpdesk.spec.ts` (one, two and no organizations; a forged choice through the real form is refused and shown; the operator sees and filters by the organization) and the same matrix inside the single pilot-mode test, so the hosted job's "1 passed" still holds (its timeout is raised to 120 s).
- **Browser evidence** was produced on an isolated stack (a throwaway MariaDB database, `migrate:fresh --seed`, the application served from the worktree by PHP's built-in server), never the shared development database or staging. Legacy mode: **63 passed** across the customer Helpdesk (including journeys 9-11), customer shell, operator due-date, operator composer, Helpdesk default, Home and E2E-cleanup specs (the one marked failed is that spec's deliberate fail-then-clean-up case). Pilot mode: the pilot spec, **1 passed**, against a server verified to be in pilot mode (its own probe refuses a legacy one), after which the suite cleanup removed the requests it had raised (the multi-organization persona included). Hosted CI has not run. The full Pest suite on an isolated MariaDB 11.4 container: **4912 passed, 20 skipped, 0 failed** (4421 s); Pint and the frontend gate (typecheck, lint with no suppression growth, prettier, vitest, build) are clean. Two earlier runs failed for reasons in the new specs themselves (an unmeasured assertion and a forged choice that did not notify React) and were corrected before these results.

**Review.** An independent read-only review of this delta found no production-code issue and four test weaknesses, all fixed: a forged-choice step that did not notify React, an operator-row assertion satisfied by the filter's option list, an unposted foreign organization in the page-versus-server test, and a requester probe that proved nothing beyond the existing scope. Carry-forward for R1-4: partner support personnel must stay off every `tickets.assign` route, or the queue filter becomes a cross-tenant name list.

## L. Closure (R1-2C, 2026-11-10)

R1-2C reconciles the documentation with what A and B built, applies the five non-blocking test-strength items of the independent differential review of R1-2B (verdict SAFE TO COMMIT, no production-code finding), and validates the whole branch. It changes no production code and no behaviour.

**Documentation.** Current Release 1.0 behaviour is stated in the documents that describe the system *now*, and each older document keeps its historical text with a dated amendment that says what is superseded:

| Document | Treatment |
|---|---|
| [ADR-005](../architecture/adr/ADR-005-organization-multitenancy.md) | **Amendment 2**: Ticket scoping is policy-based with a direct attribution column and no `OrganizationScope`; attribution is not access; consequences (creation-time membership under locks, E5, no backfill). The 2024 decision and the 2026-06-30 amendment are kept. |
| [`database-schema.md`](../architecture/database-schema.md) | Current: the `tickets` row, the data-scoping note and the RESTRICT list now name `organization_id` and `submitted_by_id`; `company_id` is gone. |
| [`rbac-design.md`](../architecture/rbac-design.md) | Current: the scoping paragraph is corrected and a new section states creation authorization, provenance, unchanged viewing, presentation and the **R1-4 obligation**. |
| [EPIC-010B](../epics/EPIC-010B-tenant-scoping.md) | Matrix cell marked superseded and a dated amendment (the 10B boundary did not change). |
| [EPIC-010D](../epics/EPIC-010D-helpdesk-security-hardening.md) | Dated amendment under **D3** (answered); **D1 is not reopened**; header pointer. |
| [EPIC-017](../epics/EPIC-017-helpdesk-mvp-customer-shell.md) | **Amendment 3**, a top-of-document update, and inline "superseded" notes on the "wait for Directory" rows and on "this epic needs no migration". |
| [Roadmap](./product-roadmap.md) | Forward note (R1-2 implemented, fulfils MO-4A, attribution is not visibility, the R1-4 obligation) and a pointer on the "company-level visibility" line. |
| [`information-architecture.md`](./information-architecture.md) | Current: the Ticket entity and the Ticket ↔ Organization relation. |
| [MO-1A](./release-1.0-managed-organizations-mo-1a.md), [Release 0.9 scope](./release-0.9-scope-and-architecture.md), [README](./README.md) | One-line supersession notes and the index row. |

**Review items O-1 to O-5 (all test-only).**
- **O-1** The suspended-account scenario now asserts the real answer (HTTP 403, nothing created, nothing mailed) instead of "not a 500".
- **O-2** A test fails if `matrixScenarioNames()` (the dataset, declared before the application boots) and `matrixScenarios()` (the fixtures) ever stop naming the same set, or a name repeats.
- **O-3** Journey 11 no longer posts a hard-coded `1`: it posts a **real** organization's id (read from the operator queue filter, like journey 10) and an id that does not exist, and requires the same refusal for both. A small `organizationId()` helper serves both journeys.
- **O-4** Journey 10 also asserts the control's own `validity.valueMissing` after the blocked submit, so it is the browser's constraint and not only a `required` attribute on the markup.
- **O-5** `CleanupE2eTicketsTest` covers the multi-organization persona (its `E2E-` requests in either organization are removed; a request without the prefix and its memberships are not) and that the no-organization persona is not an owner.
- None needed restructuring, so none is deferred.

**Accepted limitations and obligations carried forward.**
- **R1-4:** partner-support users must **not** inherit operator-wide `tickets.assign` to view or manage supported customer Tickets. That ability opens the whole queue, the organization filter (a list of every organization with a Ticket), CSV and bulk actions (no per-ticket `authorize`). It needs its own customer-specific grant and design review; it must not be met by widening `TicketPolicy::view` or by handing out `tickets.assign`.
- **Attribution is not access.** Nothing here makes any Ticket visible to a new person. Organization-level visibility remains Post-v1 (EPIC-010D D1).
- **E5:** a former member keeps access to, and sees the organization name on, their own Tickets. A removed requester's Tickets stay attributed to the organization.
- **No backfill.** A database that holds Tickets must be rebuilt (`migrate:fresh`) before the migration runs; it refuses and lists counts only. The shared development database is not rebuilt by this work.
- **R1-5** (partner-originated requests with a named contact) is the first time `submitted_by_id` differs from `user_id`; the column existed, so no data migration was needed. Built as [R1-5](./release-1.0-r1-5-partner-originated-requests.md) (`TicketAttribution` gained `requesterId`, so all three values come from the access layer).
- Not in R1-2: partner support access, on-behalf-of submission, Incident functionality, Ticket subtypes.

**Validation (isolated stack; the shared development database, staging and production were never touched).** `./dev check` binds to the running development containers (which mount the original checkout), so its steps were run individually against this worktree on a throwaway MariaDB 11.4.13: CLI self-tests (391 assertions), `git diff --check`, Pint (559 files), `npm run check` (typecheck, lint with no suppression growth, prettier, 115 vitest files / 1,988 tests, build), and the **full Pest suite: 4915 passed, 20 skipped, 0 failed**. Browser: the whole `tests/Browser` tree in legacy mode, **220 passed**, 1 skipped, and the pilot spec in a server verified to be in pilot mode, **`1 passed`**, with the single-test reporting contract of the hosted pilot job intact (the matrix is inside that one test; a spec that cannot detect pilot mode fails, it does not skip). Two specs failed in that legacy run, neither touched by R1-2: `projects-drawer.spec.ts:428` (a drawer-layout frame check) fails identically on a clean `origin/main` (`79340a9`) in the same container, and a `customer-shell.spec.ts` keyboard test is intermittent (it passed in the earlier runs, and the same account-menu keyboard tests fail under repetition on clean `origin/main` too); `time-migration.spec.ts:350` (a 3 px timer-pill shift) failed in two runs, also fails on clean `origin/main` and passed in the last, so it is font-metric dependent in the container. These are container rendering effects, not regressions; hosted CI is the arbiter. Hosted CI had not run when this was written.
