# Progress Log

This log tracks development progress sprint by sprint.

| Sprint | Epic | Key Deliverables | Status |
|--------|------|-----------------|--------|
| 1 | EPIC-001 | Git, Docker, Laravel scaffold, Tailwind v4, tests | **Done** |
| 2 | EPIC-002 | Invitation system, local auth, SSO | Pending |
| 3 | EPIC-003 | RBAC, roles, permissions, admin UI | Pending |
| 4 | EPIC-004 | Ticket management full feature | Pending |
| 5 | EPIC-005 | Project management + Kanban | Pending |
| 6 | EPIC-006 | Billing + Stripe | Pending |
| 7 | EPIC-007 | Time tracking | Pending |
| 8 | EPIC-008 | CRM + Organizations | Pending |
| 9 | EPIC-009 | CMS + documentation | Pending |

---

## Sprint 1 — Foundation (DONE — commit `faa6d38`)

**Completed 2026-03-24.**

### Delivered
- [x] Git repository initialized on `main`
- [x] `.gitignore` covering Laravel, PNPM, Docker, and OS artifacts
- [x] Full documentation structure (`docs/`) with all 9 epics, architecture docs, 6 ADRs
- [x] Docker Compose dev stack: PHP-FPM 8.3, Nginx 1.25, MariaDB 10.11, Redis 7, Mailpit, queue worker
- [x] `Makefile` with `install`, `up`, `down`, `test`, `shell`, `fresh`, `lint` targets
- [x] **Laravel 13** scaffolded in `intechral-client-portal/`
- [x] All core packages declared in `composer.json` (Fortify, Socialite, Spatie Permission, Spatie Activity Log, Spatie Media Library, Stripe, DomPDF, Pest)
- [x] Tailwind CSS v4 with CSS-native light/dark theming (`data-theme` attribute, CSS custom properties)
- [x] PNPM + Vite configured (package.json with `packageManager` field, Vite config unchanged from Laravel default)
- [x] Application module directory structure: `app/Modules/{Auth,Tickets,Projects,Billing,TimeTracking,CRM,CMS}/`
- [x] `PermissionCatalogue` — single source of truth for all platform permissions
- [x] `OrganizationScope` — Eloquent global scope for organization-level data isolation
- [x] Database seeders: `PermissionSeeder`, `RoleSeeder`, `DevSeeder`, `DatabaseSeeder`
- [x] Base Blade layout (`layouts/app.blade.php`) with nav, theme switcher, role-gated menu items, footer
- [x] Pest PHP configured (`tests/Pest.php`, `tests/bootstrap.php`)
- [x] Foundational tests: application smoke test, `PermissionCatalogue` unit tests, guest middleware test

### Known Follow-ups for Sprint 2
- [ ] Run `make install` to verify full stack boots end-to-end (Docker + migrations + seeds)
- [ ] Install Composer packages (run `composer install` inside the container — current `vendor/` is base Laravel only, added packages are in `composer.json` awaiting `composer update`)
- [ ] Run `pnpm install` inside container
- [ ] Verify `php artisan test` passes green with Pest

---

## Sprint 2 — Authentication (Next)

**Scope:** EPIC-002 — invitation system, local registration, OpenID SSO, session management, 2FA.

Branch: `epic/002-authentication`
