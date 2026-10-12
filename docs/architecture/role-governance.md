# Role Governance, Permission Synchronization and First-Operator Bootstrap (R0.9-1D)

**Authority:** Release 0.9 decision H4 ([§5.4](../product/release-0.9-scope-and-architecture.md#54-h4-code-owned-built-in-roles)),
owner-ratified: built-in roles are code-owned; custom roles are the customization mechanism; production permission
synchronization is deterministic, idempotent and previewable, and revokes only under an explicit approval.
**Status:** implemented in R0.9-1D; independent review (Opus 5.5): **SAFE TO COMMIT**; committed on the feature branch; first hosted run recorded in the CI doc (draft PR #30); release approval and the open obligations below are unchanged.
**Companions:** [RBAC Design](./rbac-design.md) · [Account Lifecycle](./account-lifecycle.md) ·
[Release 0.9 scope](../product/release-0.9-scope-and-architecture.md)

This document adds no decision. Where the owner has not decided something it says so
([pending owner decisions](#pending-owner-decisions)).

## Built-in and custom roles

| | Built-in (`operator`, `user`) | Custom (everything else) |
|---|---|---|
| Defined by | Code: `App\Shared\Permissions\BuiltInRoles`, from `PermissionCatalogue` | Operators, in the Roles screens |
| `operator` | Every permission in the catalogue | |
| `user` | `PermissionCatalogue::userDefaults()` | |
| Permissions change through | `php artisan roles:sync` only (a reviewed plan) | Roles screens (`roles.manage`), guarded |
| Renamed or deleted | Never, by ordinary administration | Delete when nobody holds it (`roles.admin`), guarded |
| Users assigned | Yes, ordinary user administration (`users.manage`) | Yes |
| In the Roles list | Tag **Built-in**, link **View** (read-only page) | Tag **Custom**, link **Edit** |

`BuiltInRoles` is the single authoritative catalogue: the controller, the views, the seeders, the synchronizer and the
bootstrap all read it. Names compare case-insensitively (the `roles` table does), and the names are reserved: a custom
role cannot be created as `operator`, `Operator` or `user`, so one cannot pose as a built-in role before the real one exists.
Role ids and every user's role assignments are untouched by any of this.

**Refusal contract.** `AccessGrants::syncRolePermissions` and `deleteUnassignedRole` refuse a built-in role with
`BuiltInRoleException`, so the rule holds for the Roles screens and for any direct caller of the service. The edit and
delete endpoints check it first, before validation. Answer: **HTTP 409** with `{"message": …}` for a JSON caller; for a
browser, back with the error under `role`, which the Roles and Edit pages display (the same convention as the last-
administrator refusal). The built-in edit page lists the permissions in a disabled fieldset and offers no Save or Delete.

### Every path that can change a role's permissions

| Path | Built-in roles | Custom roles |
|---|---|---|
| `RoleController::update` → `AccessGrants::syncRolePermissions` | Refused | Allowed, last-administrator guard |
| `RoleController::destroy` → `AccessGrants::deleteUnassignedRole` | Refused | Allowed when nobody holds it, guard |
| `RoleController::store` | Name reserved | Creates a role (no holders, so no guard is needed) |
| `UserController::updateRoles` → `AccessGrants::syncUserRoles` | Assignment only | Assignment only |
| `roles:sync` → `BuiltInRoleSynchronizer::apply` | The only path | Never touched |
| `RoleSeeder` | Local and testing only (resets to the code); **refuses elsewhere** | Never touched |
| `PermissionSeeder` | Additive: creates missing catalogue permissions, removes nothing | |
| `DevSeeder` (local and testing only) | Assigns dev users to roles; grants a dev user a direct permission | |
| Tinker, SQL, restoring a backup | Outside the application; shell or database access is the authorization | |

No application path assigns a user a direct permission.

## The synchronization workflow

`php artisan roles:sync` compares the code (`BuiltInRoles::definitions()`) with the stored state and brings the built-in
roles in line. It creates missing permissions and built-in roles and changes the grants of the two built-in roles. It
never deletes a role or a permission, never changes a custom role's grants, and never touches a user's roles or direct
permissions. Nothing runs it automatically: not a request, a migration, a provider, boot or `DatabaseSeeder` (a test scans
the code to pin this).

### 1. Preview (the default; writes nothing)

```
$ php artisan roles:sync
Plan 3f9a1c0b7d2e4a58
  create permission tasks.view_all
  grant tasks.view_all to operator
  grant tickets.view to user
  REVOKE billing.admin from user
  1 revocation(s): these remove access that the roles hold now.
Active account administrators now:   [1, 4]
Active account administrators after: [1, 4]
Stored permissions no built-in role defines (reported, never changed): legacy.thing
Dry run: nothing was written. To apply after review: php artisan roles:sync --apply --plan=3f9a1c0b7d2e4a58
Applying this plan requires explicit --allow-revocations approval.
```

The plan is a pure read: it queries the tables directly, takes no lock and writes nothing, not even the permission cache.
Actions are sorted and the output is byte-identical between runs. Order: create permissions, create roles, grants,
revocations. `--json` prints the same plan for tooling. The **fingerprint** is a hash of the actions and the before and
after administrator ids; it identifies exactly this plan. Stored permissions that no built-in role defines are reported
only (a custom role may hold them).

### 2. Review, then apply

```
php artisan roles:sync --apply --plan=<fingerprint>                       # additions only
php artisan roles:sync --apply --plan=<fingerprint> --allow-revocations   # a plan that revokes
```

The dry run never suggests `--allow-revocations` as part of the command to run. When the plan revokes, it states separately that
applying it requires that explicit approval, so approving a revocation is a deliberate act and not a copy-paste.

- `--apply` **requires** `--plan`. `--plan` or `--allow-revocations` without `--apply` is an error, never a quiet dry run.
- The plan is rebuilt **under the account-administrator lock** and must still have the reviewed fingerprint; otherwise
  the command fails as **stale** and changes nothing (a concurrent change, or a different plan, cannot ride on an old
  approval). The refusal deliberately does **not** print the fingerprint of the plan the stored state now needs, so a stale
  approval never hands the caller a replacement approval for a plan nobody has reviewed. Re-run the dry run, inspect the new
  plan and approve it deliberately.
- A plan with revocations additionally needs `--allow-revocations`. Revoking is the only destructive step.
- The whole step is one transaction inside the guard. Afterwards the stored state must equal the plan's prediction (nothing
  left to do, administrators as predicted) or it rolls back.
- On success one activity-log entry, `synchronized built-in roles`, records the fingerprint, each action, the revocation
  count and the administrators before and after. It is written inside the transaction, so it describes a committed
  outcome only. An empty plan writes nothing.
- Exit code 0 on success (including "already in sync"), 1 on any refusal, with the reason printed.

### Fails visibly, changes nothing

- **Malformed definitions** (`BuiltInRoles::problems()`): a duplicate or malformed permission name, a built-in role granted
  a permission the catalogue does not define, an `operator` that is not the whole catalogue or lacks `users.view` /
  `users.manage`, or definitions that do not cover exactly the built-in roles.
- **Ambiguous stored state:** a built-in role name that exists for another guard, only under a differently-cased name, or a
  built-in role holding a permission of another guard.
- **Stale or unapproved plan**, or **the last active administrator would be removed** (the guard).

### Effective administrators are part of the plan

The plan shows who is an active account administrator now and after the plan, computed over the stored grants (a suspended
user never counts) and verified against the guard's own SQL after applying. A definition that quietly grants
`users.view` and `users.manage` to the `user` role therefore appears in the reviewed plan as an administrator change, and
changes the fingerprint.

### Deployment procedure (Release 0.9)

0. **Preflight: `APP_ENV=production`.** Confirm the production environment is configured with `APP_ENV=production` (and not the
   `.env.example` value `local`). `RoleSeeder` and `DevSeeder` run only when the environment is `local` or `testing`, so a
   production host left at `local` would re-enable the no-preview role reset. This is a checklist item, not an environment-
   management system.
1. Deploy the code and run the migrations as usual.
2. `php artisan roles:sync` and read the plan. A fresh deploy that added a permission shows only additions; anything marked
   `REVOKE` needs a decision.
3. Apply the reviewed plan with its fingerprint (and `--allow-revocations` only if revocations were intended).
4. `php artisan queue:restart` (existing requirement). Optionally re-run the preview: it should report "in sync".

**Existing installations may have customized built-in roles.** Before the first `roles:sync` against an installation whose
`operator` or `user` role was edited through the Roles screens (possible before R0.9-1D), review the drift the plan reports.
Preserve any intended customization in a **custom role** first (create it with the extra permissions and assign it to the users
concerned), and only then approve a plan with revocations. Applying a revoking plan removes those grants from the built-in role
for everyone who holds it, and a rollback of the code does not restore them.

Skipping step 3 degrades quietly (a permission missing from the database denies everything), so the release checklist must
include it. Where this runs in the production pipeline is part of the hosting discovery (R0.9-2a).

### Audit and rollback considerations

- Apply is transactional and the guard refuses an unsafe result, so there is no partial application to undo.
- A revocation is not undone by a rollback of the code: re-adding the grant is a code change to the definitions plus a new
  reviewed plan. This is intended: nothing is silently restored.
- R0.9-1D adds no migration, so rolling the application code back stays schema-compatible. The rollback restriction of R0.9-1C (suspended accounts) is unchanged.
- The stored roles are the data. Backups include them. A restore followed by a preview shows exactly how far the restored
  roles are from the code.

## Seeders

| Seeder | Local and testing | Elsewhere (production, staging) |
|---|---|---|
| `PermissionSeeder` | Creates missing permissions | Same (additive, removes nothing) |
| `RoleSeeder` | Resets `operator` to the whole catalogue and `user` to the defaults through the synchronizer, revocations included, with no preview and no audit entry (disposable data) | **Throws**, pointing at `roles:sync`; changes nothing |
| `DatabaseSeeder` | Permissions, roles, dev accounts | Permissions only, with a warning to run `roles:sync`; no roles, no dev accounts |

Release 0.9 still forbids re-running a seeder that rewrites authoritative rows against production
([§4](../product/release-0.9-scope-and-architecture.md#4-production-data-durability-principles)). Behaviour change for local
databases: `operator` now equals the catalogue exactly, so a stale permission row is no longer granted to it.

## Last administrator, locking and the permission cache

The synchronization and the bootstrap use the R0.9-1C machinery unchanged:

- Both take the guard's exclusive lock on the `users.manage` permission row first, so they run one at a time with every
  other grant mutation, suspension and role change, and read the **current persisted** grants under that lock.
- `AccessGrants::guardedGrantChange` runs the synchronizer inside the guard and clears the shared permission cache with
  `DB::afterCommit` and `DB::afterRollBack` (finding M-1): registered at the start of the guarded transaction, so a failure
  that rolls back clears what the work reloaded from its own uncommitted rows, and again when the guard returns, so an outer
  transaction that rolls back is covered too. The synchronizer writes the role-permission links directly, but it creates
  missing permissions and roles through the permission library (`Permission::create`, `Role::create`), whose existence check
  reloads the shared permission cache from rows the transaction has written and not yet committed. That is why the cache is
  cleared on rollback as well as after commit.
- Revoking can never leave zero active administrators (`LastAccountAdministratorException`, rolled back). A suspended user
  is not an administrator for any of this.

On a new database with no `users.manage` row yet there is nothing to lock: two simultaneous first `roles:sync --apply`
runs would collide on the permissions' unique index and one fails visibly, rolled back.

### Residual permission-cache risk (inherited from R0.9-1C; not fixed here)

Recorded from the R0.9-1D independent review (its informational item 5, not to be confused with R0.9-1C's Info-5 on suspended-status visibility). The post-commit clear closes the window in which the library clears the cache
inside the transaction. It does **not** close this one: a concurrent request reads the old permissions from the database before
the commit, and stores them in the shared (Redis) cache **after** the post-commit invalidation has run. That entry then keeps a
revoked permission alive until the cache expires (`permission.cache.expiration_time`, 24 hours). It is not introduced by R0.9-1D;
`roles:sync` and every other role change share it, and it is **not conclusively fixed** by anything in this package.

- It is a **security-hardening obligation before production permission revocations are relied upon** (for example, revoking a
  grant from a role as a response to an incident).
- A one-time `permission:cache-reset` during deployment shortens the exposure after that deployment only. It does not address
  concurrent changes made afterwards, so it is not a solution.
- No cache redesign is part of this package. The design question (for example a cache version stamped under the lock, or
  re-checking the stored grants for sensitive decisions) is open and belongs to a later hardening item.

## First-operator bootstrap

`php artisan operator:bootstrap <email> [--name=]` creates the first account administrator of a **new installation**.

- **Authorization is shell access to the server.** There is no route, no HTTP surface, no self-service path, and Fortify
  registration stays off. An email address is only the identity of the account being created.
- It asks for the password and its confirmation (hidden prompts; never on the command line or in the environment) and
  creates the account through the application's own `CreatesNewUsers` action: the same name, email and password rules as
  the invitation flow (12+ characters, mixed case, a number, a symbol, not breached). Non-interactive runs have no
  password and fail. Two-factor authentication is **not currently enforced** for operators, and the command says so. Whether it must be is an open owner decision; the account can enable it under Profile as any user can.
- It gives the new account the built-in `operator` role and nothing else. It **never elevates an existing account**: an
  email that already exists (any letter case) is refused.
- It requires the reviewed synchronization to have run: the stored `operator` role must exist and carry `users.view` and
  `users.manage`, or it refuses and says to run `roles:sync`. It never uses `RoleSeeder`.
- It decides "new installation" **under the guard's lock**: it refuses once any account, active **or suspended**, holds
  `users.view` and `users.manage`. Two concurrent attempts create at most one operator (the second waits, then refuses).
  Repeating the command is refused, creates nothing, and says why. Ordinary customers do not block it.
- It writes one activity-log entry, `bootstrapped first operator`, and verifies that the new account really is an active
  administrator before it commits.

### Initial setup is not disaster recovery

The bootstrap deliberately does not handle "administration exists but nobody can use it": a lost password, a lost two-factor
device, or administrators all suspended through the database. There is no break-glass path, and none is invented here.
Password reset and two-factor recovery codes remain the user's own recovery. A recovery procedure is
[an owner decision](#pending-owner-decisions).

## CRM company deletion safeguard

Deleting a CRM company that projects still reference ended in a database error (HTTP 500) after R0.9-1B made
`project_company.crm_company_id` `ON DELETE RESTRICT`. The constraint is correct and unchanged. `CrmService::deleteCompany`
now counts the linked projects first and throws `CompanyHasProjectsException`; if a project is linked between that check
and the delete (a stale check), the engine refuses the delete (error 1451) and that is translated to the same refusal.

- Browser: back to the edit page with the message under `company`. JSON: **HTTP 409** with `{"message": …}`.
- Nothing is changed or logged on a refusal: the company, its associations, its contacts and its tickets are as they were.
- An unlinked company is deleted exactly as before (contacts and tickets keep their rows with the company cleared).
- Authorization is unchanged (`crm.manage`; a user without CRM access still does not resolve the company).
- This is only the refusal. It is not a company archival, erasure or project-deletion policy; **H6 is not ratified** and
  nothing here treats it as approved.

## Tests

| Subject | File |
|---|---|
| Built-in refusal, custom roles, reserved names, assignments, UI | `tests/Feature/Admin/BuiltInRoleGovernanceTest.php` |
| Plan, dry run, apply, idempotence, stale and unapproved plans, ambiguity, administrators, atomicity, cache | `tests/Feature/Admin/BuiltInRoleSynchronizerTest.php` |
| The command: dry run, flags, exit codes, stable output, code-reachability scan | `tests/Feature/Console/SyncBuiltInRolesCommandTest.php` |
| Seeder restrictions | `tests/Feature/Admin/ProductionSeederSafetyTest.php` |
| Bootstrap | `tests/Feature/Admin/OperatorBootstrapTest.php` |
| Real-MariaDB races: synchronization vs demotion, two identical syncs, two bootstraps | `tests/Feature/Admin/LastAccountAdministratorConcurrencyTest.php` (worker: `tests/Support/account_admin_worker.php`) |
| Post-commit cache for the synchronization (file store, and Redis where `REDIS_HOST` names a server) | `tests/Feature/Admin/PermissionCacheCommitOrderTest.php` |
| CRM company deletion | `tests/Feature/Crm/CompanyDeletionSafetyTest.php` |

R0.9-1C tests that edited the built-in `operator` role through the Roles screen as a way to demote an administrator now
use a custom role carrying the same permissions (`LastAccountAdministratorTest`), because that edit is refused by design.

## Known limitations

- The model itself is not locked: `Role::find(…)->delete()` in a shell, or SQL, can still change a built-in role. Shell and
  database access are the authorization boundary.
- Stored permissions that no built-in role defines are reported and kept, never deleted.
- A custom role that holds a permission later removed from the catalogue keeps the stored row; nothing reconciles it.
- The bootstrapped account's email is not marked verified (the application does not require verification anywhere).
- The inherited permission-cache repopulation race ([above](#residual-permission-cache-risk-inherited-from-r09-1c-not-fixed-here)) is open and must be hardened before production permission revocations are relied upon.
- Hosted CI ran this package once ([run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30): the full Pest suite passed with 0 skipped, and the Redis variants of the cache
  tests ran on a real Redis server (a dedicated step fails on any skip). It is evidence, not release approval, and does not
  address the residual cache race above.
- Not in this package: email normalization on write, removing `client_id` from mass assignment, invitation-token handling
  (H10), and project and company delete policy (H6). They remain in the [§11](../product/release-0.9-scope-and-architecture.md#11-work-packages-and-parallel-hosting-discovery)
  row for R0.9-1D and need a decision on whether they follow in this package.

## Pending owner decisions

1. **Disaster recovery.** What happens when administration exists but no one can sign in (lost credentials, lost two-factor
   device)? Options include an audited, operator-run reset command distinct from the bootstrap, or a restore procedure.
   Nothing is implemented.
2. **Must operators use two-factor authentication?** Already open in [§10.2](../product/release-0.9-scope-and-architecture.md#102-other-recommendations-not-yet-ratified).
   The bootstrap does not enforce it.
3. **Where `roles:sync` runs in the production pipeline** and who approves a plan with revocations (hosting discovery,
   R0.9-2a).
4. Whether the remaining items of the §11 R0.9-1D row (above) belong to this package.
5. Still open and unrelated: Info-5 (customer-side visibility of suspended status), H5 to H10, and the ticket-number
   high-water-mark design ([§7.3](../product/release-0.9-scope-and-architecture.md#73-ticket-number-continuity)).
