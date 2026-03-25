# EPIC-001: Project Foundation & Dev Environment

**Status:** In Progress
**Branch:** `epic/001-foundation`
**Goal:** Establish a fully working local dev environment, a production-ready Laravel project structure, Tailwind CSS v4 theming, and the testing infrastructure that all subsequent epics depend on.

---

## User Stories

### STORY-001-01: Docker Dev Environment
**As a** developer,
**I want** a single `docker compose up` command to bring up the full stack,
**So that** I can develop without installing PHP, Composer, or MariaDB locally.

**Acceptance Criteria:**
- [ ] `docker compose up -d` starts PHP-FPM 8.3, Nginx, MariaDB 10, Redis, and Mailpit
- [ ] `http://localhost:8080` serves the Laravel welcome page
- [ ] `http://localhost:8025` serves the Mailpit web UI
- [ ] `docker compose exec app composer install` works without errors
- [ ] `.env` is pre-populated from `.env.example` with dev defaults

### STORY-001-02: Laravel Application Scaffold
**As a** developer,
**I want** a clean Laravel 11 installation configured for this project,
**So that** I have a solid, idiomatic starting point.

**Acceptance Criteria:**
- [ ] Laravel 11 installed via Composer
- [ ] Database connection uses MariaDB container
- [ ] `php artisan migrate` runs successfully
- [ ] `php artisan key:generate` is handled in entrypoint
- [ ] Cache and session drivers set to `redis`

### STORY-001-03: Tailwind CSS v4 with Theming
**As a** developer,
**I want** Tailwind CSS v4 configured with a CSS-native theming system,
**So that** the UI supports light/dark modes and extensible custom themes.

**Acceptance Criteria:**
- [ ] Tailwind CSS v4 installed and building via Vite
- [ ] CSS custom properties define a `light` theme (default) and a `dark` theme
- [ ] Dark mode toggles via a `data-theme="dark"` attribute on `<html>`
- [ ] Design tokens (color palette, typography scale, spacing) are documented
- [ ] A theme switcher component exists in the base layout

### STORY-001-04: Testing Infrastructure
**As a** developer,
**I want** PHPUnit/Pest configured with feature and unit test suites,
**So that** every subsequent feature can be TDD-driven from the start.

**Acceptance Criteria:**
- [ ] Pest PHP installed as the test runner
- [ ] Separate `Unit` and `Feature` test suites configured in `phpunit.xml`
- [ ] Test database uses SQLite in-memory (fast) or a dedicated MariaDB test DB
- [ ] `php artisan test` passes with zero errors on a fresh checkout
- [ ] GitHub Actions (or equivalent CI) runs tests on every push

### STORY-001-05: Git Repository & Branch Strategy
**As a** developer,
**I want** a clean git history with a documented branch strategy,
**So that** the team can collaborate without conflicts.

**Acceptance Criteria:**
- [ ] `main` branch contains only production-ready code
- [ ] Each epic has a long-lived `epic/NNN-name` branch
- [ ] Feature branches are named `feature/NNN-short-description`
- [ ] Conventional commits are used (`feat:`, `fix:`, `test:`, `docs:`, `chore:`)
- [ ] `.gitignore` covers Laravel, Node, Docker, and OS artifacts

---

## Definition of Done

- All acceptance criteria above are checked
- `docker compose up` → `php artisan test` → all green
- Documentation updated in `docs/progress/`
- Merged to `main` via PR with at least one review
