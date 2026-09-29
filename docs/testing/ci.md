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

Not yet measured on GitHub. Local reference points on the owner's machine: `./dev check` 6.3 minutes
(Pest 4.3), the browser suite 5.6 minutes. On a hosted runner, expect the `browser` job to add the
cold image build (Chromium and PHP extensions compile) to that. Record the first real runs here.

## Reproducing a CI failure locally

Start the stack (`./dev up`) and run the matching local command from the table above; for the browser
job, `./dev test:e2e` (a single spec: `./dev test:e2e tests/Browser/shell.spec.ts`). Run `./dev check`
and `./dev test:e2e` one after the other, not at once. On a failed `browser` run, the workflow prints
the tail of `laravel.log`; Playwright traces are not uploaded.

## Known limitations and deferred items

- **Not enforced** until required checks are available on the GitHub plan (above).
- **Inherited debts CI will surface rather than cause** (EPIC-013 §31): `tests/Browser` is neither
  type-checked nor linted; the standalone-task fixture leaks one row per browser run (A9.7 — harmless
  in CI's throwaway database; `./dev test:e2e` reports it as a count warning, not a failure); two Pest
  guards are vacuous (A13.13); Vitest/jsdom is load-sensitive (A11.17, A12.14, A13.15), and hosted
  runners are smaller than the development machine, so a `userEvent` timeout in CI should be
  reproduced alone before being treated as a regression.
- **The no-shift browser tests measure a warm-cache paint.** They pass only when fonts and CSS are
  reusable from the HTTP cache, as nginx's headers allow (above). Whether a first, cold visit is
  shift-free is not covered; recorded, not changed.
- **Frontend failures cascade into Pest.** Pest's Blade and Inertia responses need the Vite manifest
  that `npm run check` builds last. If that chain fails before `vite build`, hundreds of Pest tests fail
  with `ViteManifestNotFoundException` (or "Not a valid Inertia response"), and the volume of failure
  output can push the job towards its timeout. Fix the first frontend failure; the Pest failures follow
  from it. (Locally a leftover `public/build` hides this.)
- **Ubuntu 26.** GitHub moves `ubuntu-latest` to Ubuntu 26.04 from 19 October 2026. The pin stays on
  24.04 until a deliberate trial run on 26.04 shows the baseline still passes there.
- **Deferred:** Docker layer caching for the `browser` job's image build; uploading Playwright traces
  on failure; a manual (`workflow_dispatch`) trigger; required-check enforcement once the plan allows.
