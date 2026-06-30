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

## Built-in Roles

### `operator` (Platform Operator)
- Assigned ALL permissions
- Cannot be deleted or have permissions removed
- Intended for the service provider's internal team

### `user` (Platform User)
- Minimal default permissions:
  - `tickets.view`, `tickets.create`
  - `projects.view`
  - `time.log`, `time.view_own`
  - `billing.view` (own invoices only)
  - `cms.view`
- Intended for the service provider's clients

## Custom Roles

Any number of custom roles can be created. Custom roles:
- Have any combination of permissions from the catalogue
- Can be assigned to any user
- Can be deleted only if no users are currently assigned to them

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

Operators bypass these scopes. A non-operator with no memberships receives an empty tenant view. Tickets, projects, invoices, and time entries have no `organization_id` column and continue to use ownership, company/project membership, invoice-client, and policy logic instead of the direct scope.

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

The built-in `user` role receives the `*.view_org` permissions. The `org.*` permissions exist in the catalogue, but organization-admin self-service routes are not yet implemented; current member management is under `crm.manage`.

### Organization Tables

- `organizations` — owned by a user; linked from `crm_companies.organization_id`
- `organization_members` — unique `user_id` + `organization_id`, with `admin` or `member`; users may join multiple organizations

> **Note:** A full org-role table system is not built. The current role is the simple enum on `organization_members`.

### Authorization Check Order

1. Is the user an `operator`? → Full access everywhere.
2. Does the user have the required **platform** permission? → Check passes or fails.
3. For tenant-scoped data, is the record reachable through one of the user's memberships? → Visible or hidden/404.
4. Apply the model policy or ownership rule for the requested action.

## Seeding

Permissions are seeded from `database/seeders/PermissionSeeder.php`, which reads from a central permissions catalogue in `app/Shared/Permissions/PermissionCatalogue.php`. Each module registers its permissions there.

Built-in roles are seeded in `database/seeders/RoleSeeder.php` and called from `DatabaseSeeder.php`.

Organization membership roles are stored on the pivot and are not seeded as Spatie roles.
