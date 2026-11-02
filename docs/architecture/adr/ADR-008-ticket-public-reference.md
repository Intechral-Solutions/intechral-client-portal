# ADR-008: Ticket public reference (C6-R)

**Date:** 2026-10-31  
**Status:** Accepted (owner decision, 2026-10-31; Option B)  
**Supersedes:** Release 0.9 decision C6 (a previously communicated ticket number is never reissued), by dated addendum in [the Release 0.9 scope](../../product/release-0.9-scope-and-architecture.md#12-supersession-record). The earlier C6 text is not edited.  
**Related:** scope sections 7.1 and 8 (backup and restore); EPIC-010D A3.5, P3a and P3b (history, kept).

## Context

C6 required that a ticket number, once communicated to a customer, is never issued again, including after disaster recovery
restores an older database. Ticket numbers were derived from the autoincrement id (`TKT-` plus the zero-padded id), so a
restore to an older state would re-issue the numbers of every ticket created after the backup. Meeting C6 would have needed
reserved id blocks, a ceiling renewed on the host and escrowed off the host, or a different key type. For a first release
with a handful of customers on shared hosting, that machinery costs more than the risk it removes.

## Decision

1. Internal BIGINT primary keys and foreign keys are unchanged. Relationships, joins, policies and queue payloads keep using
   them internally.
2. The customer-facing identity of a ticket is an independently generated, cryptographically random reference of 10
   Crockford Base32 symbols (50 bits), shown as `TK-XXXXX-XXXXX` (14 characters).
3. Alphabet `0123456789ABCDEFGHJKMNPQRSTVWXYZ` (no I, L, O, U). Each symbol is `random_int(0, 31)`; no modulo bias, no
   dependence on the id, the clock or any counter.
4. Storage reuses `tickets.ticket_number VARCHAR(16) UNIQUE` (migration `2026_03_25_130335`). No new column, no migration.
5. Uniqueness is enforced by the database. A creation that hits a duplicate on that index is retried with a new reference,
   at most 5 attempts, each attempt in its own savepoint. Only that specific violation is retried; every other error
   propagates. Exhaustion fails closed: no ticket row, attachment row, stored file, notification or queued job.
6. A reference is immutable once issued. The model refuses to change a canonical `ticket_number`. The one sanctioned
   exception is the guarded operation that replaces non-canonical (legacy) values (see Existing data).
7. A reference is an identifier, not a credential. Authorization stays in `TicketPolicy`; knowing or guessing a reference
   grants nothing.
8. Routing, links, notifications, props, search and exports use the reference. Numeric ids are not accepted on any
   ticket-addressed route.
9. Release 0.9 does not include UUIDv7, reserved id blocks, ceiling management, an off-host reservation service, or a
   compatibility lookup for sequential numbers.

## Owner-accepted risk (supersedes C6's absolute guarantee)

After disaster recovery to an older consistent state, non-reuse of a previously issued public reference is **probabilistic,
not absolute**. For L references lost in the rollback and M issued afterwards, the probability that any new reference equals
a lost one is at most L x M / 2^50 (2^50 is about 1.13e15). For L = 1,000 and M = 100,000 this is about 9e-8. The owner has
accepted this explicitly in place of C6. Within a live database, collisions among current tickets are handled by the unique
index and the bounded retry (expected collisions for 100,000 tickets: about 4e-6 per full set).

What is NOT relaxed: a restore still requires an identified, consistent, verified backup set (scope sections 7.1 and 8), and
the stale-identity safeguards below.

## Existing data

Any `ticket_number` that does not match the canonical pattern is legacy (`TKT-0001`, `TKT-E2E1`, `TKT-F…`, `TMP-…`).

- The intended production deployment is a fresh installation, separate from the existing production portal. No real
  customer-facing `TKT-` reference from this portal is known to exist (see Compatibility assumptions; to be verified before
  deployment).
- Legacy values get a repeatable, guarded application operation, `php artisan tickets:rereference`, not ad hoc SQL:
  dry-run by default; `--apply --expect=N` requires N to equal the number of legacy rows or nothing changes; `--check`
  exits non-zero if any legacy row exists; applying is gated to the staging conversion (explicit config gate, row cap,
  `--expect`); the immutability guard permits only this legacy-to-canonical conversion (the command, and DevSeeder for a legacy development fixture); each row is a compare-and-set update inside its own transaction using the same
  generator and bounded retry; only `ticket_number` changes (ticket rows, replies, attachments, history, time entries and
  stored attachment paths are untouched); a second run finds nothing to do.
- No lookup path maps old numbers to new references. Old links return 404.

## Stale numeric identity (recovery)

In this change (application code): references-only ticket routes, nested attachment URLs, bulk-by-reference and the time-attribution reference check. Deferred to the backup and restore tooling and runbook (not implemented here): session purge, cache clear, queue review, attachment quarantine and the recovery verification checks. The model guard covers evented Eloquent saves only; query-builder or raw writes to `ticket_number` bypass it and no code does so.

A restored database reuses numeric ids that belonged to different tickets in the lost state. Safeguards:

- Ticket routes accept references only; `/tickets/{numeric id}` is 404.
- Attachment download is nested under the ticket reference (`/tickets/{reference}/attachments/{attachment}`); the old
  `/attachments/{attachment}/download` route is removed (404).
- Operator bulk actions submit references, not ids. Timer and time-entry ticket attribution carries a companion
  `ticket_reference` that must match the ticket id it accompanies.
- Restore invalidates all sessions (so no pre-restore page can submit a form), clears the database cache, and holds queue
  processing until the restored `jobs` / `failed_jobs` have been reviewed.
- Private attachment storage stays keyed by the internal numeric id (`tickets/{id}/`); public identity (reference) and
  storage identity (id) stay separate. Restore isolates files that have no restored database row.
- Recovery verification checks canonical format, uniqueness and identity consistency inside the restored snapshot (child
  rows resolve; attachment paths match their ticket id) instead of the obsolete sequential-number checks. Any id-to-reference
  fingerprint is derived from the dump or the restored copy, never from a separate live query.

## Consequences

- Positive: no operational ceiling to maintain; references reveal nothing about volume or creation order; the
  sequential-number invariants and their tests disappear.
- Negative: customers lose the short, speakable number; the tradeoff is accepted. References in already-sent mail (sandbox
  only to date) stop resolving.
- Residual: an attachment link that survives restore, within a valid ticket reference, may resolve to a different attachment
  of the same ticket whose numeric id was reused. It is still ticket-scoped and policy-checked; no cross-ticket or
  cross-user exposure. Accepted.

## Alternatives rejected

- Reserved id blocks / ceiling renewal / off-host escrow: operational machinery out of proportion to the risk.
- UUIDv7 primary keys: rewrites every foreign key and relation for a property the reference already provides.
- Keep sequential numbers and document the gap: leaves enumerable public identifiers and a known reuse path.

## Rollout and verification

Staging (two disposable UAT tickets with legacy numbers), in this order:

1. A verified database backup exists and is restorable (a hard gate; the staging backup tooling must be complete first).
2. `php artisan down`. Under strict routing a legacy ticket is a 404 the moment the new code is live, so nobody uses the site until converted.
3. Deploy the new release. No migration is involved.
4. Open the gate: `TICKET_REREFERENCE_ALLOWED=true` (and rebuild the config cache if it is cached).
5. `php artisan tickets:rereference` (dry run), then `php artisan tickets:rereference --apply --expect=2 --map-file=<new file outside the repository>`.
6. Close the gate (remove the variable; rebuild the config cache). `runtime:preflight` fails, and the cron workers refuse to start, while the gate is open (`helpdesk.rereference_allowed` in `RuntimeProfile`), so run the preflight only after this step. This fits the sequence: the site is down during the conversion, and a cron run that refuses to start in that window exits non-zero and changes no data. The cron wrapper may report it as a failed run; that is expected, and the two cron entries can be paused for the window if the notice is unwanted.
7. `php artisan tickets:rereference --check` exits 0. This is the check **after** conversion.
8. `php artisan up`; smoke test: a reference URL opens, the old numeric URL is a 404, an attachment downloads.

Rollback after conversion restores the step-1 database as well as the previous release, because the previous release cannot route references.

Production is a fresh installation, separate from the existing portal, which is not modified or adopted. Before it opens to traffic: the `tickets` table is empty and `tickets:rereference --check` exits 0; the recorded output is the proof that production starts with no legacy ticket records. The gate is never opened there. This check belongs to the EPIC-010D A1.10 first-install preflight.

## Compatibility assumptions (to be verified before production deployment)

- No real customer-facing `TKT-` reference from this portal has been issued: all mail to date went to a sandbox sink. Re-verify the mail history before deployment.
- The reference lives only in `tickets.ticket_number`; no other table stores a copy.
- `tickets:rereference` and its config gate are Release 0.9 scaffolding and are removed once staging is converted and production has proven zero legacy rows.
