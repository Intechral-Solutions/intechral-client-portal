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
| `user@intechral.test` | user (minimal) | Reusable persona; `persona: 'member'` | Authorization coverage depends on the minimal-permission actor; kept distinct from operator so capability-filtering specs mean something |
| `e2e-login-flow@intechral.test` | user | `auth-migration.spec.ts` (invalid credentials, valid login, logout, password confirmation) | The test's subject is the login form itself, not a permission; using the reusable operator email would add this file's login churn to the same Fortify bucket every worker's own operator login already shares |
| `e2e-profile-mutation@intechral.test` | user | `inertia-coexistence.spec.ts` (account-mutation test) | Edits its own name/email; a failure part-way must never leave the *shared* operator account looking like a different person for every later run |
| `e2e-signout@intechral.test` | operator (all permissions) | `shell.spec.ts` (sign-out test) | Sessions are database-backed, so signing out destroys the session row presented; the test's assertion (no admin item leaks into a fully-permissioned account's menu) holds for any fully-permissioned account, not specifically the shared one |
| dynamically created manager (`e2e-wp3-manager-<timestamp>@intechral.test`) | user + `projects.manage` | `projects-migration.spec.ts` (membership test) | Created by the test itself to prove a *specific* non-admin manager's read-only membership view; a fresh, explicitly anonymous context (`contextFor('anonymous')`) is required so it doesn't inherit the project's authenticated `storageState` |

The three `e2e-*` fixtures are seeded by `database/seeders/DevSeeder.php`, guarded (like the
operator/user accounts) to `local`/`testing` environments only, and never a production bootstrap
requirement.

## Login budget

Fortify's limiter key is email + IP, 5 requests/minute. With `workers: 3`:

| Identity / category | Max `POST /login` per normal run | Why |
|---|---:|---|
| `operator@intechral.test` (fixture) | ≤ 3 | One worker mints it at most once; at most 3 workers exist |
| `user@intechral.test` (fixture) | ≤ 3 | Same reasoning |
| `e2e-login-flow@intechral.test` | ≤ 3 | One file, one worker, sequential tests: 1 (invalid) + 1 (valid) + 1 (password confirmation) |
| `e2e-profile-mutation@intechral.test` | 1 | One test, one login |
| `e2e-signout@intechral.test` | 1 | One test, one login |
| dynamically created manager | 1 per run, unique email every time | Never shares a bucket with any other run or test |

Every bucket stays at least 2 attempts below the 5/minute ceiling, without depending on exact
scheduling within the run.

Adding a spec file that only uses the `operator`/`member` personas does not change this table: a
worker mints each persona at most once however many files it runs, so the per-persona bound stays the
worker cap. `blade-shell.spec.ts` (EPIC-013 WP5) is such a file — it adds ten flows and no login. Its
sign-out coverage rides on `shell.spec.ts`'s existing single `e2e-signout` login, which now signs out
from the Blade account menu.

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
