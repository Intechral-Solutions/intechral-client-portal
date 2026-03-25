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

## Useful Raw Docker Commands

```bash
# Run any artisan command
docker compose exec app php artisan <command>

# Run Pest with coverage report
docker compose exec app ./vendor/bin/pest --coverage

# Stop and remove volumes (destroys all database data)
docker compose down -v
```
