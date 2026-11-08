# Release 1.0: Managed Organizations, MO-2A1 (membership security and administrator approval)

**Status:** implemented on `feature/r1-0-mo-2a1-membership-approval`; the independent review (2026-11-07) was **SAFE AFTER TARGETED REMEDIATION**; the remediation below is done and the branch is **integrated with `main` at `8cbda05`** (RE-C1 and HD-P1). The independent differential closure review (2026-11-08) was **SAFE TO SUBMIT PR**; three documentation corrections from it are applied. **Approved for PR submission; not merged, not deployed.** Hosted CI is the merge gate.
Release 0.9 staging is unchanged. This record covers MO-2A1 only. **It is operator-only: no customer-facing delegation, no
invitation change and no new access of any kind is introduced.** MO-2A2 (invitations), MO-2B (customer-facing delegation), MO-3 and
MO-4 are not started. It follows the MO-2 design review (SAFE AFTER TARGETED DESIGN REVISIONS) and the owner's two clarifications,
recorded under "Operator-only revocation" and "Rollback", and the owner's rulings of 2026-11-08 on the review (see "Remediation after the independent review").

## Role is not approval

`organization_members.role = admin` has never granted anything, and still does not. MO-2A1 adds a **separate, explicit, audited
operator act**, approval, recorded on the membership itself:

| Column | Meaning |
|---|---|
| `admin_approved_at` | nullable datetime: when an operator approved this membership as an administrator |
| `admin_approved_by` | nullable reference to the approving operator (`users.id`, `ON DELETE RESTRICT`) |

An **approved administrator** of an organization is a membership that, all at once, has `role = admin`, has a **complete approval record** (both `admin_approved_at` and `admin_approved_by` set; either one alone counts for nothing),
belongs to an **active** account (`disabled_at` null), and belongs to an account that holds **no operator eligibility** (`portal.operator`,
or the `operator` role, directly or through a role; broader on purpose, so an operator whose capability has not been synchronized yet is
still never an organization administrator). That definition lives in exactly one place, `App\Services\OrganizationAuthority`, and takes
only an organization id, a user id and the membership tables. Spatie permissions other than the operator exclusion (`org.admin`,
`org.invite`, `org.manage_roles`, `users.admin`, `crm.manage`), `organizations.owner_id` and the managing-partner relationship are
**not inputs**, so no unrelated permission can activate anything. This is *approval eligibility*. No route, policy or screen in this
release lets a customer act on it, and there is no delegation feature flag yet.

`Organization::isAdmin()` (no callers) was renamed `hasAdminRole()`: role only, and the name says so.

## Historical rows

Every existing membership keeps both columns `NULL`: **no backfill, no inference**. A historical administrator row is, by construction,
not approved, and stays so until an operator approves that exact membership after the owner has reviewed it. Nothing approves, demotes
or deletes anyone automatically, including the preflight and the migration.

## The membership service

`App\Services\OrganizationMembership` is the only writer of `organization_members` for operators (the promotion of a company still
attaches its promoting operator, unchanged). The legacy `CrmService::addMember`, `updateMemberRole` and `removeMember` were **removed**;
a test fails if any other code writes the table. Every operation requires an explicit actor, authorizes `manageMembership`, runs in one
transaction whose **first statement locks the organization row `FOR UPDATE`**, reads the membership under that lock, and writes an
activity-log entry in the same transaction. **Call each mutation at the top level of a request or command, never inside an outer transaction** (the same rule as `AccountAdministrators::guard`): the lock is only the first statement of a transaction the service opens, so inside an outer one the caller's earlier reads are an older snapshot and the last-administrator check could decide on stale rows.

| Operation | Rule |
|---|---|
| Add | Refuses an existing member and an inactive account; never changes an existing role (the old `syncWithoutDetaching` silently demoted an administrator). Adding as `admin` creates an unapproved administrator. |
| Promote | Sets `role = admin`. Never approves, never restores an approval; a leftover approval record on the member row is cleared and audited with its actual previous approver and time. |
| Demote | Sets `role = member` and clears both approval columns **in the same statement**. A demote-then-promote cannot revive authority. |
| Remove | Removes the membership only; the account is neither suspended nor deleted. |
| Approve | Operator-only. Needs an active account, `role = admin`, no operator eligibility. Stamps who and when, and `updated_at` with the same instant. A repeat is a no-op that keeps the original stamp. A **half-written** record (a time without an approver, or the reverse) is *not* "already approved": it is refused (`incomplete_approval`) until an operator revokes it. |
| Revoke approval | Operator-only, explicit, below. |

