<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountLifecycleService;
use App\Services\UserMembershipList;
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

    /** The account-type and status filters the list accepts; anything else is ignored, never an error. */
    public const TYPES = ['operator', 'customer'];

    public const STATUSES = ['active', 'suspended'];

    public function index(Request $request)
    {
        // A repeated or array-shaped parameter (`?search[]=x`) is ignored like an unknown filter value, never a type error.
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;

        $users = User::with('roles')
            // The name/email alternatives are ONE grouped predicate: a bare `where(name) or where(email)` would let the email branch
            // escape every filter added after it (an operator filter would list customers whose email matched).
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($type !== null, fn ($q) => $q->operatorEligible($type === 'operator'))
            ->when($status !== null, fn ($q) => $status === 'suspended'
                ? $q->whereNotNull('disabled_at')
                : $q->active())
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // One query for the page's memberships, never one per row.
        $memberships = UserMembershipList::forUsers($users->getCollection()->pluck('id')->all());

        return view('admin.users.index', [
            'users' => $users,
            'memberships' => $memberships,
            'search' => $search,
            'type' => $type,
            'status' => $status,
        ]);
    }

    public function show(User $user)
    {
        $user->load('roles', 'organizationInvitation.organization', 'organizationInvitation.inviter', 'socialAccounts');
        $allRoles = Role::orderBy('name')->get();
        $activity = Activity::causedBy($user)
            ->orWhere('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->with('causer')
            ->latest()
            ->take(20)
            ->get();
        $memberships = UserMembershipList::forUser($user);

        return view('admin.users.show', compact('user', 'allRoles', 'activity', 'memberships'));
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
        $this->grants->syncUserRoles($user, $newRoles, $request->user());

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
