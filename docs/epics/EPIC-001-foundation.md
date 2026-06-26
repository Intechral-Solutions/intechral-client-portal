# EPIC-001: Project Foundation & Dev Environment

**Status:** Implemented
**Committed:** 2026-03-24

---

## Goal

Establish a fully working local dev environment, a production-ready Laravel project structure, Tailwind CSS v4 theming, and the testing infrastructure that all subsequent epics depend on.

---

## User Stories

### STORY-001-01: Docker Dev Environment
**As a** developer,
**I want** a single `docker compose up` command to bring up the full stack,
**So that** I can develop without installing PHP, Composer, or MariaDB locally.

**Acceptance Criteria:**
- [x] `docker compose up -d` starts PHP-FPM 8.3, Nginx, MariaDB 10, Redis, and Mailpit
- [x] `http://localhost:8080` serves the Laravel welcome page
- [x] `http://localhost:8025` serves the Mailpit web UI
- [x] `docker compose exec app composer install` works without errors
- [x] `.env` is pre-populated from `.env.example` with dev defaults

### STORY-001-02: Laravel Application Scaffold
**As a** developer,
**I want** a clean Laravel 13 installation configured for this project,
**So that** I have a solid, idiomatic starting point.

**Acceptance Criteria:**
- [x] Laravel 13 installed via Composer
- [x] Database connection uses MariaDB container
- [x] `php artisan migrate` runs successfully
- [x] `php artisan key:generate` is handled in entrypoint
- [x] Cache and session drivers set to `redis`

### STORY-001-03: Tailwind CSS v4 with Theming
**As a** developer,
**I want** Tailwind CSS v4 configured with a CSS-native theming system,
**So that** the UI supports light/dark modes and extensible custom themes.

**Acceptance Criteria:**
- [x] Tailwind CSS v4 installed and building via Vite
- [x] CSS custom properties define a `light` theme (default) and a `dark` theme
- [x] Dark mode toggles via a `data-theme="dark"` attribute on `<html>`
- [x] Design tokens (color palette, typography scale, spacing) are documented
- [x] A theme switcher component exists in the base layout

### STORY-001-04: Testing Infrastructure
**As a** developer,
**I want** PHPUnit/Pest configured with feature and unit test suites,
**So that** every subsequent feature can be TDD-driven from the start.

**Acceptance Criteria:**
- [x] Pest PHP installed as the test runner
- [x] Separate `Unit` and `Feature` test suites configured in `phpunit.xml`
- [ ] Test database uses SQLite in-memory (fast) or a dedicated MariaDB test DB
- [x] `php artisan test` passes with zero errors on a fresh checkout
- [ ] GitHub Actions (or equivalent CI) runs tests on every push

### STORY-001-05: Git Repository & Branch Strategy
**As a** developer,
**I want** a clean git history with a documented branch strategy,
**So that** the team can collaborate without conflicts.

**Acceptance Criteria:**
- [x] `main` branch contains only production-ready code
- [x] `.gitignore` covers Laravel, Node, Docker, and OS artifacts
- [ ] Each epic has a long-lived `epic/NNN-name` branch
- [ ] Feature branches are named `feature/NNN-short-description`
- [x] Conventional commits are used (`feat:`, `fix:`, `test:`, `docs:`, `chore:`)

---

## Implementation

### What Was Built

**Docker Dev Stack**
- `docker-compose.yml`: PHP-FPM 8.3, Nginx 1.25, MariaDB 10.11, Redis 7, Mailpit, queue worker
- Entrypoint handles `composer install`, `key:generate`, `migrate`, and `storage:link` on first boot
- Root `package.json` npm scripts: `npm run up`, `down`, `shell`, `fresh`, `test`, `install`

**Laravel 13 Application**
- Scaffolded in `intechral-client-portal/` (nested under repo root)
- Module directory structure: `app/Modules/{Auth,Tickets,Projects,Billing,TimeTracking,CRM,CMS}/`
- `PermissionCatalogue` — single source of truth for all platform permissions
- `OrganizationScope` — Eloquent global scope for org-level data isolation

**Tailwind CSS v4 + Theming**
- CSS custom properties for `light` / `dark` themes; toggles via `data-theme="dark"` on `<html>`
- Theme switcher component in base nav

**Database Seeders**
- `PermissionSeeder`, `RoleSeeder` (operator + user built-in roles), `DevSeeder`, `DatabaseSeeder`

**Base Blade Layout**
- `layouts/app.blade.php` with nav, theme switcher, role-gated menu items, footer
- `errors/403.blade.php`

**Testing Infrastructure**
- Pest PHP configured (`tests/Pest.php`, `tests/bootstrap.php`)
- Foundation smoke tests: app boots, `PermissionCatalogue` unit tests, guest middleware

**Documentation**
- Full `docs/` tree: 9 epic files, architecture docs (system overview, auth flow, database schema, RBAC design, Docker setup), 6 ADRs

### Known Gaps

- GitHub Actions CI not yet configured
- Test database isolation strategy not confirmed (SQLite vs dedicated MariaDB test DB)
- Epic/feature branching strategy not followed in practice — all work committed to `main`

---

## Definition of Done

- All acceptance criteria above are checked
- `docker compose up` → `php artisan test` → all green
- Documentation updated
- Merged to `main` via PR with at least one review