**Authorization.** `OrganizationPolicy::manageMembership` requires an **active** account (not suspended, the existing `User::isSuspended()` meaning) holding **both** `portal.operator` and `crm.manage` (capabilities, not the
`operator` role name), checked by the controller (first statement) and again by the service. The active check is in the policy's shared boundary, so it also holds for a service call made with an explicit actor (`tinker`, a job), where the web login's refusal of suspended accounts does not apply, and it applies equally to the MO-1A relationship operations. The global `org.*` permissions, `owner_id`,
the managing-partner relationship and an organization administrator, approved or not, give no ability. A side effect worth knowing:
before MO-2A1 the member routes sat behind the bare `crm.manage` permission, so a customer account holding it who belonged to an
organization could add members to it and make them administrators (an existing tenant test pinned that). It now answers 403.
The existing global organization scopes are unchanged: the routes bind through the scope, which keys on the `operator` role name, so a custom-role operator (the capabilities without the role) reaches the pages of the organizations the scope shows them (those they belong to) and gets 404 for the rest, while the service itself is not scoped and works for them with an explicit actor.

## Last approved administrator

An ordinary demotion or removal that would take an organization from **one or more active approved administrators to none** is refused
and rolled back, whoever asks (the same before-and-after count `AccountAdministrators::guard` uses). An organization with no approved
administrator is a **valid** state, every organization starts there, and it stays operator-managed. Suspending an account is never
blocked by this rule, and reactivating one keeps its approval (an operator's revocation is the only thing that clears it).

The serialization point is the organization row. Accepted residual: a suspension that commits at the very moment of another
administrator's demotion can leave none active; the operator recovers by approving someone else.

### Operator-only exceptional revocation

Revoking an approval clears both columns and keeps the membership and role. It is the **one** path allowed to leave an organization with
no approved administrator, deliberately and for security recovery, and only when the operator confirms it: otherwise it is refused and
nothing changes. When it does leave none it is audited under its own event name, "organization administrator approval revoked (no
approved administrator remains)", with `leaves_no_approved_administrator: true`. On the screen the control asks for an explicit
checkbox, and the ordinary Remove and Make Member controls of the only approved administrator are disabled with the reason.

## Audit and provenance

Every change is an activity-log entry on the organization, caused by the acting operator, with the member's id and name and the previous
and new role and approval. Approval provenance (who, when) is also on the membership row; the entry records it again with the change.

## Migration and N-1

`2026_11_07_090000_add_organization_admin_approval` is **additive** and re-runnable: each column and the named foreign key are detected
and completed independently (an interrupted run is finished by the next), an existing piece must have the shape the migration would
create or it stops, and existing data is never rewritten. **No CHECK** requires approval to imply `role = admin`: the Release 0.9 code
writes roles without knowing these columns, so during a rollback window it could violate one. Release 0.9 code uses the existing
columns unchanged and cannot write the new ones. The candidate is registered and pinned in `database/rehearsal/candidates.php`; the
foreign-key manifest has one new entry; the integrity snapshot gained two conditional checks (approval on a non-administrator,
and a half-written approval).

## Preflight and activation requirements

`php artisan organizations:preflight` gained a read-only **administrator-approval inventory**, **ids only** (it never prints an email, so
the section is safe to keep as evidence): unapproved historical administrators, approved active customer administrators, approved
suspended administrators, operator-held administrators, approved memberships whose account is now an operator, approved rows that are no
longer administrators, incomplete approvals (a failure), organizations with no active approved administrator, and administrators of
several organizations. It now **fails** (exit 1) on integrity damage: an approval record on a membership that is not an administrator, a half-written record, and a membership whose `updated_at` is later than its `admin_approved_at` (this release stamps both with one instant, so only older code can produce it). The timestamp check is **diagnostic evidence, not proof**: a clean result never shows that no approval was revived. Before anything may ever honour an approval: run it against a copy of real data, have the owner review the
unapproved rows, and let an operator approve exactly the reviewed memberships one by one. Never "approve all".

## Rollback: no approval may remain

Release 0.9 code (and any older code) can change a membership's role **without clearing an approval**. A demote followed by a re-promote by older code leaves a row that is `admin` with its old approval intact, indistinguishable from a genuine one, and no application trace. Owner ruling (2026-11-08): **a code-only rollback to Release 0.9 is prohibited while either approval column holds a value.** It is enforced, not merely documented:

- `database:rollback-assessment` (RE-C1) **refuses** while any `organization_members.admin_approved_at` or `admin_approved_by` is non-null: a complete record, a half-written one, or one on a non-administrator row. It names the organization and user ids (never an email) and the counts, and `--expected` exits 1 and prints no lines to compare for a refused assessment.
- The way out is the explicit, audited **per-membership revocation** on the organization page (`OrganizationMembership::revokeApproval`), available whenever *either* column is populated. The owner ruled this sufficient for MO-2A1; **no bulk command exists**, so many approvals mean many revocations, each with its own audit entry.
- The rollback declaration (`database/profile/rollback/0.9.json`) now lists this migration, its two nullable columns and its foreign key, and the old verifier's output has five lines, captured from the real Release 0.9 archive (see the [rollback document](../operations/rollback-1.0-to-0.9.md)).
- After any rollback, the re-upgrade preflight fails on the damage older code can leave (approval on a non-administrator, half-written record, membership changed after approval), and every administrator needs renewed operator review and an explicit new approval before delegation is ever enabled. Nothing is silently grandfathered or reactivated, and no generic rollback-compatibility framework is built for this.

