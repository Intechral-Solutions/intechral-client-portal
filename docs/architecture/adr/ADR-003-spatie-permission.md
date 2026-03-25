# ADR-003: Use Spatie Laravel Permission for RBAC

**Date:** 2024-03-24
**Status:** Accepted

## Context

The platform requires a flexible, extensible RBAC system with:
- Named permissions (not just roles)
- Multi-role support per user
- Gate/Policy integration
- Easy seeding and testing

## Decision

Use **Spatie Laravel Permission** (`spatie/laravel-permission`).

## Rationale

- Battle-tested library with 15M+ installs
- Full Gate integration: `$user->can('permission')` works out of the box
- Roles and permissions cached in Redis for performance
- Clean API for seeding: `Permission::create(['name' => 'tickets.create'])`
- Middleware provided: `permission:tickets.create`

## Consequences

- Introduces three additional DB tables (standard Spatie schema)
- Cache must be cleared on role/permission changes (`php artisan permission:cache-reset`)
- Custom roles are managed through Spatie's API, not raw SQL
