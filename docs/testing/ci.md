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

Two jobs, in parallel, on separate runners pinned to `ubuntu-24.04` (not `ubuntu-latest`, so the
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

- **PHP 8.3 and Node 22**, as in `.docker/php/Dockerfile`, with that image's extensions
  (`shivammathur/setup-php`, `actions/setup-node`).
- **MariaDB** is the compose `db` service itself (`docker compose up -d --wait db`): the same image,
  `my.cnf`, `init-testing.sql` (which creates the testing database) and healthcheck as local. Pest
  never runs against SQLite: `tests/bootstrap.php` pins the MariaDB testing database and
  `TestDatabaseSafety` refuses anything else.
- **Host `db`.** `tests/bootstrap.php`, `phpunit.xml` and `.env.example` address the database as
  host `db`, as the app container does. CI adds `127.0.0.1 db` to the runner's `/etc/hosts` rather
  than changing them.
- **`src/.env`** is a copy of `.env.example` with a generated key, as `npm run setup` makes it. Pest
  does not read it (`APP_ENV=testing` uses `.env.testing` and `phpunit.xml`). No Redis is started:
  none of these gates touches it.
- **`git diff --check`** locally inspects uncommitted changes. A clean checkout has none, so CI
  checks what the change introduces: on a PR, `HEAD` is GitHub's merge commit and `HEAD^1` is `main`.
  (The tree as a whole is not whitespace-clean — some older docs and one Blade view — so a whole-tree
  check would fail for reasons unrelated to the change.)

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
