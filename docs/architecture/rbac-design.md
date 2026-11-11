# RBAC Design

## Library

Authorization is handled by **[Spatie Laravel Permission](https://spatie.be/docs/laravel-permission)**. This provides:
- `roles` and `permissions` tables
- `model_has_roles` and `model_has_permissions` pivot tables
- `HasRoles` trait on the `User` model
- Laravel Gate integration (`$user->can('permission.name')`)

## Permission Naming Convention

All permissions follow the pattern: `{module}.{action}`

Examples:
```
tickets.view
tickets.create
tickets.assign
tickets.admin

billing.view
billing.create
billing.manage
billing.admin

projects.view
projects.create
projects.manage
projects.admin

crm.view
crm.create
crm.manage
crm.admin

time.log
time.view_own
time.view_all
time.manage

tasks.view_all

cms.view
cms.edit
cms.publish
cms.admin

users.view
users.invite
users.manage
users.admin

roles.view
roles.manage
roles.admin

settings.view
settings.manage
```

**Known gap — `time.view_own`:** this permission exists in the catalogue and was granted by default to the `user` role until R1-1 (it is no longer a default), but nothing in the live application currently checks it as an enforcement boundary. Implemented Time behavior is gated by `time.log` (may log/see one's own entries) and `time.view_all` (may see every user's entries); there is no code path where holding or lacking `time.view_own` changes what a request can do. EPIC-011E (Projects and Kanban Migration) reviewed this while auditing Time-adjacent contracts and deliberately did not reinterpret or wire it — that was out of its scope. Future permission-model work should either give `time.view_own` a real enforcement meaning or retire it from the catalogue; until then, treat `time.log` as the operative "see your own time" gate.

**Tasks — `tasks.view_all` and the inert `tasks.view_org` (EPIC-014 WP3):** `tasks.view_all` offers the **All Tasks** view (`TaskPolicy::viewAll`; the `tasks.all` navigation item; `/tasks?view=all`). It is a surface capability, never an object grant: the rows inside All Tasks are exactly the ones `TaskPolicy::view` allows (board tasks of projects the actor may view, and the actor's own standalone tasks), so holding it never reveals another user's standalone task, a project the actor cannot view, or a ticket-kind task. It is granted to `operator` through the usual all-permissions sync and is **not** in the `user` defaults; any role may be given it. `tasks.view_org` lost its only consumer when the "My organization" view was retired (EPIC-014 Q5): it stays in the catalogue, unused (no longer a `user` default since R1-1), as recorded permission debt (EPIC-014 §22 P1), the same treatment `projects.view_org` received in EPIC-011E. Existing development databases need `PermissionSeeder` and `RoleSeeder` re-run to pick up `tasks.view_all`.

**Tasks — object authorization (`TaskPolicy`, EPIC-014 §7):** every `tasks.*` route authorizes through `App\Policies\TaskPolicy`, and the Tasks list computes its row abilities as a batched restatement of it (`TaskRowAbilities`, parity-tested). No permission beyond `tasks.view_all` is involved.

| Ability | Board task (`project_id` set) | Standalone task (no project, no ticket) | Ticket-kind task |
|---|---|---|---|
| `view` | `ProjectPolicy::view` (membership or `projects.admin`) | creator **or** current assignee | `TicketPolicy::view`, internal only: never listed or opened in the Tasks workspace |
| `update`, `delete`, `assign` | `ProjectPolicy::manage` | creator or current assignee | denied |
| `complete`, `reopen` | `ProjectPolicy::manage`, **or** the current assignee while still a project member | creator or current assignee | denied |
| `move` (arbitrary column/position) | `ProjectPolicy::manage` only; never the member-assignee | n/a | n/a |

- **Board tasks delegate** to `ProjectPolicy` through the Gate; `TaskPolicy` adds only the member-assignee Complete/Reopen arm. Assignment alone grants nothing: a departed assignee is denied `view` like any outsider.
- **Assignment targets** are validation, not policy: a board task takes a current project member or null (re-saving an unchanged departed assignee is allowed); a standalone task takes the actor or null, or keeps its unchanged holder (no cross-person standalone assignment). The same rules apply from task detail and from the list's single-row control, whose candidates come only from the projects the actor may assign on.
- A malformed row linked to both a project and a ticket is denied every ability. Standalone delete runs through the shared recorded-time guard like board delete.

## Built-in Roles

### `operator` (Platform Operator)
- Assigned ALL permissions
- Cannot be deleted or have permissions removed
- Intended for the service provider's internal team

### `user` (Platform User)
- Minimal default permissions (the Release 1.0 customer minimum, R1-1):
  - `tickets.view`, `tickets.create`
  - `projects.view`
  - `cms.view`
- No billing, time or organization-wide permission by default. `billing.view` (own invoices only: the list, the page and payment all
  need it, plus ownership) and `time.log` are explicit grants to a direct customer, through a custom role or a direct grant; a
  managed customer organization never inherits them. In pilot and closed mode the customer surface gate still answers 404 for the
  billing and time routes and hides those capabilities from the browser, whatever has been granted; that is a route restriction,
  separate from the capability checks that decide in legacy mode (see the R1-1 section of `customer-pilot-surface.md`).
- Intended for the service provider's clients

**Built-in roles are code-owned (Release 0.9, decision H4).** Their names and permissions are defined in
`App\Shared\Permissions\BuiltInRoles` (from the permission catalogue), the Roles screens show them read-only, and their
stored grants change only through the reviewed `php artisan roles:sync`. See [Role Governance](./role-governance.md).

**The shared permission cache is fenced (Release 0.9, R0.9-1E).** The role-to-permission mapping the library caches in Redis is stored under a key that carries a random epoch held in the database and replaced inside every transaction that changes the mapping, so a revocation that has committed cannot be undone by a slower reader's late cache write. Two operating rules follow: run `php artisan permission:cache-reset` after changing roles or permissions outside the application (SQL, a restore, a deployment), and make no role-permission changes while old and new application versions run together. See [Role Governance](./role-governance.md#permission-cache-generation-fencing-r09-1e).

## Custom Roles

Any number of custom roles can be created. Custom roles:
- Have any combination of permissions from the catalogue
- Can be assigned to any user
- Can be deleted only if no users are currently assigned to them
- Cannot take a built-in role's name (`operator`, `user`, in any letter case)
- Are never touched by the built-in role synchronization

## Last Active Account Administrator (Release 0.9, H3)

At least one **active** (not suspended) user must always hold both `users.view` and `users.manage`, directly or
through a role. Changing a user's roles, changing a role's permissions, deleting a role and suspending a user are each
refused when they would leave none, including under concurrent changes. See
[Account Lifecycle](./account-lifecycle.md#last-active-account-administrator).

## Permission Resolution

A user's effective permissions are the **union** of all permissions from all assigned roles:

```php
// User with roles: ['user', 'billing-reviewer']
// 'user' has: tickets.view, projects.view
// 'billing-reviewer' has: billing.view, billing.manage
// Effective: tickets.view, projects.view, billing.view, billing.manage

$user->can('billing.manage'); // true
$user->can('tickets.create'); // false
```

## Checking Permissions

**In controllers:**
```php
$this->authorize('tickets.create');
// or
abort_unless($request->user()->can('tickets.create'), 403);
```

**In Blade templates:**
```blade
@can('billing.manage')
    <a href="{{ route('billing.create') }}">New Invoice</a>
@endcan
```

**In routes:**
```php
Route::middleware(['auth', 'permission:tickets.view'])->group(function () {
    Route::get('/tickets', [TicketController::class, 'index']);
});
```

## Organization Membership and Scoped Permissions

When a CRM company is promoted to an **Organization**, membership adds a tenant-visibility boundary alongside platform permissions. A user may join multiple organizations.

### Two-Layer Authorization Model

```
Platform layer (Spatie)              Organization membership pivot
───────────────────────              ─────────────────────────────
operator → all platform permissions  admin  → organization admin marker
user     → default permissions       member → regular member marker
custom   → assigned permissions      one user may have many memberships
```

### Data Scoping

Tenant-aware models follow their real schema path:

```php
// Direct tenant key: CrmCompany
$query->whereIn('organization_id', $user->organizations()->select('organizations.id'));

// Indirect paths:
// Organization -> organization_members
// CrmContact   -> company.organization_id
```

Operators bypass these scopes; "operator" is the explicit `portal.operator` eligibility (`CustomerPilotGate::isOperator`), not the built-in role's name (R1-1). A non-operator with no memberships receives an empty tenant view. Projects, invoices, and time entries have no `organization_id` column and continue to use ownership, membership, invoice-client, and policy logic instead of the direct scope. Tickets gained `organization_id` in R1-2 (2026-11-10) as **attribution, not access**: no scope applies to `Ticket`, and visibility stays the requester or an operator holding `tickets.assign` ([ADR-005 Amendment 2](./adr/ADR-005-organization-multitenancy.md#amendment-2-r1-2-2026-11-10-ticket-scoping-is-policy-based-with-a-direct-attribution-column-no-organizationscope)). For `Project` specifically that means `project_members` membership or `projects.admin`: a project's linked CRM company (`project_company`) is visibility metadata only and never grants access on its own (EPIC-011E D2).

### Organization Permissions

Platform permissions decide whether an action is available; organization membership narrows which tenant records can satisfy it. Current tenant-related permissions are:

```
tickets.view_org
projects.view_org
tasks.view_org
billing.view_org
time.view_org
org.invite
org.manage_roles
org.admin
```

The built-in `user` role no longer receives the `*.view_org` permissions (R1-1); they remain in the catalogue, read by no code path, as recorded debt. `tasks.view_org` is inert since EPIC-014 WP3 (see the Tasks note above). The `org.*` permissions exist in the catalogue, but organization-admin self-service routes are not yet implemented; current member management is under `crm.manage`.

### Organization Tables

- `organizations` — owned by a user; linked from `crm_companies.organization_id`
- `organization_members` — unique `user_id` + `organization_id`, with `admin` or `member`; users may join multiple organizations

> **Note:** A full org-role table system is not built. The current role is the simple enum on `organization_members`.

### Authorization Check Order

1. Is the user an `operator`? → Full access everywhere.
2. Does the user have the required **platform** permission? → Check passes or fails.
3. For tenant-scoped data, is the record reachable through one of the user's memberships? → Visible or hidden/404.
4. Apply the model policy or ownership rule for the requested action.

## Project workspace authorization (EPIC-015)

EPIC-015 added **no permission** and changed no role; it introduced named seams over the existing ones ([EPIC-015 §7](../epics/EPIC-015-projects-ux-expansion.md#7-authorization-and-customer-safety-contract)):

- **Settings access** (`ProjectSettingsAccess`) is the accepted A9 conjunction, `ProjectPolicy::manage` **and** `projects.manage`. It decides the Settings action, the Milestones `manage` ability, and on the Overview the budget and the names-and-roles roster, whose keys are absent from the props for everyone else. The Settings routes still enforce it themselves (route middleware plus `authorize('manage')`), and a parity test pins the resolver to a real `projects.edit` request.
- **Project time visibility** (`ProjectTimeAccess`): `time.view_all` sees all of a project's attributed time, `time.log` alone sees only the viewer's own, and neither sees none (the Overview omits its time key; the Project Time tab answers 403 and is not offered). `ProjectPolicy::view` is checked first.
- **New-time eligibility on a board task** (`AccessibleTimeContext`) requires current `ProjectPolicy::view`; a stored assignee alone grants nothing once the user has left the project. Stopping a running timer and editing an entry whose task is unchanged stay allowed.
- **A task linked to both a project and a ticket** has no valid kind: every task ability and new time on it are refused, and it appears in no list or count.
- **Project Tasks** (`TaskQuery::forProject`) is always a subset of `TaskPolicy::view`; milestone Complete/Reopen uses the same A9 rule as the other milestone mutations.

## Seeding

Permissions are seeded from `database/seeders/PermissionSeeder.php`, which reads from a central permissions catalogue in `app/Shared/Permissions/PermissionCatalogue.php`. Each module registers its permissions there. The seeder only creates what is missing.

Built-in roles are defined in `app/Shared/Permissions/BuiltInRoles.php`. `database/seeders/RoleSeeder.php` resets them to that definition **in local and testing environments only** (it refuses anywhere else); production and any data that matters uses `php artisan roles:sync`, a previewed, fingerprint-approved synchronization that revokes only on an explicit approval ([Role Governance](./role-governance.md)). A new permission therefore needs: a constant and an `all()` entry in the catalogue (plus `userDefaults()` if the `user` role should have it), then `roles:sync` on each environment.

Organization membership roles are stored on the pivot and are not seeded as Spatie roles.

## Ticket organization attribution and creation authorization (R1-2, 2026-11-10)

Current Release 1.0 behaviour; it adds no permission and changes no policy.

- **Creating.** `tickets.create` is still the ability. Which organization a Ticket belongs to is decided on the server inside the creation transaction by `ServiceRequestAccess::attributionFor`: the actor must be an unsuspended user holding `tickets.create` and a **current membership** of the organization, whatever the membership's role or approval (approval is read by no customer-facing authorization). A user with one organization is attributed to it without naming it; with several, the request must name one; with none, creation is refused with an explanation and no form is shown. An unknown organization and one the actor does not belong to return the **same** 422.
- **Provenance.** `submitted_by_id` is always the authenticated actor. Today it equals `user_id`; it is a separate column so that a later partner-originated request (R1-5) needs no data migration. Nothing in R1-2 lets anyone submit on behalf of another user.
- **Viewing is unchanged.** `TicketPolicy::view` is requester-or-operator. Membership, approval, `role = admin`, partner or managed relationships grant **nothing** on a Ticket. `tickets.view_org` remains reserved and unused.
- **Presentation.** The requester sees the organization **name** (never an id, never a list of other organizations) on their own tickets; the create page receives only the actor's own memberships. Operators see the organization on the queue and the ticket, can filter the queue by it, and get it in the CSV export and the new-ticket alert.
- **R1-4 obligation (partner support).** Partner-support users must **not** inherit the operator-wide `tickets.assign` ability merely to view or manage supported customer Tickets. `tickets.assign` opens the whole queue, the organization filter (a list of every organization that has a Ticket, so a cross-tenant name list), the CSV export, the bulk actions (which have no per-ticket `authorize`) and assignee eligibility. Partner support needs its own customer-specific grant, designed and reviewed before code (MO-4B / R1-4), and may not be satisfied by widening `TicketPolicy::view` or by handing out `tickets.assign`.

Design record: [Release 1.0: R1-2 Ticket organization attribution](../product/release-1.0-r1-2-ticket-attribution.md).
