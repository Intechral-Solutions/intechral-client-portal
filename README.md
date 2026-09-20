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

## Dev Scripts

All developer commands are npm scripts defined in the root `package.json`:

| Script | Description |
|--------|-------------|
| `npm run setup` | First-time setup: build Docker image, install deps, migrate & seed |
| `npm run up` | Start all containers |
| `npm run down` | Stop all containers |
| `npm run restart` | Stop and restart all containers |
| `npm run build` | Rebuild Docker images (no cache) |
| `npm run dev` | Start Vite HMR dev server inside the container |
| `npm run fresh` | Reset the database and re-seed |
| `npm run test` | Run the full Pest test suite |
| `npm run lint` | Run Laravel Pint (PHP code style) |
| `npm run shell` | Open a bash shell in the app container |
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
