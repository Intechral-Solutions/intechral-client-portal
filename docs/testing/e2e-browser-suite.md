# E2E browser suite: authentication architecture

Living reference for `tests/Browser`'s authentication design. This is POST-WP4 E2E hardening, done
after EPIC-013 WP4 was committed at `27f151a` — see EPIC-013 Amendment 9 for the history and the
evidence behind each decision. This document describes the current architecture and why it looks
the way it does; update it in place as the suite evolves, rather than treating it as a historical
record.

## Why sessions are minted per worker

Playwright runs one worker process per spec file by default (`fullyParallel: false`), and every
worker's requests hit the same Laravel install, with sessions stored in the database
(`SESSION_DRIVER=database`). Two designs were tried before this one:

1. **One login per test.** The original suite. 64 real logins a run drove Fortify's 5-per-minute
   (per email+IP) limiter into throttling most of the run.
2. **One login per persona per run**, shared by every worker (a Playwright `setup` project). This
   fixed the login volume, but every worker then read and wrote the *same* database-backed session
   row: one worker's flash message or validation-error bag could appear in another worker's page,
   because Laravel flash data survives exactly one subsequent request and workers interleave their
   requests against the one row.

The current design mints a session **per worker, per persona**, in `tests/Browser/support/auth.ts`.
A worker-scoped `sessions` fixture signs a persona in through the real login form the first time one
of that worker's tests needs it, keeps the resulting cookies in memory (never on disk), and reuses
them for every later test the worker runs — however many spec files that turns out to be, since the
fixture memoizes per persona for the worker's whole lifetime.

## Why the worker count is capped explicitly

Minting per worker fixed the shared-row problem, but reopened the limiter problem one level up: with
enough spec files defaulting to a persona, and Playwright's default worker count tied to machine core
count, ordinary parallel execution alone could mint enough real logins for one persona inside a single
Fortify window to collide with it — before any authentication-subject test even ran. A worker mints a
given persona's session **at most once for its entire lifetime**, so capping the worker count bounds
that persona's total real-login count for the whole run to at most the cap, regardless of how many
spec files need that persona or how many cores the machine has. `playwright.config.ts` sets
`workers: 3` for exactly this reason — explicit and deliberately modest, not left to default.

## Identities

