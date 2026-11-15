# Release 1.0 readiness defect: `PermissionCacheEpoch::current()` throws when the epoch row is missing and another transaction creates it

**Status: FIXED in the pre-RC1 security hardening (2026-11-14; see [Resolution](#resolution-2026-11-14) at the end).** Originally: open, **pre-existing** (introduced with the epoch in Release 0.9, R0.9-1E), found on 2026-11-11 while verifying R1-3A. It is **not** part of R1-3A and nothing in the epoch subsystem was changed for it. It is recorded here as a separate Release 1.0 release-readiness item for the owner to schedule.
**Severity:** low in steady state, but it is an uncaught `RuntimeException` on the permission-check path, so a hit is a 500 for that request.
**Release 1.0 preproduction obligation: discharged by the resolution below.** (It was not part of R1-3A and was not fixed there.)

## When the epoch row is absent

The row is **not** guaranteed to exist forever after its first creation. It is absent after:

- **a fresh installation**, until the first permission check or the first built-in role synchronization creates it;
- **`cache:clear` (or any flush) against the database cache store**, which deletes every row of the `cache` table, the epoch row included;
- **a database restore, or any clearing of the cache table** (a restored backup that predates the row, a manual `TRUNCATE`/`DELETE`, a rehearsal or test cleanup).

Each of those puts the system back in the vulnerable first-use state, and the first requests after it race to create the row. Operational runbooks for a restore or a cache clear on the database store should therefore treat the first minutes afterwards as exposed (or warm the epoch with one permission check before opening traffic) until the remediation below is made.

## What is wrong

[`PermissionCacheEpoch::current()`](../../src/app/Support/Permissions/PermissionCacheEpoch.php) reads the epoch row and, when it is absent, inserts one with `INSERT IGNORE` and reads it again:

```php
$token = $this->read();                      // plain SELECT
if ($token !== null) { return $token; }
$this->table()->insertOrIgnore($this->row($this->newToken()));   // INSERT IGNORE
return $this->read() ?? throw new RuntimeException('The permission cache epoch could not be created.');   // plain SELECT again
```

Both reads are **plain (consistent) reads**. Under `REPEATABLE READ`, inside a transaction whose snapshot was established **before** another transaction created and committed the row, the second read still sees no row: the `INSERT IGNORE` is ignored (the row exists, committed by the other transaction) and the read returns `null` from the old snapshot, so the method throws. In autocommit, every statement takes a new snapshot, so only a caller **inside a transaction that has already read something** is affected.

Two writers can create the row in the same moment. The loser's `INSERT IGNORE` waits for the winner to commit; when it does, the loser reads from its older snapshot and throws.

## Reproducible scenario (raw SQL, two sessions)

Verified on MariaDB 11.4.13 (a scratch table, `k` the primary key, dropped afterwards):

```sql
-- session B                                      -- session A
START TRANSACTION;
SELECT COUNT(*) FROM t WHERE k='epoch';  -- 0
                                                  START TRANSACTION;
                                                  INSERT INTO t VALUES ('epoch','A-token');   -- not committed yet
INSERT IGNORE INTO t VALUES ('epoch','B-token');  -- WAITS for A
                                                  COMMIT;
-- B's insert is ignored (duplicate), then:
SELECT COUNT(*) FROM t WHERE k='epoch';  -- 0   <- the row exists and is committed, B's snapshot cannot see it
```

In the application, with the database cache store and the epoch row deleted:

1. Worker H runs a guard-class mutation (a role's permission change, or `roles:sync`). It advances the epoch (an upsert that creates the row) and is held before it commits.
2. Worker T runs `TicketService::create()`, whose authorization check (`ServiceRequestAccess::authorizeActor` → `can('tickets.create')`) runs inside the creation transaction and reads the epoch through `current()` after the transaction's first read.
3. H commits. T throws `RuntimeException: The permission cache epoch could not be created.` and the ticket is not created.

This is exactly what the two "T and I arriving behind a frozen hook … epoch row missing" tests in [`OrganizationInvitationConcurrencyTest`](../../src/tests/Feature/Crm/OrganizationInvitationConcurrencyTest.php) observe; they tolerate that single known outcome for T (matched on the exception message) and assert that nothing deadlocked, timed out or retried. When this defect is fixed, those two variants should be tightened to require `ok` for every worker.

It is not reachable through today's HTTP routes for the common requests: the `can:` route middleware loads the permission copy (and with it the epoch) **before** the controller opens a transaction. It is reachable from any code that checks a permission for the first time in a process inside a transaction (services called from commands, queue jobs, and tests).

## Effect of R1-3A

R1-3A's R4 (advance the epoch first, inside the guarded transaction, before the invitation locks) does not create this defect and does not change its precondition (a missing row). It moves the hook's own advance, which creates the row on a fresh installation, earlier in the hook's transaction, so a concurrent in-transaction first use waits for the hook's commit for slightly longer before it fails. In the other direction the hook itself no longer depends on a pre-advance read (its first epoch access is its own write), so the hook is not exposed to the defect.

## Suggested minimal remediation (not applied)

Make the second read a **locking read**, which returns the latest committed row regardless of the snapshot:

```php
return $this->table()->useWritePdo()->where('key', $this->key())->sharedLock()->value('value')
    ?? throw new RuntimeException('The permission cache epoch could not be created.');
```

Verified on the same two-session sequence: `SELECT … LOCK IN SHARE MODE` after the ignored insert returns `A-token`. Only the post-insert read needs it (the first read is a cheap fast path that may legitimately be stale: a stale `null` only leads to the insert-or-ignore). It takes one shared lock on the epoch row inside the caller's transaction until it ends, which conflicts only with a transaction that advances the epoch, and those serialize on it anyway. Add a regression test with two real connections (the T/H interleaving above) that expects `ok` for both. Alternatively seed the row in a migration or in the first-synchronization command so that the row exists before any request, which removes the creation race entirely; both remedies can be used together.

## Resolution (2026-11-14)

**Fixed in `PermissionCacheEpoch::current()`; no schema change and no change to any lock order. It does not remove locking: the ignored duplicate insert on the ephemeral path takes a shared lock on the existing epoch row, which the calling transaction holds until it ends (before the fix the call threw and the transaction usually rolled back), so a concurrent epoch advance waits behind it. This path is rare (first use only).**

- **What it does now.** After the ignored insert, if the epoch row still cannot be read **and the connection is inside a transaction**, `current()` returns an **ephemeral token** (`snapshot-` plus 128 random bits): unique to that call, never stored, known to no other reader. The permission library then loads the mapping from the same snapshot (the state this decision is entitled to) and files it under a key nobody will ever look up. Outside a transaction an unreadable epoch is still the same `RuntimeException`: an autocommit statement has no snapshot excuse, so that case is a real failure.
- **Why not the suggested locking read (this record's earlier recommendation, withdrawn).** `SELECT … LOCK IN SHARE MODE` returns the *other transaction's new* token while the transaction's snapshot still holds the *older* mapping. The library would then file the old mapping under the key every later reader trusts, so a revocation that committed in between would **survive for the cache lifetime**: the exact race the epoch exists to prevent. Separately, with MariaDB's `innodb_snapshot_isolation=ON` (the default from 11.6.2; off in the 11.4 series this project runs) a locking read of a row the snapshot cannot see is **error 1020**, so that remedy would also have made the application depend on the setting. The ephemeral token needs neither.
- **Seeding the row** (the record's alternative) was not adopted: a cache clear or restore removes the row again, so it would only narrow the window.
- **Tests.** `PermissionCacheEpochFirstUseTest` (two real MariaDB sessions: B takes its snapshot, A creates and commits the row, B asks; the old code throws `The permission cache epoch could not be created.`, which was reproduced against the old code before the fix was applied; the token is ephemeral, unique per call, never stored, nothing overwritten; a caller that creates the row itself still stores one token; an unreadable table outside a transaction is still an error). The two "T and I arriving behind a frozen hook … epoch row missing" variants of `OrganizationInvitationConcurrencyTest` no longer tolerate the old outcome: **every worker must finish `ok`**, and they do (4 of 4 variants, 132 assertions). The existing epoch suites (`PermissionCacheEpochTest`, `…MutationTest`, `…DatabaseStoreTest`, `…CommitOrderTest`) pass unchanged.

### The "preload permissions before transactional ticket creation" requirement: assessed, not adopted

The R1-3 record asked for a preload in `TicketService::create()` "before any non-HTTP ticket path". Findings: both callers of the creation path today are controllers (`TicketController::store` behind `can:tickets.create`, `SupportRequestController::store` behind `auth`); there is **no** command, job or seeder that creates a request. `TicketController::store` is behind `can:tickets.create`, which loads the permission copy (and the epoch) before the controller opens its transaction; the partner-support path decides by the operator-issued designation, not by `tickets.create`. So the defect was reachable only from code that checks a permission for the first time in a process inside a transaction, as the original record said, and there is none today. With this fix that case is **safe**, so a preload is not needed for correctness. It was **not added** because it would move cache-table reads and writes out of the creation transaction, which changes the lock structure the R1-3A concurrency tests pin, for no demonstrated benefit. **Rule kept for the future:** a new non-HTTP caller may rely on `current()` now; if it wants the cache I/O out of its transaction it should call `$actor->can('tickets.create')` once before opening it, and re-run `OrganizationInvitationConcurrencyTest`.
