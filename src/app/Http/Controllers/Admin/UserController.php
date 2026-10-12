<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function __construct(
        private readonly AccessGrants $grants,
        private readonly AccountLifecycleService $lifecycle,
    ) {}

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
            ->with('causer')
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

        // Release 0.9 (H3): refused if it would demote the last active account administrator.
        $this->grants->syncUserRoles($user, $newRoles);

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

    /** Release 0.9 (H3): suspend an account. Refused for one's own account and for the last account administrator. */
    public function suspend(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $suspended = $this->lifecycle->suspend($user, $request->user(), $validated['reason']);

        return back()->with('status', $suspended
            ? $user->name.' has been suspended and signed out everywhere.'
            : $user->name.' is already suspended.');
    }

    /** Release 0.9 (H3): reactivate a suspended account. Grants nothing; the user signs in afresh. */
    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reactivated = $this->lifecycle->reactivate($user, $request->user(), $validated['reason'] ?? null);

        return back()->with('status', $reactivated
            ? $user->name.' has been reactivated. They can sign in again.'
            : $user->name.' is already active.');
    }
}
