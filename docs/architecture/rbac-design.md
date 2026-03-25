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

## Organization-Scoped Roles

When a CRM company is promoted to an **Organization**, its users gain a second layer of authorization: **organizational roles**. These are separate from platform roles and only govern access within the organization's own data.

### Two-Layer Authorization Model

```
Platform Layer (Spatie roles)         Organization Layer (org_roles)
─────────────────────────────         ──────────────────────────────
operator  → all platform perms        org_admin  → all org perms
user      → minimal platform perms    org_member → view + create within org
custom    → any platform perms        custom     → any org-scoped perms
```

### Data Scoping

All modules scope queries by `organization_id` for organization members:

```php
// In a base scope applied to Ticket, Project, Invoice, TimeEntry, etc.:
if ($user->organization_id && !$user->hasRole('operator')) {
    $query->where('organization_id', $user->organization_id);
}
```

### Organization Permissions

Org-scoped permissions follow the pattern `org.{module}.{action}`:

```
org.tickets.view
org.tickets.create
org.projects.view
org.billing.view
org.time.log
org.invite            ← invite users to the org
org.manage_roles      ← manage org-level roles
org.admin             ← all of the above
```

### Organization Tables

- `organizations` — linked 1:1 with a CRM company
- `organization_users` — `user_id`, `organization_id`, pivot
- `organization_roles` — org-scoped roles (`org_admin`, `org_member`, custom)
- `organization_role_users` — assignment of org roles to users within an org

### Authorization Check Order

1. Is the user an `operator`? → Full access everywhere.
2. Does the user have the required **platform** permission? → Check passes or fails.
3. For org-scoped data, is the record's `organization_id` the user's org? → Gate passes or 403.
4. Does the user have the required **org** permission? → Check passes or fails.

## Seeding

Permissions are seeded from `database/seeders/PermissionSeeder.php`, which reads from a central permissions catalogue in `app/Shared/Permissions/PermissionCatalogue.php`. Each module registers its permissions there.

Built-in roles are seeded in `database/seeders/RoleSeeder.php` and called from `DatabaseSeeder.php`.

Default organization roles (`org_admin`, `org_member`) are seeded in `database/seeders/OrganizationRoleSeeder.php`.
