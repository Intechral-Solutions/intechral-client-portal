# ADR-005: Organization-Scoped Multi-Tenancy

**Date:** 2024-03-24
**Status:** Accepted
**Amended:** 2026-06-30 by EPIC-010B to match the implemented schema; 2026-11-10 by R1-2 (Ticket attribution), see [Amendment 2](#amendment-2-r1-2-2026-11-10-ticket-scoping-is-policy-based-with-a-direct-attribution-column-no-organizationscope)

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

## Amendment 2 (R1-2, 2026-11-10): Ticket scoping is policy-based with a direct attribution column; no `OrganizationScope`

The text above is the historical decision and is kept. Where it says a model "must document whether scoping is direct, relational, or policy-based", this amendment documents `Ticket`, and corrects the earlier statement (EPIC-010B, 2026-06-30) that tickets "do not contain `organization_id`".

**Current Release 1.0 behaviour.**
- `tickets.organization_id` (NOT NULL, foreign key to `organizations.id`, `ON DELETE/UPDATE RESTRICT`) records which customer organization a Ticket belongs to. `tickets.submitted_by_id` (NOT NULL, foreign key to `users.id`, RESTRICT) records the authenticated actor who submitted it. `tickets.user_id` stays the **requester**. `tickets.company_id` was never written and is dropped.
- Both columns are set once, inside the creation transaction, from a server-side decision (`ServiceRequestAccess::attributionFor`), and are immutable afterwards (model guards; not mass-assignable). The browser may *name* an organization; it is never trusted to decide one.
- **Attribution is not access.** The column answers "which organization is this Ticket for?"; it does **not** answer "who may see it?". Visibility is unchanged: the requester, or an operator holding `tickets.assign` (`TicketPolicy::viewAsRequesterOrOperator`). Organization membership grants no Ticket visibility, and `tickets.view_org` stays reserved and unused (EPIC-010D D1 is not reopened).
- **No `OrganizationScope` on `Ticket`, deliberately.** The scope is a read filter that cannot decide "may this actor file for that organization", and a global scope on `Ticket` would silently change the requester and operator queries. Scoping for `Ticket` is therefore **policy-based**, with the direct column as data. Any later organization-level read (R1-4 partner support) is a separate, explicitly reviewed ability, not a scope retrofitted onto this column.

**Consequences.**
- Creation requires a *current* membership of the chosen organization, checked under shared locks on the organization and membership rows (the lock order `OrganizationMembership` uses), so a concurrent removal cannot slip a Ticket through. An unknown and a foreign organization return the same refusal.
- The column survives later membership removal: a former member still sees their own Tickets with the organization name (owner decision E5). Membership rows are not a historical record; the Ticket's own column is.
- Existing Tickets cannot be attributed (no provable organization). The migration refuses a populated `tickets` table and lists counts only; disposable environments are rebuilt. There is no backfill and none is invented.

Design record: [Release 1.0: R1-2 Ticket organization attribution](../../product/release-1.0-r1-2-ticket-attribution.md).
