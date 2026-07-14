<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('roles')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%")
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user)
    {
        $user->load('roles', 'invitation.invitedBy', 'socialAccounts');
        $allRoles = Role::orderBy('name')->get();
        $activity = Activity::causedBy($user)
            ->orWhere('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->latest()
            ->take(20)
            ->get();

        return view('admin.users.show', compact('user', 'allRoles', 'activity'));
    }

    public function updateRoles(Request $request, User $user)
    {
        $validated = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $oldRoles = $user->getRoleNames()->toArray();
        $newRoles = $validated['roles'] ?? [];

        $user->syncRoles($newRoles);

        // Invalidate permission cache for this user
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $added = array_diff($newRoles, $oldRoles);
        $removed = array_diff($oldRoles, $newRoles);

        $changes = [];
        if ($added) {
            $changes[] = 'added: '.implode(', ', $added);
        }
        if ($removed) {
            $changes[] = 'removed: '.implode(', ', $removed);
        }

        if ($changes) {
            activity()->causedBy(auth()->user())
                ->performedOn($user)
                ->withProperties(['roles' => implode('; ', $changes)])
                ->log('updated user roles');
        }

        return back()->with('status', 'Roles updated for '.$user->name.'.');
    }
}
