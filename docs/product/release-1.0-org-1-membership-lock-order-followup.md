# Release 1.0: ORG-1 membership-writer lock-order remediation (follow-up specification)

**Status (2026-11-20): PROPOSED. Not authorized, not implemented, and deliberately NOT part of ORG-2.** It is written down here because the independent CP6 review of ORG-2 confirmed a real deadlock whose root cause is in ORG-1's membership writers, and a finding that lives only in a review message is lost. **Release-blocking: this remediation, with its concurrency review, must be complete before the first customer-facing Release 1.0 deployment.** It needs its own owner authorization and its own branch; it must not be folded into the ORG-2 commit.

Related: [ORG-0 record, section 6](./release-1.0-org-0-organization-consolidation.md#6-lock-acquisition-matrix) (the lock matrix and the confirmed cycle), [ORG-2 record, section 16](./release-1.0-org-2-physical-consolidation.md#16-cp7-final-verification-and-pre-commit-readiness) (how ORG-2 handles it), [ADR-011 section 13](../architecture/adr/ADR-011-organization-consolidation-and-lifecycle.md).

## 1. The defect

The documented global lock order is `users.manage` permission row, then **user rows**, then **Organization rows (ascending)**, then invitation rows, then the Person row. Most writers follow it. Some ORG-1 membership writers take the Organization row exclusively first and meet a user row later, through a foreign key. **Only one of them is a demonstrated participant in the confirmed cycle; the rest are classified below by what is known, and the classification is not to be read as stronger than the evidence.**

| Writer | What it does today | Status |
|---|---|---|
| `OrganizationMembership::add` | `transact()` locks the **Organization row exclusively first**, then inserts the membership row; the insert takes a shared foreign-key (FK-S) lock on the **member's user row** (`organization_members.user_id`) | **Reproduced.** The membership writer of the engine-confirmed three-party deadlock (section 2) |
| `OrganizationMembership::approve` | Organization X, then the membership row, then an update that sets `admin_approved_by`, a foreign key to the **approving actor's** user row | **Inferred, not reproduced.** Same mechanism (an FK parent lock on a user row after Organization X), on the actor's row, which would also be a participant when that row is the shared one in a Project creation. No test has run it |
| `OrganizationMembership::promote`, `::demote` | Organization X, then the membership row, then a role update. `setRole` writes NULL into the approval columns, and a NULL foreign-key value takes no parent lock | **Not demonstrated as participants.** They take no user-row lock through a foreign key, so by the evidence they are not inverted writers. Reordering them for consistency would be a refactor, distinct from fixing a proven defect, and is the reviewer's and the owner's choice. The CP6 review and the ORG-0 matrix listed `promote` with `add` and `approve`; the code reading above does not support that |
| `OrganizationMembership::addFromInvitation` (inside the acceptance orchestrator) | Issuer user row (S) first, then Organization X, then the invitation row (X), the membership insert last | **Not a confirmed participant, and not expected to be one.** It is actor-first for the issuer. Acceptance always CREATES the recipient's user row, in the same transaction and before the membership insert (an existing account is refused with `account_exists`), so that row is held by the accepting transaction itself and no other session can hold or queue on it; its foreign-key check therefore cannot form the demonstrated three-way cycle through an externally held recipient row. This path is not classified as a participant. The follow-up's audit still examines it like every other writer, and does not assume either way |
| Initial portal enablement (`OrganizationDirectory::enablePortalAccess`, ORG-2) | Actor user row (S) first (`lockActor`), then Organization X, then the state update and the actor's own membership | **Not an inverted writer.** It is actor-first; the foreign keys it writes (`portal_enabled_by`, the membership's `user_id`) point at the row it already holds. Not a demonstrated participant |

The writers that follow the global order (and so oppose the inverted ones) include **Project creation** (user rows shared, ascending, then Organization rows shared; ORG-2 CP6 F-3), **partner support grant** (`users S` then Organization X), and every user-row writer that holds a user row exclusively and then waits for something (suspension, reactivation, profile and role writes).

## 2. The confirmed three-party cycle (CP6 review, 2026-11-20; engine-confirmed)

Three sessions, two resources. Let `U` be a member's user row and `O` an Organization row.

1. **Project creation** holds `U` **shared** (it locked the creator and every member first) and asks for `O` **shared**.
2. **Membership add** holds `O` **exclusively** and asks for `U` **shared** (the foreign-key parent lock of the row it inserts). **This is the reproduced participant.** `approve` has the same shape with `U` the approving actor's row, which is inferred and not reproduced.
3. **A user-row writer** (an account suspension, any `UPDATE users`) asks for `U` **exclusively** and QUEUES behind the shared holder in (1).

