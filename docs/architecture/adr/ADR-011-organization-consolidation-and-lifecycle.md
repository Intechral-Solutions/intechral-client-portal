# ADR-011: Organization consolidation and portal lifecycle

- **Date:** 2026-11-18
- **Status:** **Accepted** as the approved **target architecture** (owner rulings of 2026-11-18, following the architectural reconciliation assessment and its independent review). Packages ORG-1 to ORG-3 implement it; each needs its own explicit owner authorization ([ORG-0 record](../../product/release-1.0-org-0-organization-consolidation.md)). Until a package merges, `main` behaves as described in the as-built documents, not as described here. **ORG-1 is implemented and merged** (PR #74, `main` `0f69fd9`): [ORG-0 record, section 11](../../product/release-1.0-org-0-organization-consolidation.md#11-org-1-as-built-implementation-record). **ORG-2 is implemented and merged** (PR #75, squash `11a6a73`, 2026-11-21; post-merge CI on `main` `11a6a73` (run 44897642399) is green on its re-run (attempt 2): attempt 1 failed one step, the database-store permission-cache gate (DB-F2, `1205 Lock wait timeout` in `PermissionCacheDatabaseStoreTest`, a known flake family that ORG-2 does not touch), and nothing else; not deployed): [ORG-2 record](../../product/release-1.0-org-2-physical-consolidation.md). **ORG-3 is approved as a four-package sequence** ([ORG-3 record](../../product/release-1.0-org-3-organization-workspace.md)); only ORG-3a (portal-access correctness) is implemented on its own branch and not merged until its pull request is. ORG-3b to ORG-3d, DIR-3 and DIR-4 are not implemented or authorized. The decisions below are unchanged by ORG-2's implementation.
- **Supersedes:**
  - [ADR-010](./ADR-010-directory-identity-organization-model.md) **decision 2** ("Organization: one concept, two records"), the **name-synchronization row of decision 3**, and the **"Physical consolidation"** section.
  - The **DIR-2** work package of the [DIR-0 record](../../product/release-1.0-dir-0-directory-identity-organization.md), which is replaced by ORG-1 to ORG-3.
  - The MO-1A "pair of exactly one of each" model, the one-company rule and the company delete guard, **for the target model**. They describe the code until ORG-2 retires them ([MO-1A](../../product/release-1.0-managed-organizations-mo-1a.md)).
  - Roadmap ruling R13 of DIR-0 and the 2026-11-17 roadmap statement "Not required: physical consolidation".
- **Amends:** [ADR-005](./ADR-005-organization-multitenancy.md) (Amendment 3), [ADR-003](./ADR-003-spatie-permission.md) (Amendment 1), ADR-010 (Amendment 1).
- **Keeps:** ADR-010 decisions 1, 4 (as re-pointed below), 5, 6, 7, 8 and 9, and its "Multiple affiliations" section.
- **Related:** [R1-3](../../product/release-1.0-r1-3-organization-invitations.md) (invitation lock order), [R1-4](../../product/release-1.0-r1-4-partner-support.md) (disable state, partner support), [MO-2A1](../../product/release-1.0-managed-organizations-mo-2a1.md) (membership and approval), [Release 1.0 notes](../../product/release-1.0-notes.md) (R1-0 data ruling).

Labels used in this ADR: **Approved** is an owner ruling or an approved target. **As built** is verified in the repository at `main` `010d4e0`. **Future** is recorded so later work can start from it; it is not a Release 1.0 commitment.

## Context

ADR-010 (2026-11-17) chose to present one Organization over two tables, `crm_companies` (the Directory profile) and `organizations` (the tenancy record), and to defer physical consolidation. It also planned DIR-2 to keep the two in step: name synchronization, a stronger delete guard and a profile-repair path.

An architectural reconciliation of 2026-11-18 re-examined that choice against the code (read-only, no database or test execution). Its findings, confirmed by an independent review of the same day:

- Every authority-bearing foreign key already points at `organizations` (tickets, memberships, invitations, the managing relationship). Only People, the project link and two tenant scopes point at `crm_companies`. The two tables share one fact, the name, and no code synchronizes it.
- DIR-2 was unbuilt, so there was no sunk cost, and most of its service work existed only to compensate for having two tables.
- Release 1.0 is a fresh install with no authoritative data (R1-0), so consolidation is cheapest now, as ADR-010 itself observed.
- **Existing defects, independent of consolidation.** A disabled Organization can still be invited into and joined: invitation, membership and relationship writes read no organization state (`disabled_at` is read only by request filing, partner support, support grants and the dashboard). "Enable portal access" has no service-level authorization and writes no audit entry. These need a ruling whatever the table layout.

The owner approved consolidation and ruled on the open questions. This ADR records the result.

## Decision

### 1. One canonical Organization (Approved)

- `organizations` becomes the **single canonical Organization table**. There is one Organization id, one name, one profile and one lifecycle.
- `crm_companies` is **retired by a forward migration in ORG-2**. It is not retired in ORG-0 or ORG-1.
- An Organization may exist in the Directory **without portal access** (Directory-only).
- Every existing reference keeps pointing at the canonical Organization: memberships, tickets, invitations, partner-support grants, and the managing relationship.
- **No Git rollback and no rewrite of migration history** is planned. Shipped migrations are not edited, so every SHA-256 pin of the rehearsal harness stays valid, and the release verification and recovery guarantees stay authoritative.
- Because there is one name, **the name-synchronization, profile-repair and "profile cannot be lost" mechanisms of ADR-010 and DIR-2 are not built**: the invariant they protected is true by construction.

### 2. Portal lifecycle (Approved)

Portal participation is an explicit state of the Organization row.

| State | Meaning | Row condition (planned) |
|---|---|---|
| **Not enabled** | Exists in the Directory; has never participated in the portal | `portal_enabled_at` is NULL |
| **Enabled** | Portal participation is available, through independently authorized memberships and permissions | `portal_enabled_at` set, `disabled_at` NULL |
| **Disabled** | Previously enabled; portal participation is suspended | both set |

Valid transitions: Not enabled → Enabled; Enabled → Disabled; Disabled → Enabled. **There is no transition from Enabled or Disabled back to Not enabled.** An Organization that ever had portal access is disabled, never demoted, so tickets, memberships and invitations never reference a Directory-only row.

- **Two separate acts.** *Initial enablement* (Not enabled → Enabled) and *re-enablement* (Disabled → Enabled) are different, separately authorized and separately audited operations. Initial enablement **refuses a Disabled row** and never clears `disabled_at`. Re-enablement **refuses a Not enabled row**. Disable refuses a Not enabled row. Each refusal is a clear message from the service, never a database error.
- **Provenance.** Planned columns `portal_enabled_at` and `portal_enabled_by` (the operator, a foreign key to `users`, RESTRICT). They must **both exist or both be NULL**, stated as a database CHECK (`(portal_enabled_at IS NULL) = (portal_enabled_by IS NULL)`) and re-checked by the preflight and the integrity snapshot, because `database:verify` pins foreign keys, not CHECK constraints.
- **Provenance is not authority.** As built, `organizations.owner_id` (NOT NULL) records "the operator who enabled portal access". `OrganizationPolicy` already states that it is not an authority input, and DIR-1 labels it "Enabled by". It is **replaced by `portal_enabled_by`** (a rename that makes the column nullable, so one column carries one meaning). Nothing may treat the enabling operator as holding authority over the Organization.
- Further planned CHECKs, as a backstop to the services: `disabled_at`, `can_manage_customers` and `managed_by_organization_id` may be set only when `portal_enabled_at` is set.
- **Authority is two separate abilities.** *Initial enablement* (Not enabled → Enabled) is authorized by **`manageMembership`**. *Disabling and re-enabling* an existing portal Organization **retain `manageRelationships`**, as implemented in R1-4. They are separate operations decided by separate policy abilities. Both abilities currently reduce to the same capabilities (an active account holding `portal.operator` and `crm.manage`), but they are not interchangeable, and their future semantics must not be collapsed into one. Each is checked in the controller **and** again in the service, from the tables, with a table-based check under the Organization row lock, and each act writes an audit entry. As built, initial enablement is guarded only by the `crm.manage` route permission (in `pilot` and `closed` customer modes the pilot gate refuses non-operators on `crm.*` routes, so over HTTP it effectively needs `portal.operator` as well); the service performs no check and writes no audit entry. Legacy mode has no such gate.

### 3. Behaviour of a Disabled Organization (Approved, final owner ruling)

**Refused while an Organization is Disabled:**

- issuing, resending or reissuing an invitation (any new live invitation);
- accepting an invitation;
- new memberships (operator add);
- membership **privilege elevation** (promotion) and **administrator approval**;
- new partner-support grants;
- new partner designations and new managing relationships (designate, link or reassign a customer);
- new Service Requests, and partner-support activity for or by the Organization (read, reply, create), which stay suspended.

**Allowed, subject to the existing authorization of each act:**

- membership removal and demotion;
- revocation of administrator approval;
- revocation of a support grant;
- revocation of an invitation;
- removal of a managing relationship (unlink, remove a designation);
- ordinary Directory profile maintenance and non-privileged settings, including the Classification.

**Preserved.** Historical visibility and replies are unchanged where they are already authorized: a requester keeps access to their own existing Service Requests and may reply. Disabling does **not** delete memberships, close accounts, terminate sessions or lock a requester out. **No organization-wide historical Ticket visibility is introduced**: visibility stays the requester or an operator holding `tickets.assign` (ADR-005 Amendment 2).

**The effective-access rule recorded for every later package.** Blocking new writes is sufficient for Release 1.0 only because the two readers of membership-derived authority (request filing and partner support) already refuse a Disabled Organization. Records of administrator approval and support grants are kept, and grant nothing while Disabled. Any future Organization-scoped authority must require **the record AND the Organization Enabled and not Disabled AND the user active**.

As built (2026-11-18), the refusals in the first list are **not** enforced for invitations, memberships and relationships: no existing test covers invitation or membership writes against a Disabled Organization. ORG-1 introduces them, so this is a behaviour change operators can see.

### 4. Outstanding invitations are revoked on disable (Approved, final owner ruling)

Disabling an Organization **permanently revokes every outstanding live invitation**.

- Revocation is **in the same transaction** as the disable.
- Each invitation's historical row is kept. The revocation carries the stable reason **`organization_disabled`** and audit provenance (actor, time, organization).
- A queued invitation notification must not send after revocation (the existing send-time eligibility check already drops a revoked row; it must also refuse a Disabled Organization).
- **Re-enabling never reactivates them.** A revoked invitation is terminal by the existing rules; a new invitation is a new credential, issued deliberately.
- **Acceptance independently revalidates Organization state** under the Organization row lock, so the acceptance-time check, not the revocation, is the guarantee against a missed row.
- The disabling transaction follows the **actor-first** lock order (section 13): `revoked_by` is a foreign key to the actor, so the actor's user row is share-locked first. Its full transaction contract (lock sequence, deterministic invitation locking, revalidation under lock, no Gate and no permission-cache loading inside it, and slot handling as a design decision) is in the [ORG-0 record, section 6.2](../../product/release-1.0-org-0-organization-consolidation.md#62-disable-time-invitation-revocation-transaction-contract).

Rationale (from the independent review): invitations are bearer credentials sitting in mailboxes, not existing access. The repository already revokes rather than pauses for issuer suspension (R1-3) and partner removal (R1-4). "Unusable while Disabled" would bring every invitation under seven days old back to life on re-enable without the re-enabling operator knowing.

### 5. The portal-state fence (Approved)

A Directory-only Organization must **never acquire portal capability through the existence of its row**. Today a Directory-only row (`disabled_at` NULL) would pass every existing check, and only the absence of a membership would protect it. The fence is therefore a hard prerequisite of making such rows possible.

- **Sites.** Every locked decision site reads the portal state: invitation issue and resend; every acceptance read (`findOpen`, `acceptLocked`, `addFromInvitation`) and the notification's send-time check; membership add, promote and approve; support grants; partner designation and customer assignment, and both candidate lists; and, as a second line beside `disabled_at`, the three reads in `ServiceRequestAccess` and the two reads in `PartnerSupportAccess` (which a drift test keeps equal).
- **Table reads, never the Gate.** Inside an existing locked transaction the check is a read of the Organization row already locked (or about to be), never a Gate or policy call: a first permission-cache load inside such a transaction can close a lock cycle (R1-3, 6.2.3).
- **Not an Eloquent global scope.** Every deciding read bypasses the model on purpose, so a scope cannot be the fence.
- **No new lock at the existing fence sites.** Adding the portal-state check there needs no additional lock: every site already holds or takes the Organization row. This does not apply to the disable-and-revoke transaction (section 4), which is a new transaction and acquires the actor's user row and the open invitation rows as well.
- **A single predicate** (one small value type that answers Not enabled, Enabled, Disabled) is applied at each site. A source-scanning test lists every reader of `organizations` and fails on an unlisted one.
- **Backstops:** the CHECK constraints of section 2; `organizations:preflight` fails on a Directory-only row that has any member, invitation, ticket or relationship. The database cannot state "no member without portal access" across two tables, so that part stays service-enforced.
- **Readers that assume "a row exists, so portal access exists"** are corrected: the portal-status read model (`OrganizationListFacts::portalStatus`), the dashboard organization count, both candidate lists, and the preflight's administrator-approval check (`organizationsWithoutApprovedAdministrator`), which must filter to portal-enabled rows so Directory-only rows neither count as "With portal access" nor flood the report.
- **Customer-facing safeguards stay intact:** tenant isolation, and the response allowlists (customer pages emit an Organization's `id` and name only, through explicit column lists and presenter allowlists). **Internal Organization profile notes and Person details must not leak** through any customer or partner presentation. A recursive leakage test over customer pages, partner pages, shared props and mail is required (DIR-0 invariant S3); hiding `notes` on the model is an optional extra.
- The factories and `DevSeeder` default every Organization to **Enabled**, so the existing invitation, membership and partner suites keep passing unchanged as the control group. A Directory-only factory state exists for the fence tests.

### 6. Organization Classification (Approved, final owner ruling)

A minimal Classification ships in Release 1.0: storage and service in **ORG-2**, the selector in **ORG-3**.

| Classification | Meaning |
|---|---|
| `general` | Neutral default |
| `client` | An Organization classified as a client |
| `vendor` | An Organization classified as a vendor or supplier |

- **Exactly one** classification per Organization. **Built-in values only**: no custom classifications, no multiple classifications, no inheritance framework.
- **Storage contract (proposed, subject to implementation verification):** a non-nullable string column on `organizations` with default `general`, backed by a PHP string-backed enum and validated by it. Unknown stored values display as unrecognized and are reported by the preflight. A database CHECK is optional. The column is created in the ORG-2 migration, beside the other profile columns.
- **No authorization effect.** No policy, scope, gate, middleware or authority service may read it, and a source-scanning test pins that from the day the column exists. It confers **no Partner authority and no portal access**. The managing-partner designation (`can_manage_customers`) is a separate, existing concept; **no `partner` classification** is introduced in Release 1.0 (the interface already says "Partner" for the designation).
- An operator may change it **independently of portal state**, including on a Not enabled or Disabled Organization. A change preserves existing roles, memberships, permissions, settings and attributes. Changes are audited; a no-op change needs no audit entry.
- **Future defaults** (not built): a classification may later provision *missing* role definitions, attribute sets or settings. Provisioning is **additive and idempotent**, inserting missing defaults by stable identifier and never updating, removing or assigning, and it must **never automatically assign a role to a User**.
- Relationship classifications of the Information Architecture (prospect, partner, subcontractor and others, applicable to People and Organizations) remain a separate, later concept; this field is not that.

### 7. Organization, Person, User and Membership (Approved)

Four responsibilities stay distinct:

| Concept | Responsibility |
|---|---|
| **Organization** | Canonical business identity |
| **Person** | Directory identity (`crm_contacts`), optionally affiliated with an Organization |
| **User** | Authenticated account, optionally linked explicitly to one Person |
| **Membership** | User-to-Organization association and authority context |

- A **Person's affiliation** and a **Person–User link** grant **no portal permission** and expose no Directory information. ADR-010 decisions 5 and 6 and the five "Multiple affiliations" rules stand.
- **Release 1.0 keeps the single optional Person–Organization affiliation column**, re-pointed from `crm_company_id` to `organization_id` (a foreign key to `organizations.id`, SET NULL, in ORG-2). Several affiliations remain architecturally supported (the later affiliation table) and are not implemented.
- **People become operator-only.** The tenant scope that makes a Person visible through its company's tenant (`OrganizationThroughCompanyScope`) is replaced by an operator-only scope in ORG-2, because an affiliation must never decide access (ADR-010 decision 6). This follows the reconciliation assessment (D7) and the independent review's approval of it; the 2026-11-18 owner instruction did not restate it, so the independent review of ORG-0 should confirm it.
- **DIR-3 and DIR-4 keep their identity-linking and invitation guarantees**, amended only where consolidation changes identifiers, locking or dependencies (section 14).
- `organization_members` stays the only source of customer capability, written only by `OrganizationMembership`, `PartnerSupportGrants` and the enablement step.

### 8. Native fields and the extensible-attributes contract (Approved as a contract; Future)

- **Native columns** hold essential, security-sensitive and frequently queried data. Organization **name, slug, website, phone, address and notes** are native columns on `organizations` (`notes` is operator-internal, never selected on a customer or partner path). `slug` is generated for every Organization at creation and stays unique and NOT NULL.
- **Future, not authorized:** an extensible entity may later receive a standardized nullable JSON column, provisionally **`custom_attributes`** (never `attributes`, which collides with Eloquent's internal property). Attribute **definitions** eventually provide stable identifiers, validation, defaults, applicability and visibility. Rules for any future implementation:
  - **writes merge**: a write sets named keys and preserves unrelated attribute values; values whose definition was removed or left out of a template are preserved and not shown;
  - **defaults apply on read**, never backfilled;
  - **visibility defaults to operators only**; a definition must opt in before any customer-facing code may read it;
  - **attributes never grant access** implicitly;
  - **promotion of an attribute to a native field** is one migration that establishes one authoritative value and removes the key, with no uncontrolled dual write.
- **Not authorized in ORG-0 to ORG-3:** a JSON column, registry tables, an editor, templates or a generalized metadata engine.

### 9. Future Organization roles (contract; Future)

Four conceptual layers, kept apart:

1. **Global platform authority** (Spatie global roles and permissions, as today; `$user->can(...)` with no Organization argument).
2. **Centrally managed delegation envelopes or role templates**, specifying per capability what is fixed, configurable or nondelegable. Intechral only.
3. **Organization-scoped role definitions**, derived from a permitted template within its envelope.
4. **Membership-specific role assignments**, rows keyed to `organization_members`, written by the membership service.

- The same central vocabulary or authoring concepts may inform scoped templates, but **a global permission must not acquire Organization scope merely by reuse**, and the global catalogue is **not an unchecked Organization-role grant mechanism**. Organization authority is always decided for a (User, Organization) pair, from tables, through one resolver.
- **Not in Release 1.0:** no activation of the unused global `org.*` and `*.view_org` permissions, no Spatie `teams`, no Organization role tables, no delegated role administration. The existing membership administrator-approval model is unchanged until a separately reviewed implementation authorizes new behaviour.
- **Prerequisite for any delegated administration (existing condition, outside ORG scope):** any holder of `users.manage` can assign any role, including `operator`. It is contained today by the customer route allowlist and must be closed first.

### 10. Navigation and the unified Organization experience (Approved; ORG-3)

- **Directory → Organizations** is the canonical Organization management interface: **one list and one detail experience**. Profile, People, projects and Portal access appear within it, subject to authorization.
- **System → Organization access** remains as a **filtered administrative list** of Organizations that have **ever been portal-enabled**, including Disabled ones. It links into the canonical detail.
- Both paths reference **one Organization identity**. There is no second editor, no duplicated Organization service and no alternative security path. The security-reviewed portal-administration routes are preserved where practical.
- Account, Person and Organization interfaces may cross-link, without coupling authorization to identity associations.

### 11. Deletion (Approved)

Deletion is restricted to Organizations that **have never had portal access** (`portal_enabled_at` IS NULL), with **explicit dependency handling**: the affiliation of Persons is cleared deliberately (SET NULL) under the lock, and project links and every other dependency are either refused or removed by a stated, tested rule. As planned, `organization_members.organization_id` changes from CASCADE to RESTRICT, so the database refuses a delete that the service should already have refused. Removing an Organization becomes an application path for the first time, which amends the R1-3 assumption that it is not.

### 12. Migration and release-safeguard contracts (Approved)

- **Forward only.** ORG-2 is one forward migration, piecewise and re-runnable. It **refuses, changing nothing and listing counts only, when `crm_companies` holds any row** (the R1-0 refuse-and-list pattern). Because MariaDB commits DDL implicitly, the refusal runs **before the first destructive DDL statement**, and each resumed piece detects what is already done.
- **`down()` is schema-only.** It cannot restore deleted data and must not be described as doing so. It refuses when any data exists that it could not restore (profile data, Directory-only Organizations, affiliations, project links). ORG-1's `down()` refuses while any `portal_enabled_by` is NULL, so ORG-2 must be rolled back before ORG-1.
- **Existing-data rule.** Existing `organizations` rows are all portal organizations, so ORG-1 backfills `portal_enabled_at` from `created_at` and `portal_enabled_by` from `owner_id`. A development database holding hand-made companies must be cleared or rebuilt, with the owner's explicit authorization per environment, before ORG-2 runs on it. Release 0.9 staging is untouched.
- **Rehearsal baseline.** The 45 baseline pins and 7 candidate pins stay untouched. ORG-2 is **not** added to the candidate set: the pinned baseline fixtures deliberately populate `crm_companies`, `crm_contacts` and `project_company`, so the migration would refuse them. This does not waive migration-specific tests or restore-drill compatibility.
- **Safeguards that change with the schema:** the foreign-key manifest `database/profile/foreign-keys.json` is regenerated in the same change; the historical migration tests that need `crm_companies` are retired or made to roll the new migration back first; `organizations:preflight` is rewritten; the rehearsal **integrity snapshot** must check the schema before it reads `crm_companies`, because the backup restore drill runs it against the *current* schema and would otherwise fail after ORG-2; the backup integrity snapshot and host-parity smoke (MyISAM, latin1, MariaDB 11.4) exercise the new DDL; `database:verify` does not pin CHECK constraints, so the pairing check is carried by the preflight and the snapshot.

### 13. Concurrency and lock order (Approved as constraints; the matrix is the contract)

The global lock order is **extended, never reordered**:

`users.manage` permission row → **user rows** → **organization rows, ascending id** → **invitation rows, ascending id** → **Person row** (DIR-4) → everything else.

Two consequences of consolidation:

- **New foreign-key parent-lock hazard.** InnoDB takes a shared lock on the parent row when a child row is inserted or its foreign-key column changes. People and project links now point at the Organization row, the most contended lock in the system. A Person write that sets an affiliation therefore **takes the Organization row (shared) first, before any invitation or Person row**, never holding a Person row while waiting for an Organization. Concrete cycle: a Person-targeted acceptance holds Organization X and waits for Person P; a Person edit holds P and waits for a shared lock on X. A transaction that holds a shared Organization lock never upgrades it.
- **Disable with invitation revocation is actor-first.** `revoked_by` references the actor; a suspension holds that user row exclusively while it waits for invitation rows. If disable took the actor row late, through the foreign key, the two would deadlock. The disabling transaction takes the **actor's user row (shared) first**, then the Organization row, then nominates and locks the open invitation rows in ascending key order, following the actor-first pattern of R1-3 (rule R3); the full contract, including which R1-3 rules do and do not carry over, is in the [ORG-0 record, section 6.2](../../product/release-1.0-org-0-organization-consolidation.md#62-disable-time-invitation-revocation-transaction-contract).

Checks inside these transactions are table reads (section 5). The acquisition matrix, with verified existing behaviour distinguished from proposed behaviour, is in the [ORG-0 record, section 6](../../product/release-1.0-org-0-organization-consolidation.md#6-lock-acquisition-matrix). **The proposed lock behaviour is reasoned from documented InnoDB behaviour and is not proved**: ORG-1, ORG-2 and DIR-4 each require **two-connection MariaDB 11.4 concurrency tests, each pair in both orders**, as R1-3 did.

### 14. Work packages (Approved)

| Package | Purpose |
|---|---|
| **ORG-0** | Documentation baseline: this ADR, supersession annotations, phased contracts, acceptance criteria, review gates. The current package |
| **ORG-1** | Portal-state enforcement: lifecycle columns and provenance constraints, state-aware security checks, Disabled behaviour, transactional invitation revocation, authorization, audit and focused concurrency verification. Establishes the fence **before** Directory-only Organizations can exist through normal operations |
| **ORG-2** | Physical consolidation: forward migration, canonical profile, retirement of `crm_companies`, Person and project relationship conversion, service refactoring, tenant isolation, historical checks, the Classification field. Destructive-migration safeguards, real MariaDB concurrency proofs and customer-data leakage verification |
| **ORG-3** | Unified Organization experience: one canonical management experience, the Classification selector, the retained filtered System → Organization access list |
| **DIR-3 / DIR-4** | Contracts kept, dependencies revised, security gates kept. DIR-3 follows ORG-2 and can progress independently of ORG-3. DIR-4 depends on the Organization experience and the Person–User link, and incorporates the amended locking rules |

Prerequisites, acceptance criteria and review gates per package are in the [ORG-0 record, section 7](../../product/release-1.0-org-0-organization-consolidation.md#7-work-packages-prerequisites-acceptance-and-gates). The 2026-11-17 Release 1.0 commitment (Directory, identity linking, invitation integration and a coherent Organization experience) is delivered by this sequence in place of DIR-2.

## Deferred (Future, not a Release 1.0 commitment)

- **Staged Organization administration:** preparing memberships, invitations and role changes while an Organization is Disabled, activated later by an explicit process. A roadmap entry only ([FUTURE — Platform capabilities](../../product/product-roadmap.md#future--platform-capabilities)). Its essential invariant: staging records stay separate from effective memberships, invitations, grants and other authorization-bearing records unless a future access resolver explicitly distinguishes inactive states.
- Extensible attributes (section 8); Organization roles and delegation (section 9); the affiliation table and People merging; a unified People-and-members roster; Classification provisioning, custom or multiple classifications; removal of the unused global `org.*` and `*.view_org` permissions; renaming `crm_contacts`, `CrmContact` and the `crm.*` routes; the Billing Account (which gains a single Organization key).

## Consequences

- **Benefit:** one Organization key. "A portal Organization has a profile" is true by construction, and the name, repair and delete-guard scaffolding is never built.
- **Benefit:** closes two existing defects (a Disabled Organization can be invited into and joined; enablement is unaudited and unauthorized in the service).
- **Benefit:** tightens one tenancy surface: People stop being scoped through an affiliation.
- **Cost:** a larger package than DIR-2 (relative size L against M), and one predicate added at about nine locked decision sites in code that DIR-2 deliberately left alone.
- **Cost:** a new lifecycle state on the most security-sensitive table, which needs its own security review before any Directory-only row can exist (hence ORG-1 first).
- **Cost:** a new lock-order hazard, from foreign-key parent locks on the Organization row.
- **Cost:** profile fields (including internal notes) now sit on a row that members can load through the model; customer safety rests on explicit column lists and a leakage test.
- **Behaviour change:** Disabled now also refuses invitations, acceptance, memberships, elevation, approval, grants and relationships, and disabling revokes outstanding invitations permanently.
- **Retired scaffolding:** the pair model, the one-company rule, the two-table delete choreography, `OrganizationScope` as a class, `AccessibleCrmCompany` and the `crm_companies` unique-link invariant.
- **Open:** the items listed in the [ORG-0 record, section 9](../../product/release-1.0-org-0-organization-consolidation.md#9-open-items-and-deferred-documentation).

## Amendment 1 (2026-11-20): ORG-2 owner rulings

Recorded by the owner when authorizing ORG-2 CP0 and CP1. The decisions above stand except where this amendment says otherwise; the full register is [section 12 of the ORG-0 record](../../product/release-1.0-org-0-organization-consolidation.md#12-org-2-owner-rulings-and-amendments-2026-11-20).

- **Section 11 (Deletion), amended.** Deletion is enforced by the authorized service under the Organization row lock (never-enabled only), RESTRICT foreign keys protect dependent records, and a source guard prevents any other application deletion path. Database triggers and new enablement-history tables are not required. A Project link refuses deletion; links are never removed silently. The earlier acceptance phrase "refused by the service and by the database" is withdrawn as too broad.
- **Section 2 (Portal lifecycle), clarified.** Initial enablement of an already Enabled Organization is a controlled `already_enabled` refusal with no side effect. A Disabled Organization needs the separate re-enable.
- **Section 6 (Classification), settled.** `varchar(32) NOT NULL DEFAULT 'general'`, no database CHECK; the catalogue is one PHP enum; PHP validation, `organizations:preflight` and the integrity snapshot detect an invalid value.
- **Section 7 (D7), confirmed.** People are visible only to authorized operators; a Person affiliation never grants access.
- **Section 12 (Migration), settled.** The project link table is `project_organization`. `organizations.created_by` is NOT NULL, RESTRICT to `users`, backfilled from `portal_enabled_by` (the enabling operator is the nearest recorded actor; a NULL enabling actor on a preexisting row is refused before any DDL). Historical migration tests run on disposable databases built to their historical schema instead of reversing ORG-2 on the shared testing database.
- **Section 13 (Locking), tightened.** Every Project to Organization association write takes shared Organization locks in ascending id order before it writes. This is required, not recommended.
- **Project associations and the Disabled state (ORG-2 ruling 11, 2026-11-21), clarified.** ADR-011 states no rule for Project associations; this ruling adds one and is not a previously established requirement. A Project association is business metadata and grants no portal authority, but a non-operator may create a NEW association to an Organization only if it is Enabled; an authorized operator may link a Disabled or Directory-only Organization; existing links survive disabling and may be retained or removed under the ordinary Project authorization. Unknown, hidden and ineligible ids fail with one message.
