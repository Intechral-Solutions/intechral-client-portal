# Release 1.0 readiness defect: `PermissionCacheEpoch::current()` throws when the epoch row is missing and another transaction creates it

**Status:** open, **pre-existing** (introduced with the epoch in Release 0.9, R0.9-1E), found on 2026-11-11 while verifying R1-3A. It is **not** part of R1-3A and nothing in the epoch subsystem was changed for it. It is recorded here as a separate Release 1.0 release-readiness item for the owner to schedule.
**Severity:** low in steady state, but it is an uncaught `RuntimeException` on the permission-check path, so a hit is a 500 for that request.
**Release 1.0 preproduction obligation (retained).** This defect must be remediated, or explicitly accepted by the owner with the conditions below, before Release 1.0 goes to production. It is not part of R1-3A and is not fixed there.

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
