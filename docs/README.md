# Intechral Client Portal — Developer Documentation

Welcome to the development documentation for the **Intechral Client Portal**. This documentation tracks architecture decisions, epic planning, progress, and API references from project inception to completion.

## Project Overview

The Intechral Client Portal is a multi-tenant SaaS platform built on Laravel 13 / PHP 8.3 and MariaDB 10, designed to be deployable on cPanel-compatible hosting environments. It provides:

- Invitation-based user registration with local credentials **or** OpenID SSO
- Role-based access control (RBAC) with built-in Operator and User roles plus fully custom roles
- Ticket Management
- Project Management
- Billing & Invoicing
- Time Tracking
- CRM (Customer Relationship Management)
- CMS (Content Management / Documentation)

## Documentation Index

| Section | Description |
|---------|-------------|
| [Architecture](./architecture/README.md) | System design, ADRs, database schema |
| [Epics](./epics/README.md) | Development epics and story breakdowns |
| [Progress](./progress/README.md) | Sprint-by-sprint progress log |
| [API](./api/README.md) | Internal and external API documentation |

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.3 |
| Framework | Laravel 13 |
| Database | MariaDB 10 |
| Cache / Queue | Redis |
| CSS | Tailwind CSS v4 |
| Auth | Laravel Fortify + Laravel Socialite (OpenID) |
| Authorization | Spatie Laravel Permission |
| Testing | PHPUnit + Pest |
| Dev Environment | Docker Compose |
| Deployment Target | cPanel (shared/VPS) |

## Development Workflow

1. All features are gated behind **epics** — no work begins without an epic and stories defined.
2. Every feature is driven by **TDD** — tests are written before implementation.
3. Commits are scoped to epics using conventional commit prefixes (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
4. Docker is used for the local dev environment; production targets cPanel/PHP-FPM.

## Getting Started

See [Docker Setup](./architecture/docker-setup.md) for bringing up the local dev environment.
