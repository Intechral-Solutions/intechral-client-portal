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
├── Makefile
└── docs/
```

## Quick Start

```bash
# 1. Clone repository
git clone <repo-url>
cd "Client Portal"

# 2. Copy environment file
cp intechral-client-portal/.env.example intechral-client-portal/.env

# 3. First-time setup (builds images, installs deps, migrates, seeds)
make install

# OR step by step:
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app pnpm install
docker compose exec app pnpm run dev
```

## Frontend Tooling

Frontend assets are managed with **PNPM** and bundled with **Vite**:

```bash
# Install JS dependencies
docker compose exec app pnpm install

# Dev mode (HMR via Vite)
docker compose exec app pnpm run dev

# Production build
docker compose exec app pnpm run build
```

## Services Detail

### PHP-FPM (`app`)
- Custom Dockerfile at `.docker/php/Dockerfile`
- PHP 8.3 with extensions: `pdo_mysql`, `redis`, `mbstring`, `xml`, `gd`, `zip`, `bcmath`, `intl`, `opcache`
- Composer 2.7 installed globally
- Node.js 22 LTS + PNPM installed globally
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

## Useful Commands

```bash
# Run artisan commands
docker compose exec app php artisan <command>

# Run all tests
docker compose exec app php artisan test

# Run Pest with coverage
docker compose exec app ./vendor/bin/pest --coverage

# Open a shell in the app container
docker compose exec app bash

# View logs
docker compose logs -f app

# Reset database
docker compose exec app php artisan migrate:fresh --seed

# Stop all services
docker compose down

# Stop and remove volumes (destroys database data)
docker compose down -v

# Or use the Makefile shortcuts:
make up / make down / make test / make shell / make fresh
```