| Identity | Role | Used by | Why this identity |
|---|---|---|---|
| `operator@intechral.test` | operator (all permissions) | Reusable persona; default for most feature specs | Authorization coverage depends on the full-permission actor |
| `user@intechral.test` | user (minimal) | Reusable persona; `persona: 'member'` | Authorization coverage depends on the minimal-permission actor; kept distinct from operator so capability-filtering specs mean something. Since EPIC-017 WP1 this actor is in the **Focused** customer shell (top bar; no rail, drawer, breadcrumb or global timer) |
| `e2e-login-flow@intechral.test` | user | `auth-migration.spec.ts` (invalid credentials, valid login, logout, password confirmation) | The test's subject is the login form itself, not a permission; using the reusable operator email would add this file's login churn to the same Fortify bucket every worker's own operator login already shares |
| `e2e-profile-mutation@intechral.test` | user | `inertia-coexistence.spec.ts` (account-mutation test) | Edits its own name/email; a failure part-way must never leave the *shared* operator account looking like a different person for every later run |
| `e2e-signout@intechral.test` | operator (all permissions) | `shell.spec.ts` (sign-out test) | Sessions are database-backed, so signing out destroys the session row presented; the test's assertion (no admin item leaks into a fully-permissioned account's menu) holds for any fully-permissioned account, not specifically the shared one |
| `e2e-customer@intechral.test` | user (minimal) | `customer-shell.spec.ts`; `persona: 'customer'` | The customer-shell journeys (Focused frame in both renderers, the sheet, the account menu, `/time` Start → Stop). Owns its own session and its own active-timer set, so it never races a shared persona. Owns the seeded `TKT-E2E2` request that is waiting on it |
| `e2e-customer-other@intechral.test` | user (minimal) | none in EPIC-017 WP1; `persona: 'customerOther'` | A second, unrelated customer, seeded for the Helpdesk isolation journey of the next package (it must be unable to see `e2e-customer`'s tickets). Costs no login until a spec uses it |
| `e2e-operational-member@intechral.test` | user + `projects.manage` (direct) | `shell.spec.ts`, `blade-shell.spec.ts`, `time-migration.spec.ts`, `tasks-migration.spec.ts`; `persona: 'operationalMember'` | A customer-scoped actor that still gets the **Operational** shell (`projects.manage` is an operator-surface capability), for specs whose subject is the rail, drawer, breadcrumb or timer pill and tray, which a plain customer no longer sees. It is the single owner of the member active-timer set that `time-migration.spec.ts` documents |
| dynamically created manager (`e2e-wp3-manager-<timestamp>@intechral.test`) | user + `projects.manage` | `projects-migration.spec.ts` (membership test) | Created by the test itself to prove a *specific* non-admin manager's read-only membership view; a fresh, explicitly anonymous context (`contextFor('anonymous')`) is required so it doesn't inherit the project's authenticated `storageState` |

The six `e2e-*` fixtures are seeded by `database/seeders/DevSeeder.php`, guarded (like the
operator/user accounts) to `local`/`testing` environments only, and never a production bootstrap
requirement. Re-seed the development database after pulling a change that adds one
(`docker compose exec app php artisan db:seed --class=DevSeeder` is idempotent: every account is
`firstOrCreate` by email); a hosted run seeds from scratch.

**Which shell an identity sees** is decided by capability, never by name: any operator-surface
capability (`ShellPresentationResolver::OPERATIONAL`) selects the Operational shell, anything else
the Focused customer shell. A spec that asserts Operational chrome must therefore use `operator` or
`operationalMember`, not a plain customer.

## Login budget

Fortify's limiter key is email + IP, 5 requests/minute. With `workers: 3`:

| Identity / category | Max `POST /login` per normal run | Why |
|---|---:|---|
| `operator@intechral.test` (fixture) | ≤ 3 | One worker mints it at most once; at most 3 workers exist |
| `user@intechral.test` (fixture) | ≤ 3 | Same reasoning |
| `e2e-login-flow@intechral.test` | ≤ 3 | One file, one worker, sequential tests: 1 (invalid) + 1 (valid) + 1 (password confirmation) |
| `e2e-profile-mutation@intechral.test` | 1 | One test, one login |
| `e2e-signout@intechral.test` | 1 | One test, one login |
| `e2e-customer@intechral.test` | ≤ 3 | A worker mints it at most once; one spec file uses it |
| `e2e-customer-other@intechral.test` | 0 | Seeded, not yet used by any spec |
| `e2e-operational-member@intechral.test` | ≤ 3 | A worker mints it at most once; at most 3 workers exist |
| dynamically created manager | 1 per run, unique email every time | Never shares a bucket with any other run or test |

Every bucket stays at least 2 attempts below the 5/minute ceiling, without depending on exact
scheduling within the run.

Adding a spec file that only uses the `operator`/`member` personas does not change this table: a
worker mints each persona at most once however many files it runs, so the per-persona bound stays the
worker cap. `blade-shell.spec.ts` (EPIC-013 WP5) is such a file — it adds ten flows and no login. Its
sign-out coverage rides on `shell.spec.ts`'s existing single `e2e-signout` login, which now signs out
from the Blade account menu.

## Shared records: never select by position

Workers share one database, so a list or `<select>` another spec's fixture can enter must be addressed by a
stable identity, never by `index`, `nth()` or sort order. The Finance invoice tests once chose the client with
`selectOption({ index: 1 })`. `projects-migration.spec.ts` temporarily creates a user named `</select>...`, which
sorts before every seeded name, so on overlapping workers index 1 was that user. Its teardown deleted the user, and
`invoices.client_id` cascades, so the Finance test's invoice vanished mid-test and the invoice-list assertion failed
(two hosted runs on docs-only commits, 2026-10-07). The tests now select the seeded operator by label
(`chooseInvoiceClient` in `blade-theme-controls.spec.ts`), and the draft-invoice test asserts the invoice is billed to
that client.

## E2E ticket cleanup

Tickets have no delete route, so the suite's tickets are removed by a local/testing-only command
(EPIC-017 §25.3, WP2 G3-A): `php artisan e2e:cleanup-tickets`. It takes **no argument and no option**.

**What it deletes.** A ticket is eligible only when **both** hold: its title begins with the literal,
case-sensitive prefix `E2E-`, **and** its requester is one of the seeded E2E customers,
`e2e-customer@intechral.test` or `e2e-customer-other@intechral.test` (`DevSeeder`). Anything else is left alone:
an ordinary ticket, an `e2e-…` in lower case, an `E2E-…` owned by the operator or any other account, and the seeded
fixtures `TKT-E2E1` and `TKT-E2E2` (titled `E2E fixture: …`, so never eligible, whoever owns them). Replies,
attachment rows and status histories go with the ticket through their existing `cascadeOnDelete` keys, and the
ticket's private files go with it. Both conditions are checked again on the locked row just before it is removed
(below), so a ticket renamed or reassigned after the run began is left alone.

**Where it runs: the environment contract.** `local` and `testing` only, and it checks **two** values, both of which
must be one of them: the runtime environment (`app()->environment()`) **and** the configured one (`config('app.env')`,
from `APP_ENV`). Laravel's global `--env=local` option overrides only the runtime value, so a command-line selector
alone cannot authorise a run: `APP_ENV=production php artisan e2e:cleanup-tickets --env=local` is refused. Any
other environment, `production` included, is refused with a non-zero exit **before** the command reads or changes
anything. `CleanupE2eTicketsTest` proves the `--env` case in a real child process pointed at an unreachable database,
so "refused" also means "before any query".

**Linked time or tasks are refused, never detached.** `time_entries.ticket_id` and `tasks.ticket_id` are
`nullOnDelete`; deleting a ticket would silently detach that record. Two layers keep that from happening:

1. *Up front, for the whole run.* If **any** eligible ticket has a linked time entry or task, the command deletes
   **nothing at all**, names the ticket number and the counts, and exits non-zero. It does not delete, re-point or
   null those records; they belong to a person. This is a plain read, so it is a courtesy (a known blocker never
   produces a half-done cleanup), **not** the safety mechanism.
2. *Per ticket, in one transaction, under a row lock.* This is the mechanism. For each ticket the command opens a
   transaction and takes `SELECT … FOR UPDATE` on the ticket row. InnoDB takes a shared lock on the **parent** row
   whenever a child row is inserted or re-pointed at it, so while that lock is held no time entry or task can be
   linked to the ticket: the attempt waits. Under the lock it re-checks the title and owner, reads the linked rows with
   locking reads (`select id … lock in share mode`, rows rather than an aggregate, and a *current* read rather than a
   snapshot), refuses if any exist, removes the attachment directory, deletes the row and commits. The lock is held
   through the file removal, so nothing can be linked while the files are going. After the ticket is gone, the foreign
   key itself rejects a late attempt (error 1452) instead of the delete nulling it.
   `TicketService::addReply` also locks the ticket row before it writes an attachment, so an upload cannot interleave.

`CleanupE2eTicketsConcurrencyTest` proves it with a **second database connection** and the real command, at two exact
moments, and asserts the persisted state rather than query order: a link **committed before** the lock makes the command
refuse and leaves the link intact; a link **attempted after** the lock is held is stopped by the lock wait (1205) and,
once the ticket is gone, by the foreign key (1452) — a new time entry, a standalone time entry re-pointed at the ticket,
and a standalone task re-pointed at it — and nothing is ever attached or nulled. `CleanupE2eTicketsTest` also asserts the
SQL shape (a row lock on `tickets`, then locking reads of both child tables, then the delete).

Remaining limits, stated plainly: the database lock cannot make the **filesystem** transactional (see the table); a
conflicting lock held by someone else waits up to `innodb_lock_wait_timeout` (50 s by default) and then that ticket is
reported as a failure; and a deadlock with a concurrent time-entry write is possible in principle, in which case InnoDB
aborts one side and the ticket is reported as a failure and kept. None of these detaches anything.

**Files first, then the row.** For each ticket the command removes its `tickets/{id}/` directory on the private
`local` disk (the path comes from the integer id, never from a title or a stored path), confirms the directory is
gone, and only then deletes the row, all inside the transaction above.

**Directory state is proven, not guessed.** PHP's `is_dir` and `file_exists` return false both for "not there" and for
"not allowed to look", so they are not used to prove absence. The command lists the storage root, then `tickets/`, then
`tickets/{id}`. A directory is **absent** only when its parent listed fine and does not contain it. If any of those
listings fails, the state is **unknown**, the ticket is kept, and the run exits non-zero. If any candidate's state is
unknown, the whole run is refused up front (a permission mismatch is systemic) and nothing is deleted.

| Situation | Result |
|---|---|
| Directory exists with files | files removed, directory confirmed gone, row deleted; counted, exit 0 |
| Directory exists and is empty | removed, row deleted; counted, exit 0 |
| Directory provably absent (parent listed, entry not in it) | safe absence: the row is deleted |
| `tickets/` or `tickets/{id}` exists but cannot be listed (a `0700` directory owned by another user) | **unknown**: nothing deleted, row kept, exit 1; the output says to run as the web-server user |
| Storage root cannot be listed, or `tickets/{id}` is not a plain directory | unknown: row kept, exit 1 |
| Directory cannot be emptied (no write permission), or the storage call fails or returns `false` | row **kept**, ticket reported, exit 1; the next run retries it |
| Partially removed (some files gone, then a failure) | row kept, exit 1; the next run removes what is left |
| Directory is still there although the delete reported success | row kept, exit 1 |
| Row delete fails after the files went | reported, exit 1; the transaction rolls back, the next run finds a ticket with no files and deletes it |
| The ticket has attachment records but the storage has no `tickets/` directory at all | failure, row kept (a different storage root than the database's) |
| Ticket already gone when the lock is taken | nothing to do; not counted as deleted |
| Second run | `Eligible E2E tickets: 0`, exit 0 |

The previous design inferred absence from the **root** being invisible; that missed the real case (`storage/app/private`
is `0777` and `tickets/` is `0700 portal`, so another user sees `tickets/` but not `tickets/{id}`), and a run under the
wrong user deleted the rows, orphaned the files and exited 0. The regression tests use real `chmod` on a disposable
disk, and the same scenario was re-run as a different UID against the real command: it now refuses and exits 1. Another
user's access cannot be simulated without root, so the Pest tests remove the permissions from the current user (also
true to what a non-owner sees); they are skipped when run as root.

Atomicity the design does **not** have: a file deletion and a database commit cannot be made one operation. A crash
(`SIGKILL`) after the files went and before the commit leaves a ticket with no files, which the next run deletes; the
files of an E2E ticket are test data and are not recoverable once removed. Nothing here can lose the row while its
files remain.

The report gives counts and ticket **numbers** only: eligible, deleted, files and directories removed, and each
failure. It prints no titles, file names or contents, and error text from the storage layer has the private storage
path replaced with `<storage>`.

**Lifecycle.** Cleanup is **suite-level**; it never runs per test or per worker, because every worker's tickets
share the `E2E-` prefix and a run mid-suite would delete tickets other workers are still using.

1. `./dev test:e2e` takes a host lock, probes the app container for a running `playwright test` process, and
   refuses to start if either shows another suite.
2. It runs a **recovery sweep** (the command, as the web-server user `portal`, which owns the attachment files)
   before the baseline is read. A failed sweep stops the run before Playwright starts.
3. It reads the baseline: the counts (`projects`, `tasks`, `time_entries`, `tickets`) and the number of entries under
   `storage/app/private/tickets`, so the baseline holds only the non-E2E state and the seeded fixtures. The directory
   count is read as `portal` (the directory is `0700`), and an unreadable directory **stops the run**; it is never
   read as zero.
4. Playwright runs, as the host user, with `E2E_TICKET_CLEANUP_OWNER=dev` in its environment. Its **`globalTeardown`**
   (`tests/Browser/support/global-teardown.ts`) sees that and **does not delete anything**; it only reports that the
   cleanup is handed over.
5. After Playwright has exited, the wrapper runs the command **as `portal`** and reads the counts and the directory
   count again.
6. Reconciliation: `tickets` must equal its baseline **and** the attachment directory count must equal its baseline. A
   mismatch of either **fails** the run and nothing is deleted to make it pass; `projects`, `tasks` and `time_entries`
   keep their warning-only comparison. The directory count is there because a missing ticket row is no proof that its
   private files are gone. It is not an orphan sweeper: it deletes nothing and does not look inside the directories.

**Who runs the cleanup, and why.** The private files are `0600` in `0700` directories owned by `portal` (the user
php-fpm runs as). `./dev test:e2e` runs Playwright as the host user (`HOST_UID:HOST_GID`), which is `portal`'s UID on
the maintainers' machines but is the runner's own UID on a hosted runner, and a non-root process cannot become
`portal` from inside the container. So `globalTeardown` runs under an identity that may not see the files, and the only
place a `portal` invocation is available is the wrapper's `docker compose exec -u portal`. Hence the hand-off. The
files' permissions are not relaxed, and the hosted runner's UID is not assumed.

**A suite started by hand** (`npm run test:e2e` from `./dev shell` or elsewhere) has no wrapper, so there is no
hand-off variable and **`globalTeardown` is responsible**: it runs the command as the current user, after every
worker. Where that user cannot see the files the command refuses (see the table), the teardown throws, and the run
**fails**; it never reports a cleanup that did not happen. That run gets no sweep, no lock, no reconciliation and no
directory count; run `./dev test:e2e` afterwards to clean up as `portal`.

The wrapper reports four results separately and never lets one hide another: `Playwright exit`, `ticket cleanup
exit`, `ticket reconciliation` and `attachment directories`. The exit status is Playwright's own when it failed,
otherwise 1 if the cleanup or either reconciliation failed.

**Interruption.** Teardown runs after a failed test or fixture, a worker that exits abruptly and Ctrl-C (SIGINT);
each was probed against a disposable Playwright configuration with three workers. It does
**not** run after `SIGKILL`, an out-of-memory kill or a lost container, and nothing here claims it does. Such a run
may leave `E2E-` tickets until the next `./dev test:e2e` recovery sweep.

Under the wrapper the teardown does nothing; the wrapper's post-run cleanup is what removes the tickets, so what
happens depends on whether **the wrapper** survives:

| Situation | Wrapper cleanup |
|---|---|
| Playwright fails (failed tests, a fixture error, a non-zero exit) | runs, as long as the wrapper is alive |
| A Playwright worker fails or exits abruptly, and the wrapper is alive | runs after Playwright exits |
| The wrapper itself is terminated (closed terminal, signal, `SIGKILL`), or the host or container is lost | **not guaranteed**: the cleanup step may never run, and whether Playwright stops with the wrapper has not been probed |

After the last row, eligible leftovers (tickets and their files) stay until the **next** `./dev test:e2e`, whose
recovery sweep removes them before it takes its baseline. That sweep runs only after the single-suite checks pass, so
it will not start while a suite is still running in the container. A direct `npm run test:e2e` has no sweep at all;
run `./dev test:e2e` afterwards.

**One suite per database.** Two suites against one database can delete each other's tickets (the command is
untargeted by design). The host lock and the container probe stop the common cases; they cannot see a suite on another
machine or one started after the check, and the lock directory has two small windows of its own (between creating it
and writing the owner's PID, and when two runs clear the same stale lock), so this is a supported constraint, not a
guarantee. A ticket created by hand during a run shows up as a reconciliation mismatch.

**Queued notifications.** Creating a ticket and replying both dispatch queued mail (`ShouldQueue`, Redis, a
`queue:work` worker in the dev stack). A job that runs **after** its ticket was deleted used to fail with a
`ModelNotFoundException` as the worker unpacked it: that error is raised immediately, before the notification runs, and
the job is failed on the spot (no retry) into `failed_jobs`. `TicketCreatedNotification` and
`TicketRepliedNotification` now carry `#[DeleteWhenMissingModels]` (Laravel 13.17, the installed version, honours it for
queued notifications; verified, not assumed), so that one case, a missing ticket or reply, ends as a successful discard
with no `failed_jobs` row. This is a production notification lifecycle change, limited to those two classes: it changes
no queue worker, queue connection, recipient or message content. It is **owner-ratified 2026-10-09** (EPIC-017 §37).
Nothing else changes. Any failure while the notification is being sent, a
mail transport error included, still fails and is retried as before, and an existing ticket still sends. A deleted
*notifiable* (the recipient user) is not affected by the attribute: the notifiable is queued as a collection, which
restores empty, so that job already did nothing without it. The cleanup itself never touches Redis
or `failed_jobs`, and nothing in the E2E tooling consumes a worker's queue. The hosted CI job does not start the queue
worker, so nothing runs there. `TicketNotificationMissingModelTest` proves all of this with the real database queue and
`queue:work --once` (a sync job cannot show whether `failed_jobs` was written), including a control that fails without
the attribute.

## Guards against regression

- `tests/Browser/session-isolation.spec.ts` — behavioral: an explicitly anonymous context really
  renders the login form; reusable session state is cookies-only (no `localStorage` origins); the
  operator and member personas remain distinct actors.
- The `sessions` fixture's `claimSession` check — a worker that mints a session records its CSRF
  token's hash in a shared temp directory; a second worker minting the *same* session (the original
  shared-session defect, reintroduced) fails immediately with a clear error, rather than producing an
  intermittent flash-message failure much later.
