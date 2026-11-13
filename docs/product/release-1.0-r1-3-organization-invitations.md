# Release 1.0: R1-3 Organization-bound invitations (MO-2A2, design record)

**Status:** **design reviewed and reconciled, 2026-11-10 (independent Opus design review: SAFE AFTER TARGETED DESIGN REVISIONS; blocking findings B1 to B4 and the refinements resolved, section 0, map in section 17). R1-3A (the foundation) was implemented on 2026-11-11 on `feature/r1-3-organization-invitations`, UNCOMMITTED, awaiting the independent Opus R1-3A security review; section 18 records what was built, the four final corrections applied at implementation, the deviations and the evidence. The first review of that work found a lock-order conflict in the revocation hooks (18.3); the independent deadlock review was accepted and the owner approved the remediation **O4 + R4** on 2026-11-11 (hooks never write the uniqueness slot, lock the actor and the invitations before the mutation, and advance the permission-cache epoch before they do). It is implemented and recorded in 18.3 to 18.8, and sections 4.1, 6.2 and 9 below are rewritten to match; it awaits a second independent review. R1-3A was committed as `d49921b` after the second independent review. **R1-3B (registration integration and legacy retirement) was implemented on 2026-11-11, independently reviewed and committed as `886a241`: section 19.** **R1-3C (the operator Invitations card and routes, the browser journey, `e2e:cleanup-invitees`, the documentation and preflight reconciliation) is implemented, UNCOMMITTED, was reviewed independently (SAFE AFTER TARGETED REMEDIATION), and the accepted remediation (Referrer-Policy fixed at two layers, cross-organization Resend and cleanup-race coverage, stronger browser assertions, a 30-an-hour issuance limit, CI confirmation) is implemented, still UNCOMMITTED and awaiting the closure review: sections 20 and 20.10.** Nothing is pushed, deployed or migrated outside the disposable test databases.** Sections 4 to 7, 9 and 12 to 15 were rewritten by the reconciliation; the rest is the original draft with small additions.
Written against `main` at `c512f69` (PR #61 merged, post-merge CI green). Release 0.9 staging is untouched.
R1-3 is the owner plan's name for the roadmap's **MO-2A2**. Neither name is changed (the bridge is in the [R1-2 record, section D](./release-1.0-r1-2-ticket-attribution.md#d-mo-4a-reconciliation)).

**Labels used below.** **[Approved]** = already implemented or owner-ruled; this record only relies on it. **[Proposed]** = new in this record; it is a recommendation until the owner rules on it (section 12).

**One-sentence goal.** An authorized operator invites a *new* person into *one named organization*; the invitation is a protected, expiring, revocable, single-use credential; accepting it atomically creates the account **and** an ordinary (never administrator, never approved) membership.

---

## 0. Review adjudication (read first)

The independent Opus design review returned **SAFE AFTER TARGETED DESIGN REVISIONS**. The architecture of section 3 (separate `organization_invitations` entity, explicit organization binding, hashed tokens, encrypted queued mail, seven-day lifetime, ordinary unapproved membership, operator-only issuance, existing-account and customer-administrator work deferred, legacy organization-less issuance **and acceptance** retired) is **accepted**. The 14 decisions of section 12 are accepted, with revised **D8** and **D12**. Four blocking findings (B1 to B4) and the refinements below are **resolved in this revision**, in the sections named in the map in section 17. The owner's rulings of this adjudication also: accept shared-hosting access-log exposure of the token as a documented residual (no tokenless redirect flow in this package), and keep the A/B/C package structure, with an independent Opus security review **before A is committed**.

> **Provenance note.** This revision was reconciled from the owner's adjudication, supplemented by the session-memory summary of the review (which supplied the cache-write deadlock behind B3, the `ShouldQueueAfterCommit` requirement and the absence of an email normalizer). The review's full text and its finding labels (M1, M2, L1 to L6) were not available, so the refinements are mapped **by topic** (section 17) rather than by label. Confirm the mapping in the consistency review.

**Final consistency corrections (this revision).** The hooks take **no organization lock** (the narrower approach), authority loss is detected by a **before/after comparison of the table-based predicate** with a reinstated-hook for permanence, and a concrete cycle between suspension and acceptance (through the `users.invited_by` foreign key) was found and fixed by a **first-statement shared lock on the issuer's user row** (6.2). The global lock order, the pair-by-pair table and the stated limit of the argument are in 6.2.3. C1 and C2 are approved (12.1).

**Final corrections applied at R1-3A implementation (2026-11-11, owner).** (1) Secondary-index maintenance and foreign-key checks take locks beyond the primary-key record locks the row-level argument is about (6.2.2, 6.2.3). (2) A revocation sweep never turns an already-expired invitation into a revoked one, including when it expires between selection and update (6.2.2). (3) The `APP_KEY` statement accounts for `APP_PREVIOUS_KEYS` (5.6). (4) `OrganizationMembership::addFromInvitation()` is internal to the acceptance orchestrator and does not accept "some transaction is open" as proof that the locks were taken in the canonical order (4.3, 7.2).

**What changed in substance.** (1) `live_email` is the *slot holder*, released by issuance, not a proxy for "unexpired" (B1). (2) Pending invitations are **permanently revoked** when their issuer is suspended or loses membership-management authority through any application path (B2). (3) Acceptance asks a **table-based** predicate, `OrganizationAuthority::isMembershipManager`, never the permission cache (B3). (4) The unrestricted membership method is replaced by an **invitation-derived** one that revalidates everything it relies on (B4). (5) Several overstated claims are corrected (L6).

---

## 1. Current-state findings and constraints

### 1.1 What the invitation flow does today **[Approved, implemented]**

| # | Fact | Where |
|---|---|---|
| F1 | An invitation is **email + plaintext token** (`Str::random(64)`, unique column), `invited_by`, `status` enum `pending/accepted/expired`, `expires_at` (48 h), `accepted_at`. **It carries no organization.** | `Models/Invitation.php`, `2026_03_25_054944_create_invitations_table` |
| F2 | The token is stored **in clear** and the mail is queued with the `Invitation` *model* (`SerializesModels`: only the id travels; the worker re-reads the row and reads `->token`). Hashing the column therefore breaks the mail unless the token travels another way. This is the **H10** decision ([0.9 §10.1](./release-0.9-scope-and-architecture.md#101-independent-review-decisions-h5-to-h10): "an encrypted queued notification carrying the token, or synchronous sending"). H10 is **not ratified**. | `InvitationNotification`, `InvitationService::invite` |
| F3 | `invite()` is **not atomic**: it expires earlier pending rows for the address, then inserts, with no transaction, no lock and no unique constraint, so two simultaneous invites can leave two live invitations. | `InvitationService::invite` |
| F4 | Issuance authority is the global permission `users.invite` (`POST /invitations`, `can:users.invite`). It is organization-blind, and any role (including a custom role) can carry it. Only `operator` holds it by default. | `routes/web.php:60` |
| F5 | Inviting an address that already has an account is **not refused**; acceptance refuses it (`User::where('email')->exists()`) and then expires the invitation in a *second* statement outside the failed transaction. | `acceptAtomically` |
| F6 | Acceptance (password or SSO) locks the invitation row `FOR UPDATE`, creates the user, assigns the built-in `user` role, writes the password history or social account, marks `accepted`, all in one transaction. A suspended account's email is "taken" and so is refused. The invitation page and the "unknown, expired, accepted" answers are one safe page (no oracle). | `InvitationService`, `InvitationController`, `account-lifecycle.md` |
| F7 | **Acceptance creates a customer with no organization.** Since R1-2 a customer with no membership gets `NOT_LINKED` and **cannot raise a request** (`ServiceRequestAccess`). The live invitation flow is therefore a dead end for the one thing a customer is for. This is the product gap R1-3 closes. | R1-2 section B/C, `ServiceRequestAccess::soleOrganization` |
| F8 | There is **no audit** of invitations (no `activity()` call anywhere in the flow). | `InvitationService` |
| F9 | SSO acceptance exists but is **deferred for customers** in `closed` and `pilot` modes (`CustomerPilotGate::ssoDeferred()` is true for every mode except `legacy`, honoured only in `local`/`testing`); the invitation page offers the password form only. | `CustomerPilotGate`, `SocialiteController` |
| F10 | The invitation routes (`invitation.show`, `invitation.register`) are guest-only, allow-listed for customers in `ACCOUNT_ROUTES`, and have **no throttle**; the token is in the URL path. | `routes/web.php`, `CustomerPilotGate` |
| F11 | Provenance: `users.invitation_id` (FK to `invitations.id`, SET NULL) and `users.invited_by` (SET NULL). The admin user page reads `invitation.invitedBy`. | `User`, `Admin/UserController`, `admin/users/show.blade.php` |
| F12 | The SSO invitation intent stores the **plaintext token in the session context** (`invitationToken`), and `sso.redirect` (with `?invitation=`) has no throttle and no response-header protection. | `SocialiteController` |
| F13 | The application configures **no trusted proxies**, so `$request->ip()` is the immediate peer; the existing `login`/`two-factor` limiters rely on it. | `bootstrap/app.php`, `FortifyServiceProvider` |
| F14 | Every application path that changes roles or permissions goes through one class, `AccessGrants` (`syncUserRoles`, `syncRolePermissions`, `deleteUnassignedRole`, `guardedGrantChange` for `roles:sync`), each inside `AccountAdministrators::guard` (first lock: the `users.manage` permission row); suspension runs through `AccountLifecycleService::suspend`, also inside the guard. `RoleController::store` calls `syncPermissions` directly on a role that has no holders yet; nothing grants direct user permissions. | `AccessGrants`, `AccountLifecycleService`, `RoleController` |
| F15 | The browser suite cleans **tickets** (an untargeted `e2e:cleanup-tickets` after all workers) and **tasks** (per-test tracking). Nothing removes users, memberships or invitations. | `tests/Browser/support/global-teardown.ts`, `e2e-fixtures.ts` |

### 1.2 What the organization side gives and demands **[Approved]**

- `OrganizationMembership` is the **only writer of `organization_members`** (a test, `OrganizationMembershipTest` "leaves no other writer", fails if any other file writes the table, with two named exceptions: `CrmService` promotion and `ProjectService`). Every method authorizes `OrganizationPolicy::manageMembership` (an **active** account holding `portal.operator` **and** `crm.manage`), takes the **organization row `FOR UPDATE` as the first statement of its own transaction**, reads the membership under lock, audits in the same transaction, and must be **called at the top level, never inside an outer transaction**. `add()` refuses a suspended account and an existing member and never writes an approval.
- Lock order for membership mutations: **organizations → organization_members**. R1-2's `ServiceRequestAccess` uses the same order (shared locks) inside the ticket transaction.
- `role = admin` grants nothing; an *approved* administrator is one definition in `OrganizationAuthority` and **no route, policy or screen reads it** (MO-2A1). **MO-2B's mandatory requirement** (an operator-eligibility change must never silently restore delegated authority) is recorded there and is **not** needed by R1-3, because R1-3 honours no approval.
- Management relationships grant no access (MO-1A). The tenant scope for organizations keys on `portal.operator`; `organizations.*` routes are `can:crm.manage` and **operator-only for customers** (denylisted by `CustomerPilotGate`; unlisted routes are denied).
- Tenant data scoping is **policy-based** (ADR-005 Amendment 2); membership is the proof of "member now" for Ticket creation (R1-2). A new member needs **no further grant** to raise a request for their organization, and sees only their own tickets (owner-only visibility, unchanged).

### 1.3 Release-data ruling **[Approved, R1-0]**

No authoritative production or customer data exists; staging is disposable; Release 1.0 is fresh-install and rebuild-first; **no rollback to 0.9, no N-1 for the 0.9 build, no mandatory rehearsal candidate**. A migration may refuse a populated table. Still required: `database:verify` with `database/profile/foreign-keys.json` (every new foreign key is added to it), fresh-migration verification, hosted CI, and the security and tenant-isolation tests. **None of the retired 0.9 rollback gates is revived here.** Pinned migrations (the `invitations` create migration is SHA-pinned in `database/rehearsal/baseline.php`) must not be edited.

### 1.4 Constraints that shape the design

1. The membership write must be **inside the acceptance transaction** (atomic) yet the writer service says "top level only, operator actor only". That seam is the central design question (section 4.3).
2. A hashed token cannot be recovered, so a "resend" can only be a **new token**.
3. The R1-2 customer path (`tickets.create` for the built-in `user` role, sole-organization nomination) already works for any member; R1-3 adds **no permission and no role**.
4. The customer pilot allow-list pins **route names**; the new public behaviour should reuse `invitation.show` / `invitation.register` so the allow-list does not change.

---

## 2. Recommended minimum implementation scope **[Proposed]**

**In R1-3**

1. A new table **`organization_invitations`** and its model, factory and service (section 4).
2. **Operator-only issuance, resend (as supersede) and revocation**, on the organization page, authorized by `manageMembership`. New people only.
3. **Hashed token** (closes H10 for this flow) delivered by an **encrypted queued mail**.
4. **Password acceptance** that atomically creates account + role `user` + membership `member`. SSO acceptance rides the same core and stays deferred exactly as today (with a hash-only session context).
5. **Audit** of issue, supersede, revoke and accept in the activity log.
6. Retiring the organization-less operator invitation **including its SSO acceptance branch** (section 12, D2).
7. A small acceptance-page change: the page names the organization.
8. **Permanent revocation of an issuer's pending invitations** when the issuer is suspended or loses membership-management authority through an application path (section 6.2), and the table-based predicate `OrganizationAuthority::isMembershipManager` (section 4.4).

**Not in R1-3** (section 10 lists the dependencies): customer-administrator invitations (MO-2B, delegation stays off), inviting **existing** accounts, any invitation role other than member, bulk or CSV invites, an invitation inbox for the invitee, partner-originated invitations (MO-3), a generic invitation or approval framework, SSO enablement, MFA, email verification changes.

---

## 3. Model decision: new entity versus evolving `invitations` **[Proposed]**

| | **A. New `organization_invitations`** (recommended) | B. Evolve `invitations` |
|---|---|---|
| Organization binding | `organization_id NOT NULL`, FK `RESTRICT`, from birth | nullable column (legacy rows have none) or a refuse-if-populated migration plus `NOT NULL` |
| Token hash (H10) | `token_hash` from birth; no plaintext column, no dual lookup | add `token_hash`, then stop using and drop/blank `token`; legacy plaintext tokens linger or must be purged |
| Lifecycle | `revoked_at/by`, no ENUM | add a `revoked` state to a MariaDB ENUM; legacy code and tests assume three |
| Pinned artefacts | untouched (baseline migration, `baseline.php`, rehearsal fixtures that insert plaintext-shaped tokens) | edits the table that pinned artefacts describe, by a new migration, but fixtures and the `users.invitation_id` FK semantics change meaning |
| Provenance | `accepted_user_id` on the new row; `users.invited_by` still set; **no change to `users`** | one FK keeps working |
| Code | new service/notification; the legacy acceptance code is **deleted** from the live path | one service evolves; org-less representability must be defended forever |
| Cost | two tables briefly; legacy table inert, dropped later | fewer files, but a permanent "no-organization invitation" hole in the type |

**Recommendation: A.** The deciding point is the invariant "an invitation always names exactly one organization and carries no role", which is trivial to make true in a new table and has to be defended forever in an evolved one. Under R1-0 nothing needs preserving, so the usual argument for evolving (live data) does not apply. The legacy `invitations` table, its model and `users.invitation_id` stay as inert history; their removal is a later cleanup (section 10). *If the owner prefers B, the lifecycle, security and concurrency rules below are unchanged; only section 4.1 changes.*

---

## 4. Proposed data and service boundaries **[Proposed, revised by B1, B3, B4]**

### 4.1 Schema (one additive migration; no data touched)

`organization_invitations`

| Column | Rule |
|---|---|
| `id` | |
| `organization_id` | `NOT NULL`, FK `organizations.id` **RESTRICT** |
| `email` | `NOT NULL`. Always written, looked up and compared through **one** normalizer, `OrganizationInvitations::normalizeEmail()` (`Str::lower(trim())`, after the `email` validation rule and `max:255`). The same function is used for the existing-account check, the SSO provider-email comparison and the address a new account is created with. **No user-email normalizer exists today**: `users.email` is only protected by its `utf8mb4_unicode_ci` collation, which is case- and accent-insensitive, so equality must be decided **by the database** (`where email = ?`, never a PHP string comparison) while the normalizer keeps stored values canonical. This is a new, single function, not a reuse. |
| `token_hash` | `CHAR(64)` `NOT NULL` **UNIQUE**: lower-case hex SHA-256 of the token. Lookup is by hash equality through the unique index. A fast hash is correct because the token is ~380 bits of CSPRNG output (not a password). |
| `invited_by` | `NOT NULL`, FK `users.id` **RESTRICT** (provenance, like `admin_approved_by`) |
| `expires_at` | `NOT NULL` (issue time + 7 days, one constant) |
| `accepted_at`, `accepted_user_id` | nullable; `accepted_user_id` FK `users.id` RESTRICT and **UNIQUE** |
| `revoked_at` | nullable. **This is the state.** |
| `revoked_by` | nullable FK `users.id` RESTRICT: the human who caused the revocation (the operator, the re-issuing operator, the administrator who suspended the issuer or changed their roles). **NULL** when no human did (for example `roles:sync` from the command line). |
| `revoked_reason` | nullable `VARCHAR(32)` (not an ENUM). **C1, approved.** A closed set of stable codes, defined once as constants on `OrganizationInvitations`, accepted by the writer and refused if unknown: **`operator`** (an operator revoked it), **`superseded`** (a re-issuance replaced it), **`issuer_suspended`**, **`issuer_authority_lost`**, **`issuer_reinstated`** (6.2.1). Descriptive only, never a decision input: `revoked_at` is the state; a reason without `revoked_at`, or `revoked_at` without a reason, is read as revoked (fail closed). The audit entry for every revocation carries the **same code**, the actor and a `source`; the operator screen shows the code as a fixed label. Codes are never reused or renamed. |
| `live_email` | nullable; **the uniqueness slot** (B1, below). **UNIQUE (`organization_id`, `live_email`)**. |
| `created_at`, `updated_at` | |

There is **no `status` column and no `role` column.** State is derived: accepted if `accepted_at`; revoked if `revoked_at`; expired if `expires_at <= now`; otherwise pending. Time expiry needs no write and no job. No role column means no request, bug or future caller can request administrator rights through this table.

**`live_email` semantics (B1).** `live_email` marks **the row that currently holds the (organization, address) slot**. It is **not** a claim that the invitation is unexpired, and it is **not** cleared by time (nothing runs at the moment of expiry, by design). It is cleared on exactly these events: acceptance, an **operator's** revocation, supersession, and **release by a later issuance**. A **system revocation** (the hooks of 6.2: suspension, reactivation, a role or permission change) does **not** clear it (O4/R1): a hook writes neither `live_email` nor `organization_id`, because updating a column of the unique `(organization_id, live_email)` index makes InnoDB share-lock the parent organization row from inside a hook (18.3). A hook-revoked invitation therefore keeps the slot, exactly like an expired one, until the next authorized issuance for the address releases it under the organization lock. At most one row per (organization, address) can hold it, because the unique key allows one non-NULL value.

**Issuance releases the slot, under the organization lock.** In the issuance transaction, after the organization row is locked and the existing-account check passes:

1. Read the slot holder for (organization, normalized address) `FOR UPDATE` (zero or one row).
2. If it is **open** (not accepted, not revoked, not expired as of one `now()` taken once for the transaction): **supersede** it (`revoked_at`, `revoked_by` = the issuing operator, `revoked_reason = superseded`) and clear its slot; audit `superseded`.
3. If it is **not open** (expired, or already revoked by a hook and still holding the slot): **clear only its slot**. An expired row's state stays *Expired*: it keeps `revoked_at = NULL` and its history; a hook-revoked row keeps its `revoked_at`, reason and actor. No audit entry is needed for a release.
4. Insert the new row holding the slot.

So expiry followed by reissue works with **no scheduled cleanup**, an expired row is never relabelled "revoked", and the unique key is only ever the backstop (a caller that skips step 1 gets a duplicate-key error on `…live_email…`, classified in 7.2 as "an invitation was issued concurrently").

Four new foreign keys go into `database/profile/foreign-keys.json` (`organization_id`, `invited_by`, `revoked_by`, `accepted_user_id`), and `PreservationSchemaTest`'s pinned list gains the entries. The table is **not** a rehearsal candidate (R1-0).

### 4.2 Services

- **`OrganizationInvitations`** (new, `final`): `issue`, `revoke`, `findOpen(token)`, `accept` (the transactional core, shared by password and SSO), `revokeIssuedBy(array $invitationIds, string $reason, string $source, ?User $actor)` (the one primary-key revocation write of 6.2.2, called by the hooks), `reconcileIssuers(array|null $candidates, Closure $mutation, string $source, ?User $actor)` (the before/after wrapper of 6.2.1 that `AccessGrants` calls), `normalizeEmail`. **The only writer of `organization_invitations`** (a source-level test, 4.3). One private authorization seam for issuance, `authorize(User $actor, Organization $org)`, today `Gate::authorize('manageMembership', Organization::class)`; MO-2B would extend this one method.
- **`OrganizationMembership`** gains **one** method for acceptance, `addFromInvitation` (4.3).
- **`OrganizationAuthority`** gains `isMembershipManager(int $userId): bool` (4.4).
- **`OrganizationInvitationNotification`** (new, replaces `InvitationNotification` for this flow): section 5.
- A small **duplicate-key classifier** (7.2).
- The legacy `InvitationService`, `Invitation`, `InvitationNotification` and `InvitationController::store`/`register`, and the legacy invitation branch of `SocialiteController`, are **removed from the live path** (D2, section 7.3).

### 4.3 The membership seam, revised (B4)

`add()` cannot be used as is: it authorizes an operator actor, and the invitee is neither; and it must be called at the top level, whereas acceptance creates the user and the membership **in one transaction**. The review rejected an `addInvitee(lockedOrg, newUser, invitationId, inviterId)` that trusted its caller. The accepted shape is **invitation-derived**:

```
OrganizationMembership::addFromInvitation(int $invitationId, User $recipient): void   // @internal
```

It takes **two identifiers and nothing else**: no organization, no role, no inviter, no approval flag. Everything it relies on is **read from the database, under lock, inside the call**:

**Locking and validation sequence** (every step runs inside the caller's transaction; any failure throws and rolls everything back):

0. **Transaction and proof required:** `DB::transactionLevel() >= 1` **and** the orchestrator's lock proof for this invitation at this transaction level (step 2), else `LogicException`. Being inside some transaction is necessary and **not sufficient**.
1. **Nominate** (plain read of the invitation row by id): its `organization_id` and `invited_by`. This decides nothing.
2. **Require proof, do not take locks.** The method takes **no locks of its own** and trusts **no caller's transaction**: an arbitrary open transaction says nothing about which rows were locked or in what order, and a lock taken here late, after other rows, could close a cycle with a suspension. The canonical order, **issuer's `users` row (shared) → organization row `FOR UPDATE` → invitation row `FOR UPDATE`**, is taken only by the acceptance orchestrator `accept` (7.2), which records, in process-local state only it can set, that it holds those three locks for this invitation at this connection's current transaction level (`OrganizationInvitations::holdsAcceptanceLocks()`). The method refuses with a `LogicException` unless that proof holds: a caller that reaches it from any other place, or from a nested savepoint of the orchestrator's transaction, is refused. (The first lock is the 6.2.3 rule: the new user's `invited_by` foreign key will otherwise take it late.) *No consistent read may precede the orchestrator's locks:* its snapshot must begin after them. Re-reading the rows inside the call is a locking read of rows already held, so it takes no new lock and returns the latest committed state whatever the snapshot.
3. **Validate the locked invitation:** it exists; its `organization_id` and `invited_by` equal the nominated values (they are immutable); it is **open** (`accepted_at` and `revoked_at` null, `expires_at` in the future) and holds the slot (`live_email` equals `email`); the organization row exists.
4. **Issuer:** `OrganizationAuthority::isMembershipManager($invited_by)` is true now (B3; table-based, so it takes no lock and writes no cache row).
5. **Recipient (account eligibility), read from the table, not from the passed model:** it exists; it is **active** (`disabled_at` null); its email **equals the invitation's by database comparison** (`where email = ?`); it is **not already a member** of the organization (a locking read on the membership key).
6. **Write:** insert `organization_members` with `role = 'member'` as a literal and **neither approval column named**. There is no parameter that could change either.
7. **Audit** in the same transaction: `organization member added (invitation accepted)`, causer = the recipient, properties = invitation id, inviter id, organization.

The orchestrator then marks the invitation accepted (and clears the slot) in the same transaction. If something calls `addFromInvitation` and the invitation is never marked accepted, the invitation stays open but cannot add the same account twice (step 5), and the call cannot create a membership for any account other than the invited address.

**Single-writer guarantee, extended to the new path (source-level, like the existing fence).** Four tests, all scanning `app/`. They are a second line of defence: the first is the runtime proof of step 2, which makes the method unusable from anywhere but the orchestrator even if a fence were weakened.

| Fence | Asserts |
|---|---|
| existing | only `Services/OrganizationMembership.php` (and the two named existing files) writes `organization_members`: unchanged, still true because the new method lives in the same file |
| new | only `Services/OrganizationInvitations.php` writes `organization_invitations` |
| new | the **only caller** of `addFromInvitation` is `Services/OrganizationInvitations.php` |
| new | every public **mutating** method of `OrganizationMembership` calls `authorize()` first **except** `addFromInvitation` (allow-listed by name), so an unauthorized sibling cannot appear unnoticed |

Behaviour tests (A): refuses outside a transaction, refuses inside an arbitrary transaction without the orchestrator's proof, and refuses from a nested transaction level inside the orchestrator; the orchestrator takes the issuer-row, organization and invitation locks in that order (verified from the statement log of a real process); refuses an unknown, accepted, revoked, expired or superseded invitation; refuses a recipient whose email differs, who is suspended, or who is already a member; refuses when the issuer is suspended or no longer a membership manager; never produces `role = admin` or an approval; the whole operation rolls back if the audit write fails; lock order verified by a two-connection test.

### 4.4 Authorization, revised (B3)

| Who | Issue / resend / revoke | Basis |
|---|---|---|
| Operator (`portal.operator` and `crm.manage`, active) | **Yes**, any organization | `manageMembership` **[Approved policy, unchanged]**; checked in the controller first and again in the service |
| Customer, approved or unapproved organization administrator, partner administrator, holder of `users.invite` or `org.invite` alone | **No** | none of `role`, approval, `owner_id`, the managing-partner relationship or the global `org.*`/`users.invite` is an input |
| Future approved administrator (MO-2B) | not yet | the single `authorize()` seam; R1-3 builds no flag and no route |

**`OrganizationAuthority::isMembershipManager(int $userId): bool` (new).** True exactly when, **from the tables only** (never the permission cache, no Eloquent relation, no scope):

- the account exists and is **active** (`users.disabled_at` is null); and
- it holds **both** `portal.operator` **and** `crm.manage`, each **directly or through any role** (the same capability reading `AccountAdministrators::holds` already uses for `users.view`/`users.manage`; wildcard permissions are off and there is no `Gate::before`).

It is deliberately **not** `isOperator()` (which also counts the `operator` role *name*): the policy tests capabilities, so the predicate does. **Why tables and not the Gate (the deadlock, not only freshness).** `Gate`/`can()` goes through Spatie's DB-store permission cache, and a **cache miss inside an organization-locked transaction writes the cache row on the same connection**. `ServiceRequestAccess` runs `can('tickets.create')` inside the ticket transaction *before* it takes its organization shared lock, so a cache write held under the organization lock in acceptance can deadlock against it. A table read takes no lock and writes nothing. It is the authority **everywhere inside a locked transaction or a queue job**: acceptance, `addFromInvitation`, the sweeps of 6.2 and the notification's send-time check. **Issuance keeps the Gate** (`manageMembership`), because the actor there is a signed-in request user and that is the existing, approved boundary.

**Drift guard (a required test).** One parameterized test builds a matrix of accounts (built-in operator; custom role with both capabilities; each capability alone, by role and directly; the `operator` role name without the capability; suspended with both; a customer; no roles) and asserts for each that `isMembershipManager($user->id)` **equals** `Gate::forUser(User::find($id))->allows('manageMembership', Organization::class)`, with the permission cache cleared before each comparison. A change to either side that is not made to both fails it. *Option for the implementation to decide:* have `AccountAdministrators::holds` and this predicate share one private capability query; the drift guard is required either way.

---

## 5. Credential security and delivery (H10) **[Proposed, revised]**

1. **Generate:** `Str::random(64)`; **store only** `hash('sha256', $token)`. The plaintext exists in memory at issue, in the mail, and in the URL the recipient holds (and, for the SSO path, in the initiating request's query; see 7.3).
2. **Deliver:** the notification is `ShouldQueue` **and `ShouldBeEncrypted`** (Laravel 13 honours it for queued notifications: `SendQueuedNotifications` sets `shouldBeEncrypted`, verified in `vendor`). It carries the plaintext token and the invitation **id** as scalar constructor arguments, **not the model**. The `jobs` row (and, after three failures, the `failed_jobs` row) holds the payload encrypted with `APP_KEY`.
   *Why not synchronous sending:* the request would wait on SMTP (up to ~40 s with `MAIL_TIMEOUT`), and a send failure would surface after the invitation had committed.
3. **Dispatch strictly after commit (guaranteed two ways).** (a) The service dispatches **after** `DB::transaction(...)` has returned, never inside it. (b) The notification implements **`Illuminate\Contracts\Queue\ShouldQueueAfterCommit`** (confirmed in `vendor`: `SendQueuedNotifications` honours it). A bare `afterCommit` property is **not** relied on and neither is the connection setting: `queue.after_commit` is **`false`** on every connection in `config/queue.php`. So even a caller that dispatches inside an outer transaction cannot enqueue before commit. Required tests, against the real database queue: an issuance whose transaction rolls back leaves **no** `jobs` row; the worker never sees a row that is not committed. *Residual:* a process that dies between commit and dispatch leaves an invitation with no mail; the operator sees Pending and uses **Resend** (accepted risk R4).
4. **Send-time eligibility (defined).** `shouldSend()` returns **true** only when, read fresh from the tables: (i) the invitation row exists; (ii) it is **open** (not accepted, not revoked, not expired); (iii) `hash_equals(token_hash, hash('sha256', $token))`, so a superseded token is never mailed; (iv) its organization still exists; (v) **no account already has the address**; and (vi) `OrganizationAuthority::isMembershipManager($invited_by)` is true. Ineligible, including "no such row", returns **false** (mail dropped, not failed). A **database error is not caught**: it propagates, the attempt fails and the queue retries (the existing contract). Retry policy unchanged (three tries, 60 s then 180 s).
5. **Mail content:** names the organization and the inviter; both are user-authored and go through `MailText::escape` (EPIC-017 F-3). The expiry text comes from the same constant as `expires_at`.
6. **Residual risks, stated accurately (L6) and accepted:**
   - **Access logs.** The token is in the URL path, so shared-hosting web-server logs will contain it. **Ruled accepted for R1-3**, bounded by the token being short-lived (7 days), single-use and revocable, plus the page protections in item 8. **No tokenless or fragment-based redirect flow is added** unless a concrete issue makes it necessary.
   - **Where the plaintext can exist, completely:** the recipient's mailbox, the URL (browser history, server access logs), the encrypted queue payload while queued and in `failed_jobs`, and the SSO initiating request's query. It is **not** stored in the application's tables, the activity log, the application log, or the session (7.3). The earlier wording "never stored, logged or audited" overstated this and is replaced.
   - **`failed_jobs`** retains an encrypted payload until it is pruned or flushed; operate its retention like other credentials (a documented operator step, no new framework). The payload is encrypted with `APP_KEY`, and Laravel decrypts with the current key and then each key in `APP_PREVIOUS_KEYS` (`config/app.php` `previous_keys`). A rotation that **keeps the retired key in `APP_PREVIOUS_KEYS`** therefore leaves in-flight and failed encrypted mail readable and deliverable. A rotation that **drops the old key**, or an `APP_KEY` that is simply replaced, makes every payload encrypted under it undecryptable: those jobs fail permanently and their invitations must be reissued. Any `APP_KEY` rotation (owner decision H9; no procedure exists yet) must therefore keep the previous key in `APP_PREVIOUS_KEYS` until the queue and `failed_jobs` have drained or their invitations have been reissued. A test pins both outcomes with the framework's own encrypter.
7. **Replay and prefetch:** `GET /invitation/{token}` is a **read** and never consumes, so mail scanners and link previews cannot burn an invitation. Only the `POST` consumes it.
8. **Page protections.** On `invitation.show`, `invitation.register` **and the SSO initiation (`sso.redirect`) when it carries an `invitation` query**: `Referrer-Policy: no-referrer` (on the redirect response too, so the provider does not receive the token-bearing URL as a referrer) and `Cache-Control: no-store`; and a named rate limiter keyed by client IP (the `uncompromised()` password rule calls an external service per attempt, so an unthrottled `POST` is also a cost lever). The SSO limiter returns `Limit::none()` when there is no `invitation` query, so ordinary SSO login is untouched.
9. **Client-IP and proxy assumption (documented, not changed here).** The application configures **no trusted proxies** (no `trustProxies` in `bootstrap/app.php` or config), so `$request->ip()` is the immediate peer. The existing `login` and `two-factor` limiters already rely on that. On the shared host, if the web server forwards every request from one address, a per-IP limiter degrades into a **global** limiter (a legitimate invitee could be throttled by others' traffic) and gives no per-client protection. R1-3 therefore (a) sets generous per-minute limits, (b) records this assumption in `notification-delivery.md`/the runbook next to the existing login limiter, and (c) adds a **staging verification step** (observe the peer address the limiter keys on). Changing the proxy configuration is a host decision outside this package.

---

## 6. Lifecycle and rules **[Proposed, revised by B1 and B2]**

States are derived (4.1): **pending → accepted | revoked | expired**. Terminal states never return to pending; reinvitation is a **new row**.

### 6.1 Actions

| Action | Rule |
|---|---|
| **Issue** (operator) | One transaction. **First statement: shared lock on the acting user's row; then the organization row, exclusively** (6.2.3). After the lock, besides the Gate used at the controller, the service re-checks `isMembershipManager($actor)` from the tables (a Gate result can be stale for the cache's lifetime). Refuse: an address that already belongs to **any account**, active or suspended (the operator is told to use *Add member*; operator-only message, never shown to a customer); an organization that no longer exists (404). Then the **slot release of 4.1 (steps 1 to 4)**, the insert, the audit; dispatch the mail **after** the transaction (section 5.3). |
| **Resend** | Issue for the same address: a new token and expiry; an open predecessor is superseded, an expired one only has its slot released. The UI offers it on a pending or expired row. |
| **Revoke** (operator) | Acting user's row S, then the organization lock (6.2.3); `revoked_at`, `revoked_by`, `revoked_reason = operator`; slot cleared; audit. Idempotent on a terminal row (no second audit). Takes effect on the **next read**: an acceptance that has not locked the row is refused; one that already committed is not undone (the operator suspends or removes the member instead). |
| **Expire** | Time only; no write, no job. Expired rows stay listable and can be resent. |
| **Accept** | Section 7. |
| **Same address, two organizations** | Allowed (independent rows). The **first** acceptance creates the account; the second finds the address taken and is refused like any dead link. Inviting an existing account later is deferred (section 8). |
| **Retention** | Rows are never deleted by the application. |

**Audit.** Activity-log entries on the organization (subject), in the same transaction as the change: `organization invitation issued`, `... superseded`, `... revoked` (with the reason), `organization member added (invitation accepted)`. Properties: invitation id, the invitee's address, the inviter id, expiry, and for system revocations the cause and source; **never the token or its hash**. The address is operator-only data.

### 6.2 Issuer authority loss (B2): detection, permanence, lock ordering

**Ruling.** Pending invitations are **permanently revoked** when their issuer is suspended, and when the issuer **loses effective membership-management authority** through an application path. "Permanently": nothing ever clears `revoked_at` (a source-level test and a mutation probe pin it), so reactivating the account or restoring the role cannot revive an invitation; the operator issues a new one.

#### 6.2.1 What counts as "authority", and the one rule that detects its loss

"Authority" is one predicate, `OrganizationAuthority::isMembershipManager($userId)` (4.4): active **and** holding `portal.operator` **and** `crm.manage`, directly or through any role, from the tables. Its value for an account changes only when the account is suspended or reactivated, or when its effective capabilities change. The rule, applied by every covered path inside **its own existing transaction**:

> If a covered mutation changes `isMembershipManager` for an account that issued open invitations, in **either direction**, **all of that account's open invitations are revoked** in the same transaction.

Loss revokes with `issuer_suspended` (suspension) or `issuer_authority_lost`. A change **upward** (reactivation, or authority restored) revokes with `issuer_reinstated`: an invitation that is open at that moment was issued before the loss (a non-manager cannot issue), or in a race with it, and must not come back. This second half is what makes revival impossible even where the original revocation missed an invitation committed during the race (6.2.4).

**Detection is a before/after comparison of the predicate, not an inspection of what was edited.** The mutation may be a role assignment, a role's permission list, or a built-in synchronization; the predicate answers the only question that matters (does this account still hold both capabilities, and is it active?). Inside the guard transaction, after the `users.manage` lock is held (O4 + R4: **the invitations are locked before the mutation**, 6.2.3):

1. The acting user's row is **share-locked** (R3: the revocation writes `revoked_by`, a foreign key that makes InnoDB share-lock that row).
2. If the mutation reads the permission mapping (a role's permission list, the synchronization, which may create permissions), the **permission-cache epoch is advanced** (R4), inside this transaction.
3. The affected invitations are **nominated** by a plain read (an explicit account's open invitations, or every open invitation when the holders are not enumerated: a role edit, the synchronization) and **locked by primary key, ascending** (R2), before any role or permission table is touched.
4. `before` = `OrganizationAuthority::membershipManagerIds($candidates)`, a set version of the predicate (one table query, same matrix and drift guard as 4.4), where `candidates` are the explicit account or the distinct issuers of the locked invitations.
5. Run the mutation.
6. `after` = the same query (it sees the mutation's own uncommitted writes).
7. For each candidate whose membership in the set changed, revoke that account's open invitations **from the rows already locked** (6.2.2), and audit.

| Path | Existing transaction | Candidates | Detection | Reason code |
|---|---|---|---|---|
| `AccountLifecycleService::suspend` | `AccountAdministrators::guard` | the target | unconditional (the active flag just changed; if they issued nothing, the revoke matches no rows) | `issuer_suspended` |
| `AccountLifecycleService::reactivate` | its own `DB::transaction` | the target | unconditional, **after** `disabled_at` is cleared | `issuer_reinstated` |
| `AccessGrants::syncUserRoles($user, $roles, ?User $actor = null)` (sole caller `UserController::update`) | `guard` | that user | before/after | `issuer_authority_lost` or `issuer_reinstated` |
| `AccessGrants::syncRolePermissions($role, $permissions, ?User $actor = null)` (sole caller `RoleController::update`; built-in roles refused) | `guard` | **all** issuers of open invitations: a role edit changes every holder of the role, and the holders are not enumerated | before/after per issuer | per issuer |
| `AccessGrants::guardedGrantChange($mutation, string $source, ?User $actor = null)` (sole caller `BuiltInRoleSynchronizer`, i.e. `roles:sync`) | `guard` | all issuers of open invitations | before/after per issuer | per issuer; `revoked_by` NULL when run from the command line |
| `AccessGrants::deleteUnassignedRole`; `RoleController::store` | `guard` / none | none | a role with no holders changes nobody's authority | none |

**Multiple issuers.** A role edit can change several issuers at once. Every open invitation is locked (ascending) first; the changed issuers' still-open rows are then revoked by **one statement** per distinct reason over an ascending id list; the audit records one entry per revoked invitation. Suspension and reactivation take the same actor-then-invitations order before their write.

#### 6.2.2 The revocation write, and why it takes no organization lock

```
UPDATE organization_invitations
   SET revoked_at = :now, revoked_by = :actor|NULL, revoked_reason = :code, updated_at = :now
 WHERE id IN (:ids) AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > :now
```

**It never writes `live_email` or `organization_id` (O4/R1).** `:ids` are rows that were already **locked by primary key, ascending** (`select ... where id in (...) order by id for update`, which returns the **latest committed** row for each) before the mutation ran (6.2.3, R2). The ids are nominated by a plain read of the not-yet-accepted, not-yet-revoked invitations of the candidates, **without** an expiry condition: it only nominates keys. After the mutation, **one instant `:now` is taken**, a row is revoked only if it is open as of `:now` (not accepted, not revoked, `expires_at > :now`), and the `UPDATE` repeats those conditions with the same `:now`. **An already-expired invitation is therefore never relabelled revoked**: not when it was expired at selection, and not when it expires between the selection and the update, because expiry is judged on the locked row at one instant and the statement cannot see a different instant. An invitation accepted or revoked in the meantime is likewise not touched (it cannot change while the row is locked; the conditions are belt and braces). The audit has one entry per row actually revoked; the statement count is one per distinct reason, normally one.

**What this does and does not lock (revised).** The write touches `revoked_at`, `revoked_by`, `revoked_reason` and `updated_at`: none of them is part of a secondary index except `revoked_by`, a foreign key, for which InnoDB share-locks the referenced user row of the actor. That is why the actor's row is share-locked **before** the invitations (R3). Because the write changes neither `organization_id` nor `live_email`, **no entry of the unique `(organization_id, live_email)` index is modified, and InnoDB takes no foreign-key lock on the organization row** (this was the first mechanism of the 18.3 finding; a raw probe confirms the organization row is free while a hook updates a revocation). The nominating read and the locking read are primary-key access: record locks on exactly the nominated rows, no range or gap lock on the `invited_by` index and no organization lock. Audit per invitation: `organization invitation revoked` with the reason code, the issuer id, the source (`account suspension`, `account reactivation`, `user roles changed`, `role permissions changed`, `roles:sync`), and the actor (the administrator who suspended or edited; none for a command-line sync). `revoked_by` is the same actor.

**What a revoked row keeps.** Its slot, until a later issuance for the address releases it (4.1). That is what lets the hook leave the unique index alone, and it is harmless: acceptance and the seam refuse a revoked row whatever it holds, and issuance already releases any holder that is not open.

#### 6.2.3 Lock ordering (O4 + R4: the remediation of the 18.3 deadlocks)

**History, stated plainly.** The first version of this section argued that a hook "never asks for an organization lock while holding an invitation lock", so it takes none and cannot be a link in a cycle, and that its `UPDATE` took only primary-key record locks. The two-connection tests on MariaDB 11.4.13 refuted the second premise (18.3): clearing `live_email` made InnoDB share-lock the parent organization row, and the hook's primary-key-first access met issuance's index-first access on the same row. A review of that finding then found a third mechanism (a role detach's next-key lock on the `model_has_roles` index against acceptance's insert of the new account's role) and, with a three-transaction test, a fourth (below). The remediation is **O4 + R4**, approved by the owner on 2026-11-11. Organization locks in hooks (the rejected option O1) were shown to form cycles in every placement and were not adopted.

**A concrete cycle found earlier, and its fix.** Inserting the new account in acceptance sets `users.invited_by`, and InnoDB checks that foreign key by taking a **shared lock on the issuer's `users` row**. Suspension holds that row **exclusively** (it locks the target) and then needs the invitation row that acceptance already holds. Acceptance holds the invitation and then needs the issuer's row. That is a cycle. The fix is an ordering rule: **every transaction that will insert a row referencing a user takes a shared lock on that user's row as its first statement.** The hooks follow the same rule for `revoked_by` (R3).

**The four rules of the remediation.**

- **R1 (O4), hooks never write the slot.** A revocation by a hook writes neither `live_email` nor `organization_id`, so no entry of the unique `(organization_id, live_email)` index changes and InnoDB takes no foreign-key lock on the organization row (6.2.2). A hook-revoked row keeps its slot until the next authorized issuance releases it under the organization lock (4.1). This removes the first and the second 18.3 mechanisms: the hook no longer touches the unique-index entry that issuance's slot lookup locks first.
- **R2, invitations are locked before the mutation.** For the guard-class hooks, the affected invitations are nominated by a plain read and **locked by primary key, ascending, before any role or permission table is touched**; the before/after predicate comparison and the revocation then run from the locked rows, atomically with the mutation. No other transaction can hold an invitation row while waiting for a lock the hook took for its mutation (this removes the third mechanism: the role-detach next-key lock is taken only after the invitations are held).
- **R3, the actor's row first.** Where the revocation writes `revoked_by`, the acting user's row is share-locked **before** the invitations, as in issuance, revocation and acceptance. Suspension and reactivation take it before their invitation locks too.
- **R4, the permission-cache epoch is advanced first.** For mutations that read the permission mapping (a role's permission list; the built-in synchronization, which may create permissions) the epoch is advanced (`forgetCachedPermissions()` replaces the epoch row inside the transaction) **after the actor lock and before the invitation locks**. A user's role assignment does not read the mapping and does not advance it. The existing after-commit and after-rollback invalidation is unchanged.

**What O4 + R4 resolves, and what it does not.** O4 + R4 resolves the **reproduced row-level cycles**: the hook's hidden foreign-key lock on the organization row, the primary-key-first against index-first access to one invitation row, the role-detach next-key lock against acceptance's role insert, the suspension against membership-work cycle, and the three-transaction H → T → I cycle through the epoch's cache row. It is **not** a guarantee of freedom from every InnoDB gap-lock cycle (limitation 2 below names the one that is known and documented).

**Acquisition sequences** (S = shared, X = exclusive; each lock is held to commit):

| Transaction | Sequence |
|---|---|
| Suspend (`guard`) | `users.manage` permission row X → target user row X → acting administrator's row S → invitation rows X, ascending → revocation write |
| Reactivate (not in `guard`) | target user row X → acting administrator's row S → invitation rows X, ascending → revocation write |
| User's role assignment (`guard`) | `users.manage` X → acting administrator's row S → invitation rows X, ascending → role-assignment rows → revocation write |
| Role's permissions change, `roles:sync` (`guard`) | `users.manage` X → acting administrator's row S → **epoch row X** (advance) → invitation rows X, ascending → role/permission relation rows (permission mapping loaded under the private epoch key) → revocation write |
| **Issue** (operator) | acting user's row **S first** → organization row X → slot-holder row X (`FOR UPDATE`) → insert (its FK parents are already held) |
| **Revoke** (operator) | acting user's row **S first** → organization row X → invitation row X |
| **Accept** | **issuer's row S first** → organization row X → invitation row X → inserts for the new user (FK parents: the issuer row already held, the built-in `user` role row S, the new user's own rows) → invitation update |
| Existing membership mutations (MO-2A1) and ticket creation (R1-2) | unchanged: organization row → membership row, with FK checks on user rows afterwards |

No hook takes an organization lock, and no transaction takes an exclusive user row after it has waited for an invitation row.

**Argument (row level).** A cycle needs a transaction that holds a lock another needs while waiting for a lock the other holds. Take the hooks, the only new waiters:

1. A guard-class transaction waits for **only** the permission row, the epoch row (when the row is being created by another transaction), the actor's and target's user rows and the invitation rows, **in that order, and takes no lock for its mutation until it holds every invitation row**. Guard-class transactions are serialized by the permission row, so two of them never wait on each other's invitation rows; reactivation takes the same ascending order.
2. Every transaction that can **hold an invitation row** (issue, revoke, accept) has already taken its user row and its organization row, and from then on waits only for rows it creates itself, the new user's unique keys, the built-in `user` role row in S mode (nobody locks it X), and the organization row it already holds. The hook therefore waits for them and they never wait for the hook.
3. The acceptance transaction **never touches the permission cache** (verified: `assignRole` on an existing user performs a plain `roles` read and an attach). A test asserts, from the statements the process really ran with the **database** cache store, that no statement against the `cache` table runs inside it.
4. Existing organization-first paths take user rows (S) after the organization row, never wait while holding an invitation row, and so cannot close a cycle with a hook.

**The three-transaction cycle, and why R4 prevents it.** H = a guard-class hook that holds invitation rows and, in its mutation, **loads the permission mapping inside its transaction** (a role's permission list; the synchronization creating a permission); T = a ticket creation whose authorization check runs inside its transaction **before** its organization lock and, with a cold database cache, writes the cache row for the current epoch; I = a re-issue for an address H holds. Without R4: T holds the cache row, H (holding the invitation) needs the same cache row for its own load and waits for T; I holds the organization row and waits for H's invitation row; T then needs the organization row I holds: **H → T → I → H**. It was reproduced on the real server with three processes. With R4, H's load uses **its own private epoch key** (the key carries the epoch that H just advanced, which no other transaction can read before H commits), so H never needs a row T holds; and the only shared row H takes before holding invitations is the epoch row itself, for which it waits for T **only when the row is being created**, at which point H holds no invitation and nobody waits for it. The real cache statement stream was verified rather than assumed (below).

**The cache statement stream after the early advance (from the real statements, database store, cold cache, epoch row present and missing, for both a role's permission change and the synchronization creating a permission).** In order: `users.manage` row `FOR UPDATE`; actor row `LOCK IN SHARE MODE`; the epoch upsert; the framework's `DELETE … WHERE key IN (legacy key, its companion)`; the invitation rows `FOR UPDATE`; the epoch read (a plain read: no lock); a read of the copy under the **private** key (plain); an upsert of the copy under the private key; the role/permission writes; the epoch upsert again and the legacy-key delete again (the same rows the transaction already holds); the revocation `UPDATE`. A test asserts that every cache statement inside the transaction names only the epoch row, a legacy key, or a key suffixed with an epoch other than the one active when the run began. After the invitation locks the transaction therefore takes **no lock on any row another transaction can name**, except what it already held.

**Pairs examined (each run in both orders, with the second operation observed waiting where the lock structure says it must; every operation ends in exactly one transaction, none a deadlock or lock-wait victim, none retried).**

| Pair | Result |
|---|---|
| **Suspension vs acceptance** (issuer is the target) | Serialize at the issuer's row (S vs X), before either holds anything else. If suspension commits first, acceptance is refused on the committed state; if acceptance holds S first, suspension waits for it. |
| **Authority loss vs acceptance** (role, permission, synchronization; also the role change of the account with the highest id) | The hook locks the invitation first or waits for acceptance's invitation row, before taking any role lock. The loss and its revocation commit atomically; acceptance either linearizes before the change (the hook then finds an accepted row) or is refused. |
| **Issuance vs a hook** (including the re-issue of the address the hook just revoked, with the hook frozen before or after its write) | The hook holds invitation rows only; issuance waits for them after taking its own user and organization rows. A hook-revoked row that kept its slot is released by the re-issue. |
| **Issuance vs suspension of the issuer; revocation vs suspension of the revoking operator** | First-statement S lock on the actor/issuer row orders them entirely before or after. |
| **Suspension vs membership addition, approval, and Ticket creation** (the added tests; the review's second finding) | The hook writes no organization-keyed column, so it takes no lock on the organization row that the membership work or ticket holds. Both orders and two freeze points each. |
| **A hook whose actor is being reactivated** (R3) | Reactivation waits at the actor's row instead of closing a cycle with the hook's `revoked_by` foreign-key check. |
| Issuance vs revocation; acceptance vs revocation; acceptance vs reissue; two acceptances (same token; same address in two organizations); reactivation vs a guard-class hook | Unchanged from the first version of this section and re-run. |
| **H → T → I** (above), for a role's permission change and for the synchronization creating a permission, with the epoch row present and missing, with the hook frozen first and with T frozen first | No deadlock, no lock wait, no retry. With the epoch row missing and the hook first, T fails with the pre-existing first-use defect (below), which is not a lock-order problem. |

**What remains, stated plainly. No global deadlock-freedom claim is made.**

1. **The claims are row-level plus the statement streams above.** InnoDB also takes secondary-index, foreign-key and gap locks for statements the argument does not model (issuance's slot lookup and insert on the unique `(organization_id, live_email)` index; acceptance's inserts). If a cycle does arise, InnoDB aborts exactly one participant (error 1213) atomically. Acceptance, issuance and revoke retry up to three times (`ATTEMPTS`); **guard-class transactions do not retry** (`AccountAdministrators::guard` runs `DB::transaction` without attempts, and this record does not change it), so an aborted suspension or role edit would fail the request with nothing committed and the administrator would repeat it. No such abort occurred in any covered interleaving.
2. **Gap locks on the cache table: a residual that R4 does not remove.** The library's `delete from cache where key in (…legacy keys…)` (its `forget`) takes a gap lock in the cache table's key index and keeps it to commit; it is taken once before the invitation locks and repeated later on the same rows (confirmed with a raw two-session probe: the insert of a key next to two deleted absent keys timed out, error 1205, until the deleting transaction ended). **Whether another transaction's insert falls inside that gap depends on which other rows exist and on the random epoch tokens**: a committed, or not yet purged delete-marked, suffixed key below the inserted key bounds the gap and a key above it does not. A cache test on this store therefore has to seed a bounding row to be deterministic (DB-L3 does). Two further consequences:
   - **Expired permission-cache copies.** The permission copy has a 24-hour lifetime. When a transaction **reads an expired copy**, the framework's `DatabaseStore` calls `forgetManyIfExpired`, which deletes the expired key and its absent companion key and so takes the same kind of gap lock, **inside that transaction**. A hook's private-key store (R4) can fall inside such a gap. If the transaction that read the expired copy is a Ticket creation whose **first** permission-cache load happens inside its transaction, **before** its organization lock, the three-transaction shape re-forms: the Ticket holds the gap lock, the hook waits for it while holding the invitation rows, the re-issue holds the organization row and waits for the invitation row, the Ticket then waits for the organization row (**H → T → I → H**). The independent R1-3A review's raw probe of the expired-copy variant timed out (1205) when the hook's private epoch sorted below the old one. This is a **documented residual**, not covered by the tests above (they use a cold, not an expired, copy).
   - **The rule that keeps it unreachable.** **A Ticket entry point must not perform its first permission-cache load inside a transaction that later locks an organization.** Today's HTTP route satisfies it only incidentally: the `can:tickets.create` route middleware loads the permission copy (and the epoch) before the controller opens the creation transaction. A queue job, command or other non-HTTP creation path that calls `TicketService::create()` in a fresh process does not. More generally, no application path may check a permission through the Gate after taking an organization or invitation lock (authorization precedes the transaction in issue, revoke and the membership mutations); that is also why the invitation flow reads authority from the tables.
   - **Optional hardening, deliberately not done in R1-3A.** Preloading the permission mapping (a permission check) before the transaction in `TicketService::create()` would make the rule hold by construction. `TicketService::create()` is **not modified** in this package; the preload is recorded for a separate review, to be done **before any new non-HTTP Ticket creation path is introduced**.
3. **The commit race** (6.2.4) is unchanged and accepted.
4. **A pre-existing, separate defect** in `PermissionCacheEpoch::current()` when the epoch row is missing and another transaction creates it: [recorded separately](./release-1.0-defect-permission-cache-epoch-first-use.md), not changed here.
5. **Reactivation is not in the guard** (it never was), so it does not serialize with guard-class transactions through the permission row; it takes the same ascending invitation order and the actor row, and its pairs are tested.

**Required two-connection MariaDB tests (R1-3A), each pair in both orders, with a short lock-wait timeout so a hang fails the test, asserting that no operation's transaction began more than once and none ended in a deadlock or a lock-wait timeout:** the pairs in the table above, the three-transaction H → T → I tests (hook, cold-cache Ticket, re-issue) for both hook kinds and both epoch states and both arrival orders, the real cache statement stream of each hook kind, and a **mutation test** for each rule: removing R1, R2, R3 or R4 independently, and the first shared lock on the issuer's row in acceptance, issue and revoke, must each fail a named test.

#### 6.2.4 Covered paths and residuals (accurate statement)

**Covered (all application paths that can change authority):** suspension, reactivation, `syncUserRoles`, `syncRolePermissions`, `guardedGrantChange` (the `roles:sync` command). Role deletion refuses a role with holders; a new role has no holders; there is no application path that grants or revokes **direct** user permissions; an inviter cannot be hard-deleted (`invited_by` is RESTRICT). A source-level drift guard fails when production code other than `AccessGrants`, `BuiltInRoleSynchronizer`, `OperatorBootstrap` (which only grants a **new** account its role) and `RoleController::store` calls `syncRoles`, `assignRole`, `removeRole`, `syncPermissions`, `givePermissionTo` or `revokePermissionTo`.

**Residuals (TTL-bounded, accepted):**

- **Out-of-application changes:** direct database writes, `tinker`, seeders, and any future path that bypasses `AccessGrants`. For these the guarantee is the mandatory **acceptance-time** check and the **send-time** check: an invitation whose issuer is no longer a manager **cannot be accepted and is not mailed**, whether or not a hook ran. What is lost is display (it shows Pending until it expires) and permanence: authority lost and then regained by such a path within seven days lets the invitation work again.
- **The commit race:** an invitation committed by the issuer after a hook's snapshot (issuance racing the issuer's own suspension) is not in that hook's id list. It cannot be accepted or mailed while the issuer lacks authority, and the `issuer_reinstated` hook revokes it at the next transition upward.
- **A hook-revoked invitation keeps its uniqueness slot** (O4/R1) until the next authorized issuance for the address releases it. It is terminal and unusable; it blocks nothing, because issuance releases any holder that is not open.
- **Acceptance just before the loss** is a legitimate acceptance; the invitee is an ordinary member, handled by the ordinary membership controls.
- **Authority is not claimed to be continuous.** The claims are: checked from the tables at issue (in addition to the Gate), at send time and at acceptance; permanently revoked at every covered transition.

**Required tests (A):** suspend then reactivate (stays revoked, reason shown); role removal then restoration (stays revoked); custom-role permission removal and restoration (sweep, several issuers, ascending single statement); built-in sync removal (no actor); an unaffected issuer's invitations untouched; a role change that does not affect either capability revokes nothing; the raced invitation is revoked by the next upward transition; each revocation audited with the right reason, source and actor; an uncovered path simulated by a direct table write: acceptance refused and the mail dropped; no code path writes `revoked_at = NULL`.

---

## 7. Acceptance **[Proposed, revised by B3, B4 and the refinements]**

### 7.1 Resolution and presentation

The token is hashed and looked up by `token_hash`. Unknown, accepted, revoked, expired, wrong-length, "the address already has an account" and "issuer no longer authorized" all render the **same** `auth/invitation-invalid` page. The **content** is uniform; **timing equivalence is not claimed** (the open and dead paths do different work). The register page shows the address (read-only), the **organization name** and the expiry; it exposes no ids.

### 7.2 The transaction

**Before the transaction, with no locks held:** validate the request (including `uncompromised()`), hash the token, **compute `Hash::make($password)`** (bcrypt is the slow step), gather SSO identity data, and make one plain read of the invitation by hash to **nominate** its id, organization and issuer (it decides nothing; none found means the invalid page). **Nothing slow runs inside the locks:** no password hashing (`Hash::make` has returned before the transaction begins), no HTTP, no mail, no provider calls, and **no permission-cache access** (a test asserts no `cache`-table statement runs inside the transaction).

**Transaction** (the closure is idempotent because every input is precomputed; bounded deadlock retry like `OrganizationMembership::ATTEMPTS`):

1. **Lock the issuer's `users` row `FOR SHARE`** (the nominated `invited_by`; first statement, for the reason in 6.2.3), then **the organization row `FOR UPDATE`** (404/invalid if gone). *No plain (consistent) read may precede these statements:* the transaction's snapshot must begin after the locks, so the authority reads below see the latest committed state.
2. **Lock the invitation row `FOR UPDATE` by id**; require the same organization, **open** and unexpired, holding the slot.
3. **`OrganizationAuthority::isMembershipManager($invited_by)`**, table-based (B3). False means dead.
4. Require no account with the address (cheap pre-check; the unique index is the arbiter under a race).
5. Create the user (`name`, **email from the row**, the precomputed hash, `invited_by`), assign role `user`, write the password-history row.
6. **`OrganizationMembership::addFromInvitation($invitation->id, $user)`** (4.3, sequence 0 to 7), which takes no locks of its own, requires the orchestrator's proof of steps 1 and 2, and revalidates the persisted invitation, organization, recipient, account eligibility and issuer under those locks. The orchestrator records the proof just before this call and clears it in a `finally`.
7. Set `accepted_at`, `accepted_user_id`, **clear `live_email`**; write the audit entry.
8. Commit; then `Auth::login($user)` and redirect to the dashboard (existing behaviour).

Any failure rolls back **everything**. A refused attempt **writes nothing** (D10).

**Precise duplicate-key classification.** The existing code treated any `1062` as "invitation unavailable". The new classifier reads the **violated key name** from the driver error and acts per key; an **unknown or unparseable** duplicate is **rethrown** (a visible 500), never silently converted:

| Key | Where | Meaning and action |
|---|---|---|
| `users_email_unique` | acceptance | an account has the address: the invalid page |
| social-account provider identity key | SSO acceptance | that provider identity is already linked: the invalid page (the existing test) |
| `organization_invitations` (organization, `live_email`) | issuance | an invitation was issued concurrently: a refusal telling the operator to retry (the lock should make this unreachable; it is the backstop) |
| `organization_invitations` `token_hash` | issuance | regenerate the token once; a second collision is rethrown |
| `organization_invitations` `accepted_user_id`; `organization_members` (organization, user) | acceptance | **cannot happen** for a newly created account: rethrow (a bug signal) |

Each key has a test against real MariaDB (the key name format is driver-specific and must not be assumed).

**Suspension.** An account is never created suspended, and a suspended account's address is "taken", so an invitation can never sign in, change or reactivate an existing account. A suspended **issuer** is covered by 6.2 and by step 3.

### 7.3 Password and SSO paths; retiring the legacy flow

**Password path (always available).** The body carries only `name`, `password`, `password_confirmation`; email and organization come from the locked row, never the request. Password rules unchanged.

**SSO path.** It funnels through the **same** `accept` core and keeps today's rule (provider email, **normalized by the same function**, must equal the invitation's; a non-matching identity is refused with the existing message and the invitation stays open). It stays **deferred** for customers (`ssoDeferred()`), so nothing changes in `closed`/`pilot`.

**Secure callback binding, without the plaintext token in the OAuth context.** The legacy `invitation` context was `{intent, provider, initiatingUserId, invitationToken, startedAt}`, with the token in clear and **no age limit** (`validContext()` only checks that `startedAt` parses). The new context is `{intent: 'invitation', provider, initiatingUserId: null, invitationHash, startedAt}`:

- `invitationHash` is the SHA-256 of the token; the plaintext never enters the session. It is computed from the `?invitation=` query on `sso.redirect`, after that request has verified by hash that an **open** invitation exists.
- The callback **never reads a token from its own request**. It resolves the invitation **by `invitationHash`** and passes it to `accept`, which **re-verifies everything under lock** (7.2). A context that no longer resolves to an open invitation is refused.
- The context stays **one-time** (`session->pull`, as today), provider-bound, and gains a **maximum age** (a few minutes, a constant) for the invitation intent; an older context is refused as "no longer valid".
- Failure redirects can no longer rebuild `invitation.show/{token}`: a provider failure or an unusable context redirects to `login` with "This invitation could not be completed. Open the link from your email again." (the legacy code redirected to `invitation.show` with the token; this costs a little UX on a path deferred for customers).
- The same throttle and `no-referrer`/`no-store` headers apply to `sso.redirect` when it carries an `invitation` query (5.8).
- The `?invitation=` query on `sso.redirect` still carries the token (the same exposure class as the page URL, accepted in 5.6).

**Legacy retirement is complete, GET, POST and SSO.**

| Surface | Today | After R1-3B |
|---|---|---|
| `GET /invitation/{token}` (`invitation.show`) | resolves a legacy `invitations.token` | same route name and URL shape; resolves **only** `organization_invitations` by hash. A legacy token renders the uniform invalid page |
| `POST /invitation/{token}` (`invitation.register`) | `InvitationService::acceptWithPassword` | same name; calls `OrganizationInvitations::accept`; a legacy token is refused like any unknown one |
| `POST /invitations` (`invitations.store`, `can:users.invite`) | operator issues an organization-less invitation | **removed**; the users-page "Invite User" modal and its browser spec are removed; the pilot boundary row is repointed to the new operator routes |
| SSO: `sso.redirect?invitation=`, `sso.callback` `invitation` intent | `SocialiteController` → `InvitationService::findValid`, `acceptWithSocialAccount`, plaintext token in the session | rebuilt on `OrganizationInvitations` with the hash-only context above; **no reference to `InvitationService` remains** |
| `InvitationService`, `InvitationNotification`, `InvitationController::store/register`, the `Invitation` model's live use | live | removed from the live path; the model, table and `users.invitation_id` remain only as inert history |

After B, a repository search finds **no production reader of `invitations.token`** and no call to `InvitationService`; a test pins both.

**Admin-user provenance.** `Admin/UserController::show` and `admin/users/show.blade.php` read `user->invitation->invitedBy` (the legacy relation). Users created by R1-3 have `invitation_id` NULL and `invited_by` set. The page therefore gains the organization-invitation provenance through a new `User::organizationInvitation()` (`HasOne` on `accepted_user_id`), showing "Joined organization X by invitation from Y"; the legacy block stays for historical accounts. Tests cover a legacy user, an R1-3 user and a user with neither.

---

## 8. Existing accounts **[Proposed: defer, deliberately]**

R1-3 invites **new** people only. Reasons:

1. **Already covered for operators:** MO-2A1's *Add member* lists active users and adds them; the operator gap was never "add a known user", it was "bring a *new* person in with an organization".
2. **Enumeration:** inviting by address a customer administrator can type reveals whether that address has an account unless the design deliberately hides it, and consent flows ("you were invited to organization B; accept while signed in as the existing user") are a product decision with their own security surface (session fixation, confused deputy, an invited user with a role in another tenant). It belongs with MO-2B, which already owns customer-side delegation and the enumeration question.
3. **Cost:** an existing-account branch needs a sign-in-bound acceptance (a second page and flow), a policy on which of several memberships the user is acting in (R1-2's `CHOOSE` handles creation only), and notification changes.

The cost of deferring is zero for the pilot. Existing behaviour a deferral must not break: operators keep *Add member*.

---

## 9. Concurrency and security invariants **[Proposed, revised]**

**Global lock order for every writer, and where it is argued:**

`users.manage` permission row (the `AccountAdministrators` guard) → **user rows** (a shared lock on the issuing, issuer or acting user's row as the **first** statement of issue, revoke and accept; the exclusive lock suspension and reactivation take on their target) → **organization rows**, ascending id → **invitation rows**, ascending id → the rest (`users` insert, `organization_members`, `social_accounts`).

The full acquisition table, the row-level argument, the pair-by-pair examination and the **stated limit of that argument** (index-gap locks; no deadlock-freedom claim beyond the row level; a residual cycle ends in one atomic abort) are in **6.2.3**. Hooks take **no organization lock**. Existing organization-first membership paths (which take user rows only afterwards, through foreign-key checks) are unchanged.

| # | Invariant | Enforced by |
|---|---|---|
| I1 | The plaintext token is **never stored in the application's tables, the activity log, the application log or the session**; it exists only in the mail, the URL (so web-server logs), the encrypted queue payload and, for SSO, the initiating request | only `token_hash` persisted; encrypted payload; SSO session context holds the hash; tests inspect the dump, the activity log, the log channel and the session store |
| I2 | An invitation names exactly one organization and no role; the only membership it can create is `member`, unapproved | schema; `addFromInvitation` takes two ids and writes the role and approval itself |
| I3 | Acceptance is atomic and serialized per organization | single transaction; issuer row (S), organization row, invitation row in that order; no consistent read before the first lock |
| I4 | Single use | state flip under the invitation lock; `accepted_user_id` UNIQUE; `users.email` UNIQUE |
| I5 | At most one slot holder per (organization, address), and issuance always releases any holder that is not open (a hook-revoked row keeps its slot until then, O4/R1) | issue under the organization lock; `live_email` UNIQUE backstop (B1) |
| I6 | A revoked, superseded, expired or accepted token never works and is never mailed | derived state under lock; `shouldSend()` re-check incl. hash equality |
| I7 | **Authority is checked, from the tables, at issue (the Gate), at send time and at acceptance, and pending invitations of an issuer who is suspended or loses authority through an application path are permanently revoked.** It is **not** claimed to be continuous across every possible mutation path (6.2 residuals) | `manageMembership`; `isMembershipManager`; the hooks; the acceptance-time check is the guarantee |
| I8 | No cross-organization effect | organization comes from the locked row; the request supplies none |
| I9 | No privilege escalation | no customer route issues anything; `users.invite`/`org.invite`/role/approval are not inputs; new accounts get only the built-in `user` role; single-writer fences (4.3) |
| I10 | An existing account is never signed in, changed or reactivated by an invitation | step 4/5 of 7.2; suspended addresses are "taken" |
| I11 | A dead link gets one uniform **response**; timing equivalence is not claimed | one invalid page |
| I12 | The table-based predicate and the policy agree | the drift-guard test (4.4) |
| I13 | No locked transaction does slow work | hashing, HTTP and mail are outside (7.2); a test asserts the hash is computed before the transaction |

| Race | Outcome |
|---|---|
| acceptance vs **revoke** | both take the organization lock; the first to commit wins; the loser re-reads under lock |
| acceptance vs **resend/reissue** | reissue supersedes the open row under the organization lock; acceptance either commits first (reissue then refuses: the address now has an account) or finds the row revoked |
| **expiry then reissue** (B1) | the expired row holds the slot; issuance releases it under the lock and inserts; the old row stays *Expired*; no cleanup job; a caller that skips the release hits the unique backstop |
| **two acceptances** of one token | the second waits on the invitation lock, then finds `accepted_at` set |
| acceptance vs **operator Add member / membership change** | the invitee's account does not exist until the acceptance commits; changes for others serialize on the same organization lock |
| acceptance vs **issuer suspension** (B2) | serialize at the issuer's user row (acceptance S, suspension X) before either holds anything else; whichever commits first decides; a hook that follows finds an accepted row and leaves it (6.2.3) |
| acceptance vs **role or permission loss** (B2/B3) | same serialization; the acceptance-time table check also catches a loss that committed earlier through **any** path |
| sweep vs **operator revoke** | the hook waits for the invitation row the operator's revoke holds and then finds it revoked (its conditions re-evaluate on the latest row); idempotent |
| acceptance vs **organization removed or renamed** | removal is not an application path and the FK is RESTRICT; a rename is harmless |
| two operators issuing for one address | organization lock serializes; `live_email` is the backstop (database-level tests below) |
| **issuance vs suspension of the issuer** | the issuer's row (S vs X) is the first statement of each; the old cycle shape is impossible (6.2.3) |
| deadlock | consistent order; bounded retry; two-connection tests for every pair above |

---

## 10. Cross-cutting impact

| Area | Impact |
|---|---|
| **Migrations / MariaDB 11.4** | one additive create-table migration; no pinned file edited; not a rehearsal candidate; `foreign-keys.json` +4 entries; `PreservationSchemaTest` expectation list +4; `database:verify` and fresh-migration verification must pass. `live_email` unique relies on MariaDB treating NULLs as distinct (standard). The duplicate-key classifier reads the violated key name, which is driver-format-specific: it is tested on MariaDB 11.4 itself. |
| **Notifications** | `InvitationNotification` is replaced for this flow; `notification-delivery.md` (queued list, send-time table, "do not assume sent once": the encrypted payload is idempotent, a repeat carries the same live link) and `NotificationSendTimeEligibilityTest` invitation cases are rewritten; `SkipSuspendedRecipients` is unaffected (on-demand address, no `User`). Queue contract unchanged (three tries, 60/180 s). |
| **CI / test fixtures** | `OrganizationInvitationFactory` must hand tests the **plaintext** (the row holds only the hash): a `withToken()` state or a returned pair. `InvitationTest`, `SocialiteTest`, `PasswordTest`, `SuspendedAccountAuthenticationTest`, `SuspendedAccountEligibilityTest`, `NotificationSendTimeEligibilityTest`, the pilot route-boundary row `'send invitation' => POST /invitations`, `StabilizationConfigurationTest` URL test and the Blade theme e2e (`users: list and the invite modal`) are touched. **Admin-user provenance** (`User::organizationInvitation()`, the show page) and the **Playwright cleanup** of invitee accounts (section 13) are dependencies. `BaselineFixtures`/`baseline.php` keep describing the 0.9 legacy table and need no change. `IntegritySnapshot`/`organizations:preflight` gain an optional, ids-only count of open invitations. |
| **Customer pilot** | `invitation.show` / `invitation.register` keep their names and URL shape, so **no allow-list change**. New operator routes sit under `organizations.*` (already denylisted for customers); the table-driven denylist/boundary test gains rows. `customer-pilot-surface.md` §2.2/§3.2/§7 are updated. SSO remains deferred; `sso.redirect` gains a limiter (a no-op without an `invitation` query) and response headers. |
| **Frontend** | `invitation-register.tsx` gains the organization name (`shadcn/*` lint, Direction D rules apply; no new component); the operator panel is **Blade** on `organizations.show` (guarded by `BladeThemeGuardTest`); Wayfinder route files are generated and git-ignored. |
| **Docs** | `auth-flow.md` (SSO invitation context is the hash), `account-lifecycle.md` (suspension revokes the suspended issuer's pending invitations; the invitation-acceptance entry-point row), `notification-delivery.md` (also the client-IP assumption), `role-governance.md` (H10 note), `customer-pilot-surface.md`, `release-1.0-notes.md` (changes, "not in this release yet"), the roadmap forward note, `release-0.9-scope-and-architecture.md` §10.1 (H10 annotation), and this index. Per the R1-2 precedent, the roadmap note is written at implementation, not now. |

**Dependencies.** R1-2 merged (done). MO-2A1 membership service (done). No dependency on MO-2B, MO-3 or MO-4.
**Deferred (not built, not blocked by this design):** existing-account invitations; customer-administrator issuance (MO-2B, with its own flag and the operator-eligibility requirement); invitation role; partner-originated invitations and named contacts (R1-5); SSO acceptance enablement; dropping the legacy `invitations` table and `users.invitation_id`; fragment-based invitation links; operator MFA (unchanged owner decision).

---

## 11. Product surface **[Proposed]**

**Operator (smallest useful).** An **Invitations** card on `organizations.show`, beneath Members, shown only to an authorized actor (like the relationship panel; a customer who opens their own organization's page sees none of it):

- a one-field form (email) with the line "invited people join as ordinary members; administrators are made separately";
- a table of this organization's invitations: address, state (Pending, Accepted, Revoked, Expired), **the revocation reason when there is one** (revoked by an operator, superseded, issuer suspended, issuer authority lost), invited by, sent, expires; **Resend** (pending/expired) and **Revoke** (pending); accepted rows link to the member;
- flash messages say only what happened.

The users-page "Invite User" modal is removed (D2). Routes: `POST organizations/{organization}/invitations` (issue/resend), `DELETE organizations/{organization}/invitations/{invitation}` (revoke); the invitation is looked up **within the organization** (a cross-organization id is a 404).

**Recipient.** The existing register page with one added line ("You have been invited to join *Organization*"). No new screens, no account chooser, no organization picker, no role display. Landing after acceptance is the existing customer dashboard, from which R1-2 lets the member raise a request for the organization.

**Explicitly absent:** any customer-side control, any administrator choice, bulk actions, an invitations index across organizations.

---

## 12. Owner decision register

All 14 items are **accepted by the adjudication**; D8 and D12 are **revised** as shown. Where a row says "accepted", the default of the earlier draft stands.

| ID | Question | Accepted decision | Notes |
|---|---|---|---|
| **D1** | Model | **New `organization_invitations` table**; legacy table left inert | accepted |
| **D2** | The organization-less operator invitation | **Retire issuance and acceptance, including the SSO branch, in R1-3** | accepted; the SSO and admin-provenance consequences are in 7.3 |
| **D3** | Issuance authority | **`manageMembership`**; `users.invite` stops gating | accepted; the Gate stays at issuance, the table predicate is used elsewhere (4.4) |
| **D4** | H10 | **Hash the token; encrypted queued notification** (not synchronous) | accepted; the owner's explicit H10 ruling |
| **D5** | Lifetime and resend | **7 days**; resend = supersede with a new token, one constant | accepted |
| **D6** | Existing accounts | **Refuse at issuance**; defer delegated/consent-based invitation to MO-2B | accepted |
| **D7** | Role on the invitation | **None**; invitees are always `member`, unapproved | accepted |
| **D8 (revised)** | Issuer authority | **Authority is checked from the tables at acceptance and at send time (`isMembershipManager`), and pending invitations are permanently revoked when the issuer is suspended or loses membership-management authority through any `AccessGrants` path or the suspension service.** Residual, TTL-bounded cases (direct database or console changes, future bypassing paths) are stated in 6.2 and are protected by the acceptance-time check alone | replaces "re-check the issuer at acceptance (Gate)" |
| **D9** | SSO acceptance | **Port through the same core, keep deferred**; the session carries the token hash, not the token | accepted, with the session change |
| **D10** | A failed acceptance attempt | **Writes nothing** | accepted |
| **D11** | Throttle and page headers | **Include**, extended to SSO initiation; the client-IP assumption is documented (5.9) | accepted, extended |
| **D12 (revised)** | Uniqueness backstop | **`live_email` is the uniqueness-slot holder, not an "unexpired" marker; issuance releases the slot under the organization lock; UNIQUE (organization, `live_email`) is the backstop; no cleanup job** | replaces the earlier "include `live_email`" |
| **D13** | Audit | **Yes**; address included, token and hash never | accepted |
| **D14** | Packaging | **One PR, three packages (A, B, C)**; independent Opus **security review before A is committed**; closure review at the end | accepted, with the A review gate |

### 12.1 Confirmations (approved) and accepted risks

**No blocking owner decision remains.**

| ID | Decision | Approved scope |
|---|---|---|
| **C1** | `revoked_reason` | **Approved.** A narrowly defined, descriptive `VARCHAR(32)` with the closed set of stable codes in 4.1 (`operator`, `superseded`, `issuer_suspended`, `issuer_authority_lost`, `issuer_reinstated`), refused if unknown, never a decision input, carried identically in the audit entry with the actor and the `source`. |
| **C2** | Browser-suite cleanup of invitee accounts | **Approved conditionally: only if the existing infrastructure cannot do it safely.** It cannot: `e2e:cleanup-tickets` and the per-test task tracking remove tickets and tasks only, no supported route deletes an account or an invitation, and `tickets.user_id` is RESTRICT. A narrow command is therefore specified in section 13. **Unrestricted account deletion is never introduced.** If the review prefers, the browser spec can stop before acceptance and leave acceptance to Pest, which needs no command at all. |

**Accepted risks** (each is stated where it arises): **R1** token in shared-hosting access logs (ruled accepted; no tokenless flow); **R2** uncovered permission-mutation paths are protected by the acceptance-time check only (6.2); **R3** `failed_jobs` payloads and `APP_KEY` rotation (5.6); **R4** the commit-to-dispatch gap: a lost mail is recovered by Resend (5.3); **R5** the per-IP limiter's dependence on the unconfigured trusted-proxy setting (5.9); **R6** an acceptance that commits just before a suspension stands (6.2); **R7** a dead link stays "Pending" in the operator list until it expires or is revoked (D10).

---

## 13. Implementation packages, acceptance gates and checklist **[Proposed, revised]**

**R1-3A: foundation.**
Schema (with `revoked_reason`), model, factory (plaintext handoff); `OrganizationInvitations` (`normalizeEmail`, `issue` with slot release, `revoke`, `findOpen`, the transactional **`accept` core** with the 7.2 sequence, the sweeps); hashing, uniqueness and the **encrypted queued mail** with after-commit dispatch and the send-time checks; **`OrganizationAuthority::isMembershipManager`** and its drift guard; **`OrganizationMembership::addFromInvitation`** and the four fences; the **suspension and authority-loss revocation hooks** and the path drift guard; the duplicate-key classifier; `foreign-keys.json` and the schema/preservation tests.
*Gate:* focused Pest suites on a **throwaway MariaDB on a private network** (the shared testing DB is pinned; run heavy suites one at a time); two-connection concurrency tests for **every pair in 6.2.3 and section 9, each in both orders, with a short lock-wait timeout so a hang fails** (including the issuer-row-first mutation test and the no-cache-statement test); the **database-level `live_email` tests** below (accept vs revoke, accept vs reissue, double accept, expiry-and-reissue, accept vs suspend, accept vs role loss, sweep vs revoke, lock order and deadlock retry); the **mutation probes** below; migration test; `database:verify`. **Independent Opus security review before A is committed.**

**R1-3B: acceptance.**
Password and SSO registration through the core; secure credential processing (hash-only session context, precomputed password hash); **retirement of every legacy path, including SSO** (7.3); registration presentation (organization name); `invitation.*`, **`sso.redirect`** limiter and headers; admin-user provenance; pilot route-boundary rows, `ShellPresentationResolver` and its drift guard, `customer-pilot-surface.md`.
*Gate:* `cd src && npm run lint` (shadcn rules, no new suppressions; `eslint-suppressions.json` may only shrink), pilot suites, notification send-time suites, account-lifecycle suites, SSO suites, the grep for legacy readers of `invitations.token`.

**R1-3C: operator experience and closure.**
The Invitations card (state, reason, issuer, expiry, Resend, Revoke); routes and controller; `BladeThemeGuardTest`; **browser spec** (issue, mail, accept, raise a ticket attributed to the organization, another member's ticket stays invisible, revoked and superseded links are dead); the **Playwright cleanup** below; the documentation reconciliation (section 10); `organizations:preflight` open-invitation count.
*Gate:* `./dev check`, **one PR**, **hosted CI** (the final-tree gate), **independent closure review**.

**Playwright cleanup (conditional dependency, C2).** Existing infrastructure removes tickets (an untargeted `e2e:cleanup-tickets` once after all workers) and tasks (per-test tracking). **Nothing removes users, memberships or invitations**, and a user cannot be deleted while a ticket references them. A browser spec that accepts an invitation would leak an account, a membership and an invitation per run. R1-3C therefore adds **one narrowly scoped command**, `e2e:cleanup-invitees`, with these properties, each pinned by a test:

- **Refuses outside `local` and `testing`** (and in any environment where the customer-surface mode is not `legacy`/test-safe by the same environment guard `e2e:cleanup-tickets` uses); there is no route, no UI, no API and no argument that selects an account or an id.
- **Pattern-bound:** it considers only accounts whose normalized address matches the reserved test pattern (for example `e2e-invitee+<run>@example.test`) **and** that were created by an **accepted** `organization_invitations` row (join on `accepted_user_id`), plus the not-yet-accepted invitations whose address matches the pattern.
- **Refuses, deleting nothing for that account,** if the account holds any role other than the built-in `user`, any operator eligibility, a membership of an organization not marked as the suite's, or any ticket (it never deletes tickets; the ticket cleanup runs first). A matching address on an operator is a refusal, not a deletion.
- **Dependency order** (RESTRICT keys): invitation rows (`accepted_user_id`) → membership → password histories, social accounts, sessions, role assignments → the user.
- **Reports** counts and refusals, exits non-zero on any refusal or storage error, and never reads "cannot look" as "already gone" (the ticket cleanup's rule). Run **after** the ticket cleanup in the same hand-off (`./dev test:e2e` as the web-server user, or the global teardown when started by hand).
- A non-matching account, an operator with a matching address, and an account with a ticket each survive a test run of the command.

The deleted-modal browser case in `blade-theme-controls.spec.ts` (`users: list and the invite modal`) is removed or repointed to the new card.

**Database-level tests of the `live_email` backstop (A, real MariaDB 11.4, bypassing the service).** (a) Two direct inserts with the same `(organization_id, live_email)` fail with a duplicate-key error on that key; (b) many rows with `live_email = NULL` for one (organization, address) coexist; (c) the same address in two organizations coexists; (d) clearing the slot allows a new holder; (e) the service path: expiry then reissue succeeds, the old row is still *Expired* with `revoked_at` null, and exactly one row holds the slot; (f) a service caller that skips the release is rejected by the key (mutation: remove the release and test (e) fails; remove the unique key and test (a) fails).

**Mutation probes (A).** Each must fail a **named** test: remove the organization lock; remove the invitation lock; take the locks in the other order; drop the first shared lock on the issuer's row in acceptance, issue or revoke; remove the `isMembershipManager` check at acceptance; replace it with the Gate; remove it from `addFromInvitation`; remove `hash_equals` or the send-time open check; remove the unique backstop; remove the slot release in issuance (expiry-and-reissue must fail); let `addFromInvitation` accept a role; remove each of the revocation hooks (suspend, reactivate, user roles, role permissions, built-in sync); make any path clear `revoked_at`; dispatch before commit; hash the password inside the transaction; store the token in the session; convert unknown duplicate keys.

**Acceptance criteria (end to end).**

1. An operator issues an invitation for organization O; a mail with a working link is **sent from the queue after commit**; no application table, log or session holds the plaintext token.
2. Accepting it by password creates a user with role `user`, **one** `organization_members` row for O with `role = member` and **no approval**, an `accepted` invitation and the audit entries, atomically; the new user lands signed in and can raise a request attributed to O.
3. Revoked, superseded, expired, accepted, unknown, "address already has an account" and "issuer no longer authorized" all show the same invalid page; no second use works.
4. Expiry followed by reissue for the same address succeeds without any cleanup, the old row stays Expired, and the database refuses a second slot holder.
5. A customer, an administrator (approved or not) and a holder of `users.invite`/`org.invite` alone cannot issue, resend, revoke or list invitations, including through a crafted organization id.
6. Suspending the issuer, or removing their membership-management authority through an application path, permanently revokes their pending invitations; reactivation or restoration does not revive them; an uncovered path still cannot be accepted.
7. Nothing a customer can reach changed (pilot route table identical apart from the new operator-only names).

---

## 14. Questions for the brief consistency review (the design review's questions are closed)

The independent review answered the nine questions of the first draft (the seam, the lock order, the issuer re-check, H10, derived state and the backstop, enumeration, retirement of the legacy flow, MO-2B readiness, test sufficiency); their outcomes are B1 to B4 and the refinements. What remains for a short consistency read:

1. **Mapping.** Does section 17 map every finding and refinement correctly, given that it was reconstructed from the adjudication rather than the review's numbering?
2. **Lock order (6.2.3).** Is the row-level argument sound (hooks take only the permission, user and invitation rows; every invitation-row holder took its user and organization rows first; acceptance never touches the cache)? Is the **first-statement shared lock on the issuer's row** the right fix for the `users.invited_by` foreign-key cycle, or should acceptance leave `users.invited_by` unset and take no issuer lock?
3. **`addFromInvitation`.** Is re-taking the issuer-row, organization and invitation locks inside it (no-ops when held) the right way to keep the canonical order for an unexpected caller, and are the four fences enough?
4. **Predicate drift.** Is the parameterized matrix of 4.4 complete (direct and role-derived capabilities, the `operator` role name without the capability, suspended, no roles)?
5. **Overstated claims (L6).** Are the corrections in 5.6, I1, I7 and I11 the ones intended, or are there others?
6. **Cleanup.** Is a prefix-guarded, non-production cleanup command the right shape for the browser suite, or should the browser spec stop before acceptance and leave acceptance to the Pest feature and concurrency tests?

---

## 15. Security-sensitive questions to settle before implementation

The first draft's five questions are **resolved**: D4 and D2 (the owner's adjudication), the membership seam (4.3), the lock order and issuer re-check (4.4, 6.2, 9), the credential-exposure residuals (accepted, 5.6), and enumeration (operator-only disclosure accepted; customer-side disclosure is a MO-2B obligation).

Still to be proven rather than decided, and therefore **gates of package A**:

1. **Lock ordering** (6.2.3): the pair tests in both orders with a short lock-wait timeout, the issuer-row mutation test, and the no-cache-statement test. A residual gap-lock cycle is **not** ruled out; it must end in one atomic abort.
2. **Predicate equality** with the policy (the drift guard).
3. **Rollback completeness**: a failure at any step of 7.2, including the audit write and the membership revalidation, leaves no account, no membership and an open invitation.
4. **After-commit dispatch** against the real database queue.
5. **Key-name classification** on the real driver, for every row of the 7.2 table.
6. **The staging observation** of the limiter's client address (5.9), before the limits are trusted.

---

## 16. Evidence base (this checkpoint)

Read (first pass): `InvitationService`, `Invitation`, `InvitationNotification`, `InvitationController`, `SocialiteController` (invitation intents), the exceptions, the `invitations` migration and factory, `OrganizationMembership`, `OrganizationAuthority`, `OrganizationPolicy`, `ServiceRequestAccess`, `CustomerPilotGate`, `PermissionCatalogue`, `routes/web.php`, `database/profile/foreign-keys.json`, `PreservationSchemaTest`, `InvitationTest`, `NotificationSendTimeEligibilityTest`, the writer-fence test, the rehearsal baseline fixtures; the MO-1A, MO-2A1, R1-2, 1.0 notes, 0.9 scope (H10), roadmap, auth-flow, account-lifecycle, notification-delivery, role-governance and customer-pilot-surface records. Laravel's `ShouldBeEncrypted` support for queued notifications was confirmed in `vendor`. Read (reconciliation pass): `AccountLifecycleService`, `AccessGrants`, `AccountAdministrators` (the table-based `holds` capability query and the guard's lock order), `BuiltInRoleSynchronizer` (it runs through `guardedGrantChange`), the direct role/permission calls across `app/`, `RoleController`, `SocialiteController` (the session context), `admin/users/show.blade.php`, the Playwright cleanup fixtures, and the proxy configuration. **No code, migration, database, staging, remote or commit was touched; no test suite was run** (documentation-only; both passes). The Opus review's full text was not available to this pass: only the owner's adjudication and a short session-memory summary of the review.

---

## 17. Reconciliation map (adjudication to this record)

| Finding or refinement | Resolution | Section |
|---|---|---|
| **B1** `live_email` semantics, expiry and reissue | slot-holder semantics; issuance releases the slot under the organization lock; expired rows keep their state; no cleanup job; scenario and backstop in the matrix and criteria | 4.1, 6.1, 9, 13 (criterion 4), D12 |
| **B2** issuer authority loss (final: no organization lock in hooks; before/after predicate detection; reinstated hook) | permanent revocation on suspension and on role/permission loss through `AccessGrants`; paths investigated and tabulated; hooks in existing transactions; residuals stated as TTL-bounded; tests for suspend/reactivate and loss/restore | 6.2, 5.4, 9 (I7), 12 (D8), 13 |
| **B3** authoritative acceptance check (also the cache-write deadlock with `ServiceRequestAccess`) | table-based `OrganizationAuthority::isMembershipManager`; used at acceptance, send time, sweeps; Gate kept for issuance; drift-guard test against `manageMembership` | 4.4, 7.2 step 3 |
| **B4** safe membership creation | `addFromInvitation(invitationId, recipient)` revalidates invitation, organization, recipient, eligibility and issuer; role fixed, approval absent; canonical lock order; four source-level fences | 4.3, 7.2 step 6 |
| SSO plaintext token in session | session carries the hash only | 7.3, 9 (I1) |
| Throttling and headers on SSO initiation | limiter (no-op without `invitation`) and `no-referrer`/`no-store` on `sso.redirect` | 5.8, 12 (D11) |
| After-commit dispatch guaranteed | dispatch after the transaction returns plus `ShouldQueueAfterCommit` (the connection default is `false`); rollback test on the real queue | 5.3 |
| Consistent email normalization (none exists today; collation is `unicode_ci`) | one `normalizeEmail()` for issue, lookup, existing-account check, SSO comparison and the created account | 4.1 |
| Precise duplicate-key errors | key-name classifier; unknown duplicates rethrown | 7.2 |
| Minimize work inside locks | password hash, HTTP and mail outside; no consistent read before the first lock | 7.2, 9 (I13) |
| Retire every legacy acceptance path, including SSO | `SocialiteController` branch, `InvitationService`, `invitations.token` readers removed | 7.3, 13 (B) |
| Admin-user provenance | `User::organizationInvitation()` and the show page | 7.3 |
| Playwright cleanup dependency | prefix-guarded non-production cleanup command after the ticket cleanup; modal spec removed | 13, 12.1 (C2) |
| Send-time eligibility defined | six conditions; database errors retried | 5.4 |
| Client-IP and proxy assumption | documented; no trusted proxies configured; staging observation | 5.9, 15 |
| **L6** overstated residuals corrected | I1 (where the plaintext can exist), I7 (not "continuous"), I11 (content, not timing), 5.6 (complete list, `failed_jobs`, referrer), "no oracle" wording | 5.6, 7.1, 9 |
| Access-log exposure | accepted residual; no tokenless flow | 5.6, 12.1 (R1) |
| D8 and D12 revised | as ruled | 12 |
| Updated invariants, gates | I1 to I13; A/B/C gates with the A security review | 9, 13 |
| Final corrections: hook lock ordering | organization locks withdrawn from hooks; a concrete suspension-vs-acceptance cycle through `users.invited_by` found and fixed by a first-statement shared lock on the issuer's row; global order and pair table | 6.2.3, 9 |
| Final corrections: authorization-loss detection | before/after comparison of `isMembershipManager` over candidates; per-path table; multi-issuer single ascending statement; `issuer_reinstated`; covered paths and residuals | 6.2.1, 6.2.2, 6.2.4 |
| Final corrections: concrete requirements | `ShouldQueueAfterCommit`, duplicate-key classifier, hash before the transaction, GET/POST/SSO retirement table, SSO callback binding, send-time refusal, normalization, `live_email` database tests | 5.3, 7.2, 7.3, 4.1, 13 |
| C1, C2 approved | stable reason codes; conditional narrow cleanup command | 4.1, 12.1, 13 |

---

## 18. R1-3A implementation record (2026-11-11, uncommitted, awaiting the second independent Opus review)

Branch `feature/r1-3-organization-invitations`, worktree `../intechral-client-portal-r13`, from `main` at `c512f69`. Nothing is committed, pushed, deployed or migrated outside a disposable test database. R1-3B and R1-3C are not started. **The blocking finding of the first pass (18.3) was reported rather than worked around, reviewed independently, and is remediated by the owner-approved O4 + R4 (18.3 to 18.8).**

### 18.1 What was built

| Piece | Where |
|---|---|
| Schema (one additive `CREATE TABLE`), four foreign keys in the manifest, `PreservationSchemaTest` list | `database/migrations/2026_11_11_090000_create_organization_invitations_table.php`, `database/profile/foreign-keys.json` |
| Model (read model, nothing fillable, derived state) and factory (`withToken`, `expired`, `accepted`, `revoked`) | `App\Models\OrganizationInvitation`, `Database\Factories\OrganizationInvitationFactory` |
| The only writer: `issue`, `revoke`, `findOpen`, `accept` / `acceptByHash` (core shared by password and provider paths), `reconcileIssuers`, `revokeIssuedBy`, `normalizeEmail`, `hashToken` | `App\Services\OrganizationInvitations`, `InvitationAcceptance` |
| Table-based predicate and its set form | `OrganizationAuthority::isMembershipManager`, `membershipManagerIds` |
| Invitation-derived membership (two identifiers, internal) | `OrganizationMembership::addFromInvitation` |
| Revocation hooks (O4 + R4: actor row, epoch advance, invitations locked before the mutation; no slot write) | `AccountLifecycleService::suspend` / `reactivate`; `AccessGrants::syncUserRoles`, `syncRolePermissions`, `guardedGrantChange` (now `($mutation, $source, $actor = null)`); `OrganizationInvitations::reconcileIssuers(..., $fenceCache)` and `revokeIssuedBy`; the two admin controllers pass the acting user |
| Encrypted, after-commit mail with send-time eligibility | `App\Notifications\OrganizationInvitationNotification` |
| Duplicate-key classifier | `App\Support\Database\DuplicateKey` |
| Test support | `tests/Support/organization_invitation_worker.php` (a real second connection; ops for issue, revoke, accept, suspend, reactivate, role and permission changes, the synchronization, membership add and approve, ticket creation; records each statement and the cache keys it names), `CommittedUsers` (invitation rows go before the users they reference) |

The legacy `invitations` table, `InvitationService`, `InvitationNotification`, the controller and the SSO branch are **untouched** (retired in R1-3B).

### 18.2 Deviations from the approved text, each deliberate

1. **`addFromInvitation` takes no locks and requires proof** (owner correction 4). Section 4.3 step 2 and 7.2 step 6 are rewritten. The orchestrator records, in process-local state only it can set, that it holds the issuer-row, organization and invitation locks for this invitation at this connection's transaction level; the seam throws `LogicException` without that proof, including when called from a nested savepoint of the orchestrator's own transaction. The earlier text ("re-takes the locks, correct if a caller reached it with none") is withdrawn. The runtime proof is the guarantee; the source fences remain a second line.
2. **The revocation sweep is two steps, not one statement** (owner correction 2). Primary-key lock of the nominated rows in ascending order, one instant taken after the locks, a PHP open-test with that instant, then an `UPDATE` repeating the conditions with the same instant, one statement per distinct reason (normally one). An already-expired invitation is never relabelled revoked, including when it expires between selection and update (a test moves the clock between the two).
3. **`token_hash` uses the profile's `utf8mb4_unicode_ci`**, not a binary ASCII collation: `database:verify` rejects any other column character set. Harmless (only lower-case hex is stored or looked up; acceptance compares the locked row's hash with `hash_equals`).
4. **`guardedGrantChange` takes a required `$source`** so every caller names itself in the audit.
5. **`accept` takes a prepared `InvitationAcceptance`** (name plus a password hash or a provider identity), so the slow hash cannot happen inside the locks by construction.

The four owner corrections are applied to this record: the lock caveat (6.2.2, 6.2.3), the expiry rule (6.2.2), `APP_PREVIOUS_KEYS` (5.6, with a test using the framework's own encrypter), and the internal seam (4.3, 7.2).

### 18.3 The first-pass blocking finding, and its remediation (O4 + R4)

**The finding (kept as history).** The hooks' `UPDATE` of `live_email` made InnoDB share-lock the parent organization row through the foreign-key check (the unique `(organization_id, live_email)` index contains the foreign-key column), after the hook held the invitation row, inverting the organization-then-invitation order of acceptance, issue and revoke; and the hook's primary-key-first access met issuance's index-first access to the same row. With the hook frozen first, role change, permission change and the built-in synchronization deadlocked with acceptance, and a permission change with a re-issue of the address it sweeps; Laravel silently re-ran the organization-locked victim (the tests detect this: transactions began twice). Final states were correct; a hook chosen as the victim would not have retried.

**The review.** An independent review reproduced both mechanisms on scratch schemas, found a third (a role detach's next-key lock on the `model_has_roles` index against acceptance's insert of the new account's role), found that suspension against `OrganizationMembership::add` deadlocked in the same way, and showed that hook-level organization locks (O1) form cycles in every placement. A three-transaction investigation then reproduced H → T → I (a hook loading the permission mapping inside its transaction, a cold-cache ticket, a re-issue). The owner ruled **O4 + R4** and rejected O1, O2 and O3.

**The remediation as implemented** (rules and sequences in 6.2.3; the write in 6.2.2; slot semantics in 4.1):

- **R1 (O4):** `revokeLocked()` writes `revoked_at`, `revoked_by`, `revoked_reason`, `updated_at` only; never `live_email` or `organization_id`. Issuance already released any non-open slot holder; its code comment and the tests now say "a revoked holder" as well as an expired one.
- **R2:** `reconcileIssuers()` nominates (plain read) and locks the open invitations by primary key ascending **before** running the mutation; the before/after comparison and the revocation use those locked rows; the instant is taken after the mutation and the update repeats the open conditions. `revokeIssuedBy()` (suspension, reactivation) locks the same way.
- **R3:** both take `lockUserShared(actor)` first when there is an actor (none for a command-line synchronization).
- **R4:** `reconcileIssuers(..., fenceCache: true)` calls `PermissionRegistrar::forgetCachedPermissions()` (the epoch advance) after the actor lock and before the invitation locks, for `syncRolePermissions` and `guardedGrantChange`; `syncUserRoles` does not (it never reads the mapping, asserted from the statement stream). The existing after-commit and after-rollback invalidation is untouched.

No schema change, no new lock framework, no change to `AccountAdministrators::guard`.

### 18.4 Confirmed on the real server: the commit-race residual (6.2.4)

Issue first, then suspension of the issuing operator: the suspension's id list was read from a snapshot taken before it waited for the operator's row, so the invitation the issuance committed in the meantime is **not revoked by the suspension**. It cannot be accepted or mailed (the issuer lacks authority, checked from the tables) and the reinstatement sweep revokes it (`issuer_reinstated`); the test asserts both. Provisionally accepted by the owner. A locking read of the issuer's invitations by `invited_by` at suspension and reactivation would close it at the price of a range lock on the `invited_by` index; left as a decision.

### 18.5 Concurrency evidence (disposable MariaDB 11.4.13, private network, two or three real PHP processes)

- **Every pair of 6.2.3 in both orders**, with the second operation observed blocked where the lock structure says it must be, and asserting that **no worker's outermost transaction began more than once, none rolled back behind an `ok`, none ended in error 1213 or 1205**. This includes the four scenarios that failed in the first pass (role change, permission change and built-in synchronization against acceptance; a permission change against a re-issue), which now pass.
- **Added by the review:** suspension against membership addition (two freeze points), against approval (two), against ticket creation (two); a role change of the account with the highest id against acceptance, both frozen after their role writes; a sweep against a re-issue with the sweep frozen after its write; a sweep whose actor is being reactivated (R3).
- **Three transactions (H, T, I):** a role's permission change and the synchronization **creating a permission**; cold database cache; epoch row present and missing; hook frozen first with T and I behind it, and T frozen first holding its cache row with the hook and I behind it. In the "present" T-first case the hook finishes **while T still holds its cache row**, which is the decisive observation that it never waits for T.
- **The real cache statement stream** of each hook kind, asserted from the statements the worker ran: epoch upsert before the invitation locks; every later cache statement on the epoch row, a legacy key or the hook's private key; a user's role assignment touches no cache table.
- **Mutation probes, one defect each:** R1 (slot written again; organization id written), R2 (locks after the mutation), R3 (actor lock removed from the hooks; from suspension and reactivation), R4 (advance removed; advance after the invitation locks), a wrongly fenced user-role hook, the expiry conditions of the sweep removed, issuance's release of a retained slot removed, the first shared lock removed from acceptance, issue and revoke, each hook removed, `revoked_at` cleared by a path. Results in 18.9.

### 18.6 A pre-existing defect found while verifying, recorded separately

[`PermissionCacheEpoch::current()` throws when the epoch row is missing and another transaction creates it](./release-1.0-defect-permission-cache-epoch-first-use.md): reproduced in raw SQL and in the "hook first, epoch row missing" interleaving, where the ticket transaction fails with `The permission cache epoch could not be created.` It is not a lock-order problem, not caused by R1-3A, and not changed here. The two affected tests tolerate exactly that outcome for T and nothing else. The note carries the scenario and the minimal remediation (a locking read after the insert-or-ignore), verified on raw SQL.

### 18.7 A correction to the first-pass evidence

The first pass reported "no statement touches the permission cache tables inside the acceptance transaction" from a worker said to use the database cache store, with a control that showed the store was in use. **That check never ran against the database store**: the worker's environment was built as `defaults + overrides`, and PHP's array union keeps the default (`array`), so the override was ignored; the control was satisfied by the epoch row's reads alone. Fixed (`array_merge`), and the control now requires reads of the permission copy (`select * from cache where key in …`). The corrected test passes: acceptance runs no cache statement inside its transaction.

### 18.8 Deviations and limits, current

Deviations 1 to 5 of 18.2 stand. Added: the revoked row keeps its slot (O4/R1, owner-approved) and `reconcileIssuers` has a `$fenceCache` parameter. Limits, none hidden:

- No deadlock-freedom claim beyond the row-level argument and the statement streams (6.2.3); **O4 + R4 resolves the reproduced row-level cycles, not every InnoDB gap-lock cycle.**
- **Gap locks on the cache table** (6.2.3, limitation 2): the library's legacy-key `DELETE` holds a gap lock to commit; **expired permission-cache copies** trigger `forgetManyIfExpired` and the same kind of lock inside the reading transaction; the expired-copy three-way scenario (hook, Ticket whose first permission load is in its transaction, re-issue) is a documented residual that the tests do not cover. **A Ticket entry point must not perform its first permission-cache load inside a transaction that later locks an organization**; HTTP satisfies it only through the `can:tickets.create` middleware. `TicketService::create()` is not changed; the preload hardening is recorded for a separate review before any non-HTTP Ticket creation path is added.
- Guard-class transactions still do not retry; the commit race; reactivation is not in the guard.
- The pre-existing epoch first-use defect (18.6) is a Release 1.0 **preproduction obligation**, not remediated here.

### 18.9 Test and gate results

Disposable MariaDB 11.4.13 (`r13_db`, private network `r13net`, tmpfs data); never the shared development or testing database. First run 2026-11-11 after O4 + R4; **final run after the independent review's remediation (F1 to F5)**.

| Gate | Result |
|---|---|
| R1-3A tests, final tree | **170, all pass**: flow 92 (454 assertions), authority 17, migration/schema 5, concurrency 56 (1435 assertions) |
| **Entire Pest suite, final tree** (one process, 3247 s) | **5085 passed, 20 skipped (the existing skips), 0 failed** |
| **DB-L3 and its file** | The independent review found the first rewrite of DB-L3 (assert "the reader never waits") **nondeterministic**: the writer's delete of the absent legacy cache key takes an InnoDB gap lock whose extent depends on the random epoch tokens already in the table and on delete-marked rows not yet purged, so the reader's old-epoch insert sometimes waits (error 1205). It passed in the first full run and failed in the review's. **Correction (F1):** the test seeds a **committed bounding row `<prefix>spatie.permission.cache:0`** (it sorts before every hex epoch token) before the writer starts, so the gap always ends before the reader's key; it then asserts the reader does not wait (any lock error fails with the error code in the message), that `epochInTransaction` differs from the old epoch, and that the committed current epoch differs from it. The authorization-consistency assertions of the other 27 tests are untouched. Runs on the final tree: **the whole file 6 times (28 passed each) and DB-L3 alone 15 times (passed each)**, each a separate process. |
| Mutation probes (17), first run | All caught (R1, R1b, R2, R3, R3b, R4, R4b, R5, R6, R7, A4, A5, A6, F3, F4, F5, G1). Not re-run after F1 to F5: those changes touched one test, comments and documentation, `DuplicateKey`, and a source fence, not the hook code. |
| Pint | clean, 572 files |
| `migrate:fresh` and `database:verify` | pass |
| **Not run** | Playwright, `./dev check` as a whole, hosted CI, the frontend lint and `BladeThemeGuardTest` (no frontend or view file changed). |

**Dispositions of the review's optional items.** F4 (done): `DuplicateKey` reads the violated key from the **end** of the driver message only (a duplicated value that contains "for key '…'" can no longer name a key), with a regression test over real-shaped messages. F5 (done): the role-change source fence now also pins the **methods** that may change roles: `AccessGrants::syncUserRoles` and `syncRolePermissions`, and `OrganizationInvitations::acceptLocked`, using reflection over method bodies rather than a scanner.

---

## 19. R1-3B implementation record (2026-11-11, uncommitted, awaiting owner review of the authentication and SSO changes)

Branch `feature/r1-3-organization-invitations` on top of `d49921b` (R1-3A, committed and independently approved), worktree `../intechral-client-portal-r13`. Nothing is committed, pushed or deployed. R1-3C (the operator Invitations card, its routes, the browser spec, the `e2e:cleanup-invitees` command, the documentation reconciliation of section 10) is not started. **R1-3A is not reimplemented or redesigned**: no file under `app/Services`, `app/Notifications/OrganizationInvitationNotification.php`, the hooks or the migration changed.

### 19.1 What was built

| Piece | Where |
|---|---|
| Password acceptance through the R1-3A core (`findOpen` for the page, `accept` for the POST); the request carries only `name` and the password; the password hash is computed before any lock; every dead link is the invalid page; a failed validation redirects to `invitation.show` **by name** | `Http/Controllers/Auth/InvitationController` |
| SSO rebuilt on the core: hash-only, provider-bound, one-time, age-limited context; `acceptByHash`; no failure rebuilds a link; the legacy-shaped context refused for every intent; deferral for customers preserved | `Http/Controllers/Auth/SocialiteController` |
| Response headers, and the token-bearing URL kept out of the session's previous URL | `Http/Middleware/ProtectInvitationCredential` (alias `invitation.credential`, placed before the throttle in the priority list and before `guest` on the routes) |
| Limiters `invitation` (60/min), `invitation-register` (20/min), `invitation-sso` (30/min, `Limit::none()` without an `invitation` query), client IP only | `AppServiceProvider::registerInvitationRateLimiters`, `routes/web.php` |
| Query details withheld from the log on every lookup path | `Http/Controllers/Auth/Concerns/WithholdsQueryDetails` |
| Retirement: `InvitationService`, `InvitationNotification`, `InvitationEmailMismatchException`, `InvitationUnavailableException` deleted; `POST /invitations` and its controller action removed; the users-page "Invite User" button and modal removed; `users.invite` note in `ShellPresentationResolver` updated | see the changed-file inventory in the handoff |
| Presentation: the registration page names the organization (`organizationName` prop; no new component) | `resources/js/pages/auth/invitation-register.tsx` |
| Provenance: `User::organizationInvitation()` (`HasOne` on `accepted_user_id`), the admin user page shows "Joined *organization* by invitation from *inviter*", the legacy "Invited by" block stays for historical accounts | `Models/User`, `Admin/UserController`, `admin/users/show.blade.php` |
| Docs updated for what B changes: `auth-flow.md`, `account-lifecycle.md`, `notification-delivery.md` (queued list, send-time row, client-IP assumption and the staging verification step), `customer-pilot-surface.md` | the rest of section 10's list is R1-3C |

### 19.2 Findings made while implementing (each is fixed and has a test)

1. **The token reached the session store.** The framework records every full-page GET's URL as the session's "previous URL" (`StartSession::storeCurrentUrl`), in the `sessions` table with the database driver. The first load of the mailed link, and the SSO initiation, therefore stored the plaintext token, which contradicted invariant I1. Fixed in `ProtectInvitationCredential` (the request is marked a prefetch before it runs, the framework's own switch for this); the test reads the real `sessions` table and has a control proving an ordinary page *is* recorded. A first placement of the middleware **behind `guest`** still stored it when a signed-in user opened the link (the redirect happens in `guest`); it now runs first, with its own test.
2. **A 429 carried no protective headers.** The framework sorts `ThrottleRequests` ahead of unlisted middleware, so the throttle answered before the headers middleware ran. The middleware is now in the priority list before the throttle.
3. **`back()` is wrong on these routes.** The page sends `Referrer-Policy: no-referrer`, so the browser sends no `Referer` on the POST, and the previous URL is deliberately not stored; a failed validation would have redirected to `/`. The controller names `invitation.show` explicitly (a test fails without it).
4. **`QueryException` messages embed the SQL bindings**, which for an invitation lookup is the token's hash. Any database failure on these paths would have put it in the application log. The lookups are wrapped (`WithholdsQueryDetails`): a message without SQL is reported and a plain 500 is answered; a test proves the premise (the unsuppressed message contains the hash) and the outcome for all four paths.

### 19.3 Deviations from the approved text

1. **Throttle constants** (60, 20 and 30 per minute) are this package's choice for "generous per-minute limits"; they are named in one place. The POST is the tightest because it also calls the compromised-password service and bcrypt. Not yet observed against the real client address (R5, section 15 item 6: a staging step).
2. **The callback's deferral answer** (a context that names an invitation while SSO is deferred) is the login page with a password-first message and no link, because the context holds a hash. Section 7.3 specified this for failures; it is now applied to the deferral too. Initiation still redirects to `invitation.show` using the token the request itself carried.
3. **The maximum age of the invitation context is 10 minutes** (`SocialiteController::INVITATION_CONTEXT_MAX_AGE_SECONDS`; "a few minutes" in 7.3), long enough for a consent screen and a second factor; a start time more than 60 seconds in the future is refused too.
4. **The pilot route-boundary row `send invitation => POST /invitations`** was *kept and renamed* ("route removed", still refused with 404) rather than repointed, because the operator routes it would be repointed to are R1-3C's. R1-3C adds the rows for them.
5. ~~`users.invite` is not removed from the permission catalogue.~~ **Superseded by the owner's ruling of 2026-11-12: `users.invite` is removed (19.8).**

### 19.4 What is NOT done in R1-3B (by design or by the stop gate)

No operator issuance route or screen exists until R1-3C: the service can issue (R1-3A), but nothing in the application calls it, so **between B and C there is no way to issue an invitation except from a console**. `e2e:cleanup-invitees`, the browser spec for the new flow and the `organizations:preflight` count are R1-3C. Nothing in `TicketService::create()` or the permission-cache epoch changed.

### 19.5 Evidence

Disposable MariaDB 11.4.13 (`r13_db` on the private network `r13net`, tmpfs data); never the shared development or testing database, never R0.9 staging. The Pest runs were one at a time.

| Gate | Result |
|---|---|
| **Entire Pest suite, final tree** (one process, 3720 s) | **5189 passed, 20 skipped, 0 failed** (39133 assertions). The 20 skips are the existing Redis-variant tests (`PermissionCacheEpochTest` 16, `PermissionCacheCommitOrderTest` 4: "No Redis server is reachable here; the file-store variant covers the race"); the disposable network has no Redis. They are not R1-3B coverage and were skipped identically in R1-3A. |
| New or rewritten focused suites | `InvitationTest` 36 (page, GET never consumes, twelve dead links by GET and by POST, atomic acceptance, request cannot choose address, organization or role, single use, validation redirects, suspended accounts, issuer suspended then reinstated, the mailed link reaches the handler); `SocialiteInvitationTest` 47 (hash-only context, provider binding, age boundary, one-time, legacy shape for every intent, malformed hashes, query ignored, seven ways the invitation dies between initiation and callback, address validation, provider failure, no secret in session/redirect/flash/login page, deferral in closed and pilot mode, suspended account); `InvitationCredentialProtectionTest` 16 (headers on every answer including 429 and the provider redirect, three limiters, one budget across many tokens, token and hash out of the real `sessions` table and the activity and application logs, database failure on all four lookup paths); `LegacyInvitationRetirementTest` (pending legacy token dead on GET, POST, SSO initiation and SSO callback, `POST /invitations` gone, source scan for any reader of the legacy service, notification, exceptions, `invitations.token`, table); `UserInvitationProvenanceTest` 6; rewritten `SocialiteTest`, `SuspendedAccount*`, `CustomerPilot*`, `CustomerClosedModeTest`, `MailTextRenderingTest` and `NotificationSendTimeEligibilityTest` (real database queue and worker, including revoked, issuer suspended, address now an account, expired, row gone, transient outage retried) |
| R1-3A suites re-run | flow 92, plus the whole `tests/Feature/Crm`, `Database` and `Admin` directories (655 passed, 20 skipped as above, after one fence-list correction below): the foundation's contracts and its concurrency tests are unaffected |
| Mutation probes (17), one defect each | **all caught**: headers removed; token URL kept as previous URL; credential middleware not ahead of the throttle; plaintext token also in the context; no maximum age; legacy-shaped context honoured; context not consumed; provider binding removed; callback deferral removed; query details reach the log; page throttle removed; ordinary SSO login limited; validation redirect is `back()`; admin provenance removed; limiter keyed by token; a legacy token resolvable by the page; hash kept in the session after a failure. **Twelve were caught first time. Five survived and showed weak tests, which were strengthened (the "provider must not be contacted" is now a counter, the legacy-shape and provider-binding tests assert the exact refusal, the limiter test sends many tokens from one address); one probe had been an equivalent mutant (route group order, which the priority list already decides) and was redefined as removing the priority-list entry.** |
| Pint | clean, 575 files |
| Frontend | `npm run lint` (shadcn rules, **no new suppression; `eslint-suppressions.json` unchanged**), `tsc --noEmit`, `vitest` for the auth pages: pass |
| Blade | `BladeThemeGuardTest`, `BladeControlAdoptionTest`, the accessible-names and form-accessibility suites: pass (the guard caught a hand-built `<a>` on the admin user page, now `<x-ui.link>`) |
| **Not run** | Playwright (`blade-theme-controls.spec.ts` lost its invite-modal case, now `users: list`), `./dev check` as a whole, hosted CI, any staging observation of the client address the limiters key on |

**Test changes to R1-3A's own files:** one line. The role-change source fence in `OrganizationInvitationFlowTest` listed `Services/InvitationService.php` as an allowed caller with the comment "removed in R1-3B"; it is removed from the list, so the fence is now tighter.

### 19.6 Authentication and SSO contract (summary; the full statement is in `docs/architecture/auth-flow.md`)

1. The token resolves only through `organization_invitations` by hash; GET never writes; every dead link is one page; POST takes only `name` and the password.
2. The organization, the address and the role come from the locked invitation; the password hash is computed before any lock; the SSO context holds only the hash, bound to its provider, consumed once, at most 10 minutes old; the callback reads nothing from its request.
3. No failure rebuilds a link from session state; the plaintext and the hash stay out of the session store, redirects, props, logs and the activity log; a database failure on these paths is reported without SQL.
4. The routes carry no-referrer and no-store, a client-IP limiter (60/20/30 per minute) and a "not remembered as previous URL" mark, ahead of both `guest` and the throttle.
5. SSO availability is unchanged: deferred for customers in `closed` and `pilot`.

### 19.7 Owner decisions and risks carried forward

- **O-B1. Resolved 2026-11-12:** `users.invite` is retired (19.8).
- **O-B2. Ruled 2026-11-12: required before customer SSO invitation acceptance is enabled, not weakened (19.8, auth-flow.md).** Original question: whether to require a **verified** provider email (the provider's address is compared, not its verification; Microsoft's `email` claim can be attacker-set). Needs a leaked link today, so it is not exploitable in the pilot.
- **O-B3. Accepted 2026-11-12** (R1-3 stays one PR). Between B and C **no application path issues an invitation**. If B is merged alone, the pilot cannot onboard anyone by invitation until C lands.
- **R-B1. Constants provisionally accepted; host client-IP verification retained as a deployment gate (19.8).** The throttle constants and their key (the client address) are unobserved against the real host (R5). Staging step in `notification-delivery.md`.
- **R-B2.** `ProtectInvitationCredential` relies on the framework treating a prefetch as "not a navigation to remember" (`StartSession::storeCurrentUrl`). A test reads the stored session and has a control, so a framework change fails loudly.
- **R-B3.** The Playwright spec and `./dev check` have not run on this tree; hosted CI is unrun.

### 19.8 Pre-review cleanup under the owner's rulings (2026-11-12, uncommitted)

**Principle (owner).** No production or customer data needs preservation and the platform has not launched: retired functionality and obsolete permissions do not stay in the final product for backward compatibility. R1-3A's foundation is not touched.

**Artifacts removed.**

| Artifact | How |
|---|---|
| Permission `users.invite` | removed from `PermissionCatalogue` (constant and `all()`), therefore from the built-in role definitions (`operator` carries the catalogue), `PermissionSeeder`/`RoleSeeder` and a fresh install; its `ShellPresentationResolver` neutral note removed. No replacement: organization invitation authority is `manageMembership` only. No production path referenced it (a source scan test pins that). |
| Legacy `App\Models\Invitation` and `InvitationFactory` | deleted |
| `User::invitation()` and `invitation_id` in `$fillable` | removed |
| Admin user page "Invited by" (legacy) block, and the legacy eager-load | removed; only the organization-invitation provenance remains |
| `users.invitation_id` (column and foreign key) and the `invitations` table | dropped by the new migration `2026_11_12_090000_retire_legacy_invitations` |
| `foreign-keys.json` entries `users.invitation_id->invitations.id` and `invitations.invited_by->users.id`; `PreservationSchemaTest`'s `invitations.invited_by` expectation | removed |
| Tests that inserted a legacy row (`Invitation::factory()`) | adapted: a retired link is a 64-character token that no table holds, which is what an old link now is |

**Preserved:** `users.invited_by` (acceptance writes it), `organization_invitations`, every R1-3A file.

**Migration and schema consequences.** One migration, after the historical ones: drops the `users.invitation_id` foreign key and column when present, then `invitations` if present (a populated table is dropped as it is: its rows were retired plaintext credentials). It touches no permission (the synchronizer's documented rule is that a migration never edits roles or permissions): **an existing database** keeps a stale `users.invite` permission row and the `operator` role's grant until `php artisan roles:sync` runs; the plan reports the row as stale and revokes the grant on approval. A fresh install never creates it. `down()` recreates the empty legacy structure so a rollback leaves a coherent schema. Fresh `migrate:fresh` and `database:verify` pass. Pinned historical migrations and `database/rehearsal/baseline.php` are **not edited**.

**The one historical-fixture dependency, reported not fixed.** The rehearsal harness (`App\Support\Rehearsal`) builds a Release 0.9 baseline from the pinned migrations, which still create the legacy table and column, and `BaselineFixtures` fills them; that is correct for a 0.9 model and is left alone. The new migration is in neither manifest, so the harness never runs it (R1-0). The fixtures do seed the permissions table from the **current** catalogue, so retiring `users.invite` lowered their row count from 205 to 203 (as adding `portal.operator` raised it from 203 to 205 in R0.9-CS1): two test expectations and `docs/testing/migration-rehearsal.md` were updated. Also updated: `ShellPresentationDriftGuardTest` (neutral capabilities 20 to 19), and the R1-3A role-change fence list had already lost the deleted service in the previous step. No rehearsal framework change.

**Owner decisions recorded.** SSO context lifetime 10 minutes: accepted. Rate limits 60 / 20 / 30 per minute: provisionally accepted. Provider-email verification: required before customer SSO invitation acceptance is enabled; the provider review (Google supported; no Microsoft driver exists, and Microsoft's claims would be unverifiable) is in `auth-flow.md` and no case is weakened or implemented. B/C gap: accepted. Host client-IP verification: a deployment gate. R0.9 staging: untouched.

**Security questions prepared for the independent review:** answered exactly in `docs/architecture/auth-flow.md`, "Security contract clarifications". Short version: the hash **is** persisted in the session store (database driver) until pulled, replaced or the session expires, and is not a bearer credential on any web route but is a session-bound capability; `ProtectInvitationCredential` works by marking the inbound request a prefetch and depends on framework behaviour pinned by a test; `WithholdsQueryDetails` removes bindings from the log at the cost of discarding the original exception's SQLSTATE, class and trace, and runs after the transaction has already rolled back. None of the three mechanisms was changed at that point (the review's F1 and F2 changed the third's reporting and corrected the second's documentation: 19.9). One test was added for the pilot-gate 404 ordering case.

**Additional inert permission candidates (inventory, nothing removed).** A catalogue permission counted as inert when no route, policy, controller, service or view outside the catalogue, the shell classification and the rehearsal fixtures names it.

| Candidate | Status |
|---|---|
| `tickets.view_org`, `projects.view_org`, `tasks.view_org`, `billing.view_org`, `time.view_org` | **Retired in substance**: no code reads them (`RETIRED_USER_DEFAULTS`); organization visibility is membership-based. Safe to retire in the audit. |
| `time.view_own` | Retired default of the `user` role (R1-1); no code reads it. Likely retirable. |
| `org.admin`, `org.invite`, `org.manage_roles`, `users.admin` | **Superseded**: the MO-2A1 design names them as Spatie permissions that grant no organization authority (tests prove it); delegation is the approved-administrator membership. `org.invite` and `users.admin` are named in R1-3 authority tests as "holds nothing"; retiring them means adapting those tests. Retirable. |
| `settings.view`, `settings.manage` | Defined, no surface and no route; `information-architecture.md` lists them as existing. **Intentional extension point** unless the owner drops the Settings module. |
| `crm.view`, `crm.create`, `crm.admin`, `tickets.admin`, `time.manage` | Defined and never checked (CRM routes gate on `crm.manage`); `rbac-design.md` lists them. **Undecided**: neither retired nor tied to a planned release item; decide in the audit. |
| `billing.view` / `billing.create` / `billing.admin`, `time.log`, `projects.create` | **Not inert**: the first and `time.log` are explicit customer grants (`billing.view`, `time.log`), the policy and navigation read the rest (`projects.create` only in navigation). Keep. |

A consolidated catalogue audit can be scheduled before production; none of the above was touched here.

**Verification (disposable `r13_db`; nothing on the shared test database or R0.9 staging).** Fresh `migrate:fresh` and `database:verify`: pass. Focused and regression suites, all pass after the three fixes above: Database (305 with Auth and provenance), the rewritten retirement suite (legacy schema gone, permission gone from catalogue, role definitions and a fresh seed, no production reference, retired links dead on GET, POST, SSO initiation and callback), the new cleanup-migration test (fresh schema; a no-op re-run; a populated legacy structure removed with users and `invited_by` intact; `down()`), invitation password and SSO acceptance, credential protection (17), provenance, `tests/Unit` (permissions, rehearsal, backup, Blade guards), Shell, Pilot, Crm (including the R1-3A flow, authority and concurrency suites), Notifications, Accounts, Admin, Console: 2634 passed and 20 skipped (the Redis-variant cache tests) with the three expected-count fixes then green. Five probes of the cleanup (legacy table kept, column kept, permission back, a production reference, the legacy relation resurrected) were all caught. Pint clean. **Not run:** the complete Pest suite (deferred by the owner until the review corrections are final), Playwright, `./dev check`, hosted CI.


## 19.9 Independent review remediation F1–F3 (2026-11-12, uncommitted)

The independent R1-3B security review returned **SAFE AFTER TARGETED REMEDIATION**; the owner accepted it and authorized F1–F3. The authentication architecture, the O4/R4 foundation, the legacy cleanup and the permission-preservation approach are unchanged.

**F1. Safe SQL failure diagnostics.** `WithholdsQueryDetails` now reports a new `App\Exceptions\InvitationDatabaseFailure` (constant message, **no chained `previous`**) whose structured log context (`invitation_database_failure`) is an allowlist of bounded, validated scalars: SQLSTATE (`errorInfo[0]`), driver error code (`errorInfo[1]`), the violated key name (`DuplicateKey::name()`; `null` when unreadable, never guessed), connection name, original exception class, and the original stack as `file:line Class->function` strings (thrown location first, no `args`, no `object`). Never reported: the original message, `errorInfo[2]`, SQL, bindings, trace arguments, or the original exception. The HTTP answer stays a generic 500; the transaction has already rolled back when a `QueryException` leaves the core (unchanged).

*Unexpected finding while implementing F1, fixed inside F1.* The report's **own** trace is captured at construction, beneath the controller frame whose argument is the **plaintext token** (`InvitationController::show($token)`, `::register(Request, $token)`). With `zend.exception_ignore_args` off, which is PHP's default and this development image's setting (`0`), that trace carries the token as a frame argument for any consumer of `getTrace()` / `getTraceAsString()`. The previous implementation had the same property (a new `RuntimeException` built in the same place); it could expose a **prefix of the plaintext invitation token** in development exception logs: Laravel's configured formatter includes stack traces, and PHP renders each string argument in a trace shortened (to 15 characters) with `...`. (An earlier draft of this record said the default line formatter omits traces; that was wrong and is withdrawn.) It would equally reach an error tracker or any consumer of `getTrace()`. `InvitationDatabaseFailure::from()` therefore constructs the exception with `zend.exception_ignore_args` switched on for that instant and restores it, so the report carries no arguments **where the runtime override is permitted**. **Limitation:** the mechanism is an `ini_set()` at runtime; a host configuration that forbids runtime overrides (for example `disable_functions` listing `ini_set`, or an `ini_set` that the host's PHP handler refuses for this directive) can prevent it, in which case the report's own trace would carry the arguments again. The test pins the behaviour only where the override works (and has a premise assertion proving the original does carry the token in its frames). **The sanitization covers the wrapped database failures only.** Every other exception path (anything thrown beneath the controller frames, or elsewhere in the application) is unaffected by it and depends on the production PHP configuration. **Production exception-argument redaction (`zend.exception_ignore_args = On` in the production PHP configuration, verified on the real host) is therefore a mandatory Release 1.0 readiness gate**, not a nicety (obligation 6 below).

**F2. Prefetch documentation.** `docs/architecture/auth-flow.md` now states that the `Sec-Purpose: prefetch` mark is read by **two** framework components: `StartSession::storeCurrentUrl()` and **Inertia's server middleware**, which skips its redirect-with-fragment conversion (`409` + `X-Inertia-Redirect`) for a prefetch. It explains why that does not affect these routes (none of their redirects carries a fragment) and adds a tripwire test: each redirect the routes issue, sent as an Inertia request, has no `#` and is not a `409`. The middleware is not redesigned; no concrete defect was found.

**F3. Persisted session storage.** New tests read the `sessions` table (the persisted payloads, not the in-memory store) after (a) a request the throttle answers with **429** and (b) a request that ends in an invitation **database failure (500)** on each path (page, POST, SSO initiation, SSO callback). Each starts from an empty table and clears the database handler's "row exists" memory (otherwise it would `UPDATE` a deleted row and persist nothing), so exactly that request's own row is asserted: present (it holds `_token`) and free of the plaintext token and the hash. A control test proves an ordinary page *is* persisted. The earlier normal-GET, authenticated-redirect, pilot-restriction and validation coverage is unchanged.

**Mutation probes (all caught):** prefetch mark removed (7 failures, including the 429 and 500 persisted-session tests); original chained as `previous`; original message reported; ignore-args guard removed; driver text added to the diagnostics; violated key, SQLSTATE or connection dropped; frame arguments added; 500 changed to 503.

**Update (Release 1.0 SSO completion):** items 1, 3, 4 and 5 below are implemented (the session identifier is regenerated at sign-in rather than at initiation) and item 2 is implemented but **not yet verified live**; see [SSO completion](./release-1.0-sso-completion.md). The host checks (items 6 and 7) remain.

**Release 1.0 preproduction requirements recorded (not implemented here, none in F1–F3):**

1. **F4. An accurate `identity_taken` response** before invitation SSO is enabled.
2. **Google and Microsoft provider integration and end-to-end verification.** Microsoft remains a planned, required provider; its sign-in option is **not hidden or removed** merely because its driver is not yet installed (today `Socialite::driver('microsoft')` throws on initiation).
3. **Provider-email verification, failing closed** (O-B2).
4. **Provider display-name length handling.**
5. **Review optional session-ID regeneration on SSO initiation.**
6. **Mandatory readiness gate: verify production PHP exception-argument redaction on the real host**, including `zend.exception_ignore_args`, whether the host permits the runtime override `InvitationDatabaseFailure` relies on, and the effective setting for every other exception.
7. **Verify server and proxy access-log handling** of credential-bearing invitation URLs (and the client-IP assumption, 19.8).
8. **The permission-cache epoch first-use remediation (18.6) and the existing release gates.**

**F5 (optional, non-blocking):** the "only the callback calls `acceptByHash`" fence is not added; no speculative source scanner was introduced.

**Verification (final tree, disposable `r13_db`).** Credential-protection file: 29 passed (new: persisted-session after 429 and after 500 on four paths, duplicate-key diagnostics on four paths, unreadable-key, fragment tripwire, persisted-table control). **Complete Pest suite: 5205 passed, 20 skipped (the Redis-variant cache tests), 0 failed, 41189 assertions, 33 minutes.** Pint clean. No frontend files changed in this remediation. Mutation probes: 10 of 10 caught (one first-pass mutation was malformed and is not counted; it was redone correctly). **Not run:** Playwright, `./dev check` as a whole, hosted CI.


---

## 20. R1-3C implementation record

Branch `feature/r1-3-organization-invitations` on top of `886a2416` (R1-3A and R1-3B, committed and independently reviewed), worktree `../intechral-client-portal-r13`. **R1-3C is implemented and UNCOMMITTED, awaiting the independent Opus security and integration review.** Nothing is pushed, merged or deployed; Release 0.9 staging and the main checkout are untouched. **R1-3A and R1-3B are not reimplemented or redesigned**: no file under `app/Services/OrganizationInvitations.php`, `OrganizationMembership.php`, `OrganizationAuthority.php`, `AccessGrants.php`, `AccountLifecycleService.php`, the notification, the migrations or the acceptance controllers changed behaviour (the one touched file of that family, `WithholdsQueryDetails`, gained a comment). This section also **closes the gap that 19.4 and 19.7 (O-B3) recorded**: between B and C no application path issued an invitation; the operator's card is that path.

### 20.1 What was built

| Piece | Where |
|---|---|
| Operator routes: `organizations.invitations.store` (`POST organizations/{organization}/invitations`, issue and Resend) and `organizations.invitations.destroy` (`DELETE organizations/{organization}/invitations/{invitation}`, revoke) | `routes/web.php`, inside the existing `auth` + `can:crm.manage` organizations group |
| The controller: authorizes `manageMembership` as its first statement, validates the address, calls the R1-3A service, opens no transaction, writes nothing itself; both calls run under the credential-safe database-failure handler of R1-3B | `Http/Controllers/Organization/OrganizationInvitationController` (reuses `Auth\Concerns\WithholdsQueryDetails`) |
| The Invitations card (Blade, Direction D components only): the form, the table, status and reason, inviter, sent, expiry, accepted-by, Resend and Revoke, the empty and truncated states | `resources/views/organizations/_invitations.blade.php`, included by `organizations/show.blade.php` beneath Members; built only for an actor who may manage membership (`OrganizationController::show`) |
| The card's read model: newest 50 rows, derived state, fixed reason labels, Resend/Revoke eligibility, the "cannot be used" notes. Reads only, takes no lock, reads authority from the tables | `Services/OrganizationInvitationList` |
| `organizations:preflight`: an `invitations` section (counts by derived state, open ids and per-organization counts, open invitations that cannot be accepted, unknown revocation codes, six integrity failures). Ids and counts only | `Services/OrganizationPreflight`, `Console/Commands/OrganizationPreflightCommand` |
| The rehearsal integrity snapshot: the same six rules as zero-expected checks, present only on a database that has the table | `Support/Rehearsal/IntegritySnapshot::invitationChecks` |
| `e2e:cleanup-invitees` (20.5) and the seeded, empty **E2E Invitations Org** (`e2e-invitations-org`) it is bound to | `Console/Commands/CleanupE2eInvitees`, `database/seeders/DevSeeder` |
| The browser journey, its Mailpit reader, the global teardown and the `./dev test:e2e` wiring for the new cleanup, the hosted job's `queue` and `mailpit` start | `tests/Browser/organization-invitations.spec.ts`, `tests/Browser/support/mailpit.ts`, `support/global-teardown.ts`, `scripts/dev/commands.sh`, `scripts/dev/tests/run.sh`, `.github/workflows/ci.yml` |
| Documentation reconciliation (20.7) | see the table there |

### 20.2 The operator authorization and invitation lifecycle contract

**Authority.** One boundary and no other: `manageMembership` (an **active** account holding `portal.operator` **and** `crm.manage`), checked (1) by the route (`can:crm.manage`, the first gate), (2) by the controller as its first statement, (3) by the service through the Gate, and (4) again **from the tables, under the user and organization locks**, inside the service's transaction (`isMembershipManager`). A refusal at any layer writes nothing. Not inputs, by construction and by test: the actor's own membership or role in any organization, an administrator's approval, `owner_id`, the managing-partner relationship, the global `org.*` and `users.*` permissions.

| Actor | Issue / Resend / Revoke / see the card | Result |
|---|---|---|
| Operator (`portal.operator` + `crm.manage`, active) | yes, any organization | |
| Approved or unapproved organization administrator holding `crm.manage` | no | 403 |
| Member holding a stray `crm.manage`; holder of `org.invite` + `crm.manage`; `portal.operator` without `crm.manage` | no (the page for the first two shows **no card at all**, not a hidden one) | 403 |
| Partner administrator of a managing organization | no | 404 (the tenant scope already hides a customer's organization from a non-member) |
| Ordinary customer; the account an invitation created | no | 403 or 404 |
| Suspended operator | no | 403 |
| Guest | sign in | 302 to login |

**Inputs.** `email` (a string, an address, at most 255). The organization is the route's; an invitation id names a row **within** that organization (the service looks it up under the organization lock, so another organization's id is a 404, never "forbidden", and a non-numeric id never reaches the controller). An organization, a role, an inviter, an expiry, a slot or a state in the body is **ignored** (tested). The service normalizes the address (`normalizeEmail`) and decides existence by the database.

**Responses.** Success redirects to the organization page with a flash that names the address (operator-only data) and the seven-day lifetime; Revoke says "Invitation revoked. Its link no longer works." or, for a row that is no longer pending (accepted, revoked, expired, superseded), "That invitation was no longer pending. Nothing was changed." and writes nothing, not even a second audit entry. Refusals that are the operator's to act on come back as a **field error** with the attempted address kept: the address already belongs to an account (active or suspended: use Add member) and "another invitation for this address was issued at the same moment" (the unique key's backstop). A missing organization or invitation is 404; an authorization failure is 403; an unexpected database failure is a plain 500 whose log entry carries no address, token, hash or binding (20.4).

**The card's lifecycle language** (derived, never stored): **Pending** (with the expiry, and a plain note when the link cannot be used: the address is now an account, or the inviter can no longer invite), **Accepted** (when, and by whom: a link to the user page only for a viewer who holds `users.view`), **Revoked** (with one of five fixed labels for the closed set of reason codes: revoked by an operator, replaced by a newer invitation, the inviting operator was suspended, lost the authority, or their authority changed; an unknown or missing code reads plainly "Revoked"), **Expired** (kept, never relabelled revoked). **Revoke** is offered on a Pending row only; **Resend** on the newest Pending or Expired row of an address only (an older expired row must not supersede a newer open one). Resend is the same route as issue: it supersedes an open predecessor (its link dies, its queued mail is dropped at send time) or only releases an expired one's slot. **The service's transaction-entry contract is kept**: the controller is not in a transaction and neither opens one nor retries; `issue` and `revoke` are entered at the top level and run their own (with the R1-3A bounded retry).

**What the card never shows:** a link, a token, a hash (it is not selected), a revoker's identity beyond the fixed label, or another organization's rows. A customer who opens their own organization's page sees none of it.

### 20.3 Notification integration, verified through the routes (`OrganizationInvitationOperatorTest`)

Real database queue and the array transport; "sent" is a `MessageSent` event carrying the rendered message.

| Requirement | Proof |
|---|---|
| The mail carries the correct organization-bound link | the decoded message contains `/invitation/<token>` whose SHA-256 equals the stored `token_hash`; opening it renders the registration page naming **this** organization and address; the stored row and the audit entry hold the hash only |
| Only the intended new account can accept | the recipient form takes a name and a password; an address, an organization, a role or an approval smuggled into the body is ignored; the account is created on the **invited** address with role `user` only and exactly one ordinary, unapproved membership of the invited organization; an existing account is refused unchanged; the same address invited to two organizations creates one account and one membership |
| Dispatched after commit | nothing is mailed until a worker runs; an issuing transaction that fails (the audit write) leaves **no row and no job**; a failure to enqueue after the commit leaves a Pending row and no mail (R4) |
| Send-time eligibility | the mail is **not sent** when the inviting operator is suspended before the worker runs, when the address becomes an account, and when the inviter loses the authority by a path no hook covers (a direct table write) |
| Reissue and revocation invalidate earlier links | a resend: the first link is dead on GET and POST and its queued mail is dropped, only the second link is mailed and works; a revocation: the link is dead and its queued mail dropped; both leave the uniform page and write nothing |
| Unexpected database failures log no token, hash or recipient binding | forced failures on the audit insert, the invitation insert, the queue insert and the revoke audit: the report contains `invitation_database_failure` (SQLSTATE, driver code, key name, call-site locations) and **none of** the address or the hash, in the log or the response; the plaintext never reaches the application at all (it exists only in the encrypted job and the mail) |

### 20.4 Credential-safe handling on the operator paths

The issue insert binds the token's **hash** and the recipient's **address**, which is operator-supplied personal data; the activity-log insert binds the address too. A `QueryException`'s message embeds the SQL with its bindings, so both actions run under `WithholdsQueryDetails` (R1-3B, with the F1 allowlisted `InvitationDatabaseFailure`): the same mechanism, not a new one. The residual stated in 19.9 applies unchanged: non-database exceptions thrown beneath `issue($organization, $email, $actor)` carry the address as a frame argument wherever the host's `zend.exception_ignore_args` is off, which is why **production exception-argument redaction remains a mandatory readiness gate** (obligation 6). The plaintext token never appears as an argument on these paths (the controller never sees it).

### 20.5 `e2e:cleanup-invitees`

The browser journey accepts real invitations, so each run leaves an account, a membership and an invitation that nothing else deletes. The command is the one narrow tool C2 approved; **it is not a general account or database cleanup**. Its contract, each rule pinned by a test (`CleanupE2eInviteesTest`, `InvitationFlowTest` fences, the `./dev` self-tests):

- **Environment.** `local` and `testing` only: both the runtime and the configured environment, checked before any read; `--env` cannot authorise a run (proved in a child process pointed at an unreachable database). No argument, no option.
- **Three markers at once.** Address exactly `e2e-invitee+<run>@example.test` (lower case, a reserved test domain) **and** created by an accepted organization invitation **and** into one of the suite's own organizations (`e2e-invitations-org`, seeded empty). A pending, expired, revoked or superseded invitation: the same address and the same organization. A partial match (the pattern outside the suite's organization, an accepted invitation by a differently addressed account, the pattern on an account no invitation created, an operator-like account on the pattern) is **ambiguous: reported, left, exit non-zero**. A real organization's invitations and members, and every account off the pattern, are never touched.
- **Refused, deleting nothing of the account:** any role beyond the built-in `user`; any direct permission; operator eligibility or membership-management authority (from the tables, so even the `user` role being given the capability refuses every invitee); a membership of another organization; and **any row anywhere referencing the account** other than the four kinds removed. The referencing tables come from the schema's foreign keys at run time, so a table added tomorrow refuses the account until a person decides (tested with a table created in the test); tickets, time entries, tasks, comments and projects therefore block it, and the ticket cleanup is **not** extended to invitees.
- **One transaction per account**, in the global lock order (the account's row, the organizations ascending, its invitations ascending, verified from the statement log), re-checking everything under the locks, deleting in dependency order: accepted invitation rows, memberships, password histories, social accounts, sessions, the role assignment, the user. A failure rolls that account back whole and the run continues, then exits non-zero; the report names ids and counts, never an address, token or hash. The activity log is audit and is left alone. Idempotent.
- **Single-writer fences.** The command is a **named exception** in the two source fences (`organization_invitations`, `organization_members`) with a third test pinning that it only ever **deletes** those tables (no insert, update or upsert, no model, no membership service).
- **Wiring.** `./dev test:e2e` runs it **after each ticket cleanup** (recovery sweep, whose refusal stops the run before it starts; and after Playwright), folds its status into the run's and the summary line; a hand-started suite gets both from `globalTeardown`, tickets first, each failure reported even if both fail. No test, fixture or worker runs it (a self-test pins the single permitted user file).

### 20.6 Browser journey

`organization-invitations.spec.ts` (16 tests after the review remediation; 12 at the first handoff) against the seeded organization: issue and the pending row; the recipient follows the link **read from Mailpit** (as a person reads their mailbox), sees the organization, registers, lands signed in, and the same link is no longer the form; the new member is refused every operator route; the operator sees Accepted with the name and nothing left to revoke; re-inviting the address is refused beside the field; revoke (dead link, fixed reason label); resend (first link dead, second works, history kept); tokens that were never issued; the recipient's mismatched confirmation (the person stays, the field is named, nothing is written); the operator form by keyboard and its error association; the refusals for an ordinary customer (page, both routes, nothing mailed) and a guest.

**Deviations from the approved browser specification (section 13), both deliberate.** (1) It does **not** raise a ticket as the invitee or show that another member's ticket stays invisible: a ticket raised by an invitee is not one the ticket cleanup may delete (its owners are the seeded customers), and the invitee cleanup refuses an account that has one, so such a step would leave an undeletable account. Both are proved in Pest (the operator-to-recipient journey in `OrganizationInvitationOperatorTest`, and the R1-2 suites); the R1-2 browser specs cover the seeded members. Widening the ticket cleanup to invitees is an **owner decision** (20.9). (2) It needs the stack's `queue` and `mailpit` services, a new infrastructure dependency of the browser suite (docs/testing/e2e-browser-suite.md).

**Finding, FIXED in the review remediation (20.10 R1): the effective `Referrer-Policy` depends on the web server.** The application sent `Referrer-Policy: no-referrer` on the invitation pages; the stack's nginx **appended** `strict-origin-when-cross-origin`, the response carried both values and a browser applies the **last** one, so Chromium sent the full token-bearing URL as the `Referer` of same-origin requests and nginx logged it. The first handoff recorded this as a finding and asserted the header only as "one of the values"; the independent review demonstrated the leak. It is now closed at both layers and proved from the browser's side (20.10). The effective policy and access-log handling on the production host (LiteSpeed) remain obligation 7.

### 20.7 Documentation and preflight reconciliation

Section 10's "Docs" list and the C package's reconciliation, each against the implementation:

| Document | State |
|---|---|
| `auth-flow.md`, `account-lifecycle.md` | reconciled in R1-3B (the legacy flow is described as retired; hashed token; SSO context holds the hash); unchanged by C |
| `notification-delivery.md` | queued list and send-time row (B); **C adds** the first production caller, the operator-visible consequences, the credential-safe failure behaviour and the no-throttle statement |
| `role-governance.md`, `release-0.9-scope-and-architecture.md` §10.1 | H10 annotated **resolved for the invitation flow** (hashed token, encrypted queued mail); the other H10 sub-questions unchanged |
| `customer-pilot-surface.md` | the two new route names, operator-only twice over, **no allow-list change** (B had updated the rest) |
| `release-1.0-notes.md` | the R1-3 change row; "not in this release yet" corrected (MO-2A2 is no longer pending; customer-side invitation, existing-account invitation and customer invitation SSO remain); two more migrations, the queue and `roles:sync` facts, the new dev-only command |
| `product-roadmap.md` | forward note (written at implementation, as for R1-2) |
| `database-schema.md`, `migration-rehearsal.md` | the table's reporting rules and the snapshot checks |
| `e2e-browser-suite.md`, `ci.md`, `README.md` | the spec, the Mailpit dependency, the cleanup, the hosted-job change (marked unproven) |
| `EPIC-002`, `EPIC-011C` | historical epics: a supersession note at the lines that described the retired route, permission and 48-hour flow |
| this record and `docs/product/README.md` | status, this section |

**Preflight and expected counts.** `organizations:preflight` gains the section of 20.1 (design record 10: "an optional, ids-only count of open invitations", extended with the integrity rules so the report can fail on data the service could never write). The snapshot's checks appear only when the table does, so **no existing expected count changes**: the Release 0.9 rehearsal baseline still has none of them (R1-0: the 1.0 migrations are not candidates), and `InvitationIntegrityChecksTest` adds the real migration to the rehearsal primary to prove the six rules, the empty baseline and the application-clock rule. The expected counts that **did** change are those of the new fixtures: the seeded organizations (5 to 6, with the suite's empty organization) in `DevSeederPersonasTest`, and the Level B Blade inventory (59 to 60, `organizations/_invitations`) in `BladeThemeGuardTest`.

**What stays as the reviewed design says, and is stated so.** The O4/R4 limitations of 6.2.3 and 18.8 (no global deadlock-freedom claim; the cache-table gap-lock residual; guard-class transactions do not retry; the commit race; the epoch first-use defect) are **unchanged** and not re-argued here. The permission catalogue **retains its deliberate forward-looking capabilities** (`org.invite`, `org.admin`, `org.manage_roles`, `users.admin`, the Settings and CRM capabilities listed in 19.8): none was touched, none grants anything here, and tests continue to use `org.invite` as an input that must grant **nothing**. **The customer/administrator authority distinction is explicit**: an organization administrator, approved or not, holds no operator authority and cannot issue, resend, revoke or list invitations; customer-side delegation is MO-2B and would extend the one `authorize()` seam of the service, not this controller.

### 20.8 Evidence

*(At the first R1-3C handoff. The review remediation's own evidence is in 20.10; where a number differs, 20.10 is the later one.)*

Disposable MariaDB 11.4.13 (`r13_db` on the private network `r13net`); never the shared development or testing database, never Release 0.9 staging. The browser stack was a separate set of containers on `r13net` (PHP-FPM, nginx, a queue worker, Mailpit, and a separate database `r13_e2e`), not the main `portal_*` stack.

| Gate | Result |
|---|---|
| New or extended Pest files | `OrganizationInvitationOperatorTest` (54), `CleanupE2eInviteesTest` (42), `OrganizationPreflightTest` (19, 11 new), `InvitationIntegrityChecksTest` (9), the two source fences, the pilot route-boundary rows (the whole Pilot directory: 236 passed), `DevSeederPersonasTest`, `LegacyInvitationRetirementTest` (a tripwire made precise: the retired action is named by its namespace), `BladeThemeGuardTest` (inventory 60) |
| **Entire Pest suite, final tree** | **5323 passed, 20 skipped (the existing Redis-variant cache tests, skipped identically in R1-3A and R1-3B: no Redis on the disposable network), 0 failed** (42023 assertions, 2020 s, one process). The run began before the last test added to `OrganizationInvitationOperatorTest` (the first link dead by GET and POST after a delivered-mail resend); that file was re-run on its own afterwards: 54 passed (452 assertions) |
| Mutation probes (20 on the new code, one defect each) | 19 caught first time; **one survived and exposed a weak test** (the controller's refusal handling: `followingRedirects()` then `assertSee` passed on a 500 debug page that quotes the exception message). Strengthened to assert the redirect and the session error, plus a new test for the concurrent-issue backstop, and re-run: caught. Probes: the credential-safe wrapper removed from issue and from revoke, the authorize removed from the controller, the address rule loosened, the card built for everyone, Resend on every row, the "cannot be used" note removed, rows of other organizations listed, and for the cleanup: no environment guard, the pattern loosened, no organization lock, every organization counted as the suite's, roles, referencing tables and operator eligibility not checked, sessions not removed; and the preflight and snapshot rules |
| Browser (Chromium, isolated stack, legacy mode) | the new spec: **12 passed**; the **entire suite: 234 passed, 1 skipped** (the pilot spec, which skips outside pilot mode and is covered by the hosted pilot job). The two cleanups were then run as the wrapper does: tickets (26 removed) then invitees (1 account, 1 membership, 6 invitations), and a second run of each found nothing |
| `./dev` self-tests | 419 assertions pass (the new lifecycle, order, identity, argument and failure-policy tests included). `shellcheck` is not installed here |
| Frontend | `npm run lint` (no new suppression; `eslint-suppressions.json` unchanged), `tsc --noEmit`, `prettier --check` and `vitest` (1988 tests) pass; the spec and its helpers type-check under the project's strict options in a temporary configuration (the project's own tsconfig does not include `tests/Browser`) |
| Blade | `BladeThemeGuardTest`, `BladeControlAdoptionTest`, `TargetAccessibleNamesTest`, `TargetFormAccessibilityTest` pass |
| Pint | clean |
| **Not run** | hosted CI (including the changed `browser` job: the `queue` and `mailpit` start step is unproven), `./dev check` as a whole (its steps were run individually, against the isolated stack, because `./dev` targets the main stack), the pilot browser spec in pilot mode, any staging observation |

### 20.9 Residual risks and owner decisions

**Owner decisions.**

1. **Widen the ticket cleanup to invitees?** **Ruled: no** (review remediation: "Do not broaden the E2E cleanup command to remove Tickets"). The browser journey still cannot raise a ticket as the invitee (deviation 1 of 20.6); that stays a Pest matter.
2. **A per-operator limit on issuance?** **Ruled and implemented: 30 attempts an hour per authenticated operator**, issuance and Resend together, revocation exempt (20.10).
3. **Is the browser suite's dependency on the stack's queue worker and Mailpit acceptable on hosted CI?** **Ruled: yes**; the startup, readiness, failure behaviour and cleanup are now explicit (20.10, `docs/testing/ci.md`). Still unproven until the first hosted run, which is a merge gate after the PR is opened, not a gate for the local commit.

**Residual risks (none new in the security architecture).**

- **Hosted CI is unrun** for the whole branch, including the changed `browser` job (`docker compose up -d --wait queue mailpit` after composer, `.env` and migrate). If the worker or Mailpit does not come up the spec fails naming that cause; it never skips.
- **The commit-to-dispatch gap (R4)**: a process that dies, or a queue insert that fails, between the commit and the dispatch leaves a Pending invitation and no mail; the card shows Pending and Resend recovers it. Tested for the enqueue failure.
- **A dead link can read Pending** until it expires or is revoked (R7) when the issuer lost the authority through a path the revocation hooks do not cover. The card now says so for that case and for "the address became an account"; acceptance and send time decide independently of what the card says.
- **The effective Referrer-Policy and access-log handling of the token URL on the production host** (20.6, 20.10; obligation 7). The development stack is fixed and tested; the production web server's behaviour, and its access log's recording of the token-bearing request line, are still to be observed (a Release 1.0 preproduction requirement, unchanged).
- The operator routes inherit the R1-3A concurrency contract and its stated limits; no lock order was changed. The new `e2e:cleanup-invitees` takes its locks in the global order and runs only in local and testing.

**Preproduction obligations carried forward unchanged (19.9, none implemented here):** F4 an accurate `identity_taken` response before invitation SSO is enabled; Google and Microsoft provider integration and end-to-end verification (Microsoft's option is not hidden); provider-email verification, failing closed; provider display-name length handling; optional session-ID regeneration on SSO initiation; **production exception-argument redaction (a mandatory readiness gate)**; server and proxy handling of credential-bearing URLs and the client-IP assumption; the permission-cache epoch first-use remediation (18.6, with the preload hardening of `TicketService::create()` before any non-HTTP ticket path); and the existing Release 1.0 gates.

### 20.10 Independent-review remediation (R1-3C, 2026-11-12)

The independent Opus review returned SAFE AFTER TARGETED REMEDIATION; the owner accepted it and authorized R1 to R4, the throttle and the CI confirmation. No security architecture of R1-3A or R1-3B changed; nothing was committed.

**R1. The Referrer-Policy leak (confirmed by the reviewer, now closed at two layers).**

- *Application.* `ProtectInvitationCredential` sets the request attribute `credential_response` (public constant `ATTRIBUTE`, set nowhere else), and `app.blade.php` renders `<meta name="referrer" content="no-referrer">` **immediately after `<meta charset>`**, before the viewport tag, the pre-paint script, the font preloads and the assets, only when the attribute is set. The attribute is set per request by the middleware, so a client cannot turn the tag on or off (a Pest test sends the hostile header and query on `/login`), and ordinary pages carry no tag and no new global policy. The meta policy is the document's own and is not overridden by a response header, whatever a proxy appends.
- *Development nginx.* `.docker/nginx/default.conf` gains `map $sent_http_referrer_policy $default_referrer_policy { "" "strict-origin-when-cross-origin"; default ""; }` and `add_header Referrer-Policy $default_referrer_policy;` (an empty value emits nothing). The default is supplied only when the application sent none; nothing else in the proxy configuration changed. Observed by `curl` through the stack: the invitation page answers with the single value `no-referrer`, `/login` with the single value `strict-origin-when-cross-origin`.
- *Tests.* Pest (`InvitationCredentialProtectionTest`): the tag is on the invitation page, live or dead, exactly once and in front of every request-making tag; absent on login and password-request; a client cannot select it. Browser (`organization-invitations.spec.ts`, `support/referer-capture.ts`): the credential response has exactly one `Referrer-Policy` line; the meta tag directly follows `<meta charset>`; an ordinary page keeps the stack default and no tag; a capture of **every** request across page load, a same-origin `fetch`, an image, a link navigation and the registration POST shows **no `Referer` carrying `/invitation/`** (it also asserts the capture saw same-origin assets and the POST, so it cannot pass on an empty capture); and a **regression control** serves the page through a loopback proxy exactly as the old stack did (two policy lines, no meta tag) and asserts that the same capture *does* see the token in same-origin asset `Referer`s.
- *Mutation evidence (browser, isolated stack).* Original nginx and no meta tag: the capture test **fails** with the leaked URL as the received `Referer`, and the header/meta test fails. Original nginx with the meta tag only: the capture test passes (the meta alone protects the effective behaviour) while the header test fails on the duplicate line. Fixed nginx without the meta tag: the capture test passes and the header/meta test fails on the missing tag. Both fixes: all four credential tests pass. The nginx access log of the final-state run has no line whose `Referer` field carries the invitation route, against 18 such lines in the log accumulated over the mutation runs. (Static assets are logged `off`; the **request line** of the invitation URL itself is still logged: that is the access-log handling of obligation 7.)
- *Effective browser behaviour, final stack:* one `Referrer-Policy: no-referrer` header, the same policy in the document, no `Referer` on any request the page causes, the navigation away from it, or the registration POST; ordinary pages unchanged.

**R2. Cross-organization Resend.** `OrganizationInvitationOperatorTest`: one address invited by two organizations (the second later). Each organization's list is its own and each shows Resend on its own newest row; the same page asserts the control is rendered; a control inside one organization shows an older row of the address still loses Resend once a newer one exists there. The test **fails if `where('organization_id', ...)` is removed from the newest-row query** (mutation run); production code unchanged.

**R3. Cleanup race.** A deterministic test (`CleanupE2eInviteesTest`): a pending reserved-address invitation is planned, then, just before the run's first `organizations ... FOR UPDATE` statement (a `DB::beforeExecuting` hook, a real acceptance through the service), the recipient accepts. The lock-time check sees `accepted_at`, so **the invitation is not deleted** and the account it created is untouched; the next run removes both as an ordinary invitee. Removing the under-lock `accepted_at === null` check fails it (mutation run). The check itself is unchanged.

**R4. Browser assertions.** After registration the same link is opened in a **fresh anonymous context** and must show "Invitation unavailable" (the signed-in `guest` redirect is no longer the thing being tested). A separate test reads the mail from Mailpit and asserts its link is absolute and on the configured `APP_URL` origin (`configuredAppOrigin()`: the environment, else `src/.env`). A Pest test issues under a hostile `Host`/`X-Forwarded-Host`, then delivers from a console-style request built from `app.url` exactly as `queue:work` boots (`SetRequestForConsole`), and asserts every link in the message is on the configured origin and the hostile host appears nowhere (including the job payload); building the URL in the notification's constructor instead (a mutation) fails it. The Ticket cleanup scope is unchanged.

**Issuance throttle.** `App\Support\InvitationIssueThrottle`: the named limiter `invitation-issue`, 30 per hour, keyed `user:<id>` (falling back to the client address only if there is no account, which `auth` makes unreachable), attached to **`organizations.invitations.store` only**. Pest (`issuance throttling`): the 31st attempt is a 429 with `Retry-After` that creates, changes, audits and mails nothing (footprint, audit count and drained mail count compared), and a Resend is limited too; an invalid attempt counts; two operators have independent allowances; a customer's thirty refused attempts do not touch an operator's allowance and every refusal below the limit is still the 403 it was; **revocation and the organization page still work after exhaustion**; the middleware is on the issuing route and not on the revoking one; clearing one account's key restores that account only. The browser suite's repeatability: `e2e:cleanup-invitees`, inside its existing environment guard, clears the **seeded E2E operator's** counter (`operator@intechral.test`, never an argument) and says so; it clears nothing in a refused environment (tested). It runs in the same two places as the cleanup (the recovery sweep and after Playwright). There is no reset route and the production limit is unchanged. A whole browser run uses about ten of the thirty.

**CI integration (owner-approved).** See `docs/testing/ci.md`: the job now refuses to proceed unless `src/.env` delivers mail to the compose Mailpit (`MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`) and queues on `redis` (what the compose worker consumes); starts `queue` and `mailpit`; **proves** readiness (Mailpit API with retries, the worker running `queue:work`) because neither service has a health check; and on failure prints only the worker's log lines with token-shaped strings masked and Mailpit's message count. No message body is fetched, no Playwright trace is uploaded, nothing in the job holds a provider credential. Hosted CI remains unrun.

**Evidence, final tree.** Recorded in the handoff for this remediation (the test counts change as the suites grow; the commands are in `docs/testing/ci.md`). Hosted CI, the whole of `./dev check` as one command, the pilot-mode browser spec and any staging observation remain **not run**.
