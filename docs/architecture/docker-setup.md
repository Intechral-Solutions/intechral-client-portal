# Docker Dev Environment Setup

## Services

| Service | Image | Port (host) | Purpose |
|---------|-------|-------------|---------|
| `app` | `php:8.5-fpm` (custom) | — | Laravel PHP-FPM |
| `nginx` | `nginx:1.25-alpine` | `4242` | Web server |
| `db` | `mariadb:11.4.13` | `3306` | Primary database (production version) |
| `redis` | `redis:7-alpine` | `6379` | Cache / sessions / queues |
| `mailpit` | `axllent/mailpit` | `8025` (UI), `1025` (SMTP) | Local email testing |
| `queue` | `php:8.5-fpm` (custom) | — | Laravel queue worker |

## Project Structure

```
Client Portal/
├── intechral-client-portal/   ← Laravel application source
├── .docker/                   ← Docker configuration files
│   ├── php/                   ← PHP-FPM image, php.ini, entrypoint
│   ├── nginx/                 ← Nginx virtual host config
│   └── mysql/                 ← MariaDB my.cnf
├── docker-compose.yml
├── package.json               ← Root npm scripts for dev environment
└── docs/
```

## Quick Start

**Requirements:** Docker Desktop, Node.js

```bash
# 1. Clone repository
git clone <repo-url>
cd "Client Portal"

# 2. First-time setup (builds Docker image, installs deps, migrates, seeds)
npm run setup
```

That's it. `npm run setup` handles everything. Open `http://localhost:4242`.

## Developer CLI

