# ADR-005: Organization-Scoped Multi-Tenancy

**Date:** 2024-03-24
**Status:** Accepted

## Context

Client organizations need to:
- Have their users see only their organization's data (tickets, projects, billing, time)
- Allow organization admins to invite new users to their org
- Define organization-level roles that scope permissions within the org's data

This requires a lightweight multi-tenancy layer without a full multi-tenant framework.

## Decision

Implement **organization scoping** using a `organization_id` foreign key on all resource tables, enforced through **Eloquent global scopes** on each model. Organization-level roles are stored in a separate `organization_roles` table, distinct from platform roles (Spatie).

## Architecture

```
users
  └── organization_id (FK → organizations)

organizations
  └── company_id (FK → companies, 1:1)

organization_roles (org-scoped, separate from Spatie roles)
  └── organization_id
  └── name, permissions (JSON)

organization_role_users
  └── user_id, organization_role_id, organization_id

tickets / projects / invoices / time_entries
  └── organization_id (nullable — platform-operator-only records have null)
```

## Global Scope Implementation

Each scoped model (Ticket, Project, Invoice, TimeEntry) applies `OrganizationScope`:

```php
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if ($user && $user->organization_id && !$user->hasRole('operator')) {
            $builder->where($model->getTable().'.organization_id', $user->organization_id);
        }
    }
}
```

## Rationale

- Shared database, single schema — simpler than row-level security or schema-per-tenant
- Global scopes ensure data isolation without modifying every query manually
- Platform operators bypass scoping (they see everything)
- Organization admins manage their own user base independently of platform operators
- Avoids heavy multi-tenant packages (Tenancy for Laravel) which are overkill for this use case

## Consequences

- All new resource tables must include `organization_id` (nullable for operator-only records)
- Seeds must set `organization_id` on test fixtures
- The `WithoutOrganizationScope` trait must be used in admin operations that query across orgs
- Reporting for platform operators must explicitly remove the scope
