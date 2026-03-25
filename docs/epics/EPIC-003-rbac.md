# EPIC-003: Roles, Permissions & Authorization

**Status:** Pending
**Branch:** `epic/003-rbac`
**Goal:** Implement a flexible RBAC system with two built-in roles (Operator, User) and support for custom roles with granular permissions that control visibility and functionality throughout the platform.

---

## User Stories

### STORY-003-01: Built-in Roles
**As a** platform operator,
**I want** two built-in roles (Operator, User) to exist out of the box,
**So that** the platform is usable immediately after installation.

**Acceptance Criteria:**
- [ ] `operator` role has all permissions granted by default
- [ ] `user` role has a minimal read-only permission set
- [ ] Built-in roles cannot be deleted (soft guard in UI and DB)
- [ ] Roles seeded automatically on fresh install

### STORY-003-02: Permission Catalogue
**As a** platform operator,
**I want** a well-defined catalogue of permissions organized by module,
**So that** I can precisely control what each role can do.

**Acceptance Criteria:**
- [ ] Permissions follow the pattern `module.action` (e.g., `tickets.create`, `billing.view`)
- [ ] All permissions are seeded from a central catalogue (not hard-coded)
- [ ] New modules register their permissions via a `PermissionProvider`
- [ ] Permissions are grouped by module in the UI

### STORY-003-03: Custom Role Management
**As a** platform operator,
**I want** to create, edit, and delete custom roles with any combination of permissions,
**So that** I can tailor access for different user groups (e.g., "Billing Viewer").

**Acceptance Criteria:**
- [ ] CRUD interface for custom roles (name, description, permissions)
- [ ] Role name must be unique
- [ ] Deleting a role with assigned users is blocked with a clear message
- [ ] Bulk permission assignment via module groups

### STORY-003-04: User Role Assignment
**As a** platform operator,
**I want** to assign one or more roles to any user,
**So that** their access is determined by their roles' combined permissions.

**Acceptance Criteria:**
- [ ] Operators can assign/remove roles from the user management page
- [ ] A user can hold multiple roles (permissions are union of all assigned roles)
- [ ] Role changes take effect on the user's next request (cache invalidated)
- [ ] Audit log records role changes

### STORY-003-05: Authorization Gates & Policies
**As a** developer,
**I want** Laravel Gates and Policies wired to the permission system,
**So that** authorization checks are consistent across controllers and views.

**Acceptance Criteria:**
- [ ] `$user->can('tickets.create')` works in controllers, views, and API
- [ ] Blade directive `@can('billing.view')` works in templates
- [ ] Unauthorized access returns HTTP 403 with a user-friendly error page
- [ ] All route groups protected by middleware (`permission:`)

---

## Definition of Done

- All acceptance criteria above are checked
- TDD: every gate/policy has corresponding tests
- Spatie Laravel Permission used as the underlying library
- Merged to `main` via PR
