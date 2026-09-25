# ADR-005: Organization-Scoped Multi-Tenancy

**Date:** 2024-03-24
**Status:** Accepted
**Amended:** 2026-06-30 by EPIC-010B to match the implemented schema

## Context

Client organizations need to:
- Have their users see only their organization's data (tickets, projects, billing, time)
- Allow organization admins to invite new users to their org
- Define organization-level roles that scope permissions within the org's data

This requires a lightweight multi-tenancy layer without a full multi-tenant framework.

## Decision

Implement lightweight organization scoping in the shared database using the relationship actually present for each resource. `crm_companies.organization_id` is the direct tenant key; organizations scope through `organization_members`; contacts scope through their CRM company. Models without `organization_id` use ownership, membership, invoice-client, or policy logic and must not receive the direct-column global scope.

**Current state (EPIC-011E D2, 2026-09):** for `Project`, that mechanism is `project_members` membership or `projects.admin` — never the project's linked CRM company. A project-to-company link (`project_company`) is visibility **metadata only**; it does not, by itself, grant a user in that company's organization access to the project.

## Architecture

```
users
  └── organization_members (many-to-many)

organizations
  ├── owner_id (FK → users)
  └── organization_members.role (admin/member)

crm_companies
  └── organization_id (nullable FK → organizations)

crm_contacts
  └── crm_company_id (nullable FK → crm_companies)
```

## Global Scope Implementation

Only a model with a direct `organization_id` column applies `OrganizationScope`:

```php
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Operators bypass tenant filtering.
        // Other users match every organization_members row they own.
        $builder->whereIn('organization_id', $membershipOrganizationIds);
    }
}
```

## Rationale

- Shared database, single schema — simpler than row-level security or schema-per-tenant
- Global scopes protect tenant-aware model queries and implicit route binding
- Platform operators bypass scoping (they see everything)
- Multi-organization membership is supported by the pivot rather than a user column
- Avoids heavy multi-tenant packages (Tenancy for Laravel) which are overkill for this use case

## Consequences

- New tenant-aware models must document whether scoping is direct, relational, or policy-based
- A direct scope may only be registered on a table that actually contains `organization_id`
- Non-operators with no memberships must receive an empty tenant view, never an unfiltered query
- Cross-tenant identifiers accepted from requests must be validated through scoped Eloquent queries
