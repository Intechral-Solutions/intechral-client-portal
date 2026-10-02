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

**Known gap — `time.view_own`:** this permission exists in the catalogue and is granted by default to the `user` role (see below), but nothing in the live application currently checks it as an enforcement boundary. Implemented Time behavior is gated by `time.log` (may log/see one's own entries) and `time.view_all` (may see every user's entries); there is no code path where holding or lacking `time.view_own` changes what a request can do. EPIC-011E (Projects and Kanban Migration) reviewed this while auditing Time-adjacent contracts and deliberately did not reinterpret or wire it — that was out of its scope. Future permission-model work should either give `time.view_own` a real enforcement meaning or retire it from the catalogue; until then, treat `time.log` as the operative "see your own time" gate.

**Tasks — `tasks.view_all` and the inert `tasks.view_org` (EPIC-014 WP3):** `tasks.view_all` offers the **All Tasks** view (`TaskPolicy::viewAll`; the `tasks.all` navigation item; `/tasks?view=all`). It is a surface capability, never an object grant: the rows inside All Tasks are exactly the ones `TaskPolicy::view` allows (board tasks of projects the actor may view, and the actor's own standalone tasks), so holding it never reveals another user's standalone task, a project the actor cannot view, or a ticket-kind task. It is granted to `operator` through the usual all-permissions sync and is **not** in the `user` defaults; any role may be given it. `tasks.view_org` lost its only consumer when the "My organization" view was retired (EPIC-014 Q5): it stays in the catalogue and the `user` defaults, unused, as recorded permission debt (EPIC-014 §22 P1), the same treatment `projects.view_org` received in EPIC-011E. Existing development databases need `PermissionSeeder` and `RoleSeeder` re-run to pick up `tasks.view_all`.

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

Operators bypass these scopes. A non-operator with no memberships receives an empty tenant view. Tickets, projects, invoices, and time entries have no `organization_id` column and continue to use ownership, membership, invoice-client, and policy logic instead of the direct scope. For `Project` specifically that means `project_members` membership or `projects.admin`: a project's linked CRM company (`project_company`) is visibility metadata only and never grants access on its own (EPIC-011E D2).

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

The built-in `user` role receives the `*.view_org` permissions. `tasks.view_org` is inert since EPIC-014 WP3 (see the Tasks note above). The `org.*` permissions exist in the catalogue, but organization-admin self-service routes are not yet implemented; current member management is under `crm.manage`.

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
