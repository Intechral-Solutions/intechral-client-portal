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

## Operator eligibility: `portal.operator` (R0.9-CS1)

`portal.operator` is a catalogue permission that marks an account as **eligible to be an operator** for the customer-surface
boundary ([Customer Pilot Surface Gate](./customer-pilot-surface.md)). It is a trust designation, not a permission to perform any
action: every endpoint still checks its own capability, and holding it alone reaches 403 pages, not data.

- The built-in `operator` role carries it because it carries the whole catalogue; the built-in `user` role does not and cannot be
  edited to (`AccessGrants::assertCustom`). `BuiltInRoles::definitions()` is unchanged in shape, so **`roles:sync` shows it as two
  additions** on an existing installation: create the permission, grant it to `operator`.
- A custom role carries it only if an authorized operator (`roles.manage`) adds it on the Roles screens. Nothing grants it to a
  custom role automatically, and no other permission implies it. A custom operator role that is not given it turns its members
  into customers once the boundary is in `closed` or `pilot` mode.
- Preview with `php artisan roles:sync`; find the custom roles and accounts that need an explicit decision with
  `php artisan customer-surface:preflight` (read-only). The procedure and its order are in
  [Customer Pilot Surface Gate §10](./customer-pilot-surface.md#10-deployment-operator-eligibility-and-rolessync).
- It is classified `PRESENTATION_NEUTRAL` by the shell drift guard: it gates no route and selects no shell.
- The R0.9-1C last-administrator protection and the R0.9-1D approval behaviour apply unchanged.

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

### Permission-cache generation fencing (R0.9-1E)

**Status:** implemented in R0.9-1E; the independent security review (Opus 5.5) passed with no Critical, High or Medium findings.
Transactional epoch fencing closes the confirmed stale-cache repopulation race recorded by the R0.9-1D review (its informational
item 5, not to be confused with R0.9-1C's Info-5 on suspended-status visibility) **for the supported current topology**: one
primary database connection, no read replica, a shared Redis cache. R0.9 WP2 added permanent regression coverage of the same
invariant on the **database** cache store, the approved shared-hosting profile ([below](#permission-cache-security-on-the-database-store-r09-wp2)).
Release 0.9 is not customer-ready.

**The race.** The permission library caches in the shared store (Redis) which roles carry each permission, for 24 hours. A
request that reloads that mapping from the database just before a revocation commits, and stores it after the writer has cleared
the cache, keeps the revoked grant alive for the cache lifetime. R0.9-1C's post-commit clear cannot prevent it: it runs before
the late store. It was reproduced with two independent PHP processes, MariaDB and Redis.

**The invariant.** Once a role-permission revocation has committed, authorization checks that *begin afterward* do not grant
through stale shared cache data. A decision already under way, and any later check made from the copy the same request already
loaded, completes under the state it loaded (request-bounded; a queue worker starts every job with no copy, see below).

**The mechanism.** The cache key is `permission.cache.key` plus `:` plus the **epoch**, a random 128-bit token.

- A reader reads the epoch *before* it reads the cache or the database, so whatever it stores goes under the epoch it read.
- Every change to the shared mapping replaces the epoch with a fresh token **inside the writer's own transaction**. The token
  commits or rolls back with the change, and no other connection sees it before the commit.
- A reader that began before a commit can therefore only fill a key that no reader beginning afterward consults. Tokens are
  never reused, so an obsolete entry cannot become active again whatever it still holds, and it expires under the normal TTL.
- There is no post-commit step to lose. A writer killed right after COMMIT has already moved the epoch.

**Storage and connection contract** (`App\Support\Permissions\PermissionCacheEpoch`).

- One row in the existing Laravel `cache` table (migration `0001_01_01_000001`; **no new migration**), key
  `<cache.prefix>spatie.permission.epoch`, value the token, expiration ten years out. The table name comes from
  `cache.stores.database.table`.
- It uses the application's **default database connection**, the connection the permission models write through, so the write
  joins the caller's transaction. Reads use `useWritePdo()`: always the primary, never a replica.
- It deliberately does **not** use the `database` cache *store*: that store reads through the read replica when one is
  configured and can be pointed at another connection (`DB_CACHE_CONNECTION`); either would break the guarantee. No read/write
  split and no read replica is configured today, and **a replica deployment is unsupported**. The epoch read is primary-only (a
  test proves it with an unreachable replica), but that is only half of the guarantee: the library's own loader
  (`PermissionRegistrar::getPermissionsWithRoles()`) reads through the read connection whenever no transaction is open, and this
  package does not change it. With an asynchronous replica, a reader could load pre-revocation rows from a lagging replica and
  store them under the *active* epoch, where they would be served for the cache lifetime. Introducing a replica is therefore a
  deployment and architecture constraint, not an implemented capability: permission loading must first be routed explicitly to the
  primary (or equivalent consistency proved) before the configuration is enabled.
- First use creates the row with `INSERT IGNORE`; concurrent first uses converge on one token. If the row is lost (`cache:clear`
  on the database store, a restore), the next reader mints a new token, which also makes every older entry unreachable.
- The epoch lives in whichever database is the default connection, so the testing and rehearsal databases each have their own.

**Integration** (`App\Support\Permissions\EpochPermissionRegistrar`, bound with `extend` in `AppServiceProvider`). It overrides only
`getPermissions()`, the *only* route to the library's loader (checked in 6.25: `Permission::getPermissions()`, therefore
`findByName`/`findById`/`create`, `hasPermissionTo`, `User::permission()`, the Gate's before-callback and `Role::hasPermissionTo` all
end there), and `forgetCachedPermissions()`, which advances the epoch and then clears the library's in-process copy and the old
unsuffixed key. It returns `true` once the epoch has advanced, so `permission:cache-reset` reports success.

**What advances the epoch.** The library calls `forgetCachedPermissions()` on every save or delete of a role or permission and on
every role-permission change made through its models, which covers custom role edits, role creation and deletion, permission
creation, renaming and removal, and `syncPermissions`/`givePermissionTo`/`revokePermissionTo`. The built-in synchronization writes
role-permission links with raw SQL the library never sees, so `BuiltInRoleSynchronizer::execute()` advances the epoch itself,
inside its transaction and after its last write. A user's own roles and direct permissions are not in the shared mapping and do
not advance it. Changes made outside the application (SQL, a shell, a restore) do not either: run `php artisan permission:cache-reset`
afterwards.

**The existing hooks are kept.** `AccessGrants`' post-commit and post-rollback clears still run. Each now advances the epoch once
more (and clears the old key): redundant, harmless, and unable to weaken the guarantee, because the epoch only moves to a token
never used before. After a rolled-back change the epoch therefore differs from before it, a harmless extra reload.

**Queue workers.** The registrar is a singleton and a worker resets only *scoped* instances between jobs, so it would keep the
mapping it loaded for its first job indefinitely. `AppServiceProvider` clears the in-memory copy on every `JobProcessing` event for a
non-`sync` connection; each job's first check reads the current epoch. `sync` jobs run inside the request that dispatched them.

**Failure behaviour.** Nothing swallows an epoch error. If the epoch cannot be read, the check throws (it never falls back to a
cache that may be stale). If it cannot be advanced inside a transaction, the exception aborts that transaction and the grant change
does not commit. The cost is one primary-key read per request that authorizes. A shared-cache outage still makes checks fail, as
before; a revocation no longer depends on it: clearing the old unsuffixed key is best-effort (reported, not thrown), because the
epoch has already made every suffixed entry unreachable.

**Deployment and rollback.**

- No migration and no schema change. Security compatibility is a separate question from schema compatibility.
- **Do not change role permissions while old and new application versions run together** (a rolling deployment). Old code
  ignores the epoch: a revocation made through an old instance does not advance it, and instances running the new code would keep
  serving entries stored under the current epoch until they expire. The new code clears the old unsuffixed key, which helps old
  instances read a new instance's change, but old instances keep the old race.
- After a deployment completes, run `php artisan permission:cache-reset`; it advances the epoch, so it is authoritative.
- Restart queue workers on deployment (existing requirement): a worker that predates the change has neither the fence nor the reset.
- After a Redis flush the epoch is unaffected and the next requests rebuild the cache. During a Redis outage checks fail as before.
- **Rolling back to code that ignores the epoch restores the old race**, whatever the schema says. The suspension-specific
  rollback restriction of R0.9-1C remains in force.

**Limits.** A decision under way, or a request that already loaded its copy, can still complete under the earlier state. SQL or a
restore without `permission:cache-reset` is not seen. Mixed-version windows are unsafe, as above. A Spatie upgrade that changes
how the loader reads the cache key would be caught by the race tests.

### Permission-cache security on the database store (R0.9 WP2)

The approved Release 0.9 hosting profile keeps the shared cache in MariaDB (`CACHE_STORE=database`, default connection). That store differs
from Redis and the file store in the one way that matters here: its writes are rows of the `cache` table on the **same connection** the
permission change uses. `tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php` proves the unchanged invariant (*once a revocation has
committed, a check that begins afterward does not grant through stale shared cache data; a decision already under way may finish under the
state it read*) on that store, and CI runs it as a mandatory step ([ci.md](../testing/ci.md)). Nothing about the mechanism changed.

**Method.** Real, independent PHP processes against the real MariaDB `database` driver, wired exactly as `CACHE_STORE=database` wires it
(`permission.cache.store = default`; a unique `CACHE_PREFIX` per test, shared by the epoch row and the entries as in production). The
revocation runs in a child that owns its transaction (`tests/Support/permission_cache_tx_writer.php`), so the test process holds no
transaction and no lock and can never wait for a lock that it holds. Every process appends to one ordered trace
(`permission_cache_trace.php`) before it signals anything that lets another proceed. The flag-file barriers enforce the interleavings (a
parked process cannot go on until released), and each scenario asserts the events that matter as an **ordered subsequence** of the trace
(other events may fall between them), not as the complete sequence.
Waits are flag files with a 60 s bound, never sleeps or `information_schema` polling; a timeout, crash, lock-wait error or skip fails.

| Code | Proven (authorization correctness) |
|---|---|
| A | A stale reader (read epoch and rows before the commit, stored after it) cannot restore a revoked permission; a reader that begins afterward uses the new epoch and is denied. The stale entry really is in the table, under the obsolete epoch. |
| A-control | **Negative control.** The same interleaving with the library's own unfenced registrar *does* grant the revoked permission, so A has teeth. |
| B | Readers after the commit all see the revocation, whichever of them stores the entry and however `KeyWritten`/`KeyWriteFailed` is reported; exactly one row. |
| C1, C2 | A rolled-back revocation leaves the committed grants authoritative: library path (epoch restored exactly) and application hooks (epoch advanced once more, a harmless reload). A reader during the open transaction is still granted: the revocation has not committed. |
| D | Consecutive revocations; stale readers finishing in either order never resurrect an earlier state. |
| F1, F2, F3 | A cache **miss while a revocation is in flight**: stored after the commit (F1), stored before it (F2), stored and then rolled back (F3, where serving the entry is correct). The reader reads the *committed* epoch, never the writer's uncommitted one. |
| G1, G2, G3 | A persistent worker decides each job under the state committed when the job begins, across an open and a committed revocation; without the job-boundary reset it would keep granting (control); a reader inside its own REPEATABLE READ transaction keeps deciding under its snapshot and fills only the obsolete epoch. |
| J | A torn read (between the permissions and roles queries) fills only the obsolete epoch. |

Every scenario with a layout variant also runs with `DB_CACHE_CONNECTION` pointing at a second connection to the same database. That is
**not** an approved layout (see the hazard below); the invariant holds there too.

**Database-store write semantics (E1, E2, C1).** Represented as they are:

- On the default connection a cache write made inside the writer's transaction (the writer's own reload under the epoch it just created)
  is invisible to other connections, commits with the transaction (E1) and **disappears with a rollback** (C1), unlike the file and Redis
  stores, where the rolled-back state stays in the store (still unreachable). On a separate cache connection it commits on its own (C1).
- Every `syncPermissions` loads the mapping and, on a miss, stores it under the **current, still committed** epoch. On the default
  connection that insert is part of the writer's transaction.
- An upsert of an identical entry (same value, same expiration second) changes no row: MariaDB reports 0 affected rows and Laravel fires
  `KeyWriteFailed` although the entry is in the table (E2, with a frozen clock per reader so the outcome is deterministic). Tests therefore
  treat both events as "the write was issued" and read the table for what is stored.

**Negative controls that do not apply.** The unfenced control of the *pre-commit* store (R0.9-1C M-1) cannot be made deterministic
on this store: the reader's write can wait for the writer's transaction and then land after (or race with) the writer's post-commit clear,
an order a test cannot fix without observing server lock state. The post-commit race it shares with A is controlled by A-control; the
pre-commit window is covered by F2. No failing control was manufactured.

**Mutation sensitivity** (run by hand on the WP2 branch, reverted each time, not automated; scenarios A, C1, F2, G1, D = 11 tests):

| Production mutation | Result |
|---|---|
| A reader does not suffix its cache key with the epoch | 11 of 11 fail (real assertions) |
| A revocation does not advance the epoch | 11 of 11 fail |
| The `JobProcessing` reset is removed | exactly **DB-G1** fails; the 10 others pass |
| The epoch is memoized in the process; or tokens are reused; or the epoch is advanced on its own connection | everything fails, but in *setup* (the library's own lookups read a never-invalidated cache, or the installation waits for its own transaction), so these are not targeted detections and are not claimed as such |

**Database locking and latency (characterised; not authorization).**

- The epoch read is a consistent read: it never waits for a writer and never sees its uncommitted epoch (L1).
- A reader whose miss stores the very row that a *loading* writer's open transaction has inserted waits for that transaction (L3),
  bounded by `innodb_lock_wait_timeout` (50 s by default), and then writes after the commit: the post-commit case of A. A revocation that
  loads nothing (the built-in role synchronization's raw-SQL shape) holds no such row (F2).
- **Separate cache connection hazard (observed, cause not fully explained, not approved).** In loops of built-in role installations with
  `DB_CACHE_CONNECTION` set, the second connection's cache `INSERT` waited for the synchronization transaction of the *same PHP process*
  until the lock-wait timeout in 4 of 46 runs. The server showed the waiter requesting an X lock on the `PRIMARY` supremum pseudo-record
  held by that open transaction (48 rows modified), and in both runs whose statements were logged the failure came right after the epoch
  row's `expiration` had changed inside it. A single connection cannot wait for itself, so the approved profile is not exposed to this.
  The tests install under the default layout for this reason, and the layout is documented as unsupported.
- The earlier investigation's intermittent InnoDB lock-wait timeouts came from the harness (a parent holding the transaction while waiting
  for a child whose cache write needed that transaction's row). These tests cannot do that. Its one unexplained lock-*observation* timeout
  concerned `information_schema` polling, which is not carried over.

**Remaining production host assumptions (not proven by CI).** Testing proves behaviour on MariaDB 11.4.13 in Docker; it does **not**
prove the actual Namecheap configuration. Still to be verified there: the server really is MariaDB 11.4.x with InnoDB tables (the host's *default* engine is MyISAM, so InnoDB comes from the application's
own `engine = InnoDB` and is verified by `database:verify`: [Database Profile](./database-profile.md)) and
`REPEATABLE-READ` (the 2026-10-19 probe reported it) (a different isolation level changes lock behaviour, not the epoch's MVCC reasoning, and must be tested), the effective
`innodb_lock_wait_timeout`, no read replica and no `DB_CACHE_CONNECTION`/`DB_CACHE_TABLE` override, that the web and queue processes use
the same database, `cache:clear` and restore procedures (they delete the epoch row, which is safe: a new token is minted), queue worker
restarts on deploy, and the mixed-version deployment rule above. Release 0.9 remains **not customer-ready**.

**Production preflight requirements (carry into the WP3 handoff; not implemented in WP2).** The tests above prove the fence only for
the supported cache topology, and the application does not check that it is running in it. The production preflight must **reject** (fail
the deployment, not warn) any of:

- a cache store other than `database` (`CACHE_STORE` / `cache.default`);
- a permission cache store other than `default` (`permission.cache.store`), which would move the library's cache away from the store the
  epoch fences and the tests exercise;
- a separate cache connection (`DB_CACHE_CONNECTION` set): the epoch is always on the default connection, so the entries and the epoch
  would no longer share a transaction, and that layout can wait on its own transaction (see the hazard above);
- database read/write splitting or a read replica on the default connection (`read`/`write` host lists in `config/database.php`): the
  library's own loader reads through the read connection and would store pre-revocation rows under the active epoch;
- an unexpected `DB_CACHE_TABLE` (the epoch row lives in `cache.stores.database.table`; anything but the migrated `cache` table is
  unreviewed).

**Same effective `CACHE_PREFIX` everywhere.** The epoch row and every permission-cache entry are keyed under `cache.prefix`, which is
`CACHE_PREFIX` or, when that is unset, a slug derived from `APP_NAME` plus `-cache-`. The web, queue-worker and cron/scheduler
processes must therefore resolve the **same effective prefix**, including when it is `APP_NAME`-derived (a different `APP_NAME` or
`.env` for the worker or the cron environment would give it its own epoch row and its own entries: a revocation made by one would never
advance the epoch the other reads). The preflight must compute the effective prefix in each process context and fail if they differ; the
tests use one unique prefix per scenario and cannot detect this.

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
| The cache-repopulation race with independent processes, a negative control, outer transaction, rollback, consecutive revocations, role deletion, a killed writer, flushes, a persistent worker, the built-in synchronization, torn reads, concurrent first use, failures | `tests/Feature/Admin/PermissionCacheEpochTest.php` (workers: `tests/Support/permission_epoch_reader.php`, `permission_epoch_writer.php`) |
| Loading paths, mutation coverage, atomic rollback, the job-boundary reset, cache outage | `tests/Feature/Admin/PermissionCacheEpochMutationTest.php` |
| The same invariant on the **database** cache store (A–G, J, negative control, rollback, KeyWriteFailed, locking characterisation), mandatory in CI | `tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php` (workers: `tests/Support/permission_epoch_reader.php`, `permission_cache_tx_writer.php`, `permission_cache_trace.php`, shared helpers `permission_cache_harness.php`; gate: `src/tests/Support/verify_database_cache_security.php`, tested by `tests/Unit/Ci/DatabaseCacheSecurityGateTest.php`) |
| CRM company deletion | `tests/Feature/Crm/CompanyDeletionSafetyTest.php` |

R0.9-1C tests that edited the built-in `operator` role through the Roles screen as a way to demote an administrator now
use a custom role carrying the same permissions (`LastAccountAdministratorTest`), because that edit is refused by design.

## Known limitations

- The model itself is not locked: `Role::find(…)->delete()` in a shell, or SQL, can still change a built-in role. Shell and
  database access are the authorization boundary.
- Stored permissions that no built-in role defines are reported and kept, never deleted.
- A custom role that holds a permission later removed from the catalogue keeps the stored row; nothing reconciles it.
- The bootstrapped account's email is not marked verified (the application does not require verification anywhere).
- The permission-cache repopulation race found by the R0.9-1D review is addressed by R0.9-1E ([above](#permission-cache-generation-fencing-r09-1e)), which is implemented and passed independent security review for the supported current topology (no read replica); hosted CI and Playwright for it are outstanding, and its limits and the mixed-version deployment hazard are listed there.
- Hosted CI ran this package once ([run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30): the full Pest suite passed with 0 skipped, and the Redis variants of the cache
  tests ran on a real Redis server (a dedicated step fails on any skip). It is evidence, not release approval, and predates
  R0.9-1E.
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
