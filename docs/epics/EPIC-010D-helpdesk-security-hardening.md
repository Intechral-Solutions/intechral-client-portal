# EPIC-010D: Helpdesk Security and Integrity Hardening

**Status:** Planned
**Roadmap bucket:** NOW → [Critical Helpdesk hardening](../product/product-roadmap.md#critical-helpdesk-hardening)
**Series:** hardening epics [EPIC-010A](./EPIC-010A-mariadb-test-parity.md) · [EPIC-010B](./EPIC-010B-tenant-scoping.md) · [EPIC-010C](./EPIC-010C-billed-time-entry-locking.md) · **EPIC-010D**
**Planned:** 2026-09-24, against `epic-011e-projects-kanban` at `a09c723`
**Implementation branch:** `hardening/epic-10d-helpdesk-security` (from `804ae6f`)
**Work packages:** WP0 **Complete** (2026-09-24) · WP1 **Complete** (2026-09-24) · WP2 Not started
**Amendments:** [Amendment 1 (2026-09-24)](#amendment-1-wp0-results-2026-09-24): WP0 results, H1–H9 confirmed, H9 sequencing rule, concurrency evidence, production preflight procedure, plan corrections; [Amendment 2 (2026-09-24)](#amendment-2-wp1-results-2026-09-24): WP1 results, final policy semantics, H1/H2/H3/H4/H9 fixed, defense-in-depth, WP2 notification de-duplication decision

---

## Contents

- [Amendment 1: WP0 Results (2026-09-24)](#amendment-1-wp0-results-2026-09-24)
- [Amendment 2: WP1 Results (2026-09-24)](#amendment-2-wp1-results-2026-09-24)

1. [Status and parent context](#1-status-and-parent-context)
2. [Goal](#2-goal)
3. [Why this work is immediate](#3-why-this-work-is-immediate)
4. [Scope](#4-scope)
5. [Explicit non-goals](#5-explicit-non-goals)
6. [Locked decisions](#6-locked-decisions)
7. [Current authorization model](#7-current-authorization-model)
8. [Defect register](#8-defect-register)
9. [Target authorization and visibility contracts](#9-target-authorization-and-visibility-contracts)
10. [Reply security](#10-reply-security)
11. [Internal-note and attachment security](#11-internal-note-and-attachment-security)
12. [Search visibility](#12-search-visibility)
13. [Assignment eligibility](#13-assignment-eligibility)
14. [Bulk robustness](#14-bulk-robustness)
15. [Ticket-number and export integrity](#15-ticket-number-and-export-integrity)
16. [Tests-first strategy](#16-tests-first-strategy)
17. [Data and migration considerations](#17-data-and-migration-considerations)
18. [Work packages](#18-work-packages)
19. [Exit criteria](#19-exit-criteria)
20. [Deferred Helpdesk work](#20-deferred-helpdesk-work)

---

## Amendment 1: WP0 Results (2026-09-24)

WP0 ran on `hardening/epic-10d-helpdesk-security`, branched from `804ae6f` (the committed plan). It added tests and test support only: **no application, route, policy, migration, configuration, dependency, or frontend file changed.** Every H1–H9 finding reproduced as planned. Where this amendment conflicts with the body, the amendment wins. The body corrections it requires are applied in place (§8 F-6, §17, §18 WP0).

### A1.1 Suites added

All suites are in `src/tests/Feature/Tickets/`. Each test name starts with one of these prefixes:

- **BASELINE**: correct behavior that must survive WP1/WP2.
- **DEFECT Hn (WPx flips …)**: *current broken behavior*, pinned only as evidence. The named WP flips or replaces it.
- **GUARD**: a contract WP1 must not break while fixing Hn.
- **ORDER**: current order of operations.
- **CHARACTERIZATION**: a recorded fact.

| File | Covers | Tests |
|------|--------|-------|
| `TicketSecurityCharacterizationTest.php` | H1, H2, H3 | 20 |
| `TicketVisibilityCharacterizationTest.php` | H4, H9, F-1 | 11 |
| `TicketAssignmentCharacterizationTest.php` | H5, reply notifications, stale assignee | 15 |
| `TicketBulkCharacterizationTest.php` | H6 | 16 |
| `TicketExportCharacterizationTest.php` | H7, F-6 | 17 |
| `TicketNumberCharacterizationTest.php` | H8, including a real concurrency probe | 7 |
| `TicketTestHelpers.php` | Shared fixtures (actors `owner`, `customer`, `operator`, and `agent` = custom role with `tickets.view` + `tickets.assign` + `time.log` but no `operator` role). Attachment fixtures refuse to write unless `Storage::fake('local')` is active; CSV parsing uses RFC-4180 (`escape: ''`) | — |
| `src/tests/Support/ticket_number_worker.php` | Child process for the H8 probe (pattern of `project_task_worker.php`); forces `APP_ENV=testing` and `MAIL_MAILER=array` | — |

Total: 86 new tests. The full suite is 906 passed (3567 assertions). No `todo` placeholders were added. Target tests are written red at the start of WP1/WP2 (§16).

### A1.2 Confirmed behavior

| ID | Confirmed | Key facts established |
|----|-----------|-----------------------|
| H1 | Yes | An unrelated `user`-role account, and even an account with **no role or permission at all**, posts a public reply to any ticket: the response is 302 with "Reply added.". The reply row is authored by the attacker, the attachment row and file are stored under `tickets/{ticket_id}/`, and `TicketRepliedNotification` goes to the owner and the assignee. `is_internal=1` from a non-operator is coerced to public, so it is not a gate. The operator reply route already refuses customers (403, nothing written). A missing ticket is 404 |
| H1 order | — | 1. `auth` middleware only. 2. Validation (an unauthorized request with an empty body gets `body` errors, **not** 403). 3. `is_internal` coercion. 4. Reply row insert. 5. For each file: disk write, then attachment row. 6. Owner notification. 7. Assignee notification. There is no transaction, so a failure after step 4 leaves the reply. WP1 must put `authorize('reply')` before step 2 |
| H2 | Yes | The owner downloads an internal-note attachment by id (200, bytes returned) although the show page hides it. The decision never reads `reply_id`/`is_internal`, including for a row whose reply belongs to another ticket (0 such rows in dev, P4b). An unrelated user gets 403 for every attachment of a foreign ticket. Answers: 403 for an existing foreign id, 404 for an unknown id, and 404 for an authorized row whose file is gone. So an attachment id's existence is observable, which is acceptable because ids are sequential. `Content-Disposition` carries the filename only, never the path. The `local` disk has `serve => true`, but unsigned `/storage/{path}` requests are refused (403), and nothing generates signed URLs. So the download route is the only access path |
| H3 | Yes | A term found only in an internal note makes the owner's ticket match, which changes the paginator total. A control ticket does not match. No snippet, note body or metadata is rendered: the list shows title, number, category, priority, status and age. Operator queue search matching internal text is pinned as BASELINE |
| H4 | Yes (latent) | A same-company peer with `tickets.view_org` sees the title, number and a `tickets.show` link that 403s. The clause depends on `tickets.view_org`. The operator's `/tickets` lists own and company-linked tickets only. Reachable only for `company_id` set outside the app (F-1) |
| F-1 | Yes | Neither an explicitly chosen company nor the single-organization auto-selection is persisted: `company_id` stays `NULL` |
| H5 | Yes | Single and bulk assignment accept a `user`-role customer, a custom role without `tickets.assign`, or a role-less account. Unassign works. An unknown id is a validation error with no change and no activity-log row. Each successful assignment writes an activity-log entry (`assigned ticket`, causer, `assignee_id`). Owners of the paths: `Operator\TicketController::assign` (single) and `Operator\TicketBulkController::update` (bulk), both through `TicketService::assign` |
| H6 | Yes | 500s with no mutation, from these exact throw sites: bulk `action=status` without the key (`ErrorException`: undefined array key "status"); bulk `status=''` (`TypeError`: `safeTransition()` string, null given); bulk `action=assign` without the key and single assign without the key (`ErrorException`: undefined array key "assignee_id"). Bulk `assign` with the empty placeholder **unassigns every selected ticket** and flashes success. An impossible transition is skipped per ticket, but the flash still counts every selected ticket. Invalid/missing action, empty selection and an unknown ticket id are validation errors with no mutation |
| H7 | Yes | `fputcsv` with PHP's default `\` escape writes formula-leading titles (`=`, `+`, `-`, `@`, leading spaces, leading tab) and submitter/assignee names verbatim. A customer plants one through the ordinary create form. `\"` inside a value is written in PHP's escape dialect, which an RFC-4180 reader corrupts. The query orders by `created_at DESC` only. Comma, quote, newline and UTF-8 round-trip correctly. `Content-Type: text/csv`, no BOM, header row pinned. The Time export (`TimeInertiaTest` "exports filtered RFC-compatible CSV and neutralizes spreadsheet formulas") remains the WP2 reference |
| H8 | Yes | See A1.5 |
| H9 | Yes | See A1.3 |

### A1.3 H9: route gate vs policy for the capability-only agent

The actor is `agent`, a custom role with `tickets.view` + `tickets.assign` and no `operator` role. A second variant, the `user` role plus a direct `tickets.assign` grant, behaves the same under the policy.

| Surface | Route gate | Policy / rule | Result for agent |
|---------|------------|---------------|------------------|
| `TicketPolicy::view` | — | `tickets.view && role operator` | **Denied** (owner ✓, operator ✓, customer ✗) |
| Operator queue, show (including internal notes and attachment links), status, assign, operator reply (including internal) | `can:tickets.assign` ✓ | none | Allowed |
| User show `/tickets/{id}` | `can:tickets.view` ✓ | `view` ✗ | **403** |
| Attachment download (public or internal) linked from operator show | `auth` ✓ | `view` ✗ | **403** |
| User reply route | `auth` ✓ | none (H1) | Allowed, and can post internal |
| Timer start with `ticket_id` | `can:time.log` ✓ | `AccessibleTimeContext` → `view` ✗ | **422 `ticket_id`** |
| Timer context options `type=ticket` | `can:time.log` ✓ | filtered by `view` ✗ | **Own assigned ticket not offered** |
| Reply notification "View Ticket" link | — | links `/tickets/{id}` | **Link 403s** |
| Dashboard recent tickets, navigation | keyed on `tickets.assign` | — | Operator links, Ticket Queue shown (consistent) |

### A1.4 WP1 sequencing and `authorize()` placement

**Sequencing rule (binding):** the H9 `TicketPolicy` correction (operator visibility = `tickets.assign`) lands **in the same commit as, or before,** any new `authorize('view')` / `authorize('reply')` call on an operator action. Adding those calls first would make the literal-role policy deny the agent the operator show, status, assign and reply actions it can use today. That would further restrict custom roles in an intermediate state. The **GUARD H9** test pins these as 200 and must stay green throughout WP1.

| Location | Add in WP1 |
|----------|------------|
| `Operator\TicketController::show` (line 35), `::updateStatus` (52), `::assign` (63) | `authorize('view', $ticket)` |
| `Operator\TicketReplyController::store` (12), serving both reply routes | `authorize('reply', $ticket)` as the first statement |
| `TicketController::downloadAttachment` (102) | attachment policy (§11) |
| `TicketController::show` (86) | unchanged `authorize('view')`; reply filter via `viewInternal` |
| `Operator\TicketBulkController::update`, `Operator\TicketController::index`, `Operator\TicketReportController::index`/`export` | none: bulk, list and aggregate actions stay route-gated, because once H9 lands the gate and the policy agree for every ticket |

### A1.5 H8: ticket number evidence

- Numbers are `TKT-` + `MAX(id)+1`. Nothing reserves the value between computing it and inserting (reflection: two reads return the same number).
- A taken number makes `create` throw `UniqueConstraintViolationException` with no ticket, attachment, file or mail. Over HTTP the customer gets a 500.
- Deleting the highest ticket makes the next create **re-issue its number**. No route deletes tickets or users today; `TKT-{id}` removes this too.
- `TicketFactory` numbers (`TKT-0001`…`TKT-9999`) share the service namespace.
- **Concurrency probe** (testing DB only; committed rows removed in `finally`; testing DB left with 0 tickets and 0 users):

  | Workers × creates | Attempts | Succeeded | Lost to unique violation | Other errors |
  |---|---|---|---|---|
  | 4 × 10 (suite default, 4 runs) | 40 | 29–30 | 10–11 | 0 |
  | 8 × 25 | 200 | 103 | 97 | 0 |
  | 8 × 50 | 400 | 193 | 207 | 0 |

  The committed probe asserts only what holds before and after WP2: every attempt either succeeds or loses with a unique violation, and committed numbers are distinct. WP2 tightens it to "every attempt succeeds". `TICKET_RACE_WORKERS`, `TICKET_RACE_CREATES` and `TICKET_RACE_REPORT=1` are honored when set inside the app container.

### A1.6 Notifications and stale assignees (input to WP2 §13)

- `TicketRepliedNotification` is `ShouldQueue` and mail only. It contains the ticket number and title (subject), a greeting with the recipient name, the author name, a 200-character body preview, and a "View Ticket" link to `/tickets/{id}`.
- Recipients:
  - the owner, on a public reply by someone else;
  - the assignee, on a public reply by someone other than the assignee;
  - nobody, for internal notes.
- There is **no visibility check** on either recipient.
- **When the owner is also the assignee, they receive two copies.** WP2 preserves this unless the owner approves de-duplication; it is not a confidentiality issue.
- Confirmed today:
  - a customer assignee receives reply previews for a ticket they cannot view;
  - an assignee who lost `tickets.assign` keeps the assignment and keeps receiving previews.
- WP2 therefore needs:
  1. no auto-unassign (confirmed: nothing reacts to role changes);
  2. an assignee-notification gate on `view` at send time in `TicketService::addReply`;
  3. eligibility re-validated on every assignment write, including re-submitting the same stale id;
  4. explicit unassign still allowed (BASELINE pinned).

### A1.7 `company_id` evidence

- No application write path persists `company_id` (F-1 test).
- Factories can set it directly, which is how H4 is reproduced.
- Dev has 0 company-linked tickets (P1).
- `ProjectVisibilityTest` "renders no ticket link that TicketPolicy would deny" also sets it directly for the dormant `/tasks` ticket rows. Its actor is a plain `user`, so WP1's policy change leaves it green: a guard, not a test to flip.

### A1.8 Plan corrections

1. **§17 query 3** would treat non-numeric numbers as `0` with a cast warning. Dev holds `TKT-E2E1`, an intentional `DevSeeder` fixture for the Playwright time spec. The invariant is restated over numeric-form numbers only (A1.10 P3a/P3b). Non-numeric numbers cannot collide with `TKT-{id}`.
2. **F-6** was mis-stated. `$request->date(…, 'Y-m-d')` keeps the current time of day, so **both** window edges sit at "now" on the given dates: part of the first day and part of the last day are excluded. Report and export share the window. Still deferred.
3. **H6** now names its four throw sites (A1.2), which the WP2 target tests must cover.
4. **H8** also covers re-issue of a deleted highest ticket's number, which is removed by the same fix.
5. **§16 "Tests encoding obsolete behavior"** is extended by the flip lists in A1.9.

### A1.9 Tests WP1/WP2 will flip or replace

- **WP1:**
  - Security suite: all `DEFECT H1` (3), `ORDER H1`, `DEFECT H2` (2), `DEFECT H3`.
  - Visibility suite: `DEFECT H4` and all `DEFECT H9` (4).
  - Assignment suite: `DEFECT H9 consequence` (mail link).
  - `BASELINE H4` "company clause is driven by tickets.view_org" stays true but loses its premise; reword or remove it with the clause.
- **WP2:**
  - Assignment suite: `DEFECT H5` (single ×3 dataset, bulk, customer-assignee preview) and `DEFECT stale assignee`.
  - Bulk suite: all `DEFECT H6`, including the throw-site dataset, which is replaced by validation tests.
  - Export suite: all `DEFECT H7`.
  - Number suite: all `DEFECT H8`, the re-issue and factory-namespace characterizations, and the probe tightened to all-succeed.
  - Existing tests: `TicketOperatorQueueTest` "allows operator to assign a ticket" and "bulk-assigns tickets to an operator" (role-less assignees).
- **Must stay green:**
  - every `BASELINE` and `GUARD` test;
  - the existing Ticket suites;
  - `ProjectVisibilityTest` "renders no ticket link that TicketPolicy would deny";
  - `TimeTrackingTest` ticket-context tests.

### A1.10 Production preflight procedure

Run before deploying WP1, and again before WP2. Rules:

- read-only, with a user that has `SELECT` only where possible;
- **never** from a development workstation against production without the operator's explicit go-ahead;
- record the counts in this epic.

1. Connect to the production database. Run `START TRANSACTION READ ONLY;` so any write attempt errors.
2. Run the queries below, which make no schema or data change.
3. Run `ROLLBACK;` and record every count with the date.

```sql
-- P0 total tickets (context)
SELECT COUNT(*) FROM tickets;
-- P1 H4 reachability: company-linked tickets
SELECT COUNT(*) FROM tickets WHERE company_id IS NOT NULL;
-- P2 stale/ineligible assignees (no tickets.assign directly or via any role)
SELECT COUNT(*) FROM tickets t
WHERE t.assignee_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM model_has_permissions mhp JOIN permissions p ON p.id = mhp.permission_id
                  WHERE mhp.model_type = 'App\\Models\\User' AND mhp.model_id = t.assignee_id
                    AND p.name = 'tickets.assign' AND p.guard_name = 'web')
  AND NOT EXISTS (SELECT 1 FROM model_has_roles mhr JOIN role_has_permissions rhp ON rhp.role_id = mhr.role_id
                  JOIN permissions p ON p.id = rhp.permission_id
                  WHERE mhr.model_type = 'App\\Models\\User' AND mhr.model_id = t.assignee_id
                    AND p.name = 'tickets.assign' AND p.guard_name = 'web');
-- P3a numbers not in service form (informational; e.g. seeded fixtures)
SELECT COUNT(*) FROM tickets WHERE ticket_number NOT REGEXP '^TKT-[0-9]{4,}$';
-- P3b H8 invariant for WP2's TKT-{id}: MUST be 0
SELECT COUNT(*) FROM tickets
WHERE ticket_number REGEXP '^TKT-[0-9]{4,}$'
  AND CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED) > (SELECT COALESCE(MAX(id), 0) FROM tickets);
-- P4 H2 exposure: internal-note attachments
SELECT COUNT(*) FROM ticket_attachments a JOIN ticket_replies r ON r.id = a.reply_id WHERE r.is_internal = 1;
-- P4b integrity: attachment and parent reply on different tickets (expected 0)
SELECT COUNT(*) FROM ticket_attachments a JOIN ticket_replies r ON r.id = a.reply_id WHERE r.ticket_id <> a.ticket_id;
```

**Gates:**

- **P3b ≠ 0 blocks WP2's numbering change** until investigated.
- **P4b ≠ 0** is investigated before WP1 ships the reply→ticket guard.
- **P1 and P2** decide how loudly the release notes mention list and notification changes; they do not block.

**Development results** (database `portal`, 2026-09-24, same SQL inside `START TRANSACTION READ ONLY … ROLLBACK`):

| P0 | P1 | P2 | P3a | P3b | P4 | P4b |
|---|---|---|---|---|---|---|
| 2 | 0 | 0 | 1 (`TKT-E2E1`) | 0 | 0 | 0 |

The rest of the dev data: max id 5, `AUTO_INCREMENT` 6, 0 internal replies. Dev data is too small to say anything about production.

### A1.11 Hygiene

- The development database `portal` was checksummed before and after (all 41 tables, `CHECKSUM TABLE … EXTENDED`): identical.
- Development storage (`storage/app`) file listing is identical.
- All Ticket characterization tests use `Storage::fake('local')` and clean it in `afterEach`; the fake disk holds only its `.gitignore` afterwards.
- Mail is faked in tests (`Notification::fake()`, `MAIL_MAILER=array` in `phpunit.xml`) and probe workers use the `array` mailer. By construction, no test path can deliver to Mailpit.
- The probe ran only against `intechral_client_portal_testing`, which `TestDatabaseSafety` enforces in each worker.

---

## Amendment 2: WP1 Results (2026-09-24)

WP1 fixed H1, H2, H3, H4 and H9 in one change, with the H9 policy correction in the same change as every new `authorize()` call (A1.4 sequencing rule). There was no migration, backfill, `company_id` write, dependency change, Blade/React change, route change or WP2 behavior change.

Method: the WP1-owned `DEFECT` tests were converted to `TARGET` tests first. Against unchanged runtime code, 24 of the 26 target cases failed for the expected reasons (an unauthorized reply returned 302 instead of 403, and so on). The other two already held: the agent replying, and the operator reply route refusing the owner. The runtime change then turned all of them green. Pre-fix evidence stays in Amendment 1.

### A2.1 Policy semantics (`TicketPolicy`, the single seam)

| Ability | Rule |
|---------|------|
| `view(user, ticket)` | owner **or** `can('tickets.assign')` |
| `reply(user, ticket)` | same as `view` |
| `viewInternal(user, ?ticket)` | `can('tickets.assign')` **and** (no ticket given, or `view`). The class-level form (`can('viewInternal', Ticket::class)`) answers "may see internal content of Tickets they can view", following `ProjectPolicy::manageMembers`' optional-model style. |
| `downloadAttachment(user, ticket, attachment)` | attachment belongs to the Ticket; `view`; body attachment → allow; parent reply must exist **on the same Ticket**; internal parent → `viewInternal` |

The capability test lives in one private helper (`worksTickets` = `tickets.assign`). No role name appears in Ticket authorization. `tickets.view` alone grants nothing beyond the actor's own Tickets. `tickets.view_org` is unused and reserved (D1).

**Why one policy rather than a `TicketAttachmentPolicy`:**

- The repository registers one policy per aggregate root (`Ticket`, `Project`, `Invoice` in `AppServiceProvider`).
- The attachment decision is entirely derived from Ticket abilities (`view`, `viewInternal`).
- Keeping it on `TicketPolicy` avoids a second policy that would have to call back into the first. The controller passes the aggregate root first: `authorize('downloadAttachment', [$attachment->ticket, $attachment])`.

### A2.2 Final behavior

| Finding | Final code path |
|---------|-----------------|
| H1 | `Operator\TicketReplyController::store` (both reply routes). The new order: **1.** `authorize('reply')`; **2.** validation; **3.** `is_internal` coercion via `viewInternal`; **4.** reply row; **5.** per file: disk write, attachment row; **6.** owner notification; **7.** assignee notification. A refused actor gets 403 with no session errors, no reply or attachment row, no file and no notification, for a valid body, an empty or missing body, an attachment, and `is_internal=1`, whether the actor is an unrelated customer or a role-less account. Non-operators' `is_internal=1` is still coerced to public. Closed-ticket replies are unchanged (F-2). Reply/file atomicity is unchanged (F-7) |
| H2 | `TicketController::downloadAttachment` → `downloadAttachment` ability. The matrix is pinned for body, public-reply and internal-note attachments across owner, operator, agent and unrelated customer. An internal attachment is refused to the owner (403, no bytes, no `Content-Disposition`). An attachment whose parent reply is on another Ticket is refused to everyone, operators included. The 404 for an authorized row whose file is gone is unchanged. There is no path exposure and no signed URLs |
| H3 | `Ticket::scopeSearch($term, bool $includeInternal = false)` excludes internal replies unless the caller opts in. Both callers pass the flag explicitly from `viewInternal(Ticket::class)`: the customer index (false for customers) and the operator queue (true). Hidden text no longer changes membership or the paginator total. Wildcard escaping (F-4) is unchanged |
| H4 | `/tickets` index is `Ticket::forUser($user)` only; the company/`tickets.view_org` branch is gone. Every listed row passes `view` (pinned). A same-company peer neither lists nor opens the ticket. `/tickets` is each actor's own-requests list; operators work through the queue |
| H9 | Operator visibility is `tickets.assign`. A custom role with it, or a `user` role with a direct grant, can: open `/tickets/{id}`; see internal notes on both show pages; download every attachment they are entitled to; start timers on, and be offered, their Tickets (`AccessibleTimeContext` unchanged, it follows the policy); follow the reply-mail "View Ticket" link. Internal visibility on the user show page now goes through `viewInternal` |

### A2.3 Operator defense-in-depth

`authorize('view', $ticket)` was added to `Operator\TicketController::show`, `::updateStatus` and `::assign`, and `authorize('reply', $ticket)` to the reply controller. They landed in the same change as the policy fix. **GUARD H9** stays green: the agent still uses queue, show, status, assign and reply. Bulk, index, reports and export remain route-gated, as A1.4 planned. With the policy and the `tickets.assign` gate agreeing for every Ticket, nothing on live inspection called for more.

### A2.4 Tests

- **Converted** (DEFECT → TARGET), 26 cases:
  - Security suite: H1 unauthorized reply (12-case dataset: 2 actors × 6 payloads), H1 agent reply on both routes, H1 operator route refuses the owner, H2 full matrix, H2 guessed internal id, H2 crossed parent, H3 customer search, H3 scope opt-in.
  - Visibility suite: H4 peer, H4 no dead links, H9 ability matrix (7 actors, including `tickets.view`-only and owner-with-`tickets.assign`), H9 show and attachments, H9 time context (agent allowed, foreign customer 422, owner allowed), H9 internal notes on both routes.
  - Assignment suite: H9 mail link.
- **Removed:**
  - `ORDER H1`, now covered by the dataset's empty/missing-body cases, which prove authorization precedes validation.
  - `BASELINE H4` "company clause is driven by `tickets.view_org`", whose premise is gone; replaced by the no-dead-links target.
- **Renamed only:** `GUARD H9`, `BASELINE H4` (operator own-requests list), `BASELINE H3` (operator opt-in).
- **Untouched:** all `BASELINE` tests; all WP2-owned `DEFECT H5–H8` and stale-assignee tests; existing Ticket, Time and Projects suites. No fixture needed changing for the new policy.
- Full suite: **918 passed** (3642 assertions), up from 906.

### A2.5 WP2 handoff update

- **Notification de-duplication is decided:** one recipient receives at most one notification per reply, even when they are both owner and assignee. This supersedes A1.6's "preserves this unless the owner approves".
- WP2 also implements the stale-assignee send-time gate (§13). It can use `TicketPolicy::view` directly.
- WP1 guarantees only that an unauthorized reply never reaches notification.

### A2.6 Hygiene

- The development database `portal` checksums are identical before and after (41 tables).
- Development `storage/app` is unchanged; the fake disk is clean.
- Mail is faked in every test path.

---

## 1. Status and parent context

EPIC-010D is a small, backend-only hardening epic on the existing Blade Ticket module. It follows the EPIC-010A–C pattern: characterize, write failing target tests, fix, prove the negative cases.

- It delivers the roadmap item [Critical Helpdesk hardening](../product/product-roadmap.md#critical-helpdesk-hardening), the first NOW item of the [Product Roadmap](../product/product-roadmap.md).
- It is a prerequisite of the [Helpdesk MVP](../product/product-roadmap.md#later--helpdesk-mvp), which is where the Ticket renderer migration ([EPIC-011](./EPIC-011-react-frontend-migration.md) Phase F) now happens. **EPIC-010D is not EPIC-011F** and moves no page to React.
- It can run in parallel with the Claude Design brief and exploration; it touches no UI direction.

**Provenance.** The original Ticket discovery audit is not stored in this repository. The [roadmap register](../product/product-roadmap.md#critical-helpdesk-hardening) (H1–H6) was re-verified against live code on 2026-09-24. This document re-verified H1–H6 again and adds H7–H9 from a focused live-code rediscovery (§8). Nothing here is reconstructed from memory.

## 2. Goal

Remove the known Ticket authorization, confidentiality, and integrity defects so the Helpdesk MVP is designed and built on safe server contracts:

- every Ticket read and write is decided by a server policy, not by route placement or rendered controls;
- internal notes and their attachments are invisible to non-operators through every channel (page, download, search);
- the customer list shows exactly the Tickets the customer may open;
- assignment, bulk actions, Ticket numbering, and CSV export cannot be driven into invalid or unsafe states.

## 3. Why this work is immediate

- **H1 is a cross-tenant write path.** Any authenticated account can post a public reply into any Ticket, and the reply emails the Ticket owner and assignee. Ticket ids are sequential, so every Ticket is reachable.
- **H2 and H3 are confidentiality leaks** of operator-only content to the Ticket's customer.
- None of these depend on a design decision. The roadmap principle "security/integrity first" applies: the defects are fixed before new product work touches Helpdesk.

## 4. Scope

In scope, all backend except one minimal Blade allowance (§14):

| Area | Change |
|------|--------|
| `TicketPolicy` | Capability-based abilities `view`, `reply`, `viewInternal`; remove the literal `operator` role check |
| Attachment authorization | A policy decision over Ticket → Reply/Note → Attachment |
| Reply endpoint | Explicit policy authorization before validation, storage, persistence, or notification |
| Customer list | Same universe as the detail policy; search excludes internal notes for non-operators |
| Assignment | One eligibility rule (`tickets.assign`) enforced for single and bulk assignment; assignee notifications only to a current viewer |
| Bulk endpoint | Conditional required parameters; validate-before-mutate |
| Ticket numbering | Collision-free number generation |
| Ticket CSV export | Formula neutralization, RFC-4180 escaping, deterministic order |
| Tests | Characterization and target Pest tests; update tests that encode obsolete behavior |

## 5. Explicit non-goals

EPIC-010D does **not** include:

- React/Inertia Ticket migration (EPIC-011 Phase F, delivered by the Helpdesk MVP)
- Helpdesk visual redesign, new shell, design system, or any layout/markup change beyond §14's error block
- Incident model, Knowledge model, SLA, routing, automation
- rich text, Markdown, sanitizers, or editor dependencies ([D4](#d4--reply-format))
- Ticket → Task workflow ([D5](#d5--ticket-derived-tasks))
- organization-level Helpdesk visibility, Directory, Person/Organization/Relationship modelling, persisting `company_id` on new Tickets ([D1](#d1--customer-ticket-visibility), [D3](#d3--historical-company_id))
- notifications redesign (only the recipient gate in §13 changes)
- report/dashboard changes (only CSV serialization safety in §15 changes)
- Finance, API, CI, deployment, Docker, test-infrastructure overhaul
- replacing role-name checks outside Ticket code (the tenancy scopes; see F-5 in §8)

## 6. Locked decisions

These are settled and are not reopened by this epic.

### D1 — Customer Ticket visibility

- A customer (authenticated non-operator) sees **their own** Tickets.
- Operators see Tickets according to Ticket capabilities (§9).
- Legacy company/organization membership does **not** grant Ticket visibility. The current same-company list clause is removed, not promoted into the policy.
- Organization-level Helpdesk visibility is future work, designed deliberately on the Person/Organization/Relationship model, possibly as a configurable capability. The `tickets.view_org` permission stays in the catalogue, unused by Ticket code, reserved for that design.

### D2 — Ticket assignee eligibility

A Ticket may be assigned only to a user who **currently** holds `tickets.assign`. The backend enforces the same eligibility the operator UI lists. Tests that assign a role-less account encode obsolete behavior and are replaced.

### D3 — Historical `company_id`

No inference or backfill of historical Ticket company ownership. Whether new Tickets persist organization context belongs to the Helpdesk/Directory design. (Finding F-1 in §8 shows no application path writes `company_id` today; that stays as is.)

### D4 — Reply format

Replies remain plain text. No Markdown, rich-text editor, or sanitizer dependency.

### D5 — Ticket-derived tasks

Ticket-derived task creation stays dormant. No Ticket → Task workflow here.

## 7. Current authorization model

As read from live code at `a09c723`. Paths are relative to the repository root.

### Routes

| Route | Middleware | In-controller authorization |
|-------|------------|-----------------------------|
| `GET /tickets`, `GET /tickets/{ticket}` | `auth`, `can:tickets.view` | `show`: `authorize('view')`; `index`: none (query-scoped) |
| `GET /tickets/create`, `POST /tickets` | `auth`, `can:tickets.create` | none needed (creates own) |
| `POST /tickets/{ticket}/replies` (`tickets.replies.store`) | **`auth` only** | **none** |
| `GET /attachments/{attachment}/download` | `auth` only | `authorize('view', $attachment->ticket)` only |
| `/operator/tickets/*` (queue, show, status, assign, bulk, reports, export, replies) | `auth`, `can:tickets.assign` | none (route gate only) |

Source: [`src/routes/web.php:77-108`](../../src/routes/web.php#L77-L108). Both reply routes are served by the same `Operator\TicketReplyController::store`.

### Policy

[`TicketPolicy::view`](../../src/app/Policies/TicketPolicy.php#L10-L19): owner, **or** `can('tickets.view') && hasAnyRole(['operator'])`. It is the only Ticket policy ability. It is also used by [`AccessibleTimeContext`](../../src/app/Rules/AccessibleTimeContext.php#L63-L66) (time against a ticket) and the `/tasks` link check.

### Capability facts

- Built-in `operator` role: every permission ([`RoleSeeder`](../../src/database/seeders/RoleSeeder.php)).
- Built-in `user` role: `tickets.view`, `tickets.create`, `tickets.view_org` among others ([`PermissionCatalogue::userDefaults`](../../src/app/Shared/Permissions/PermissionCatalogue.php)). So `tickets.view` does **not** distinguish operators from customers.
- Every operator-side Ticket surface is gated on **`tickets.assign`**: the operator route group, the Ticket Queue navigation item, internal-note visibility in the user show page, internal-note posting, the operator assignee dropdown (`User::permission('tickets.assign')`), and the dashboard's all-tickets branch.
- `tickets.admin` is defined but unused.

### Content visibility

- User show page loads replies filtered to `is_internal = false` unless `can('tickets.assign')` ([`TicketController::show`](../../src/app/Http/Controllers/TicketController.php#L86-L100)).
- `Ticket::attachments()` returns ticket-level attachments only (`reply_id IS NULL`); reply attachments load through the (filtered) replies.
- Operator show loads all replies and attachments.

### Attachment relationship

`ticket_attachments(ticket_id, reply_id NULL, user_id, filename, path, …)`. `reply_id` null = attached to the Ticket body (always customer-visible); non-null = attached to a reply, whose `is_internal` decides visibility. Files are on the private `local` disk under `tickets/{ticket_id}/` and are served only by the download route.

## 8. Defect register

Severities are for this code base's threat model (multi-tenant, customer-facing, invitation-only accounts). "Code-read" means established by reading live code; WP0 characterization tests confirm each before its fix.

| ID | Severity | Finding | Evidence | Target | WP |
|----|----------|---------|----------|--------|----|
| **H1** | Critical | Any authenticated user can post a public reply (with attachments) to any Ticket; owner and assignee are emailed a preview | Route `auth` only ([`web.php:94-96`](../../src/routes/web.php#L94-L96)); no authorization in [`TicketReplyController::store`](../../src/app/Http/Controllers/Operator/TicketReplyController.php#L12-L37); notifications in [`TicketService::addReply`](../../src/app/Services/TicketService.php#L42-L65); sequential ids | Policy `reply` authorized first; refusal persists nothing, stores no file, sends nothing (§10) | WP1 |
| **H2** | High | Ticket owner can download an internal-note attachment by id | [`TicketController::downloadAttachment`](../../src/app/Http/Controllers/TicketController.php#L102-L109) checks Ticket `view` only, never `reply.is_internal` | Attachment decision over Ticket → Reply → Attachment (§11) | WP1 |
| **H3** | High | Internal-note search oracle on the customer list | [`Ticket::scopeSearch`](../../src/app/Models/Ticket.php#L100-L108) matches all `replies.body`; used by [`TicketController::index`](../../src/app/Http/Controllers/TicketController.php#L30) | Internal-note text never influences non-operator results (§12) | WP1 |
| **H4** | Medium | Customer list includes same-company Tickets that `view` refuses (dead 403 links, metadata disclosure) | `orWhereIn('company_id', …)` for `tickets.view_org` in [`TicketController::index`](../../src/app/Http/Controllers/TicketController.php#L18-L37) vs owner-or-operator policy; EPIC-011E C8. **Currently latent:** no application path writes `company_id` (F-1), so only rows set outside the app are exposed | Customer list universe == customer detail universe (D1, §9) | WP1 |
| **H5** | Medium | Assignee can be any user, including a customer | `assignee_id` validated only `exists:users,id` in [`Operator\TicketController::assign`](../../src/app/Http/Controllers/Operator/TicketController.php#L63-L72) and [`TicketBulkController::update`](../../src/app/Http/Controllers/Operator/TicketBulkController.php#L12-L35); [`TicketService::assign`](../../src/app/Services/TicketService.php#L102-L110) writes blindly | One eligibility seam: `tickets.assign` (D2, §13) | WP2 |
| **H6** | Low | Malformed bulk/assign input errors instead of validating, or mutates unexpectedly | Code-read: `action=status` without `status` reads an absent `validated` key and would pass `null` to `safeTransition(string)`; `action=assign` or single assign without `assignee_id` reads an absent key; bulk `assign` with an empty assignee **silently unassigns every selected Ticket** (the bulk UI's placeholder option is empty) | Conditional required fields; 422/redirect-with-errors; no mutation (§14) | WP2 |
| **H7** | Medium | Ticket CSV export is open to spreadsheet formula injection | [`TicketReportController::export`](../../src/app/Http/Controllers/Operator/TicketReportController.php#L57-L96) writes customer-controlled `title` and user-controlled names raw, with PHP's default `\` escape character; order is `created_at` only (ties non-deterministic). Time export already does this safely ([`TimeEntryService::exportCsv`](../../src/app/Services/TimeEntryService.php#L357-L392), `safeCsvText`) | Same safe pattern as Time export (§15) | WP2 |
| **H8** | Low | Ticket number can collide under concurrent creation | [`TicketService::nextTicketNumber`](../../src/app/Services/TicketService.php#L136-L141) is `MAX(id) + 1`, outside any transaction; `ticket_number` is `UNIQUE`, so two parallel creates compute the same number and the second fails with a 500 after the user submitted | Number derived collision-free (§15) | WP2 |
| **H9** | Medium | Literal `operator` role in `TicketPolicy::view` disagrees with the `tickets.assign` gate everywhere else | [`TicketPolicy.php:18`](../../src/app/Policies/TicketPolicy.php#L18). A custom role holding `tickets.assign` can open the operator queue and operator show (route gate) but gets 403 on every attachment link there, cannot open the same Ticket on `/tickets/{id}`, and cannot log time against it. Not a leak (it over-restricts), but it is an authorization inconsistency and blocks custom operator roles; it also sits inside the seam H2 rewrites | Operator Ticket visibility is `tickets.assign` (§9) | WP1 |

### Rediscovery findings outside the defect register

| ID | Finding | Class | Disposition |
|----|---------|-------|-------------|
| F-1 | `TicketController::store` validates and auto-selects `company_id`, but `TicketService::create` never persists it; the create form's company selector is dead input | B — Helpdesk/Directory product | Left as is under D3. Makes H4 latent; the `/tasks` org-tab ticket clause is likewise inert |
| F-2 | Replying to a closed/resolved Ticket is allowed and does not reopen it | B | Helpdesk MVP conversation semantics |
| F-3 | Reply/Ticket notification mail renders user-authored `title` and body preview through Laravel's Markdown mail template, so Markdown syntax (e.g. links) in a reply renders in the email | B / platform notifications | After H1 only legitimate participants can author replies; revisit in the notifications capability. Does not conflict with D4 (reply storage and display stay plain text) |
| F-4 | Search `LIKE` terms do not escape `%` / `_` | B | Values are bound (no injection); search semantics belong to Helpdesk MVP / global search |
| F-5 | `OrganizationScope`, `OrganizationMembershipScope`, `OrganizationThroughCompanyScope` test `hasRole('operator')` | D — shared infrastructure | Not Ticket code; Tickets are not tenant-scoped models. Belongs with Directory/tenancy consolidation |
| F-6 | Ticket reports: supplied dates keep the current time of day, so both window edges sit at "now" on the given dates and part of the first and last day is excluded (corrected in [Amendment 1](#a18-plan-corrections)); a malformed date may throw instead of validating | B — reporting | Report/export filters are consistent with each other; correctness belongs to the Helpdesk reporting successor |
| F-7 | Attachments accept any file type; ticket create/reply file storage is not transactional with the row insert | B | Served only as downloads from a private disk, after authorization; upload policy is Helpdesk MVP |
| F-8 | Operator controller actions rely on the route gate only | — | Acceptable once the policy agrees with the gate (H9). EPIC-010D adds `authorize('view')` to operator show/status/assign and `authorize('reply')` to replies as defense-in-depth because it is one line each and uses the same seam; no new abilities. **Must land with or after the H9 policy correction** ([A1.4](#a14-wp1-sequencing-and-authorize-placement)) |
| F-9 | Product doc says no activity-log implementation exists; `spatie/laravel-activitylog` is installed and used by `TicketService::assign` and admin controllers | Documentation | Noted for the next product-doc revision; not changed here |

Checked and **not** defective: the dashboard ticket count and recent list already use owner-only for non-operators; operator queue search including internal notes is intended; attachment downloads force `Content-Disposition: attachment` from a private disk; cross-tenant `company_id` on create is already rejected (EPIC-010B); `ticket_ids.*` are `exists`-validated.

## 9. Target authorization and visibility contracts

One policy, capability-based. No new permissions.

| Ability | Rule | Consumers |
|---------|------|-----------|
| `TicketPolicy::view(user, ticket)` | `ticket.user_id === user.id` **or** `user.can('tickets.assign')` | user show, attachment decision, time context, `/tasks` links, operator actions (defense-in-depth) |
| `TicketPolicy::reply(user, ticket)` | same as `view` | both reply routes |
| `TicketPolicy::viewInternal(user, ticket)` | `view(user, ticket)` **and** `user.can('tickets.assign')` | internal replies on show, internal-note posting, internal attachment download, search including internal notes |

Rationale for `tickets.assign` as the operator capability (replacing `tickets.view + role operator`):

- It is already the capability every operator-side Ticket surface uses (§7), so the change **aligns** rather than widens: a `tickets.assign` holder can already open every Ticket via `/operator/tickets/{id}`.
- `tickets.view` is a customer default and cannot mean "all Tickets".
- Built-in roles are unaffected: `operator` holds everything; `user` lacks `tickets.assign`.

Customer list contract (H4, D1): the `/tickets` index lists `Ticket::forUser($user)` only. For a customer this equals the `view` universe; for an operator it is their own requests (their full universe is the queue). The rule is **list ⊆ view for every actor, and list == view for customers**; no row can link to a 403.

Route middleware is unchanged except that none is relied on for Ticket-level authorization.

## 10. Reply security

Target contract for `POST /tickets/{ticket}/replies` and `POST /operator/tickets/{ticket}/replies`:

1. `authorize('reply', $ticket)` is the **first** statement, before validation, so an unauthorized actor learns nothing from validation errors and no upload is read.
2. Refusal: HTTP 403; no `ticket_replies` row; no `ticket_attachments` row; no file written under `tickets/{id}/`; no notification.
3. `is_internal` is honored only if `viewInternal` passes; otherwise it is coerced to `false` (current behavior, preserved; the author's own content on their own Ticket).
4. Notifications (from §13): owner on public replies by someone else (unchanged); assignee on public replies by someone else **only if the assignee currently passes `view`**.
5. Blade reply forms are unchanged; their presence is not the control.

Nuance accepted: an owner whose role lost `tickets.view` can still reply to their own Ticket via the policy (the `view` ability does not require `tickets.view` for owners, as today). No confidentiality consequence.

## 11. Internal-note and attachment security

Attachment decision (H2), implemented as a policy on `TicketAttachment` (e.g. `TicketAttachmentPolicy::download`) so the controller calls `authorize('download', $attachment)`:

```
ticket  = attachment.ticket
reply   = attachment.reply            (nullable)

deny unless view(user, ticket)
if reply is null:               allow                    (ticket body attachment)
deny unless reply.ticket_id == ticket.id                  (integrity guard)
if reply.is_internal:           allow only if viewInternal(user, ticket)
else:                           allow
```

- The storage path, the attachment id, and link rendering never substitute for this decision.
- Refusal is 403 for an actor who cannot view the Ticket's internal content; the existing 404 for a missing file stays after authorization.
- Show pages keep filtering replies by the same seam (`viewInternal` instead of an inline `can('tickets.assign')`), so page, download, and search share one boundary.

## 12. Search visibility

Target: `Ticket::scopeSearch($query, string $term, bool $includeInternal = false)`; the reply clause adds `where('is_internal', false)` unless `$includeInternal`. The safe default is `false`.

| Caller | `includeInternal` |
|--------|-------------------|
| Customer list `/tickets` | `$user->can('tickets.assign')` (every listed Ticket is the user's own, so this equals `viewInternal` per row) |
| Operator queue | `true` (route gate `tickets.assign`) |

For a non-operator, internal-note text, metadata, and attachments never affect which Tickets match, the count, pagination, or any rendered snippet (the list renders no snippets today). Title, description, number, and public reply text still match. No new search engine; F-4 (wildcard escaping) is deferred.

## 13. Assignment eligibility

**One seam:** an eligibility check in `TicketService::assign` (the single mutation path for both single and bulk assignment), e.g. a small `assertAssignable(?int $assigneeId)` that accepts `null` (unassign) or a user who currently `can('tickets.assign')`, and otherwise throws `ValidationException` on `assignee_id`. Controllers keep `exists:users,id` for the shape check; eligibility is not duplicated in controller rules or Blade. The operator dropdowns already list `User::permission('tickets.assign')`, which matches; that query is not the control.

For bulk assignment, eligibility is checked **once before the loop**, so an ineligible assignee mutates no Ticket.

### Edge case: an assignee later loses `tickets.assign`

This is a real, currently unhandled case: the former operator stays `assignee_id` and keeps receiving public-reply previews for a Ticket they can no longer open.

**Minimal safest rule (adopted as the default; the owner may override it before WP2 starts):**

1. **No automatic unassignment.** Role/permission changes are not hooked; no scheduled clean-up; no data rewrite.
2. **Notification gate:** the assignee is notified only if they currently pass `view` (§10). This closes the confidentiality gap without touching data.
3. **Re-assignment validates:** any assignment write, including re-submitting the same stale id, must satisfy eligibility. Unassigning is always allowed.
4. The stale assignee still displays on the queue (it is true data); surfacing "no longer eligible" in UI is Helpdesk MVP work.

## 14. Bulk robustness

Target validation for `POST /operator/tickets/bulk`:

| Field | Rule |
|-------|------|
| `ticket_ids` | `required`, `array`, `min:1`; each `integer`, `exists:tickets,id` (unchanged) |
| `action` | `required`, `in:assign,close,resolve,status` (unchanged) |
| `assignee_id` | `required_if:action,assign`, `integer`, `exists:users,id`, then eligibility (§13). Bulk unassign is not offered by the UI and is not supported; single-ticket unassign remains |
| `status` | `required_if:action,status`, `in:open,in_progress,pending_user,resolved,closed` |

- All validation and eligibility run before the first mutation; the loop runs inside one DB transaction.
- Per-Ticket invalid transitions keep today's semantics (skipped silently by `safeTransition`); reporting skipped rows is Helpdesk MVP.
- Code reads `$validated[...]` only for keys the rules guarantee.
- Single assign (`PUT /operator/tickets/{ticket}/assign`): `assignee_id` becomes `present`/`nullable`, so an absent key is a validation error rather than an undefined-key error; empty string still unassigns.

**Minimal Blade allowance:** `operator/tickets/index.blade.php` renders no validation errors today, so a rejected bulk request would be silent. WP2 may add the same one-line `$errors->first()` block already used in `operator/tickets/show.blade.php`. No other markup change.

## 15. Ticket-number and export integrity

### Ticket number (H8)

Target: numbers cannot collide, format stays `TKT-` + zero-padded (min 4 digits), existing numbers never change.

Recommended approach: inside one transaction, insert the Ticket with a unique temporary `ticket_number` (fits the 16-char column), then set `ticket_number = 'TKT-' . str_pad($ticket->id, 4, '0', STR_PAD_LEFT)` before commit. The auto-increment id is unique, so the number is too.

Why this cannot collide with existing data: today each number is `MAX(id)+1` at creation, which is ≤ that Ticket's own id ≤ the current max id < any new id. The WP0 preflight (§17) verifies that invariant on production data before WP2 ships. Numbers stay sequential in the common case; after a deleted highest Ticket they may skip a value, which is acceptable (not an aesthetic redesign).

Alternative if the reviewer prefers not to write a temporary value: retry on `UniqueConstraintViolationException` a bounded number of times. Rejected as the default because it still races and hides contention.

Test fixture: `TicketFactory` currently picks random `TKT-0001…9999` numbers, which can collide with a service-created number in the same test. WP2 moves factory numbers out of the service's range (e.g. a distinct `TKT-F…` form within 16 chars), without changing production numbering.

### CSV export (H7)

Target for `GET /operator/tickets/reports/export`:

- every `fputcsv` call uses `escape: ''` (RFC-4180 double-quote escaping only);
- free-text cells (`title`, submitter name, assignee name, and `category` defensively) go through the same formula neutralization as the Time export (`safeCsvText`: leading `=`, `+`, `-`, `@`, after optional whitespace, gets a `'` prefix);
- order `created_at DESC, id DESC`;
- columns, headers, filename, and date filter unchanged (F-6 deferred).

Reuse: `safeCsvText` is private to `TimeEntryService`. With a second consumer, WP2 extracts it into one small shared helper used by both exports, keeping the Time export byte-identical (its existing tests are the regression).

## 16. Tests-first strategy

Rules: Pest feature tests on MariaDB; each target test is written and seen failing before its fix; negative cases assert **absence of side effects** (rows, files via `Storage::fake('local')`, `Notification::fake()`), not only status codes.

### WP0 — characterization (green on current code)

Lock behavior that must survive the fixes: owner reply; operator public reply notifies owner; internal note does not notify; internal notes hidden from owner on show; owner downloads a ticket-body attachment; operator downloads any attachment; operator queue search matches title/number/public and internal reply text; single assign/unassign by operator to an operator; bulk close/resolve; status transition validation. Existing tests already cover several; WP0 fills gaps only.

### Target matrix

| Area | Test | WP |
|------|------|----|
| Reply | owner can reply to own Ticket | 0 (char.) |
| Reply | `tickets.assign` holder can reply (public and internal) via either route | 0/1 |
| Reply | unrelated authenticated customer gets 403 on `tickets.replies.store` | 1 |
| Reply | forbidden attempt: no reply row, no attachment row, no stored file, no notification | 1 |
| Reply | forbidden attempt with invalid body still returns 403, not validation errors | 1 |
| Reply | non-operator `is_internal=1` is stored public | 0 (char.) |
| Attachment | operator downloads internal-note attachment | 1 |
| Attachment | owner gets 403 on internal-note attachment of own Ticket | 1 |
| Attachment | owner downloads public-reply and ticket-body attachments | 0/1 |
| Attachment | unrelated user gets 403 on any attachment of a foreign Ticket (guessed id) | 1 |
| Attachment | custom role with `tickets.assign` (no `operator` role) downloads from operator show | 1 (H9) |
| Search | customer search matches own title/description/number/public reply | 1 |
| Search | customer search term present only in an internal note returns no Ticket | 1 |
| Search | operator queue search still matches internal-note text | 1 |
| List/detail | owner sees own; unrelated customer does not | 1 |
| List/detail | same-company Ticket (`company_id` set directly) is **not** listed for a `tickets.view_org` customer | 1 |
| List/detail | every row on the customer list passes `view` (no dead 403 links) | 1 |
| List/detail | custom role with `tickets.assign` can open `/tickets/{id}` and log time against it; built-in `user` cannot open another's Ticket | 1 (H9) |
| Assignment | assign to `tickets.assign` holder accepted (single and bulk) | 2 |
| Assignment | assign to role-less/customer user rejected with `assignee_id` error, assignee unchanged (single and bulk, no Ticket in the batch mutated) | 2 |
| Assignment | forged non-existent id rejected, no mutation | 2 |
| Assignment | unassign still allowed | 0 (char.) |
| Assignment | assignee who lost `tickets.assign` receives no reply notification; owner still does | 2 |
| Bulk | `action=status` without `status` → validation error, no 500, no mutation | 2 |
| Bulk | `action=status` with invalid status → validation error | 2 |
| Bulk | `action=assign` without/empty `assignee_id` → validation error, assignees unchanged | 2 |
| Bulk | invalid `action` rejected | 0 (char.) |
| Bulk | route remains 403 without `tickets.assign` | 0 (char.) |
| Single assign | absent `assignee_id` → validation error, no 500 | 2 |
| Ticket number | service create after a Ticket already holds the number `MAX(id)+1` would produce still succeeds with a unique number (deterministic stand-in for the race) | 2 |
| Ticket number | format `TKT-\d{4,}` preserved | 0 (char.) |
| Export | title `=HYPERLINK(...)`, `+1`, `-1`, `@x`, `  =x` exported with `'` prefix | 2 |
| Export | quotes and backslashes round-trip under RFC-4180 (`escape: ''`) | 2 |
| Export | equal `created_at` rows ordered by `id DESC` | 2 |
| Export | Time export output unchanged after helper extraction | 2 |

### Tests encoding obsolete behavior (replace, do not delete coverage)

- `TicketOperatorQueueTest` "allows operator to assign a ticket" and "bulk-assigns tickets to an operator" use a role-less assignee; the assignee becomes a `tickets.assign` holder, and the role-less case moves to a rejection test.
- `TicketStatusWorkflowTest` "returns 500 or validation error for invalid status via HTTP" already asserts a validation error; rename only if touched.

## 17. Data and migration considerations

- **No schema migration.** Every fix is policy, query, validation, or service logic. The temporary-number approach (§15) fits the existing `ticket_number` column.
- **No backfill** of `company_id` (D3) and no data rewrite of assignees (§13).
- **Read-only production preflight:** the exact procedure, SQL, gates and development results are in [Amendment 1 → A1.10](#a110-production-preflight-procedure). The checks are: company-linked tickets (H4 reachability); assignees without `tickets.assign` (stale assignees the notification gate will silence); the H8 non-collision invariant over numeric-form numbers, which must be `0`; internal-note attachments (H2 exposure) and the attachment/reply ticket-mismatch integrity count.
- Deploy is code-only and reversible by reverting the commits.

## 18. Work packages

Three packages. WP1 and WP2 are separable: WP1 changes who may read/write; WP2 changes what valid writes look like. WP1 lands first because it contains the critical and high findings and rewrites the policy seam WP2's notification gate uses.

### WP0 — Characterization and preflight — **Complete (2026-09-24)**

- Add missing characterization tests (§16), green on current code.
- Record the §17 preflight queries in this document.
- No application change.

Results: [Amendment 1](#amendment-1-wp0-results-2026-09-24).

### WP1 — Authorization and confidentiality (H1, H2, H3, H4, H9) — **Complete (2026-09-24)**

Results: [Amendment 2](#amendment-2-wp1-results-2026-09-24).

- `TicketPolicy`: `view` (capability-based), `reply`, `viewInternal`.
- Attachment policy (§11); controller uses it.
- Reply controller authorizes first (§10).
- Customer index: owner-only query; search with `includeInternal` (§12).
- Show page uses `viewInternal` for reply filtering; operator actions add `authorize('view')`.
- Target tests red first, then green.

### WP2 — Integrity and robustness (H5, H6, H7, H8)

- Assignment eligibility seam and assignee notification gate (§13).
- Bulk and single-assign validation; transactional bulk loop; minimal error block (§14).
- Collision-free Ticket numbers; factory number range (§15).
- Safe CSV export with shared helper (§15).
- Replace obsolete assignment tests.

Suggested branch: `hardening/epic-10d-helpdesk-security` (matching the EPIC-010C naming).

## 19. Exit criteria

- H1–H9 each fixed with the §16 tests, including side-effect-absence assertions for every refusal.
- `TicketPolicy` contains no role-name check; operator Ticket visibility is `tickets.assign` everywhere.
- Customer list universe == customer detail universe; no customer list row can 403.
- Internal-note content is unreachable to non-operators through show, download, and search.
- No schema migration, no backfill, no new dependency, no UI change beyond §14's error block.
- Full Pest suite green on MariaDB; `./dev check` passes; Pint clean.
- §17 preflight results recorded.
- Roadmap item [Critical Helpdesk hardening](../product/product-roadmap.md#critical-helpdesk-hardening) and the [epic index](./README.md) updated to the final status.

## 20. Deferred Helpdesk work

Carried to the [Helpdesk MVP](../product/product-roadmap.md#later--helpdesk-mvp) or named platform work, not EPIC-010D:

- Organization-level Ticket visibility for customers (D1), on the Directory model, possibly via `tickets.view_org` or a relationship policy
- Persisting organization context on new Tickets (F-1, D3)
- Reply-after-close semantics (F-2)
- Notification content format and preferences (F-3)
- Search semantics and wildcard escaping (F-4)
- Tenancy-scope role-name checks (F-5, Directory/tenancy)
- Report date-range correctness and validation (F-6)
- Attachment type policy and transactional uploads (F-7)
- Surfacing stale (ineligible) assignees and skipped bulk rows in the operator UI (§13, §14)
- Ticket-derived tasks (D5)
- React migration of all Ticket surfaces (EPIC-011 Phase F within Helpdesk MVP)