InnoDB grants a lock request in queue order, so the shared request in (2) now waits behind the queued exclusive request in (3), which waits for (1), which waits for (2): a cycle. Without (3), (1) and (2) are compatible on `U` and the pair does not deadlock, which is why every two-connection pair of the CP6 matrix passed and why a two-party test cannot find it.

**Observed:** a raw-SQL three-session reproduction on the isolated MariaDB 11.4 recorded error 1213 and moved `Innodb_deadlocks` (14 to 15); the victim was the user-row writer. Nothing was committed by the victim; the other two sessions completed. **No data-integrity loss was demonstrated:** the engine rolls back one whole transaction and the others commit.

**What the application does with a victim** depends on the victim's transaction:
* `AccountAdministrators::guard()` (the suspension path) runs `DB::transaction` with the default single attempt, so a suspension chosen as the victim surfaces as a database error to the administrator, with no partial state.
* `OrganizationMembership::transact()` (three attempts) and `ProjectService` / `CrmService` (three attempts) would be **silently retried by the framework** and end looking like success. The retry hides the deadlock from every test that only checks outcomes; only the server's `Innodb_deadlocks` counter shows it.

**Remaining risk: liveness, not integrity.** A rare failed or delayed administrative action, possibly retried invisibly. It is not tenant isolation, not authorization and not data loss, which is why ORG-2 can proceed, but it is a defect in a concurrent system that the product will run, hence the release block.

**Why ORG-2's Project-creation order stays as it is.** Reverting `ProjectService::create` to take the Organization rows first and the member user rows second removes THIS cycle and creates the symmetric one with the partner support grant (users S, then Organization X): the same three-party shape with the roles exchanged. The mixed orders cannot both be right; the defect is the minority order (the membership writers), and the fix belongs there.

## 3. Proposed change (for a later, separately authorized package)

Make the writers that take a user-row lock after the Organization row (`add`, and `approve` unless the audit clears it; `addFromInvitation` only if the audit finds a user-row lock it does not already hold; any other writer the audit finds) follow the global order. `promote` and `demote` are reordered only if the audit finds a user-row lock in them, or if the owner chooses the consistency refactor on its own merits:

1. In `OrganizationMembership::transact()` (or a wrapper), take the **user rows the transaction will reference, shared, ascending, in ONE statement, BEFORE the Organization row**: the actor, and the target member where there is one (for `add` the member and the actor; for `approve` the approver, who is the actor, and the member). Then the Organization row exclusively, as today, then the membership row.
2. The set must be nominated from the request's arguments, not read from a table first (a plain read would fix the snapshot; see ORG-2 F-1). A missing user is the existing refusal.
3. Audit, in the same work package, EVERY statement that locks an Organization row exclusively and later touches a user row by an explicit lock or by a foreign-key parent lock (`organization_members.user_id`, `admin_approved_by`, `portal_enabled_by`, `created_by`, `revoked_by`, `invited_by`, and the audit `causer_id` where it is a key). Produce the list with its lock sequence before changing code. Do not assume the review's three are the only ones, and do not assume they are all participants: classify each as reproduced, inferred or not a participant, as the table in section 1 does.
4. Do **not** change the global order, the Project creation order, the support-grant order, or the Person and Organization-delete orders, which are certified (ORG-2, section 15).

## 4. Acceptance (mandatory concurrency review)

* **Real MariaDB, two-connection tests, every pair in both orders**, with the server's `Innodb_deadlocks` asserted unchanged per pair, for: add and approve (and any writer the audit adds) against suspension of the member; against suspension of the actor; against Project creation containing the member; against the partner support grant; and against a second membership write on the same Organization.
* **The three-party interleaving itself** (Project creation, a membership writer, and a user-row writer queued between them), run to completion with no deadlock and, for each of the three roles, no 1213 and no moved counter. The CP6 review's probe is the template.
* **Mutation probes** that restore the old order and show the three-party test failing, with the file restored and its sha256 compared.
* **Query-log pins** that the lock sequence is now `users S, organizations X, organization_members`, and that the actor's row remains the first statement where an actor-first contract (invitation issue, disable) applies.
* Every framework retry on these paths counted: a pair that ends `ok` with two transaction begins is a failure unless the test names it an intentional restart.
* An **independent Opus High concurrency review** before commit, and the focused regression of Directory, Organization membership, invitation, partner support and Project suites on the isolated stack.

## 5. Out of scope

Role and permission writers' protocol (they lock the acting administrator, not the holders; the accepted ORG-2 window), any change to the permission cache, and any redesign of the Organization lock hierarchy.
