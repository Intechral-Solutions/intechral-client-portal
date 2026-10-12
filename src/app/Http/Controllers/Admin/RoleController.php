<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccessGrants;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly AccessGrants $grants) {}

    public function index()
    {
        $roles = Role::withCount('users')->orderBy('name')->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        $grouped = $this->groupedPermissions();

        return view('admin.roles.create', compact('grouped'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:roles,name', function ($attribute, $value, $fail) {
                // The built-in role names are reserved (H4), so a custom role cannot pose as one before they exist.
                if (is_string($value) && BuiltInRoles::isBuiltIn($value)) {
                    $fail('That name is reserved for a built-in role.');
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        activity()->causedBy(auth()->user())
            ->performedOn($role)
            ->log('created role');

        return redirect()->route('roles.index')
            ->with('status', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role)
    {
        $grouped = $this->groupedPermissions();
        $assigned = $role->permissions->pluck('name')->toArray();
        $isBuiltIn = BuiltInRoles::isBuiltIn($role->name);

        return view('admin.roles.edit', compact('role', 'grouped', 'assigned', 'isBuiltIn'));
    }

    public function update(Request $request, Role $role)
    {
        // Release 0.9 (H4): built-in roles are owned by the application, so this is refused before anything else.
        $this->grants->assertCustom($role);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Release 0.9 (H3): refused if it would remove the last active account administrator's users.view or
        // users.manage.
        $this->grants->syncRolePermissions($role, $validated['permissions'] ?? []);

        activity()->causedBy(auth()->user())
            ->performedOn($role)
            ->log('updated role permissions');

        return redirect()->route('roles.index')
            ->with('status', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role)
    {
        // Release 0.9 (H4): a built-in role is refused here, ahead of the guarded delete.
        $this->grants->assertCustom($role);

        // Release 0.9 (H3): the "no holders" check and the delete are one guarded step (AccessGrants).
        $assigned = $this->grants->deleteUnassignedRole($role, fn () => activity()->causedBy(auth()->user())
            ->log("deleted role \"{$role->name}\""));

        if ($assigned > 0) {
            return back()->withErrors(['role' => "Cannot delete \"{$role->name}\" — it is assigned to {$assigned} user(s). Reassign them first."]);
        }

        return redirect()->route('roles.index')
            ->with('status', "Role \"{$role->name}\" deleted.");
    }

    private function groupedPermissions(): array
    {
        $all = PermissionCatalogue::all();
        $grouped = [];

        foreach ($all as $permission) {
            [$module] = explode('.', $permission, 2);
            $grouped[$module][] = $permission;
        }

        return $grouped;
    }
}
