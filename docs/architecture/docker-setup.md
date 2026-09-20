# Docker Dev Environment Setup

## Services

| Service | Image | Port (host) | Purpose |
|---------|-------|-------------|---------|
| `app` | `php:8.3-fpm` (custom) | — | Laravel PHP-FPM |
| `nginx` | `nginx:1.25-alpine` | `8080` | Web server |
| `db` | `mariadb:10.11` | `3306` | Primary database |
| `redis` | `redis:7-alpine` | `6379` | Cache / sessions / queues |
| `mailpit` | `axllent/mailpit` | `8025` (UI), `1025` (SMTP) | Local email testing |
| `queue` | `php:8.3-fpm` (custom) | — | Laravel queue worker |

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

That's it. `npm run setup` handles everything. Open `http://localhost:8080`.

## Dev Scripts

All commands are npm scripts defined in the root `package.json`:

```bash
npm run setup    # First-time setup (build + install + migrate + seed)
npm run up       # Start containers
npm run down     # Stop containers
npm run restart  # Stop and restart
npm run build    # Rebuild Docker images (no cache)
npm run dev      # Start Vite HMR dev server
npm run fresh    # Reset database and re-seed
npm run test     # Run Pest test suite
npm run lint     # Run Laravel Pint
npm run shell    # Open bash in the app container
npm run logs     # Tail container logs
```

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
- PHP 8.3 with extensions: `pdo_mysql`, `redis`, `mbstring`, `xml`, `gd`, `zip`, `bcmath`, `intl`, `opcache`, `exif`
- Composer 2.7 installed globally
- Node.js 22 LTS + npm installed
- Working directory: `/var/www/app`

### Nginx (`nginx`)
- Config at `.docker/nginx/default.conf`
- Serves `intechral-client-portal/public/` as document root
- Proxies PHP to `app:9000`

### MariaDB (`db`)
- Version: `10.11`
- Dev credentials: `root/root`, database `portal`, user `portal/portal`
- Data volume: `.docker/data/mysql/` (gitignored)

### Redis (`redis`)
- Version: `7-alpine`
- No auth in dev
- Persistent data volume: `.docker/data/redis/`

### Mailpit
- SMTP on port `1025` — configure `MAIL_HOST=mailpit`, `MAIL_PORT=1025` in `.env`
- Web UI on `http://localhost:8025`
- All outgoing mail in dev is caught here

## Running the Test Suite

Tests run inside the `app` container against a dedicated MariaDB test database (`intechral_client_portal_testing`). SQLite is intentionally not used — the application relies on MariaDB-specific schema behavior (foreign-key drops during table renames) that SQLite cannot support.

### Test database setup

**Fresh Docker setup (new volume):** The test database is created automatically. `init-testing.sql` is mounted into `/docker-entrypoint-initdb.d/` and MariaDB runs it on first start.

**Existing Docker setup:** Create the test database once if it doesn't already exist:

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
# Run the full suite (recommended — same command used by npm run test)
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

A safety guard in `tests/TestCase.php` reads the active `DB_DATABASE` at boot time and throws a `RuntimeException` if the name does not contain `"testing"`. This prevents accidental data loss if `phpunit.xml` or `.env.testing` is misconfigured.

### Test configuration

Test environment variables live in `phpunit.xml`. Key settings:

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

# Stop and remove volumes (destroys all database data — requires test DB recreation)
docker compose down -v
```
