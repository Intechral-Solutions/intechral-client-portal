# EPIC-010B: Tenant Scoping Correctness & Regression Coverage

**Status:** Implemented
**Completed:** 2026-06-30

---

## Goal

Correct organization scoping against the implemented membership schema and add MariaDB-backed regression coverage for single-org, multi-org, no-org, and operator access.

## Root Cause

`OrganizationScope` read `$user->organization_id`, but `users` has no such column. Memberships live in the `organization_members` pivot, and no model registered the scope. As a result, tenant-sensitive CRM and organization queries were unfiltered for any non-operator who held the route permission.

## Tenant-Scoping Matrix

| Model | Direct `organization_id` | Boundary after 10B | Assessment / action |
|---|---:|---|---|
| `CrmCompany` | Yes | All organization IDs from `organization_members` | Direct `OrganizationScope`; fixed |
| `CrmContact` | No | `company.organization_id` | Dedicated indirect scope; fixed |
| `Organization` | No | Organization primary key through membership pivot | Dedicated membership scope; fixed |
| `Ticket` | No | Owner or ticket company, plus policy/controller logic | Existing own/org access preserved; company input now tenant-validated |
| `Project` | No | Project membership or linked company¹ | Existing policy/index scoping preserved; company inputs now tenant-validated |
| `Invoice` | No | Invoice client and policy | Existing own-record policy preserved; no generic scope applied |
| `TimeEntry` | No | User ownership plus project/ticket context | Existing ownership/service logic preserved; no generic scope applied |
| Child models | No | Parent resource and policy | No direct scope applied |

¹ **Superseded by EPIC-011E D2 (2026-09):** at the time of this epic, the index query surfaced company-linked projects to non-members, but `ProjectPolicy::view` still denied them, so those rows appeared and 403'd (a defect EPIC-011E's Amendment 1 identified as A5). D2 made the boundary explicit: the company link is visibility **metadata only** and never grants `Project` access on its own. Access is project membership or `projects.admin`, full stop; the index query and every task/milestone link are now intersected with that same policy so no company-linked-but-non-member row is ever rendered.

Unauthenticated/background queries remain unscoped because there is no acting tenant. Operators bypass all three tenant scopes. Non-operators with no memberships match no tenant records.

## What Changed

- Reworked `OrganizationScope` to use a membership subquery and support every joined organization.
- Applied the direct scope only to `CrmCompany`.
- Added `OrganizationMembershipScope` for `Organization`.
- Added `OrganizationThroughCompanyScope` for `CrmContact`.
- Added `AccessibleCrmCompany` validation so guessed company IDs cannot cross tenant boundaries in contact, ticket, or project writes.
- Added regression coverage for Eloquent queries, CRM route binding/listing, and organization member management.
- Corrected architecture, database, RBAC, and CRM documentation that described nonexistent columns and role tables.

## Regression Coverage

- Org A members see Org A companies, contacts, and organization records.
- Org A members do not see or route-bind Org B records.
- Users with no memberships receive an empty tenant view.
- Multi-org users see records for every joined organization.
- Operators see all tenant and unassigned CRM records.
- Cross-tenant member-management routes return 404.
- Cross-tenant company IDs fail validation for contacts, tickets, and projects.

## Verification

```bash
docker compose exec app ./vendor/bin/pest tests/Feature/Crm/TenantScopingTest.php tests/Feature/Crm/CrmTest.php
docker compose exec app ./vendor/bin/pest tests/Feature/Tickets tests/Feature/Projects tests/Feature/Billing
docker compose exec app ./vendor/bin/pest
```

Results:

- CRM and tenant scoping: 29 passed, 69 assertions
- Adjacent tickets, projects, and billing: 101 passed, 217 assertions
- Full MariaDB suite: **233 passed, 561 assertions** in 43.52 seconds

## Follow-Up Boundaries

- Organization-admin self-service invitations and role authorization remain an EPIC-008 gap.
- Ticket detail policy currently preserves owner-only access even when the ticket list includes a same-organization ticket; harmonizing that product rule should be handled separately.
- Invoice and time-entry organization-level access still follow their existing own-record behavior; broader `*.view_org` semantics require a dedicated authorization design.
- Tenant context for unauthenticated queue/console jobs should be made explicit before tenant-sensitive background work is introduced.

## Definition of Done

- [x] Scope uses `organization_members`, not a nonexistent user column
- [x] Multi-org, no-org, and operator behavior covered
- [x] Models without `organization_id` avoid the generic direct scope
- [x] Organization member-management boundary covered
- [x] Cross-tenant CRM company inputs rejected
- [x] Relevant architecture documentation corrected
- [x] Full MariaDB suite green (233/233)
