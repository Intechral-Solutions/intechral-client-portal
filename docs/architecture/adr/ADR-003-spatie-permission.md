# ADR-003: Use Spatie Laravel Permission for RBAC

**Date:** 2024-03-24
**Status:** Accepted
**Amended:** 2026-11-18 by ORG-0 ([ADR-011](./ADR-011-organization-consolidation-and-lifecycle.md)), see [Amendment 1](#amendment-1-org-0-2026-11-18-spatie-teams-stay-off-and-organization-roles-are-not-spatie-roles)

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

## Amendment 1 (ORG-0, 2026-11-18): Spatie `teams` stay off, and Organization roles are not Spatie roles

The decision above stands: Spatie Laravel Permission is the platform's global RBAC. [ADR-011 §9](./ADR-011-organization-consolidation-and-lifecycle.md#9-future-organization-roles-contract-future) records, as a **future contract** (nothing is built in Release 1.0), where it stops:

- **Spatie `teams` stay off.** Turning them on would filter every role lookup by a current team, and existing assignments have none, so every account would lose its permissions until a team were set on every request, job and command. Raw-SQL readers of roles (`AccountAdministrators`, `OrganizationAuthority`) ignore teams and would be wrong.
- **Organization-scoped roles are not Spatie roles.** They live in separate Organization-owned definitions with their own permission vocabulary and one resolver that always takes a User and an Organization. The global catalogue is a flat list with no scope, tier or delegability metadata and is **not** reused as an unchecked Organization-role grant mechanism; a global permission does not acquire Organization scope by reuse, and `portal.operator` (the trust anchor that bypasses the tenant scopes) can never be part of a template.
- **No unused global permission is activated.** The `org.*` and `*.view_org` permissions that no code checks stay unused (removal is separate, later work). Operator permissions keep meaning "all organizations" inside the policies.
- Four layers stay apart: global platform authority; centrally managed delegation envelopes or templates; Organization-scoped role definitions; membership-specific assignments.
