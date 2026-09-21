# Intechral Client Portal

A full-featured client portal platform built with Laravel 13, MariaDB 10, and Tailwind CSS v4. Deployable on cPanel-compatible hosting.

## Features

- Invitation-based user registration (email invite → local credentials or OpenID SSO)
- Role-based access control with built-in Operator and User roles plus custom roles
- Ticket Management
- Project Management (Kanban, milestones, Gantt)
- Billing & Invoicing (with Stripe payment collection)
- Time Tracking
- CRM (Companies, Contacts, Activity Timeline)
- CMS & Documentation (Markdown, media library)

## Quick Start

**Requirements:** Docker Desktop, Node.js

```bash
# Clone and start
git clone <repo-url>
cd "Client Portal"
npm run setup

# Open the portal
open http://localhost:4242

# Open email testing UI
open http://localhost:8025
```

See [docs/architecture/docker-setup.md](docs/architecture/docker-setup.md) for full documentation.

## Developer CLI: `./dev`

`./dev` is the one entry point for routine environment work. It is plain Bash (no host PHP/Node, no Laravel boot, no new dependencies), so it works while the containers are stopped. Run `./dev help` (or `./dev` with no arguments) for the list.

| Command | What it does | Database it uses |
|---------|--------------|------------------|
| `./dev up` / `./dev down` | Start / stop the Compose stack. `down` never removes volumes. | none |
| `./dev shell` | Bash in `portal_app` as your host UID:GID | none |
| `./dev doctor` | Read-only diagnostics: Docker, services, resolved DBs, pending migrations, tool versions, ports, root-owned files | reads dev + testing |
| `./dev db:status` | Migration status | **development** (`portal`) |
| `./dev db:backup` | Dump to `backups/dev/<db>-<YYYY-MM-DD_HHMMSS>.sql` | **development** |
| `./dev db:migrate` | Show pending, back up, ask for `yes`, run `php artisan migrate`, show status | **development** |
| `./dev test:php [pest args]` | Pest, e.g. `./dev test:php --filter=ProjectAuthorizationMatrixTest` | **testing** (`intechral_client_portal_testing`) |
| `./dev test:e2e [playwright args]` | Playwright in `portal_app` | **development**, see below |
| `./dev check [--no-php]` | CLI self-tests, `git diff --check`, Pint, `npm run check` (wayfinder, tsc, eslint, prettier, vitest, build), then the full Pest suite (~4 min; `--no-php` skips it) | testing (Pest only) |

**Safety.** Nothing trusts the command name to say which database it touches. Before acting, `./dev` boots Laravel inside the container and reads the database it actually resolves, then refuses (exit 1, nothing changed) unless it matches the contract:

- Development commands need `APP_ENV=local`, database `portal`, host `db` (and the `db` service must have been created with that database).
- `test:php` needs `APP_ENV=testing` and *exactly* `intechral_client_portal_testing`. It resolves through `tests/bootstrap.php`, so the existing `TestDatabaseSafety` guard applies, and it never falls back to development.
- `db:migrate` always backs up first, prints the backup path, then asks you to type `yes`. It runs plain `migrate` (no `--force`), never `migrate:fresh`. There is intentionally no `db:fresh`, `db:restore` or volume removal yet.

**E2E runs against the development database.** The Playwright suite drives the real app on `http://nginx` (inside the container), which uses `portal`. `test:e2e` prints project/task/time-entry counts before and after and warns if they differ; it never deletes anything (the tests' own fixture cleanup is authoritative). `--list` skips the database entirely.

**Backups** live in `backups/dev/` (gitignored, mode 0600, written as your host user). Credentials come from the `db` container's own environment and are never printed. A dump is only kept if it is non-empty and ends with mariadb-dump's completion marker.

**File ownership.** Everything runs in the container as your host UID:GID, so build output, caches and Wayfinder files stay yours. The single exception is `test:e2e`: Playwright's browsers are installed under `/root`, so it runs as root with `--output` pointed inside the container (`/tmp/dev-e2e-results`, where failure traces stay). `./dev doctor` reports any root-owned files under the generated directories and prints a one-time repair command; no `./dev` command runs `chown`.

**Adding a command.** Write `cmd_<name>` in `scripts/dev/commands.sh` (`db:status` maps to `cmd_db_status`) and add a `name|usage|description` line to `DEV_COMMANDS` (it drives both help and dispatch). Anything touching a database must call `resolve_or_die`, `print_target`, `enforce_contract` before acting. Container work goes through `app_exec`. Add a case to `scripts/dev/tests/run.sh` (`bash scripts/dev/tests/run.sh`; also run by `./dev check`).

### npm scripts

The root `package.json` keeps a few aliases. `up`, `down`, `restart`, `shell` and `test` delegate to `./dev`; the `fresh` alias (`migrate:fresh --seed` against the development database) was removed because it bypassed these guards.

| Script | Description |
|--------|-------------|
| `npm run setup` | First-time setup: build Docker image, install deps, migrate & seed |
| `npm run up` / `down` / `restart` | Same as `./dev up` / `./dev down` |
| `npm run build` | Rebuild Docker images (no cache) |
| `npm run dev` | Start Vite HMR dev server inside the container |
| `npm run test` | Same as `./dev test:php` (extra args pass through) |
| `npm run lint` | Run Laravel Pint (PHP code style) |
| `npm run shell` | Same as `./dev shell` |
| `npm run logs` | Tail logs from all containers |

## Tech Stack

| | |
|--|--|
| **Language** | PHP 8.3 |
| **Framework** | Laravel 13 |
| **Database** | MariaDB 10.11 |
| **Cache / Queue** | Redis 7 |
| **CSS** | Tailwind CSS v4 |
| **Build Tool** | Vite + npm |
| **Payments** | Stripe |
| **Auth** | Laravel Fortify + Socialite (OpenID) |
| **Authorization** | Spatie Laravel Permission |
| **Testing** | Pest PHP |
| **Dev Env** | Docker Compose |

## Documentation

Full developer documentation is in the [`docs/`](docs/README.md) folder.

## License

Proprietary — Intechral Solutions.
