# Intechral Client Portal

A full-featured client portal platform built with Laravel 11, MariaDB 10, and Tailwind CSS v4. Deployable on cPanel-compatible hosting.

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

**Requirements:** Docker Desktop

```bash
# Clone and start
git clone <repo-url>
cd "Client Portal"
make install

# Open the portal
open http://localhost:8080

# Open email testing UI
open http://localhost:8025
```

See [docs/architecture/docker-setup.md](docs/architecture/docker-setup.md) for full dev environment documentation.

## Tech Stack

| | |
|--|--|
| **Language** | PHP 8.3 |
| **Framework** | Laravel 11 |
| **Database** | MariaDB 10.11 |
| **Cache / Queue** | Redis 7 |
| **CSS** | Tailwind CSS v4 |
| **Build Tool** | Vite + PNPM |
| **Payments** | Stripe |
| **Auth** | Laravel Fortify + Socialite (OpenID) |
| **Authorization** | Spatie Laravel Permission |
| **Testing** | Pest PHP |
| **Dev Env** | Docker Compose |

## Documentation

Full developer documentation is in the [`docs/`](docs/README.md) folder.

## License

Proprietary — Intechral Solutions.