Routine environment work goes through `./dev` at the repository root (`./dev help`). It resolves and verifies the actual database before any database or test command, runs container commands as the host UID:GID, and backs up before migrating. Command reference, safety rules, backup location and how to add commands are in the [README](../../README.md#developer-cli-dev).

```bash
./dev up               # Start containers
./dev doctor           # Read-only diagnostics
./dev db:migrate       # Back up, confirm, then run pending migrations (development DB)
./dev test:php         # Pest against the testing DB
./dev test:e2e         # Playwright (runs against the development DB)
./dev check            # Full non-browser validation
```

Root `npm run` aliases (`setup`, `up`, `down`, `restart`, `build`, `dev`, `test`, `lint`, `shell`, `logs`) still work; `up`, `down`, `restart`, `shell` and `test` delegate to `./dev`. The former `npm run fresh` alias was removed: it ran `migrate:fresh --seed` against the development database without any check.

## Frontend Tooling

Frontend assets are bundled with **Vite** via npm scripts inside the container:

```bash
# Dev mode with HMR
npm run dev

# Production build
docker compose exec app npm run build
```

## Services Detail

### PHP-FPM (`app`)
- Custom Dockerfile at `.docker/php/Dockerfile`
- PHP 8.5 (preferred baseline; PHP 8.3 is the tested fallback, see [PHP version](#php-version)) with extensions: `pdo_mysql`, `redis`, `mbstring`, `xml`, `gd`, `zip`, `bcmath`, `intl`, `exif`, `pcntl`, and OPcache. OPcache is compiled into PHP 8.5, so the Dockerfile no longer installs it; the build fails if it is not loaded
- Composer 2.10.3 installed globally (pinned to an exact, reviewed release)
- Node.js 22 LTS + npm installed
- Playwright Chromium + its OS libraries, baked in at build time (see [End-to-end browser tests](#end-to-end-browser-tests))
- Working directory: `/var/www/app`

### PHP version

**PHP 8.5 is the preferred baseline** for the development image, CI and the intended production runtime. **PHP 8.3 is the supported, tested fallback.** `src/composer.json` keeps `"php": "^8.3"` (no `config.platform.php`), so the unchanged `composer.lock` installs on either.

Evidence (R0.9-2a, scratch assessment on PHP 8.5.11 against the unchanged lockfile, then this alignment): Composer platform requirements 27/27; the same 3,541 Pest tests with 0 skipped and 0 new PHP deprecations on 8.3.33 and 8.5.11; identical MariaDB 10.11 migrate-and-seed schema; Redis queue, SMTP, PDF, GD and integration-package smoke tests passed. The only deprecations either version reports are the same three `hash_hmac(): Passing null` messages from Stripe's `WebhookSignature` and Laravel's `SessionGuard`.

To run the image on the PHP 8.3 fallback, change both `FROM php:8.5-fpm` lines in `.docker/php/Dockerfile` back to `php:8.3-fpm` **and** add `opcache` to the `docker-php-ext-install` list (on 8.3 it is a separate extension), then rebuild: `docker compose build app queue && docker compose up -d app queue`, and restart `nginx`. For CI, set `php-version: '8.3'` in the `setup-php` step of `.github/workflows/ci.yml`. Nothing in the application changes.

### End-to-end browser tests

`./dev test:e2e` runs the Playwright suite inside `portal_app`, as the host UID:GID like every other `./dev` container command, against `PLAYWRIGHT_BASE_URL=http://nginx`. The suite exercises the **development** database.

Chromium comes from the image, not from the running container:

- `.docker/php/Dockerfile` runs `playwright-core install --with-deps chromium` during the build, so the browser and its apt libraries are image layers. Recreating the container (`./dev down`, `./dev restart`, `docker compose down`) keeps them.
- Browsers live in `/opt/ms-playwright` (`ENV PLAYWRIGHT_BROWSERS_PATH`), world-readable, so no root-only `/root/.cache` and no need to run E2E as root.
- The version is not hard-coded: a build stage reads `playwright-core` from `src/package-lock.json` and fails the build if it is missing or differs from `@playwright/test`. After bumping Playwright, rebuild the image so the browser revision matches.
- The Dockerfile is development-only (used by the `app` and `queue` services; production targets cPanel/PHP-FPM), so browser tooling does not reach production. The `queue` image shares the same layers.
- `test:e2e` never installs anything. If Chromium cannot launch it stops and points at the rebuild; `./dev doctor` reports the same as a failure.

Rebuild after Dockerfile or Playwright-version changes:

```bash
docker compose build && ./dev restart     # cached rebuild, then recreate ALL containers from it
npm run build && ./dev restart            # from scratch (docker compose build --no-cache)
```

Use `./dev restart`, not just `./dev up`, after a rebuild: `up` recreates only the containers whose image changed, and the long-running `nginx` container would keep a stale address for the recreated `app` and answer 502.

### Nginx (`nginx`)
- Config at `.docker/nginx/default.conf`
- Serves `intechral-client-portal/public/` as document root
- Proxies PHP to `app:9000`
- Maps browser-facing `http://localhost:4242` to Nginx port `80` in the container

### MariaDB (`db`)
- Version: `11.4.13`, pinned to the production patch version (Namecheap runs `11.4.13-MariaDB-cll-lve-log`). Moving an existing machine from the earlier `10.11` is a dump and restore: see [MariaDB 11.4 transition](#mariadb-114-transition).
- Dev credentials: `root/root`, database `portal`, user `portal/portal`
- Persistence: the Compose named volume `dbdata114` (Docker name `intechral-portal_dbdata114`), mounted at `/var/lib/mysql`. It is managed by Docker, not stored in the repository; see [Data persistence](#data-persistence).

### Redis (`redis`)
- Version: `7-alpine`
- No auth in dev
- Persistence: the Compose named volume `redisdata`, mounted at `/data` (append-only file enabled). See [Data persistence](#data-persistence).

### Data persistence

`docker-compose.yml` declares two top-level named volumes (`driver: local`): `dbdata114` (MariaDB, mounted at `/var/lib/mysql`) and `redisdata` (Redis, mounted at `/data`). They live inside Docker's storage, not under `.docker/` or the repository. Docker prefixes the runtime volume name with the Compose project name (set by `name:` in `docker-compose.yml`, or overridden by `-p` / `COMPOSE_PROJECT_NAME`), so confirm the exact name with `docker volume ls` rather than assuming it.

- `./dev down`, `./dev restart` and `docker compose down` keep both volumes; the databases survive.
- Only `docker compose down -v` (or `docker volume rm`) deletes them. That destroys the development `portal` database *and* the testing database, and no `./dev` command does it. Take a `./dev db:backup` first if you ever need to.
- `.docker/mysql/init-testing.sql` and `init-rehearsal.sql` run only when `dbdata114` is first created (empty).
- The MariaDB 10.11 volume `intechral-portal_dbdata` is **no longer declared** in `docker-compose.yml`. Docker keeps it, Compose does not manage it, and `docker compose down -v` cannot remove it. It is the rollback source until you delete it deliberately (see [MariaDB 11.4 transition](#mariadb-114-transition)).

### MariaDB 11.4 transition

Development and CI run **MariaDB 11.4.13**, the version on the Namecheap host. A machine that already holds development data on the earlier MariaDB 10.11 volume (`intechral-portal_dbdata`) moves across with a **logical dump and restore into a new volume**. Never upgrade the old volume in place: MariaDB cannot be downgraded afterwards, and the old volume is your rollback.

A machine with no data worth keeping needs nothing: `./dev up` creates the new volume and the init scripts create the testing and rehearsal databases.

**Before you start.** Do steps 1 to 4 while the stack is still on the 10.11 configuration (before you pull or check out the commit that changes `docker-compose.yml`), and confirm the exact volume name with `docker volume ls | grep dbdata` (it is prefixed with the Compose project name).

1. **Back up.** `./dev db:backup` writes `backups/dev/portal-<timestamp>.sql` (gitignored, outside every Docker volume). Check the file ends with `-- Dump completed`.
2. **Stop every writer** so the snapshot is final: `docker compose stop app queue nginx`. Check nothing else is connected: `docker compose exec db mariadb -uroot -proot -e "select user,host,command from information_schema.processlist"` should show only your own query. Stop any running Playwright or Pest run first.
3. **Fingerprint the source.** Row counts alone are not enough. Record both of these and keep the output:

   ```bash
   # A `for` loop over a captured list, not `| while read`: `docker compose exec` would swallow the piped list.
   tables=$(docker compose exec -T db mariadb -uroot -proot -N -e "select table_name from information_schema.tables where table_schema='portal' and table_type='BASE TABLE' order by 1")
   for t in $tables; do
     n=$(docker compose exec -T db mariadb -uroot -proot -N -e "select count(*) from portal.\`$t\`")
     h=$(docker compose exec -T db mariadb-dump -uroot -proot --no-create-info --skip-extended-insert --order-by-primary --skip-comments --skip-triggers portal "$t" | grep '^INSERT' | md5sum | cut -c1-32)
     echo "$t rows=$n md5=$h"
   done > before.txt
   wc -l before.txt   # one line per table: 41 at the time of the transition
   ```

4. **Optional physical safety copy.** `docker compose stop db`, then `docker run --rm -v intechral-portal_dbdata:/v:ro -v "$PWD/backups/dev":/out alpine tar czf /out/dbdata-1011.tar.gz -C /v .` (read-only mount). Start the database again before step 5 only if you are staying on 10.11.
5. **Switch.** Check out the commit with the new `docker-compose.yml`, then recreate only the database: `docker compose up -d --no-deps db`. Compose creates the empty volume `intechral-portal_dbdata114`, the init scripts run, and `docker compose exec db mariadb -uroot -proot -e "select version()"` must report `11.4.13`.
6. **Restore.** `docker compose exec -T db mariadb -uroot -proot portal < backups/dev/portal-<timestamp>.sql` (a dump written with `--databases` already contains `CREATE DATABASE`; omit the trailing `portal` for that form).
7. **Verify.** Repeat the loop from step 3 against the new database into `after.txt` and `diff before.txt after.txt` (expect no output). Then `./dev db:status` (all migrations ran, none pending), `./dev doctor`, and sign in.
8. **Start the writers.** `docker compose start app queue nginx`.

**Rollback.** Until a write happens on 11.4, rollback is lossless: check out the previous `docker-compose.yml` and run `docker compose up -d --no-deps db`; the 10.11 database starts on the untouched `intechral-portal_dbdata`. Once the 11.4 database has taken writes (sign-ins write session rows, any browser run writes data), rolling back **loses those writes unless you carry them over**: take `./dev db:backup` from 11.4 first, switch back, and decide explicitly whether to restore that dump over the 10.11 copy. Do that restore into a copy of the old volume, not the only copy. A 11.4 dump restoring into 10.11 has been checked for this schema (see the R0.9-2a handback), but treat any downgrade as a deliberate, backed-up operation.

**Cleanup, only when you are sure.** After a few days of normal use and a good backup of the 11.4 database, remove the old volume yourself: `docker volume rm intechral-portal_dbdata`. No `./dev` command and no Compose command does this for you. Keep or delete the `backups/dev/` files by your own retention rule; they contain real development data.

**The testing and rehearsal databases** in the new volume start empty and are recreated by the init scripts. They are disposable (the Pest suite and the migration rehearsal rebuild their own contents), so nothing in them needs carrying across.

### Mailpit
- SMTP on port `1025` — configure `MAIL_HOST=mailpit`, `MAIL_PORT=1025` in `.env`
- Web UI on `http://localhost:8025`
- All outgoing mail in dev is caught here

## Running the Test Suite

Tests run inside the `app` container against a dedicated MariaDB test database (`intechral_client_portal_testing`). SQLite is intentionally not used — the application relies on MariaDB-specific schema behavior (foreign-key drops during table renames) that SQLite cannot support.

### Test database setup

**Fresh Docker setup (new volume):** The test database is created automatically. `init-testing.sql` is mounted into `/docker-entrypoint-initdb.d/` and MariaDB runs it on first start.

**Existing Docker setup:** The init script does not run again for an existing MariaDB volume. Run this idempotent command to create and grant only the designated test database; it does not drop or modify the development `portal` database:

```bash
docker compose exec db mariadb -u root -proot -e "
  CREATE DATABASE IF NOT EXISTS \`intechral_client_portal_testing\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON \`intechral_client_portal_testing\`.* TO 'portal'@'%';
  FLUSH PRIVILEGES;
"
```

### Running Pest

```bash
# Run the full suite (recommended: ./dev test:php verifies the test DB target first,
# runs Pest as your host user and forwards extra arguments)
./dev test:php
# ...or directly:
docker compose exec app ./vendor/bin/pest

# Run a single test file
docker compose exec app ./vendor/bin/pest tests/Feature/Tickets/TicketSubmissionTest.php

# Run tests matching a name pattern
docker compose exec app ./vendor/bin/pest --filter "ticket"

# Run with coverage report
docker compose exec app ./vendor/bin/pest --coverage
```

### How `RefreshDatabase` works with MariaDB

Each feature test uses `RefreshDatabase`. On every test, Pest drops and recreates the schema against `intechral_client_portal_testing` only — the dev database (`portal`) is never touched.

`tests/bootstrap.php` overwrites inherited process values with the designated test environment before Laravel is created. `AppServiceProvider` then rejects every testing bootstrap unless `APP_ENV` is exactly `testing` and the resolved database is exactly `intechral_client_portal_testing`; the test base class repeats that resolved-config check immediately before `RefreshDatabase` runs. This also protects manual commands such as `php artisan --env=testing migrate:fresh`: `.env.testing` selects the dedicated database, and a bad override fails during application boot before the command can alter schema.

### Test configuration

Test environment variables live in `.env.testing` for manual Artisan commands and `phpunit.xml` for Pest/PHPUnit. Because PHPUnit's `force` attribute does not reliably replace every inherited `$_SERVER` value, `tests/bootstrap.php` is authoritative for the test process. Docker Compose does not hard-code Laravel's application environment; normal services read `APP_ENV=local` from `.env`.

Key settings:

| Variable | Value | Why |
|---|---|---|
| `DB_CONNECTION` | `mysql` | MariaDB via the mysql driver (matches `.env.example`) |
| `DB_HOST` | `db` | Docker service hostname |
| `DB_DATABASE` | `intechral_client_portal_testing` | Dedicated test database |
| `CACHE_STORE` | `array` | In-memory, no Redis required |
| `SESSION_DRIVER` | `database` | Matches production browser-session semantics |
| `QUEUE_CONNECTION` | `sync` | Jobs run inline, no worker required |
| `MAIL_MAILER` | `array` | Emails captured in array, no SMTP required |

## Browser sessions

Browser sessions use Laravel's database driver so Profile can list safe session metadata and
revoke a user's other sessions deterministically. Redis remains in use for cache and queues.
Deployments must set `SESSION_DRIVER=database` and run the existing sessions-table migration.
Changing an existing environment from Redis sessions signs users out once; live Redis sessions
are intentionally not migrated.

## Useful Raw Docker Commands

```bash
# Run any artisan command
docker compose exec app php artisan <command>

# Run Pest with coverage report
docker compose exec app ./vendor/bin/pest --coverage

# Recreate test database from scratch
docker compose exec db mariadb -u root -proot -e "DROP DATABASE IF EXISTS \`intechral_client_portal_testing\`;"
docker compose exec db mariadb -u root -proot < .docker/mysql/init-testing.sql

# DESTRUCTIVE, raw Docker only (no ./dev equivalent): also removes the dbdata114 and redisdata
# volumes, destroying the development AND testing databases. Run ./dev db:backup first.
docker compose down -v
```