## Remediation after the independent review

The review (Opus, 2026-11-07) accepted the membership and approval architecture, the lock ordering, the transaction handling and the last-approved-administrator protection, and found three rollback or security gaps and several consistency issues. Owner rulings of 2026-11-08: per-membership revocation is sufficient (no bulk command); every membership-management action needs an explicit, active, authorized operator; incomplete approval records fail closed; code-only rollback is prohibited while either approval column holds a value; operator-role or capability changes must not silently restore delegated authority once MO-2B exists (recorded below, not built); Release 0.9 staging is untouched.

| Finding | Remediation |
|---|---|
| B1 a suspended actor passed the service | the policy's shared boundary requires an active account; tests drive every mutation, approval and revocation as a suspended operator, by role and by capability, and through the routes |
| B2 the preflight passed damaged approvals | approval on a non-administrator, a half-written record, and `updated_at` later than `admin_approved_at` now fail; legitimate writes keep the timestamps equal (a test pins it) |
| B3 the assessment did not refuse approvals | refuses while either column holds a value, with positive and negative tests and a real-schema proof |
| the predicate counted a time without an approver; `approve()` called it already approved | a complete record (both columns) is required everywhere, through one helper (`OrganizationAuthority::approvalRecord()`); `approve()` refuses a half-written record |
| the promotion audit hard-coded `approved: false` | promotion, demotion, removal and revocation record the actual previous approver and time |
| no Revoke button for an approver without a time | Revoke is offered whenever either column is populated; Approve only when neither is |
| a no-op demotion claimed the approval was cleared | the flash message reports clearing only when approval data was actually cleared, and an unchanged role is said to be unchanged |

## Mandatory MO-2B security requirement (recorded, not built)

A change to an account's **operator eligibility or capabilities** (granting or removing `portal.operator`, the `operator` role, or a role carrying either) must never silently restore, create or preserve delegated authority. Today an approved membership is inert while its account is an operator, and removing the eligibility makes the same approval count again with no audit entry; that is harmless only because an approval grants nothing in this release. **MO-2B must close this before it honours any approval**, for example by clearing or invalidating the account's approvals, with an audit entry, in the same transaction as the eligibility change, or by requiring renewed approval, and it needs a test that grant-then-remove does not restore authority. MO-2A1 builds no role-governance framework for it. The other MO-2B requirements (the persistent activation marker, default-off delegation, renewed approval after a rollback) are R3 and R4 in the [rollback document](../operations/rollback-1.0-to-0.9.md#r-requirements).

## Validation evidence

Original implementation: `OrganizationMembershipTest`, `OrganizationMembershipConcurrencyTest` (5, two real MariaDB connections: concurrent demotions, concurrent removals, approve against demote in both orders, revoke against remove), `OrganizationAdminApprovalMigrationTest` (9) and the preflight tests. The review confirmed the locking (removing the lock fails six tests) and reproduced the atomicity by forcing the audit write to fail.

Remediation (2026-11-08), on a disposable MariaDB 11.4.13 on a private Docker network, never the shared testing database: `OrganizationMembershipTest` grew from 29 to 40 tests (suspended actors through the service, the routes and the relationship boundary; every operator-detection branch; half-written records in both directions through the predicate, `approve()` and the screen; the audit of promotion, demotion and removal; the flash messages; an audit-store failure rolling back every mutation; the timestamp contract) and the preflight tests gained the three integrity failures with fixtures that share one instant (the vacuous `not->toContain($a, 'message')` assertion, which Pest reads as two needles, is replaced by a direct check). 14 deliberate mutations were each caught by those two files, including the three that survived the review (the operator role-name branch, the direct-permission branch, and `approve()` writing an `updated_at` different from its stamp).

Integration with RE-C1: against the new schema and the unchanged declaration the RE-C1 tests failed by design (15 failed, 26 passed); with the declaration and the five-line literals updated they pass, with the new refusal tests. The real Release 0.9 archive (`32ec62f`, digest checked) printed the five lines in the declared order; `--expected` is identical to them; the assessment passed against the real archive directory with no approval present and refused with a complete approval, an approval time only and an approver only; a real A2 against the archive reached ACCEPT. The migration file is unchanged, so its pin is. The full local Pest suite and the browser suite were **not** run; hosted CI is the final-tree gate.

## Deferred

Invitations (`organization_invitations`, token hashing, encrypted queued mail), customer-facing delegation (routes, feature flag, pilot
allowlist), and everything in MO-2A2, MO-2B, MO-3 and MO-4 are not part of this package and unchanged.
