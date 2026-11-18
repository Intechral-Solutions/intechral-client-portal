# ADR-010: Directory, identity and Organization model

- **Date:** 2026-11-17
- **Amended:** 2026-11-18 by [ADR-011](./ADR-011-organization-consolidation-and-lifecycle.md): **decision 2, the name-synchronization row of decision 3, and the "Physical consolidation" section are superseded**; see [Amendment 1](#amendment-1-2026-11-18-the-organization-is-one-table-adr-011). The text below is the historical decision and is kept.
- **Status:** **Accepted** (owner rulings of 2026-11-17). This is the approved **target** model. **Nothing in it is implemented yet**; Release 1.0 packages DIR-1 to DIR-4 implement it ([DIR-0 record](../../product/release-1.0-dir-0-directory-identity-organization.md)).
- **Supersedes:** for Release 1.0, the 2026-11-12 deferral of "Directory consolidation, Person" as far as it covered Person records, Person–User linking, invitation integration and a coherent Organization experience ([roadmap ruling of 2026-11-17](../../product/product-roadmap.md#release-1-sequence)). It does **not** supersede the MO-1A decision to keep `organizations` and `crm_companies` as a pair ([MO-1A](../../product/release-1.0-managed-organizations-mo-1a.md)); it builds on it.
- **Related:** [ADR-005](./ADR-005-organization-multitenancy.md) (tenancy), [Information Architecture](../../product/information-architecture.md), [MO-2A1](../../product/release-1.0-managed-organizations-mo-2a1.md) (membership and approval), [R1-3](../../product/release-1.0-r1-3-organization-invitations.md) (organization invitations), [Release 0.9 scope, section 9](../../product/release-0.9-scope-and-architecture.md#9-corrected-directory-and-finance-migration-seams) (constraint 1, identity matching, which this ADR keeps).

## Context

The 2026-09-24 product direction chose four conceptual primitives (User, Person, Organization, Relationship) and said that portal access is a property of an Organization, not a separate entity type. The implementation never reached that model, and by Release 1.0 it showed three seams to operators:

- **Two organization records.** `crm_companies` is the directory record and `organizations` is the tenancy record, created by "promoting" a company. MO-1A (2026-11-06) deliberately kept them as a pair of exactly one of each. Since then tickets, memberships, invitations, the managing relationship, support designations and the disable state were all built on `organizations`, while the navigation still calls the `crm_companies` list "Organizations" and the `organizations` list "Portal access".
- **Three unconnected records of a human.** `crm_contacts` (a directory contact), `users` (an account) and `organization_members` (an account's membership). No column, relation or lookup connects a contact to a user, so inviting a known contact creates a second, unconnected identity.
- **Two administration surfaces with no links between them.** Account actions live under System → Users; membership actions live on the organization page.

On 2026-11-17 the owner ruled that Directory, Person–User identity linking, invitation integration and a coherent Organization administration experience are prerequisites of Release 1.0, and confirmed the decisions below. Physical consolidation of the two organization tables is not required.

This ADR records the model. Workflows, contracts, work packages and tests are in the [DIR-0 record](../../product/release-1.0-dir-0-directory-identity-organization.md) and are not repeated here.

## Decision

### 1. Directory

The workspace keeps the name **Directory** (the "CRM → Directory" terminology decision of 2026-09-24 is reconfirmed). Directory manages Intechral's **external** relationships: People and Organizations. It may gain richer CRM capabilities incrementally; it is not a comprehensive CRM and not an internal personnel system. Internal operators and other authenticated users are **not required** to have Directory records.

### 2. Organization: one concept, two records

An **Organization** is one conceptual entity, presented to operators as one record with one name, one page and one list. It is stored as a pair:

| Record | Table | Holds |
|---|---|---|
| **Directory profile** | `crm_companies` | identity and profile: name, website, phone, address, notes; the People affiliated with it; project links |
| **Portal organization** | `organizations` | portal participation: memberships, invitations, administrator approval, support designations, the managing relationship, the disabled state, Ticket attribution |

- **Portal access is a state of an Organization**, not a second kind of organization: *Not enabled* (a profile with no portal organization), *Enabled*, or *Disabled* (`organizations.disabled_at`).
- The pair is one-to-zero-or-one, enforced by the existing unique key on `crm_companies.organization_id`.
- **Integrity invariant (new):** a portal organization always has exactly one Directory profile. It is created only as a pair (enabling portal access); a profile whose portal organization exists cannot be deleted; a portal organization found without a profile is an anomaly that an authorized, audited, idempotent repair restores. The database cannot state this invariant (the foreign key points from the profile to the portal organization), so the services and `organizations:preflight` do.
- **Physical consolidation is not part of this decision** (see [Physical consolidation](#physical-consolidation)).

### 3. Field and relationship authority

One authoritative record per fact. Nothing is synchronized except the organization name.

| Fact | Authoritative record | Changed through |
|---|---|---|
| Organization name | `crm_companies.name` (**Directory is authoritative**); copied to `organizations.name` in the same transaction, audited | Directory |
| Organization profile (website, phone, address, notes) | `crm_companies` | Directory |
| Portal access and disabled state | `organizations` | the Organization's Portal access section |
| Managing relationship and partner designation | `organizations` (`managed_by_organization_id`, `can_manage_customers`), written only by `OrganizationRelationships` | the Organization's Portal access section |
| Person contact details and notes | `crm_contacts` | Directory |
| Account name, email, credentials, roles, status | `users` | the account holder, and System |
| Person–User link | `crm_contacts.user_id` with its provenance (decision 5) | the link service only |
| Person's affiliation with an Organization | `crm_contacts.crm_company_id` (transitional, decision 4) | Directory |
| Membership, role, administrator approval, support designation | `organization_members`, written only by `OrganizationMembership` and `PartnerSupportGrants` | the Organization's Portal access section |
| Invitation address and target | `organization_invitations` (the address is copied at issue) | the invitation service only |

**Names and email addresses of a Person and of a User are independently authoritative.** They are never copied from one record to the other after creation, in either direction, unless a later owner decision changes that. A Directory edit must never change a credential (the email address of an account is one), and an account holder must never write into a Directory record. When linked records differ, each page shows the other record's value read-only.

### 4. Person

A **Person** is a Directory record of a human, stored in `crm_contacts`. A Person exists independently: it needs **no Organization affiliation** and **no account**. The existing schema already allows both (`crm_company_id` is nullable; there is no reference to `users` other than `created_by`).

The single `crm_company_id` on a contact is a **transitional implementation constraint, not the intended Person model.** A real individual may have relationships with several Organizations and should eventually be one Person. For Release 1.0 the column is kept; see [Multiple affiliations](#multiple-affiliations) for the limitation, the rules that keep the evolution cheap, and the migration path.

### 5. Person–User association

- **Optional and one-to-one.** A Person may be linked to at most one User and a User to at most one Person. Either record may exist without the other. Operator accounts may have a Person record **when explicitly requested**; none is ever created automatically.
- **Storage.** `crm_contacts.user_id` (nullable, unique, foreign key to `users.id`, `ON DELETE RESTRICT`) with its provenance on the same row: `user_linked_at` and `user_linked_by`. The unique key is the arbiter of one-to-one in both directions. The `users` table is not changed.
- **Three origins, kept distinguishable:**

  | Origin | Evidence of identity |
  |---|---|
  | Acceptance of a Person-targeted invitation | the recipient proved control of the invited mailbox |
  | A Person created from an existing User | the record was created to represent that account |
  | A manual link of two existing records | **none beyond an operator's audited assertion**, with a required reason |

  A manual link is never presented as verified identity.
- **Explicit only.** A link is created by one of those three acts and by nothing else. An equal email address may *suggest* a candidate; it never establishes identity (Release 0.9 scope, section 9, constraint 1, is kept). Organization membership is not evidence of identity either.
- **A link grants nothing and exposes nothing.** No policy, scope, gate or presenter reads it to decide access. It never makes Directory information (the Person record, its notes, its Organization's profile) visible to the linked account holder or to anyone who could not already see it.
- **Unlinking** is an explicit, audited act that changes nothing else: not the account, not a membership, not a Ticket.

### 6. Three relationships that stay distinct

| Relationship | Between | Meaning | Carries authority |
|---|---|---|---|
| **Affiliation** | Person and Organization | a Directory fact about a business relationship | never |
| **Membership** | User and portal organization | portal participation | yes: who may file a request, who is an eligible contact, who may hold administrator approval or a support designation |
| **Management** | Organization and Organization | administrative structure (a partner and its managed customers) | none by itself ([MO-1A](../../product/release-1.0-managed-organizations-mo-1a.md), [R1-4](../../product/release-1.0-r1-4-partner-support.md)) |

Affiliation and membership are **not** merged, and `organization_members` is never used to record a person who has no account: its rows are inputs to security predicates. "Partner" in the product today is the administrative designation `can_manage_customers`; business classifications (customer, partner, prospect, vendor) remain a separate, later concept.

### 7. Person-targeted invitations

An organization invitation may be issued for a Person. The row records that durably (`organization_invitations.person_targeted`, set once) and references the Person while the Person exists (`crm_contact_id`, nullable, foreign key to `crm_contacts.id`, `ON DELETE RESTRICT`).

- **Atomic acceptance.** The account, the membership and the Person–User link are committed together, or nothing is. A Person-targeted invitation is never completed without its link and is never downgraded to an ordinary invitation. An ordinary invitation behaves exactly as R1-3 specifies.
- **Several organizations.** A Person may be invited to several organizations at once. Nothing is unique per Person: the only uniqueness rule is the existing one, one open invitation per organization and address, and supersession happens only within an organization and never changes the target silently.
- **Email change.** Changing a Person's email permanently revokes that Person's open targeted invitations, explicitly and with an audit entry; restoring the address does not reactivate them. A reissue is a new credential, by a deliberate operator act.
- **Deletion and history.** A Person cannot be deleted while linked or while an open targeted invitation names them. Historical invitations never prevent deletion: the deletion clears their reference to the Person, the rows keep everything else including `person_targeted`, and the activity log keeps who the Person was. No reference to a deleted Person is retained.

The contracts, the supersession table and the recommended lock order are in the [DIR-0 record, section 8](../../product/release-1.0-dir-0-directory-identity-organization.md#8-dir-4-person-targeted-invitations).

### 8. Authority

No new permission is introduced. Authority is an intersection of existing capabilities, decided from an **active** account, in the manner of `OrganizationPolicy`:

| Act | Requires |
|---|---|
| Create, edit, delete Directory records | `crm.manage` (unchanged) |
| Membership, invitations (including Person-targeted), approval, support designations, managing relationship, disable and enable, profile repair | `portal.operator` and `crm.manage` (unchanged: `manageMembership`, `manageRelationships`) |
| Link, unlink, or create a Person from a User | `portal.operator`, `crm.manage` **and** `users.manage` |
| Suspend, reactivate, change platform roles | `users.manage` (unchanged) |

### 9. Navigation and administration

- **Directory:** People; Organizations. The Organization page is the **one place an Organization is edited**: its profile, its People, and its portal access (membership, invitations, approval, support designations, managing relationship).
- **System:** Users (every authenticated account, with its memberships); Roles; **Organization access** (an administrative index of portal organizations that links into the Organization page; it is not a second editor); Pages.
- Person and User keep **separate management pages** with contextual cross-links. Account security is never managed from Directory, and Directory information is never managed from System.

## Multiple affiliations

**Limitation accepted for Release 1.0.** A Person has at most one Organization (`crm_contacts.crm_company_id`). Someone who works with two Organizations can today only be recorded as two contact rows, and only one of them can be linked to that person's account.

**Rules that keep the evolution cheap (binding on DIR-1 to DIR-4):**

1. Nothing keys identity on the affiliation. The link, the invitation target and the audit trail reference the Person, never the (Person, Organization) pair.
2. A Person-targeted invitation names its organization explicitly; it is not derived from the Person's affiliation, and acceptance does not change the affiliation.
3. No uniqueness rule is added over a Person's email address or over (email, Organization).
4. No workflow requires a second Person record for someone who already has one. Access to a further organization for a linked Person is a **membership** of the existing account, not a new Person and a new invitation.
5. Screens say "Organization" for the affiliation without implying it is the only one a Person can ever have.

**Migration path (not part of Release 1.0).** Add an affiliation table between `crm_contacts` and `crm_companies` (with whatever the affiliation needs: a title, a primary marker); backfill one row from every non-null `crm_company_id`; switch reads and writes; then retire the column, or keep it as the primary affiliation. The tenant scope on contacts (today "through the contact's company") becomes "through any affiliation". Duplicate People are merged only by an explicit, audited operator act that moves affiliations onto the surviving Person and requires that at most one of the merged records is linked to an account. The link column and the invitation reference are unaffected by any of this.

## Physical consolidation

Not required and not decided. The unified Organization experience is delivered over the existing pair. Consolidation becomes worth designing when a second consumer needs one organization key (a Billing Account, or the affiliation table above), and it is cheapest before the first genuine production record exists ([roadmap, principle 8](../../product/product-roadmap.md#roadmap-principles)). If it is done, the direction suggested by the current schema is to fold the profile columns into `organizations`, because every authority-bearing foreign key (Tickets, memberships, invitations, the managing relationship) already points there and only contacts, project links and two tenant scopes still point at `crm_companies`. That would be a separate, reviewed decision that amends this ADR and ADR-005.

## Consequences

- **Benefit:** one Organization and one identity story for operators, with two additive migrations (five columns) and no change to any existing authorization rule, tenant scope or customer surface.
- **Benefit:** the invitation flow remains the only way an account is created, so "create a User from a Person" means *invite*; no direct account-creation path is added.
- **Cost:** two tables continue to represent one concept, so each feature must state which record it reads, and the name is stored twice (kept equal by one writer).
- **Cost:** the integrity invariant of decision 2 is enforced by services and a preflight check, not by the database.
- **Cost:** a manual link is only as reliable as the operator who makes it; the interface must say so.
- **Open:** whether and when to consolidate physically; the affiliation table; business classifications; archiving a Person instead of deleting one.

## Amendment 1 (2026-11-18): the Organization is one table ([ADR-011](./ADR-011-organization-consolidation-and-lifecycle.md))

The text above is the historical decision of 2026-11-17 and is kept. An architectural reconciliation of 2026-11-18, confirmed by an independent review, found that the two-table pair costs more than the consolidation it deferred, and the owner approved consolidation. **This amendment records what is superseded; the target architecture is [ADR-011](./ADR-011-organization-consolidation-and-lifecycle.md). Nothing in it is implemented yet: until ORG-2 merges, the pair, the one-company rule and the delete guard describe the code.**

| ADR-010 text | Status after this amendment |
|---|---|
| **Decision 1** (Directory) | Stands |
| **Decision 2** ("Organization: one concept, two records"), including the integrity invariant "a portal organization always has exactly one Directory profile" | **Superseded.** There is one canonical `organizations` table (one id, name, profile and lifecycle). `crm_companies` is retired by a forward migration in ORG-2. The invariant is true by construction, so the profile-repair path and the services that enforced it are not built. **Portal access** is still a state, now stored on the Organization row: Not enabled, Enabled, Disabled, with no return to Not enabled ([ADR-011 §2](./ADR-011-organization-consolidation-and-lifecycle.md#2-portal-lifecycle-approved)) |
| **Decision 3**, the name row ("`crm_companies.name` authoritative, copied to `organizations.name`") and the profile row | **Superseded.** There is one name, edited directly on the Organization. Profile fields (website, phone, address, notes) become native columns of `organizations`. The other rows stand, with "Organization" read as the canonical row, and "Person's affiliation" pointing at `organizations.id` |
| **Decision 3**, "names and email addresses of a Person and a User are independently authoritative" | Stands |
| **Decision 4** (Person) | Stands. The single affiliation column is **re-pointed** from `crm_company_id` to `organization_id` (foreign key to `organizations`, SET NULL) in ORG-2 and kept for Release 1.0 |
| **Decisions 5 and 7** (Person–User association; Person-targeted invitations) | Stand. DIR-3 and DIR-4 are amended only where consolidation changes identifiers, locking and dependencies ([ORG-0 record, section 7](../../product/release-1.0-org-0-organization-consolidation.md#7-work-packages-prerequisites-acceptance-and-gates)) |
| **Decision 6** (three relationships stay distinct) | Stands. Its last sentence, that business classifications "remain a separate, later concept", is narrowed: a **minimal built-in Organization Classification** (`general`, `client`, `vendor`, exactly one, no authorization effect) ships in Release 1.0 ([ADR-011 §6](./ADR-011-organization-consolidation-and-lifecycle.md#6-organization-classification-approved-final-owner-ruling)). Relationship classifications of People and Organizations (prospect, partner and others) remain later. "Partner" stays the administrative designation and is **not** a classification |
| **Decision 8** (authority) | Stands. "profile repair" disappears with the pair. Initial enablement, and disable and re-enable, are separate acts with separate abilities: initial enablement needs `manageMembership`, while disabling and re-enabling an existing portal Organization retain `manageRelationships` (R1-4); each is checked in the controller and again in the service ([ADR-011 §2](./ADR-011-organization-consolidation-and-lifecycle.md#2-portal-lifecycle-approved)) |
| **Decision 9** (navigation) | Stands in intent: Directory → Organizations is the one place an Organization is edited; **System → Organization access** is a filtered list of Organizations that have ever been portal-enabled (Enabled and Disabled), linking into the canonical detail ([ADR-011 §10](./ADR-011-organization-consolidation-and-lifecycle.md#10-navigation-and-the-unified-organization-experience-approved-org-3)) |
| **"Multiple affiliations"** | Stands, including the five binding rules. The affiliation table remains later |
| **"Physical consolidation"** | **Superseded.** Consolidation is decided: fold the profile columns into `organizations`, by forward migrations that edit no existing migration file, in ORG-1 (fence) then ORG-2 (migration) |
| **Consequences** | The "two tables continue to represent one concept", "name is stored twice" and "invariant enforced by services and a preflight" costs no longer apply to the target. The new costs are listed in [ADR-011, Consequences](./ADR-011-organization-consolidation-and-lifecycle.md#consequences) |

The DIR-2 package specified in the [DIR-0 record](../../product/release-1.0-dir-0-directory-identity-organization.md) is replaced by ORG-1 to ORG-3.