- `tests/Unit/Configuration/BrowserAuthContractTest.php` — static PHP assertions for properties that
  can't be observed by running one spec (e.g. "no ordinary feature spec authenticates" or "the worker
  cap stays under the limiter ceiling"); see that file's own docblock for the full reasoning.

## Validated result

Full suite, normal parallel configuration (`workers: 3`, no flag override):

- POST-WP4 hardening (EPIC-013 Amendment 9): 64/64 passed, ~3.3 minutes, 12 `POST /login` (all 302),
  0 `429`, 0 `419`, 0 application `5xx`.
- After EPIC-013 WP5 (Amendment 10), with `blade-shell.spec.ts` added: 74/74 passed, 3 workers,
  2.0 minutes, 12 `POST /login` (all 302), 0 `429`, 0 `419`, 0 application `5xx`.
- After EPIC-013 WP6 (Amendment 11), with the timer pill/tray flows added: 81/81 passed, 3 workers,
  2.8 minutes, 12 `POST /login` (all 302), 0 `429`, 0 `419`, 0 application `5xx`. WP6 added seven
  tests and no logins: its flows joined `time-migration.spec.ts` rather than taking a spec file — and
  a persona — of their own. An eighth (the Blade pill at S) was added during WP6's audit follow-up,
  taking the suite to **82**; it was verified on its own and the full suite has not been re-run for
  it, so the 81/81 figure above stands as the last full-suite measurement rather than being restated.

  **Timers are global per user, so one spec file owns the `member` persona's active-timer set.**
  Spec files run concurrently across the three workers, so a second `member` spec that started or
  stopped timers would race `time-migration.spec.ts`: an exact-count assertion would see the other
  file's timer, and a stop-everything helper would stop it mid-test. Tests within a file run serially,
  which is why that file holds them all. A new spec that starts timers as `member` belongs in it, or
  must use a different actor.

  **The `operator` persona has no such owner, and predates WP6.** `board-migration`,
  `projects-migration`, `tasks-migration` and `task-detail-migration` all default to `operator` and
  start and stop timers as their "did this navigate over Inertia?" signal, while the `as an operator`
  group in `time-migration.spec.ts` both stops everything and asserts exact counts. Those files can
  overlap across workers. It is pre-existing debt, not a WP6 regression — the pre-WP6 code did the
  same — and it is recorded rather than redesigned. Do not assume operator timer state is isolated
  because member state is: new exact-count or stop-everything assertions for `operator` need either a
  single owning file or an assertion that does not depend on the actor's global timer set.
