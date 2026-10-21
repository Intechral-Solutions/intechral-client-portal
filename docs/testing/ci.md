# CI baseline

Living reference for `.github/workflows/ci.yml`, the *Lightweight CI baseline* from the
[Product Roadmap](../product/product-roadmap.md#lightweight-ci-baseline). Its scope was fixed there
and in the EPIC-013 handoff ([§30](../epics/EPIC-013-direction-d-shell-design-system.md#30-ci-handoff),
[A13.18](../epics/EPIC-013-direction-d-shell-design-system.md#a1318-ci-handoff-30-as-the-epic-closes)):
clean checkout and environment, full Pest against MariaDB, the frontend chain, the Playwright suite,
and a pass/fail signal on the PR. `./dev check` defines the checks; CI proves they pass from a clean
checkout. Build matrices, deployment, release promotion and production orchestration are out of scope.

## When it runs

- Every pull request targeting `main` (each push to the PR re-runs it; a newer push cancels the
  older run).
- Every push to `main` (never cancelled).

No other branches, no schedule, no manual trigger. Nothing is deployed, published, tagged or written
back: the workflow has `contents: read` and nothing else.

The check is **visible, not enforced**: branch protection and required checks are not available on
the repository's current GitHub plan, so a red run does not block a merge by itself. Treat it as
blocking by convention until required checks can be configured.

## What runs

Three jobs (`checks`, `browser` and the required-mode `browser-pilot`), in parallel, on separate runners pinned to `ubuntu-24.04` (not `ubuntu-latest`, so the
OS and its tooling — Docker/Compose, ShellCheck — do not change underneath the baseline).

### `checks` — the `./dev check` gates

The same five gates, in the same order, as `./dev check`. Like `./dev check`, a failing gate does not
stop the later ones, so a run reports every failure at once.

| CI gate | CI command (in `src/` unless noted) | Local equivalent |
|---|---|---|
| CLI self-tests | `bash scripts/dev/tests/run.sh` (repo root) | same command, or `./dev check` |
| `git diff --check` | `git diff --check HEAD^1 HEAD` (repo root) | `./dev check` (checks uncommitted changes); for a branch, `git diff --check main...HEAD` |
| Pint | `./vendor/bin/pint --test` | `./dev check`, or `./dev shell` then the same command |
| Frontend | `npm run check` — Wayfinder generation, `tsc`, ESLint, Prettier check, Vitest, production build | `./dev check --no-php`, or `./dev shell` then `npm run check` |
| Pest | `./vendor/bin/pest` against `intechral_client_portal_testing` | `./dev test:php` |

The job runs on the runner itself rather than in the dev image, because none of these gates serves
the app and building the image would only add minutes. The environment follows the dev stack:

- **PHP 8.5 and Node 22**, as in `.docker/php/Dockerfile`, with that image's extensions
  (`shivammathur/setup-php`, `actions/setup-node`). PHP 8.5 is the preferred baseline and 8.3 the tested fallback
  ([docker-setup.md](../architecture/docker-setup.md#php-version)); OPcache is built into 8.5, so it is not in the list.
- **MariaDB 11.4.13** (the production version) is the compose `db` service itself (`docker compose up -d --wait db`): the same image,
  `my.cnf`, `init-testing.sql` (which creates the testing database) and healthcheck as local. Pest
  never runs against SQLite: `tests/bootstrap.php` pins the MariaDB testing database and
  `TestDatabaseSafety` refuses anything else.
- **Host `db`.** `tests/bootstrap.php`, `phpunit.xml` and `.env.example` address the database as
  host `db`, as the app container does. CI adds `127.0.0.1 db` to the runner's `/etc/hosts` rather
  than changing them.
- **`src/.env`** is a copy of `.env.example` with a generated key, as `npm run setup` makes it. Pest
  does not read it (`APP_ENV=testing` uses `.env.testing` and `phpunit.xml`).
- **Redis (R0.9-1D integration).** The `checks` job starts the Compose `redis` service beside `db` and installs the PHP
  `redis` extension, because the permission-cache security tests (R0.9-1C M-1, R0.9-1D) need a shared store outside the
  database transaction and skip themselves when none is reachable. A dedicated step runs
  `tests/Feature/Admin/PermissionCacheCommitOrderTest.php` and `PermissionCacheEpochTest.php` (R0.9-1E: the cache-repopulation race
  with independent processes, its negative control, and the persistent-worker, killed-writer and failure cases) with
  `REDIS_HOST=127.0.0.1` and `--fail-on-skipped`, so a missing
  server, extension or address **fails** the job instead of quietly shrinking the count (a skip is not a pass). The whole
  suite then runs with Redis reachable too. Everything else uses the array cache, so no other gate touches Redis.
- **Database cache store (R0.9 WP2).** The approved Release 0.9 hosting profile caches in MariaDB (`CACHE_STORE=database`), where a cache
  write can share the writer's transaction. A second, mandatory step runs `tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php`
  (the same security invariant, the real `database` cache driver, independent PHP processes, an ordered event trace per scenario;
  see [Role Governance](../architecture/role-governance.md#permission-cache-security-on-the-database-store-r09-wp2)) with
  `--fail-on-skipped` and a JUnit report. Three things make it impossible to shrink quietly: the tests never skip for want of a server
  (an unavailable MariaDB errors the first test), `REQUIRE_MARIADB_VERSION=11.4.13` makes the first test fail unless the server is exactly
  the production version (the Compose `db` image is pinned to it), and `src/tests/Support/verify_database_cache_security.php` reads the report and
  fails unless every scenario in its manifest, dataset variants included, ran and passed as many times as listed. The gate's own failure
  modes (a missing scenario, a missing variant, a skip, a failure, an error, an empty report, tests from another file, a control standing in
  for the scenario it controls) are proven by `tests/Unit/Ci/DatabaseCacheSecurityGateTest.php`. The test file is in the Pest group
  `database-cache-security`; the whole-suite step runs `--exclude-group=database-cache-security`, so these scenarios (about 80 s) run once,
  not twice. A local `./dev check` or `./dev test:php` still runs them. Reproduce the CI step locally:
  `REQUIRE_MARIADB_VERSION=11.4.13 ./dev test:php tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php --fail-on-skipped --log-junit …`.
- **Provisioning check.** A step lists the databases the `portal` account can see and fails, naming the missing one, unless
  `intechral_client_portal_testing`, `..._rehearsal` and `..._rehearsal_restore` exist. A fresh runner builds a fresh
  volume, so `init-testing.sql` and `init-rehearsal.sql` must have run; a gap is reported as a provisioning failure, not as
  an unrelated test failure.
- **`git diff --check`** locally inspects uncommitted changes. A clean checkout has none, so CI
  checks what the change introduces: on a PR, `HEAD` is GitHub's merge commit and `HEAD^1` is `main`.
  (The tree as a whole is not whitespace-clean — some older docs and one Blade view — so a whole-tree
  check would fail for reasons unrelated to the change.)

### React design-system lint (`@shadcn/lint`)

The frontend gate's ESLint step also runs [`@shadcn/lint`](https://github.com/shadcn-ui/lint), adopted after EPIC-016 (the roadmap's
`@shadcn/lint` evaluation note). It checks React source for misuse of the Direction D system; it does not replace the behavioural,
token-value or browser tests, and it does not read Blade (that is `BladeThemeGuardTest`).

- **Version and upgrades.** Pinned exactly to `0.2.0` (no range): the package is young and its rules change between releases. An upgrade is a
  deliberate change that re-runs the lint and re-baselines. Installing or upgrading it never happens as a side effect of another change.
- **Integration.** The existing `npm run lint` (ESLint 10, flat config in `src/eslint.config.js`); no Oxlint, no second script, and no
  `--cache` (upstream warns that cached results go stale when a component or the theme changes). The theme and the `@/components/ui`
  primitives are discovered from `src/components.json`; `cn`, `clsx`, `cva` and `twMerge` are recognised by default.
- **Six rules, all `error`**, for `resources/js` without `*.test.*` and `test/`: `no-raw-colors`, `no-unknown-classes`, `no-restyle`,
  `no-arbitrary-values`, `no-inline-styles`, `require-static-classes`. In `components/ui/**` (the primitives style themselves) only
  `no-restyle`, `no-arbitrary-values` and `require-static-classes` are off. `no-restyle` allows layout and denies caller `h-*`, `min-h-*`,
  `max-h-*`, `size-*` (callers do not own typography or geometry); a contract exempts only the five bare Radix re-exports in
  `ui/dropdown-menu.tsx`. `no-inline-styles` allows `transform`, `transition` and `touchAction` in the two dnd-kit adapter files only.
  Composite components outside `ui/` (`page-frame`, `page-header`, `section`, `pagination`) are not governed yet.
- **Baseline.** Existing accepted or deferred findings live in `src/eslint-suppressions.json` (ESLint bulk suppressions, per file and rule
  with a count), so a **new** violation fails immediately and warnings are never used (`--max-warnings=0`). `no-raw-colors`,
  `no-unknown-classes` and `no-inline-styles` have no entries. The baseline is legacy React debt (the raw `var(--text-danger)` family,
  deferred by EPIC-016 §22), a few hand-styled Buttons, four unreadable class expressions (the imported `focusRing` constant and a row-class function) and a technical `transition-[…]` list. It
  only shrinks: fix a finding, then run ESLint with `--prune-suppressions` (a stale entry also fails lint, so the file cannot rot).
- **Baseline limitation.** ESLint bulk suppressions are counted per file and rule, not tied to individual lines. The baseline therefore
  stops a count from growing, and a count that shrinks is detected as stale. If one suppressed violation is removed and a different violation
  of the same rule is added in the same file, the count is unchanged and lint passes. The baseline does not replace code review: reviewers
  should still inspect changes in baselined files for one-for-one substitution.
- **Intentional exception.** The rail label is 10px by specification (Direction D §5.1); it carries one `eslint-disable-next-line` with that reason.

### `browser` — the Playwright suite

`./dev test:e2e` itself, on the dev stack from `docker-compose.yml`: the image is built, `app`,
`nginx`, `db` and `redis` are started (the queue worker and Mailpit are not; the suite does not use
them), then, inside the app container as the runner's UID:GID, `composer install`, `npm ci`,
`.env` from `.env.example`, `key:generate`, `migrate --seed` and `npm run build`. As locally, the suite
runs against the migrated and seeded **development** database (`APP_ENV=local`, so `DevSeeder`
provides the personas and the three `e2e-*` fixtures), through the real login form, with the image's
baked Chromium and the committed `workers: 3`. Fortify throttling is untouched.

It runs the whole `tests/Browser` suite. The roadmap asks for the "critical Playwright suite"; no
narrower subset is defined anywhere, and A13.18 hands CI the full suite (92 tests) and budgets for it.

**Why the dev stack and not `php artisan serve`.** A lighter job serving the app with PHP's built-in
server was tried first and rejected on evidence. The same 92 tests on the same tree gave 90 passed,
2 failed — the two "paints the remembered theme and panel without layout shift" tests
(`blade-shell.spec.ts`, `shell.spec.ts`), with shifts of 0.00136 and 9.6e-7 against an asserted 0. Both
failed identically on every run and with 4 or 16 PHP workers, and both pass through nginx. nginx
serves `build/` assets with `Cache-Control: public, immutable` and a one-year `expires`; the built-in
server sends no caching headers, so the probed page load re-fetches fonts and CSS instead of reusing
them. A CI server that differs from the stack in this way produces CI-only failures, so CI uses the
stack's own nginx and php-fpm.

The two jobs are separate so that the full Vitest and full Playwright suites never share a machine
(A13.18: run concurrently, both report false failures).

## Customer surface modes in CI (R0.9-CS1)

The customer-surface boundary has three modes ([Customer Pilot Surface Gate](../architecture/customer-pilot-surface.md)), and CI
keeps their proofs apart:

| Mode | Proven by | Configured by |
|---|---|---|
| legacy (the pre-boundary behaviour) | the `checks` job's whole Pest suite, and the `browser` job's full Playwright suite | `phpunit.xml` sets `PORTAL_CUSTOMER_SURFACE_MODE=legacy`; the dev stack's `docker-compose.yml` defaults it to `legacy` and the `browser` job states it. Honoured only because `APP_ENV` is `testing`/`local` |
| closed (the shipped default) | `tests/Feature/Pilot/*` in the `checks` job: each test sets the mode itself | `config(['portal.customer_surface_mode' => 'closed'])`, and the missing-key and environment-string cases |
| pilot | `tests/Feature/Pilot/*`, and the `browser-pilot` job | the job sets the variable on the app container before it starts |

**`browser-pilot`** (hosted, on the pull request) runs `tests/Browser/customer-pilot.spec.ts` against an application that is in pilot
mode, as a **required** test:

- It is a separate job on a fresh runner, so no pilot container, configuration or database exists in the normal suite's job, and the
  normal suite is not reduced to a pilot-only suite. It starts the stack with `PORTAL_CUSTOMER_SURFACE_MODE=pilot` in the job
  environment; `docker-compose.yml` passes it into the app container, where it is a real environment variable (it beats
  `src/.env`, which says `closed`) visible to php-fpm and artisan alike. `./dev test:e2e` forwards no arbitrary host variable, so
  the mode is configured before startup, not injected into Playwright.
- The mode is verified **by behaviour**, twice: `php artisan customer-surface:preflight --expect-mode=pilot` fails the job unless
  the application resolved pilot mode and `portal.operator` is provisioned (the seeded operator role carries it), and the spec
  itself probes what a signed-in customer receives. `E2E_REQUIRE_PILOT_GATE=1` (the one host variable `./dev test:e2e` forwards)
  makes the spec fail, never skip, on a legacy or closed server, a 500, a redirect or any unexpected status
  (`tests/Browser/support/pilot-probe.ts`, unit-tested in `pilot-probe.vitest.ts`).
- The Playwright step runs under `set -eo pipefail` in an explicit `bash` shell, because a pipeline's status is its last command's and
  `./dev test:e2e 2>&1 | tee pilot-spec.log` would otherwise report `tee`'s success for a failed run (closure review R-3). `./dev`
  folds every failure it can see into its own exit status (the Playwright exit, the post-suite ticket cleanup, the fixture and ticket
  reconciliation, the attachment baseline), so any of them fails the step. A negative control at the top of the step runs
  `( false | tee /dev/null )` under the same shell options and fails the job if that returns success, and the CLI self-tests
  (`scripts/dev/tests/run.sh`) assert the step text and the control's behaviour with and without `pipefail`.
- Playwright exits 0 for an all-skipped run, so a final step refuses any log that does not report `1 passed` or that reports a
  skipped test. A green job therefore means the spec ran and passed; a run in which no test executed has no `1 passed` line and fails.
- Cleanup is `./dev test:e2e`'s own (the spec tracks its one project for cleanup and raises no tickets).
- The normal `browser` job still shows the spec as `1 skipped` (the stack is legacy there); that skip is the only skip allowed, and
  only for a plainly legacy server.

Not yet observed on hosted CI: the job was written and rehearsed locally against a disposable database, not run on GitHub.

## Database profile in CI (R0.9 WP3)

`tests/Feature/Database/DatabaseProfileTest.php` (22 tests) runs in the normal `checks` job, inside the whole-suite Pest step, on the compose
MariaDB 11.4.13 that CI already starts. That server is friendlier than the Namecheap host (InnoDB, utf8mb4, strict, UTC), so the tests do not rely on
its defaults: they assert the **Laravel connection's** effective session (`time_zone +00:00`, strict `sql_mode` with `NO_ENGINE_SUBSTITUTION`,
`utf8mb4_unicode_ci`), the configuration literals (`engine => InnoDB`, `timezone => +00:00`), the engine and collation of every table and text column, the
foreign keys against `database/profile/foreign-keys.json`, the production migration guard, and a UTC round trip around the 2026 daylight-saving
transitions (with a companion test that an Eastern-time session would reject or shift the same writes, so the probe is shown to be able to fail).

Mutation check (friendly server, hand-run): removing the `engine` literal fails 4 of the 22, removing `timezone` fails 5, unregistering the guard fails 3.

**A server with the host's defaults: a smoke test, not a second suite.** The last step of the `checks` job runs `scripts/ci/host-parity-smoke.sh`
(R0.9 WP3-DB follow-up). It starts one extra `mariadb:11.4.13` (the image the compose `db` service already pulled) with the defaults the Namecheap
host reported on 2026-10-19: MyISAM default engine, latin1, `sql_mode=NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` and an Eastern-time SYSTEM zone
(`TZ=America/New_York`). Under `APP_ENV=production`, so the migration guard runs, it then:

1. proves the server really has those defaults (a changed image or flag cannot make the smoke quietly friendly);
2. negative control: with a MyISAM table already present, `migrate --force` must fail with the guard's message;
3. on a fresh database, `migrate --force` and `database:verify --json` must pass, with every table InnoDB;
4. negative control: with a stray MyISAM latin1 table added, `database:verify` must exit non-zero and name it.
5. (R0.9 WP3-A slice A1) `runtime:preflight` on the same server: refused without cached configuration; passes on a production-shaped environment with the configuration cached at an
   **isolated temporary path** (`APP_CONFIG_CACHE`, so a developer's `bootstrap/cache/config.php` is never read, overwritten, removed or left behind, and the script asserts it is
   untouched) and leaves no probe row; refused for a `log` mail transport, a `MAIL_URL` (selecting the log driver, or carrying credentials), a session cookie that is not Secure or not HttpOnly, a `file` cache store, a `retry_after` of 299 and a missing `cache_locks` table. See
   [Runtime Preflight](../architecture/runtime-preflight.md).
   (R0.9 WP3-A slice A2) The same server also runs the **cross-context verification** with real, bounded processes: `runtime:preflight --issue`, a PHP web server (`php -S`, SAPI `cli-server`) answering one
   `GET /up`, a real database queue worker (`queue:work --stop-when-empty --max-jobs=1`) and `schedule:run` through a symlink to the release, then `--verify` passes. Negative controls: a scheduler on another
   configuration cache (`CACHE_PREFIX`), a scheduler running from a second physical release directory with identical configuration, a missing scheduler observation and a superseded challenge. Each process is
   bounded by `timeout`; the only server is stopped immediately and again by the cleanup trap; the extra configuration cache and release directory live under the same `mktemp -d` directory.
   (R0.9 WP3-A slice A3) Then the **startup guard**: with an invalid production configuration cache (built with `MAIL_MAILER=log`, also under the `mktemp -d` directory) `queue:work`, `queue:listen`,
   `schedule:run` and `schedule:work` must each be refused (`queue:work` and `schedule:run` also with `--env=local`, and `queue:work` with `--env=PRODUCTION`: `--env` replaces the detected environment, not the cached configuration; `--env=testing` must instead stop at the test-database fence), with the `Refusing to start` message and the `mail.default` rule in the output (so an unrelated CLI error cannot pass), the queued job must still
   be unreserved with no attempt, and no observation may exist for the challenge; then the valid worker and scheduler start and `--verify` passes. Every process is bounded by `timeout`.

It needs no new service definition in `docker-compose.yml` and no new job. Cost: about 70 seconds in the existing job (container start, the migration set and the
runtime-preflight steps; measured 59 s locally with slice A2 (A3 adds four refused starts and two valid ones, a few seconds), 45-50 s before slice A1), and the run is not repeated for the whole suite. Maintenance: the flag list in the script must keep equal to the
host's reported defaults, and the pinned version equal to the host's `11.4.13`; when the host's defaults or version change, change the script and the
`mariadb:11.4.13` pin with them. Reproduce locally from the repository root, with Docker and PHP: `bash scripts/ci/host-parity-smoke.sh`. It is safe beside a dev stack: the container name is unique to the
invocation and published on an ephemeral loopback port, only the container (and its anonymous data volume) that `docker run` returned to it is ever removed, and
every Laravel command is pinned to it (an inherited `DB_URL`, `DB_SOCKET` or connection override is neutralised, and the pin is verified through the application before
anything is migrated).
Locally verified to fail (hand-run): with the `engine` literal removed from `config/database.php` the step fails at the production migration guard.

What this does not cover: the full Pest suite on such a server (a one-off local run is recorded in `docs/architecture/database-profile.md` section 7), the
daylight-saving round trip (the Pest file covers it with an Eastern-time session on the normal server), and the real host (isolated staging).
Re-run the full-suite reproduction by hand when `config/database.php` connection options, the database server version, or the migration set's raw SQL change.

## First hosted run of the Release 0.9 foundations (2026-10-12)

[run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30, head `3c78fb9` (the first CI of EPIC-017 WP1/WP2 and R0.9-0 to R0.9-1D; the PR is a draft and **Release 0.9 is not
release-ready**). Recorded as evidence, not as approval:

| Gate | Result |
|---|---|
| Testing and both rehearsal databases provisioned on a fresh runner | Passed (all three visible to `portal`) |
| `git diff --check`, Pint, `npm run check` (typecheck, lint, format, Vitest, build) | Passed |
| Permission-cache security tests on Redis, `--fail-on-skipped` | 9 passed, **0 skipped** |
| Full Pest (rehearsal, schema-preservation and real-process concurrency tests included) | 3,288 passed (18,541 assertions), **0 skipped**, about 9.7 minutes |
| Playwright (`./dev test:e2e`, whole suite, `workers: 3`) | 195 passed (5.6 minutes); cross-UID cleanup restored the ticket and attachment-directory baselines |
| CLI self-tests | 381 passed, **1 failed**: the shellcheck lint (SC2034, an unused loop variable in `scripts/dev/lib.sh`). Shellcheck is "only if installed": hosted runners have it, the development machine does not. Fixed afterwards by a one-token change; the verifying run is on the PR |

The `checks` job took about 13 minutes against its 30-minute limit, so no timeout change was needed.

## Constraints this workflow keeps

From A13.18; change them only with the reasoning there in hand.

- `workers: 3` stays as committed (`docs/testing/e2e-browser-suite.md`). No serialising, no raised
  timeouts, no weakened throttling to make CI pass.
- Vitest and Playwright never run on the same machine at the same time.
- The build comes from a clean checkout: no stale compiled views feed Tailwind (A4.19 #3), and the
  production build must emit the ten self-hosted WOFF2 faces.
- The Blade roots read `resources/js/shell/bootstrap.js` from disk at render time; the browser job
  serves the checked-out tree, so it is present.

## Caching

Composer downloads (keyed on `src/composer.lock`) and npm downloads (`setup-node`, keyed on
`src/package-lock.json`) in the `checks` job only. `vendor/`, `node_modules/`, build output and
databases are never cached, and a cold run must pass. The `browser` job caches nothing: its image
build and in-container installs run cold every time (see *Deferred* for the cost).

## Security

- `permissions: contents: read`; checkout with `persist-credentials: false`.
- Triggers are `pull_request` and `push` only, never `pull_request_target`, so PR code runs without
  repository secrets. The workflow uses no secrets.
- Every credential in the workflow is a dev-only value already committed (`portal`/`portal`,
  `root`/`root` in `docker-compose.yml`); `APP_KEY` is generated per run.
- Actions, pinned to major versions: `actions/checkout@v7`, `actions/setup-node@v7`,
  `actions/cache@v6` (GitHub), `shivammathur/setup-php@v2` (the de facto standard PHP setup action).

## Runtime

Measured on the first two hosted runs, both cold cache (PR [#2](https://github.com/Intechral-Solutions/intechral-client-portal/pull/2)):
the `pull_request` run took `checks` 6m39s and `browser` 6m52s; the following `push`-to-`main` run took
`checks` 6m44s and `browser` 7m47s. Local reference points on the owner's machine: `./dev check`
6.3 minutes (Pest 4.3), the browser suite 5.6 minutes — the hosted `browser` job's extra time is mostly
its cold image build (Chromium and PHP extensions compile).

## Reproducing a CI failure locally

Start the stack (`./dev up`) and run the matching local command from the table above; for the browser
job, `./dev test:e2e` (a single spec: `./dev test:e2e tests/Browser/shell.spec.ts`). Run `./dev check`
and `./dev test:e2e` one after the other, not at once. On a failed `browser` run, the workflow prints
the tail of `laravel.log`; Playwright traces are not uploaded.

## Known limitations and deferred items

- **Not enforced** until required checks are available on the GitHub plan (above).
- **Inherited debts CI will surface rather than cause** (EPIC-013 §31): `tests/Browser` is neither
  type-checked nor linted; one Pest guard is still vacuous (A13.13: `BrowserAuthContractTest.php:84`, a message passed as a second `toContain` needle; the other, in `ProjectIntegrityTest`, was fixed by EPIC-015 WP1, and EPIC-015 WP6 repaired two more of the same shape in `ProjectTaskQueryTest` and `ProjectTimePageTest`); Vitest/jsdom is load-sensitive (A11.17, A12.14, A13.15), and hosted
  runners are smaller than the development machine, so a `userEvent` timeout in CI should be
  reproduced alone before being treated as a regression.
- **Product-data counts return to baseline (A9.7 closed, EPIC-014 WP7).** Until WP7 the Tasks browser
  spec left two standalone-task rows per run, which `./dev test:e2e` reported as a count warning. The
  cleanup fixture (`tests/Browser/support/e2e-fixtures.ts`) now removes every standalone task a test
  registers through `DELETE /tasks/{task}` in its teardown, which runs whether the test passed or not,
  and fails a test that creates a standalone task without registering it. A full local run now
  reports the tracked `projects`/`tasks`/`time_entries` counts unchanged, and a second full run starts
  from the same baseline ([EPIC-014 Amendment 6](../epics/EPIC-014-tasks-workspace-overhaul.md#amendment-6-wp7-hardening-and-closeout-2026-10-01)).
  A run killed outright (no teardown) can still leave rows; they are development data.
  Tickets are different: they have no delete route, so `./dev test:e2e` also sweeps and cleans them with
  `e2e:cleanup-tickets` (run by the wrapper as the web-server user after Playwright exits, because Playwright itself
  runs as the runner's UID and cannot see the `0700` private files) and **fails** when the `tickets` count or the
  number of attachment directories does not return to its baseline (EPIC-017 §25.3;
  [E2E ticket cleanup](e2e-browser-suite.md#e2e-ticket-cleanup)).
  The runner-UID versus `portal` path ran on hosted CI for the first time in [run 38287646296](https://github.com/Intechral-Solutions/intechral-client-portal/actions/runs/38287646296) on draft PR #30: Playwright as `1001:1001`, php-fpm
  and the cleanup as `portal` `1000:1000`; the wrapper reported 3 tickets and 2 attachment files (1 directory) removed and
  both baselines restored (EPIC-017 A2.13). One run is evidence, not a guarantee: it stays the authoritative leak check.
  A test whose project-create redirect times out before the id is registered with
  `cleanup.trackProject` also leaves that project behind (EPIC-015 A2.12 #5, A4.12 #6, A5.11): under a
  contended local machine this has happened several times. Hosted CI starts from an empty database and
  reports the counts, so it remains the authoritative leak check.
- **`./dev test:e2e` serves the built assets and does not rebuild them (EPIC-015 A2.12 #5, A5.15).**
  After any front-end change run `npm run build` first; a stale `public/build` fails loudly (for example
  every create stays on `/projects/create`), and the resulting worker restarts can cascade into Fortify
  `429`s on sign-in. Do not run two browser invocations back to back for the same reason.
- **Query-count tests are deterministic (EPIC-015 A5.16).** `tests/TestCase.php` turns off the database
  session driver's garbage-collection lottery (`[0, 100]`), because its random `delete from sessions`
  landed inside measured HTTP requests and failed exact query-budget comparisons about one run in ten.
  `TestHarnessDeterminismTest` pins it. Production session configuration is unchanged.
- **Only the Blade no-shift test measures a cold paint; the Inertia one does not.**
  `blade-shell.spec.ts` loads `/operator/tickets` as the first document of a fresh context (session
  cookies only, empty HTTP cache) and counts shell-owned layout shift only, as `shell.spec.ts` always
  has: page-body reflow when the swap fonts land is not shell shift (EPIC-013 A13.9). The Inertia
  no-shift test in `shell.spec.ts` visits `/dashboard` first, so CSS and the preloaded Plex Sans
  400/500 are cached when it measures. **True-cold Inertia shell-shift verification remains blocked by
  the known Plex Sans 600 first-use re-centring issue (M2, below); the current Inertia flow warms
  fonts before the measured navigation, and its zero must not be read as a cold-load result.**
- **Two separate shell-shift incidents, kept distinct.**
  - **M1 — fixed.** Parser partial-paint of a rail item: the parser paused inside an item and Chromium
    painted it with the icon but not the label, so the icon moved (~1.7e-6, the hosted CI failure of
    2026-09-29). Fixed by label-independent rail item geometry in both renderers; not a font issue.
  - **M2 — deliberately deferred.** True-cold first use of Plex Sans 600: the active rail label
    (weight 600, not preloaded) paints in the fallback bold and re-centres by about 3.5 px when the
    face arrives, a shell shift of approximately 9.8e-7 (the exact decimal varies with Chromium and is
    not an invariant). Preloading 600 removes it but moves 24,252 B onto the early critical path,
    which measured roughly +100–170 ms to first paint / shell frame on constrained profiles (Slow 4G,
    Fast 3G) with total font bytes unchanged; that cold-first-paint cost was judged not worth a
    sub-perceptual shift. Other simple remedies were rejected (weight 500 breaks the active-label
    typography, `font-display` and hiding the shell break the locked swap and immediate-shell
    contracts, metric overrides are fallback-specific, subsetting needs new tooling and licence work).
    Revisit when the Inertia flow is to be made genuinely cold.
- **The shared shell-shift probe accounts for text-node sources.** A layout-shift source can be a Text
  node (a label re-centring reports its text run). The probe (`installShellShiftProbe`) once tested
  the raw node, and text has no `closest()`, so every text-node shift was silently dropped and could
  read as `shellShift = 0`; sources are now normalised to their owning element first. Regression
  coverage is `shell-shift-probe.spec.ts` (Chromium is authoritative for real LayoutShift behaviour).
  Which regions count, the zero threshold and the observer timing are unchanged.
- **Frontend failures cascade into Pest.** Pest's Blade and Inertia responses need the Vite manifest
  that `npm run check` builds last. If that chain fails before `vite build`, hundreds of Pest tests fail
  with `ViteManifestNotFoundException` (or "Not a valid Inertia response"), and the volume of failure
  output can push the job towards its timeout. Fix the first frontend failure; the Pest failures follow
  from it. (Locally a leftover `public/build` hides this.)
- **Ubuntu 26.** GitHub moves `ubuntu-latest` to Ubuntu 26.04 from 19 October 2026. The pin stays on
  24.04 until a deliberate trial run on 26.04 shows the baseline still passes there.
- **Deferred:** Docker layer caching for the `browser` job's image build; uploading Playwright traces
  on failure; a manual (`workflow_dispatch`) trigger; required-check enforcement once the plan allows.
