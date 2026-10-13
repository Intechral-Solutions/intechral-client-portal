# Account Lifecycle: Suspension and Reactivation (R0.9-1C)

**Authority:** Release 0.9 decision H3 ([§5.3](../product/release-0.9-scope-and-architecture.md#53-h3-account-suspension)),
owner-ratified. This document records how H3 is implemented; it adds no decision of its own beyond the implementation
choices marked **Design choice**.
**Companions:** [Authentication Flow](./auth-flow.md) · [RBAC Design](./rbac-design.md) ·
[Database Schema](./database-schema.md)

An account is **active** when `users.disabled_at` is `NULL` and **suspended** when it holds the time of suspension.
Suspension is the only supported offboarding. It is reversible, keeps the user row and its id, keeps every business
record the user owns or is named on, and keeps the user's roles and direct permissions. Hard deletion is not an
offboarding path (see [Hard deletion](#hard-deletion-r09-1b-finding-l1)).

## Authentication entry points

Every way an existing account can become, or stay, authenticated, and what stops a suspended one.

| Entry point | Code | Enforcement |
|---|---|---|
| Password login (`POST /login`) | Fortify pipeline: `RedirectIfTwoFactorAuthenticatable::validateCredentials`, then `AttemptToAuthenticate` (`SessionGuard::attempt`) | Both resolve the user through the `users` provider's `retrieveByCredentials`. The provider (`App\Auth\ActiveUserProvider`) only returns active users. |
| Two-factor challenge (`GET`/`POST /two-factor-challenge`) | Fortify `TwoFactorLoginRequest` finds the challenged user with `$model::find(session('login.id'))`, bypassing the provider | `App\Http\Requests\Auth\ActiveAccountTwoFactorLoginRequest` (bound in place of Fortify's request) treats a suspended challenged user as no challenged user, so a password step completed before the suspension cannot be finished after it. |
| Remember-me cookie | `SessionGuard::userFromRecaller` → provider `retrieveByToken` | Provider returns only active users; suspension also rotates `remember_token`. |
| Existing session | `SessionGuard::user` → provider `retrieveById` on every request | Provider returns only active users, so a session that was not purged (a row written back by a request in flight) is still a guest session. Suspension also deletes the user's `sessions` rows, and refuses to run with any session driver but `database`. |
| SSO login (`GET /auth/{provider}/callback`, intent `login`) | `SocialiteController::login` calls `Auth::login($account->user, remember: true)` directly; there is no Fortify two-factor challenge on this path | Explicit check: a suspended account gets the same response as an unlinked provider identity. |
| SSO link (intent `link`) | Requires the signed-in user | Covered by the session check: a suspended user has no signed-in user. |
| Invitation acceptance (password or SSO) | `InvitationService::acceptAtomically` | Already refuses any invitation whose email belongs to an existing account, so it can never sign in, change or reactivate an existing (suspended) account. Covered by tests. |
| Password reset link (`POST /forgot-password`) | Password broker → provider `retrieveByCredentials` | Suspended account = unknown account: no mail, and the generic `PasswordResetLinkResponse` reply. `User::sendPasswordResetNotification` also refuses a suspended user. |
| Password reset (`POST /reset-password`) | Password broker → provider | A link issued before the suspension fails as for an unknown account; the password is not changed and the account is not reactivated. Suspension and reactivation also delete the account's reset token through the Fortify password broker, so an earlier link stays dead after a reactivation within its 60-minute validity (review finding L-1). |
| Password confirmation, profile, password and two-factor management | Fortify, `auth` middleware | Require a signed-in user, so they are covered by the session check. |
| API or token authentication | None | There are no API routes, no Sanctum or Passport and no broadcasting authentication. Nothing to enforce. |

**User-facing response (design choice).** To an unauthenticated caller a suspended account is indistinguishable from
an account that does not exist: the login form says the credentials do not match, the reset form gives its usual
generic reply, and SSO says the provider identity is not linked. Nothing discloses that the account exists or is
suspended. The login rate limiters are unchanged and count these failures as before.

## Suspension and reactivation

`App\Services\AccountLifecycleService` is the domain boundary. Both operations run in one transaction.

- **Suspend** (`suspend(target, actor, reason)`): sets `disabled_at`, rotates `remember_token`, deletes any
  outstanding password-reset token and the target's database sessions, and writes an activity-log entry
  (`causer` = actor, `subject` = target, description `suspended account`, properties `reason`). It takes the
  [last-administrator lock](#last-active-account-administrator) and refuses if the target is the last one. An
  already suspended account is left unchanged (the original timestamp is kept and nothing is logged).
- **Reactivate** (`reactivate(target, actor, reason)`): clears `disabled_at`, rotates `remember_token`, deletes any
  reset token and the target's sessions again, and logs `reactivated account`. Purging again means that no session,
  remembered login or reset link from before or during the suspension survives it. The user signs in afresh through
  the ordinary paths:
  - by **password**, which is followed by the Fortify two-factor challenge when the account has two-factor
    authentication enabled;
  - by **SSO**, through a linked provider identity. SSO sign-in does **not** present the Fortify two-factor challenge;
    it signs the user in directly with remember-me ([Authentication Flow](./auth-flow.md#openid-sso-flow)). This
    is the existing SSO design, unchanged by H3.

  Roles and permissions are not touched in either direction. An active account is left unchanged.
- **Signing out everywhere fails closed (design choice; review finding L-2).** Both operations sign the user out
  through `DatabaseSessionManager::revokeAll`, which refuses (throws, so the whole operation rolls back and nothing
  changes) unless the session driver is `database`. It never reports a suspension whose sessions it could not
  delete. The operator sees a server error. The database session driver is a
  [deployment prerequisite](#release-09-deployment-and-rollback-requirements).
- **Self-suspension (design choice)** is refused: an operator cannot suspend their own account. Reactivating oneself
  is impossible because a suspended user cannot sign in.
- **Authorization.** `POST /admin/users/{user}/suspend` and `POST /admin/users/{user}/reactivate` sit in the
  `users.view` group and require `users.manage`, the same gate as changing a user's roles.
- **Why** is required when suspending (a reason of up to 500 characters) and optional when reactivating; who and when
  come from the activity log row. No new columns.

## Last active account administrator

**Definition.** An *account administrator* is a user who can reach the account-administration actions: an **active**
user who holds both `users.view` (the `/admin/users` route group) and `users.manage` (role changes, suspension), each
either directly or through any role, for the `web` guard. `users.admin` is never checked anywhere and is not part of
the definition. There is no `Gate::before` override and wildcard permissions are off, so this is exactly who the
gates admit. `App\Services\AccountAdministrators` computes the set with SQL against the permission tables (never the
permission cache).

**Protected mutations.** Every application path that can reduce the set:

- suspending a user (`AccountLifecycleService::suspend`);
- changing a user's roles (`UserController::updateRoles` → `AccessGrants::syncUserRoles`);
- changing a role's permissions (`RoleController::update` → `AccessGrants::syncRolePermissions`), which can remove
  `users.view` or `users.manage` from everyone holding the role;
- deleting a role (`RoleController::destroy` → `AccessGrants::deleteUnassignedRole`), whose "no users" check is made
  under the lock so a concurrent assignment cannot slip in between the check and the delete.

`App\Services\AccessGrants` is the one guarded path for each grant mutation. No application path changes a user's
direct permissions. The built-in role synchronization (`roles:sync`, decision H4, R0.9-1D) runs through the same guard and lock
(`AccessGrants::guardedGrantChange`), and the first-operator bootstrap takes the same lock; see
[Role Governance](./role-governance.md). `RoleSeeder` now refuses outside local and testing environments.

Through the operator action, the actor is always an active account administrator and cannot be the target, so a
single suspension cannot remove the last one; the refusal matters for concurrent changes (two administrators
suspending or demoting each other) and for any other caller of the service. A role or permission change can remove the
last one on its own (an operator removing their own `operator` role, or `users.manage` from the role everyone holds).

**Mechanism.** Each protected mutation runs inside `AccountAdministrators::guard()`, which in one transaction:

1. takes an exclusive row lock (`SELECT … FOR UPDATE`) on the `permissions` row for `users.manage`, which serializes
   every protected mutation;
2. reads the set of active account administrators;
3. applies the mutation;
4. reads the set again, and throws `LastAccountAdministratorException` (rolling everything back) if there was at
   least one administrator before and none after.

The lock is the transaction's first statement, so the reads in steps 2 and 4 see every mutation committed before the
lock was granted. Two operators suspending or demoting each other at the same time are therefore serialized: the
second sees the first's result and is refused. A mutation that does not reduce the set (or a database that already
has no administrator) is not refused.

**Response.** A browser form is redirected back with the error (the existing admin-form convention); a JSON request
gets HTTP 409.

**Permission cache (review finding M-1).** The permission library caches, in the shared cache store (Redis), which
roles carry each permission, for 24 hours. It clears that cache itself, but inside the guarded transaction, before
the commit: a concurrent request could reload the old, still-committed mapping in between and keep it, so a
permission removed from a role would go on granting access. `AccessGrants` therefore clears the cache again once a
role-permission change or role deletion is **committed** (`DB::afterCommit`): immediately when the guard was the
outermost transaction, otherwise when the caller's transaction commits, and also if that transaction rolls back
(code inside it may have reloaded the cache from its own uncommitted rows). A user's role assignments are not in the
shared cache; `UserController::updateRoles` already clears it after the guarded, committed change. Suspension does not
touch permissions.

## Hard deletion (R0.9-1B finding L1)

The permission library detaches a user's roles and direct permissions in `deleting` hooks, which run before the
`DELETE` that the R0.9-1B `RESTRICT` keys can refuse. `User::delete()` now runs inside a transaction, so a refused
delete rolls the detach back and the grants survive. This covers every Eloquent delete of a user model
(`$user->delete()`, `User::destroy()`, `forceDelete()`). A query-builder delete fires no model events and detaches
nothing. No route, command or job deletes users; suspension is the offboarding path, and legal erasure (H7) is a
separate, undecided design.

## Eligibility

Suspension removes a user from **new** selections and **new** notifications, never from history. There is no global
scope on `User`: relations, display names, activity history and report facets built from existing rows still show a
suspended user.

| Surface | Change |
|---|---|
| Ticket assignee choices (operator ticket page, bulk assign) | Active `tickets.assign` holders only. A suspended current assignee is shown on the ticket page as the selected value, labelled "(suspended)". The index "All assignees" filter keeps suspended users (it searches existing assignments). |
| Ticket assignment (`TicketService::assertAssignable`) | Refuses a suspended assignee. As for any stale assignee (EPIC-010D H5) there is no exemption for the current one: re-submitting them is refused, unassigning is allowed. |
| Project task assignee choices and validation | The member lists sent to managers (board task page, Tasks list) flag a suspended member `suspended: true`; the client never offers them as a new choice and shows a suspended holder as the current value, labelled "(suspended)". `ProjectTaskAssignee` refuses a suspended member as a new assignee; the unchanged current assignee stays valid (I8). |
| Project member candidates and validation | Active users only for **new** members. On the edit page an existing member who is suspended is listed flagged, kept in their row and can be saved; they are never offered for another row. |
| Organization member candidates and validation | Active users only. |
| Invoice client choices and validation | Active users only; the invoice's current client stays valid on edit. |
| Notifications | `App\Notifications\SkipSuspendedRecipients`, a `NotificationSending` listener, cancels any notification to a suspended `User` at send time, reading the suspension fresh from the database. That event fires inside the queued job, so a notification queued before the suspension is dropped when delivered. Reply recipients also exclude suspended users before dispatch. Invitations (sent to an email address, not a user) are unaffected. |

## Tests

| Concern | Test |
|---|---|
| Every entry point, non-leaking responses, rate limit, reactivation and MFA | `tests/Feature/Auth/SuspendedAccountAuthenticationTest.php` |
| The operator action, audit record, idempotency, self-suspension, record and grant preservation, UI | `tests/Feature/Admin/AccountSuspensionTest.php` |
| Who counts as an administrator, refusals on every path, rollback, 409 | `tests/Feature/Admin/LastAccountAdministratorTest.php` |
| Concurrent suspension and demotion on real MariaDB (worker processes) | `tests/Feature/Admin/LastAccountAdministratorConcurrencyTest.php`, `tests/Support/account_admin_worker.php` |
| Candidates, validation, notifications (including queued), historical display | `tests/Feature/Accounts/SuspendedAccountEligibilityTest.php` |
| L1: a refused delete keeps the grants (inside and outside a transaction) | `tests/Feature/Database/PreservationDeletionTest.php` |
| M-1: the permission cache is cleared after commit; a reader process reloading it just before the commit (file store, and Redis where reachable) cannot keep a revoked permission or a deleted role | `tests/Feature/Admin/PermissionCacheCommitOrderTest.php`, `tests/Support/permission_cache_reader.php` |
| L-1 (reset token removed) and L-2 (other session drivers refused, nothing changed) | `SuspendedAccountAuthenticationTest.php` (password recovery), `AccountSuspensionTest.php` (session-driver prerequisite) |

## Release 0.9 deployment and rollback requirements

These are release-readiness requirements. They do not block committing the code, but they must be met before customer
accounts are onboarded. The release gate records them in
[§5.3](../product/release-0.9-scope-and-architecture.md#53-h3-account-suspension) and
[§6](../product/release-0.9-scope-and-architecture.md#6-migration-and-rollback-contract).

1. **`SESSION_DRIVER=database` is a deployment prerequisite.** It is the configured default (`config/session.php`,
   `.env.example`). Suspension and reactivation refuse to run with any other driver, because with redis or file
   sessions they could not delete the user's sessions, and a session from before the suspension would resume on
   reactivation. Redis or file session support is not implemented.
2. **Restart queue workers on deployment** (`php artisan queue:restart`). A worker started before the deployment runs
   the old code without the notification listener, and would deliver a queued notification to a suspended user. The
   dev stack's `queue:work … --max-time=3600` worker would otherwise keep the old code for up to an hour.
3. **No ordinary rollback below R0.9-1C while any account is suspended (review finding L-3).** R0.9-1B added
   `users.disabled_at` without enforcement, and earlier builds ignore it. Redeploying any build before R0.9-1C against
   the current schema is *schema*-compatible (N-1), but it is **not security-compatible**: every suspended account can
   sign in again with its password or SSO. Before such a rollback, check
   `SELECT COUNT(*) FROM users WHERE disabled_at IS NOT NULL`; if it is not zero, fix forward instead, or follow a
   recovery procedure that keeps those accounts locked out. **That procedure is not yet established, and must be
   before customer onboarding.** N-1 schema compatibility does not by itself guarantee security compatibility.

## Independent review findings (R0.9-1C)

The independent Opus 5.5 review found one Medium issue (M-1, fixed), three Low ones (L-1 and L-2, fixed; L-3,
documented above) and six informational items, recorded here. Info-1 to Info-4 and Info-6 are accepted; Info-5 is an
open owner question.

- **Info-1 (corrected above).** This document said reactivated users sign in "with their password or SSO and their
  two-factor challenge". SSO sign-in has no Fortify two-factor challenge.
- **Info-2.** `AccountLifecycleService` copies the new state onto the caller's `$target` model inside the guarded
  transaction. If the last-administrator check then refuses and rolls back, that in-memory model still shows the
  account as suspended. The controller discards it, so nothing reads the stale value today. A future caller that
  catches the exception must reload the model.
- **Info-3.** Suspension and a concurrent assignment are not serialized. An assignment validated just before the
  suspension commits may land just after it. The result, "assigned, then suspended", is a permitted state:
  suspension keeps existing assignments, and the suspended user is sent no notification.
- **Info-4.** A request that was already running when its actor was suspended finishes with the authorization it was
  given. The last-administrator guard still holds for it, and the next request is refused.
- **Info-5 (open product and privacy question, not owner-approved).** Project member lists sent to project managers,
  who may be customer-side users, flag each suspended member `suspended: true`, and the task assignee menu labels a
  suspended holder "(suspended)". A customer-side manager can therefore see that a fellow member's account is
  suspended. The flag is needed to explain why the member is not offered. Whether customer-side managers may see it is
  a release-readiness question for the owner, recorded in
  [§10.2](../product/release-0.9-scope-and-architecture.md#102-other-recommendations-not-yet-ratified). No access
  control is bypassed: the flag goes only to viewers who already see the membership.
- **Info-6 (test hygiene).** `tests/Browser/projects-migration.spec.ts` creates its two throwaway users before its
  `try` block, so an interrupted setup, or a killed worker, can leave one behind (the per-run email keeps it from
  colliding with the next run). This predates R0.9-1C and is not corrected here.

## Known limitations and open obligations

- Inviting the email address of a suspended account is not refused at send time; acceptance is refused.
- `RoleSeeder` (decision H4) is no longer a production mechanism. R0.9-1D (implemented; independent review SAFE TO COMMIT)
  makes it refuse outside local and testing environments and moves production synchronization to `roles:sync`, which is
  guarded ([Role Governance](./role-governance.md)).
- The deployment and rollback requirements [above](#release-09-deployment-and-rollback-requirements), and Info-5.
- Residual permission-cache race (recorded by the R0.9-1D review; M-1's fix closed the in-transaction clear but not this window):
  a reader that loads the old permissions before a commit and stores them after the post-commit invalidation kept a revoked
  permission until the cache expired (24 hours). **R0.9-1E addresses it** with a database-backed epoch that fences the cache key
  and changes inside the writer's transaction; it is implemented and passed independent security review (Opus 5.5) for the supported current topology (no read replica);
  hosted CI and Playwright for it are outstanding, and its limits and the mixed-version deployment hazard are in [Role Governance](./role-governance.md#permission-cache-generation-fencing-r09-1e).
- Hosted CI ran R0.9-1C in its first run ([run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30). The hosted job now starts Redis and a dedicated step fails on any
  skip: the Redis variants of the M-1 test ran there (9 passed, 0 skipped). The residual cache race above is addressed by R0.9-1E, which postdates that run.