- After EPIC-013 WP7 (Amendment 12), with `home.spec.ts` and the board's canvas flow added: **89/89
  passed, 3 workers, 4.5 minutes**, 12 `POST /login` (all 302), 0 `429`, 0 `419`, 0 application `5xx`.
  WP7 added seven tests and no logins — `home.spec.ts` uses the default `operator` persona, whose
  session each worker has already minted.

  **Do not run the full browser suite and the full Vitest suite at the same time.** Measured: run
  concurrently, Playwright took 9.8 minutes instead of 4.5 and reported 16 failures, and Vitest
  reported 9; run one at a time, both are green. The browser suite drives a real Chromium against the
  development stack while Vitest creates a jsdom environment per file (368s of tracked time alone,
  969s under contention), and the two starve each other. Failures observed while both are running are
  evidence of nothing until reproduced alone.

  `home.spec.ts` holds no shared-resource ownership: Home is read-only, creates no fixture and starts
  no timer. The board's page-frame flow lives in `board-migration.spec.ts`, which already owns project
  fixtures and their cleanup.
- After EPIC-013 WP8 (Amendment 13), with three shell flows added to `shell.spec.ts`: **92/92 passed,
  3 workers, 4.5 minutes**, 12 `POST /login` (all 302), 0 `429`, 0 `419`, 0 application `5xx`. WP8 added
  three tests and no logins: all three use the default `operator` persona and create no fixture and no
  timer. This is the authoritative full-suite figure at the close of EPIC-013 (A12.14's 89/89 predated
  WP7's remediation).
- At the EPIC-014 WP7 closeout (EPIC-014 Amendment 6): **114/114 passed, 3 workers, 3.6 minutes**,
  then 114/114 again in 3.9 minutes as a second, separate invocation; each run 12 `POST /login` (all
  302), 0 `429`, 0 `419`, 0 application `5xx`, and the tracked product-data counts unchanged (0/0/0
  before and after both runs). `e2e-cleanup.spec.ts` (new in WP7) uses the default `operator` persona
  and adds no login; one of its three tests is an intentional `test.fail()` that proves the cleanup
  fixture's teardown removes a registered standalone task.
- During EPIC-015 (WP4 onward) the owner ruled that **hosted PR CI is the authoritative complete-browser
  gate**: on the shared local Docker VM, complete local runs repeatedly failed broadly on timeouts and
  `429` cascades in specs unrelated to the change under test, with no product assertion failing. Local
  browser runs are now targeted (the specs a change touches); a red local complete run is recorded as
  red, not re-run until green. Hosted figures at the WP5 merge: **141/141 passed, 3 workers, 7.7 minutes**
  (PR #20, head `451c74d`) and **141/141, 3 workers, 4.6 minutes** (PR #21, head `c21fa12`), each with
  the tracked product-data counts 0/0/0 before and after ([EPIC-015 A5.14, A5.15, A5.16](../epics/EPIC-015-projects-ux-expansion.md#amendment-5-wp5-optional-project-workspace-enhancements)).
