# EPIC-010C: Billed Time-Entry Locking

**Status:** Implemented
**Branch:** `hardening/epic-10c-billed-time-entry-locking`
**Completed:** 2026-06-30

---

## Goal

Make a time entry immutable once billing has claimed it, whether that state is represented by the legacy `billed` flag or an `invoice_id` link. Locked entries remain visible in timesheets and reports but cannot be changed through ordinary user or operator mutation paths.

## Canonical Billing Lock

`TimeEntry::isLockedForBilling()` is the single definition used by the model, service, controller-facing flows, and views:

| `billed` | `invoice_id` | State |
|---|---|---|
| `false` | `null` | Mutable |
| `true` | `null` | Locked |
| `false` | Non-null | Locked |
| `true` | Non-null | Locked |

The shared validation message is: `This time entry has already been billed and can no longer be modified.`

## Behavior Before and After

| Area | Before | After |
|---|---|---|
| Lock definition | Update/delete checked only `billed` in the controller | `billed = true` or non-null `invoice_id` locks the entry centrally |
| Service writes | `TimeEntryService::update()` did not enforce a billing lock | Mutation methods reload and row-lock the entry, then enforce the lock in the transaction |
| Timer mutation | Description and allocation endpoints wrote directly to models | Description, stop, delete, and allocation mutations pass through guarded service methods |
| Allocation siblings | Rebalancing could indirectly alter a locked sibling block | The whole affected slot is locked and rejected if any associated entry is billing-locked |
| Operator access | Ordinary routes had no consistent service-level boundary | Operators retain reporting visibility but cannot bypass the same mutation guard |
| UI | Invoice-linked entries could still present mutable controls | Locked entries display a marker and suppress deletion/drag mutation controls |

Billing locking complements the canonical timer invariant from EPIC-007: billed or invoiced entries must never be running. A legacy inconsistent locked/running row cannot be edited, stopped, or reallocated through an ordinary mutation path; it requires an explicit corrective data repair.

## Mutation Matrix

| Path | Locked entry result |
|---|---|
| Manual update | Rejected with validation error |
| Delete | Rejected with validation error |
| Running description update | Rejected with validation error |
| Timer stop on inconsistent legacy row | Rejected with validation error |
| Block allocation target | Rejected without changing the slot |
| Block allocation locked sibling | Entire rebalance rejected without partial changes |
| Owner/operator reports | Still visible |
| Ordinary operator update/delete route | Same lock; no bypass |

## Regression Coverage

`tests/Feature/Time/BilledTimeEntryLockingTest.php` covers:

- all four combinations of `billed` and `invoice_id`;
- normal update/delete behavior for unlocked entries;
- update and deletion rejection for billed and invoice-linked entries;
- continued owner and operator report visibility;
- rejection of timer description/stop operations on legacy locked-running rows;
- target and sibling allocation protection without partial mutation;
- operator non-bypass through ordinary routes; and
- a fresh persisted-state check that prevents a stale model from bypassing a newly applied lock.

The existing time-tracking deletion test now asserts the shared billing-lock validation response.

## Verification

- Focused time tests: **34 passed (117 assertions)**
- Full Pest suite: **247 passed (636 assertions)**
- Pint: **181 files passed**

## Scope Boundaries and Follow-ups

- This epic does not create the time-to-invoice export workflow; it protects entries after another workflow marks or links them.
- No privileged billing correction/unlock workflow was added. Corrections to already billed data need a separately designed, audited process.
- Raw SQL or query-builder writes can bypass Eloquent/service invariants and should not be used as ordinary application mutation paths.
- Invoice and ticket sequence generation concurrency remains a separate hardening item.
