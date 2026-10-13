<?php

use App\Exceptions\LastAccountAdministratorException;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;

/*
 * Release 0.9 (H3, R0.9-1C): suspending and reactivating an account through the operator action. Suspension keeps the
 * user, their records, attribution and grants; it signs them out everywhere and records who, when and why. Reactivation
 * grants nothing. See docs/architecture/account-lifecycle.md.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create(['name' => 'Olive Operator']);
    $this->operator->assignRole('operator');
});

function addSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest', 'payload' => 'payload', 'last_activity' => now()->timestamp,
    ]);
}

/** The grants a user holds, as rows: [roles, direct permissions]. */
function grantRows(User $user): array
{
    return [
        DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->orderBy('role_id')->pluck('role_id')->all(),
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->orderBy('permission_id')->pluck('permission_id')->all(),
    ];
}

/** Everything a suspension or reactivation would change for $user, so a refusal can be shown to change none of it. */
function accountState(User $user): array
{
    return [
        (array) DB::table('users')->where('id', $user->id)->first(['disabled_at', 'remember_token']),
        DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all(),
        DB::table('password_reset_tokens')->where('email', $user->email)->exists(),
        Activity::count(),
    ];
}

describe('authorization', function () {
    it('redirects guests to login', function () {
        $target = User::factory()->create();

        $this->post(route('users.suspend', $target), ['reason' => 'x'])->assertRedirect('/login');
        $this->post(route('users.reactivate', $target))->assertRedirect('/login');
        expect($target->fresh()->isSuspended())->toBeFalse();
    });

    it('is forbidden without users.manage, even with users.view', function () {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('users.view');
        $target = User::factory()->create();
        $suspended = User::factory()->suspended()->create();

        $this->actingAs($viewer)->post(route('users.suspend', $target), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('users.reactivate', $suspended))->assertForbidden();

        expect($target->fresh()->isSuspended())->toBeFalse()
            ->and($suspended->fresh()->isSuspended())->toBeTrue();
    });

    it('is forbidden without users.view, even with users.manage', function () {
        $manager = User::factory()->create();
        $manager->givePermissionTo('users.manage');
        $target = User::factory()->create();

        $this->actingAs($manager)->post(route('users.suspend', $target), ['reason' => 'x'])->assertForbidden();
        expect($target->fresh()->isSuspended())->toBeFalse();
    });
});

describe('suspending', function () {
    it('suspends, signs the user out everywhere and records who, when and why', function () {
        $target = User::factory()->create(['name' => 'Sam Target']);
        $target->assignRole('user');
        addSession($target, 'target-session-a');
        addSession($target, 'target-session-b');
        $otherSession = User::factory()->create();
        addSession($otherSession, 'bystander-session');
        $token = $target->getRememberToken();

        $this->travelTo(now()->startOfSecond());
        $this->actingAs($this->operator)
            ->from(route('users.show', $target))
            ->post(route('users.suspend', $target), ['reason' => 'Left the client company'])
            ->assertRedirect(route('users.show', $target))
            ->assertSessionHas('status', 'Sam Target has been suspended and signed out everywhere.');

        $fresh = $target->fresh();
        expect($fresh->disabled_at->equalTo(now()))->toBeTrue()
            ->and($fresh->getRememberToken())->not->toBe($token)
            ->and(DB::table('sessions')->where('user_id', $target->id)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('id', 'bystander-session')->exists())->toBeTrue();

        $log = Activity::where('description', AccountLifecycleService::SUSPENDED)->sole();
        expect($log->causer_id)->toBe($this->operator->id)
            ->and($log->causer_type)->toBe(User::class)
            ->and($log->subject_id)->toBe($target->id)
            ->and($log->subject_type)->toBe(User::class)
            ->and($log->properties->get('reason'))->toBe('Left the client company')
            ->and($log->created_at->equalTo(now()))->toBeTrue();
    });

    it('keeps the user row, id, roles, direct permissions and every business record and attribution', function () {
        $target = User::factory()->create();
        $target->assignRole('user');
        $target->givePermissionTo('projects.manage');
        $grants = grantRows($target);

        $ownTicket = Ticket::factory()->create(['user_id' => $target->id]);
        $assignedTicket = Ticket::factory()->create(['assignee_id' => $target->id]);
        $reply = TicketReply::factory()->create(['user_id' => $target->id, 'ticket_id' => $ownTicket->id]);
        $project = Project::factory()->create(['created_by' => $target->id]);
        $project->members()->attach($target->id, ['role' => 'manager']);
        $task = Task::factory()->standalone()->create(['created_by' => $target->id, 'assignee_id' => $target->id]);
        $entry = TimeEntry::factory()->create(['user_id' => $target->id]);

        $snapshot = fn () => [
            DB::table('users')->where('id', $target->id)->first(['id', 'name', 'email', 'password', 'created_at']),
            DB::table('tickets')->whereIn('id', [$ownTicket->id, $assignedTicket->id])->orderBy('id')->get(['id', 'user_id', 'assignee_id', 'status']),
            DB::table('ticket_replies')->where('id', $reply->id)->first(['id', 'user_id']),
            DB::table('projects')->where('id', $project->id)->first(['id', 'created_by']),
            DB::table('project_members')->where('project_id', $project->id)->where('user_id', $target->id)->first(['role']),
            DB::table('tasks')->where('id', $task->id)->first(['id', 'created_by', 'assignee_id']),
            DB::table('time_entries')->where('id', $entry->id)->first(['id', 'user_id']),
        ];
        $before = $snapshot();

        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => 'Offboarded']);

        expect($target->fresh()->isSuspended())->toBeTrue()
            ->and($snapshot())->toEqual($before)
            ->and(grantRows($target))->toBe($grants);
    });

    it('requires a reason of at most 500 characters', function () {
        $target = User::factory()->create();

        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');

        expect($target->fresh()->isSuspended())->toBeFalse()
            ->and(Activity::where('description', AccountLifecycleService::SUSPENDED)->exists())->toBeFalse();
    });

    it('is idempotent: suspending again keeps the original time and logs nothing more', function () {
        $target = User::factory()->create(['name' => 'Sam Target']);
        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => 'First']);
        $suspendedAt = $target->fresh()->disabled_at;
        $token = $target->fresh()->getRememberToken();

        $this->travel(1)->hours();
        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => 'Second'])
            ->assertSessionHas('status', 'Sam Target is already suspended.');

        expect($target->fresh()->disabled_at->equalTo($suspendedAt))->toBeTrue()
            ->and($target->fresh()->getRememberToken())->toBe($token)
            ->and(Activity::where('description', AccountLifecycleService::SUSPENDED)->count())->toBe(1);
    });

    it('refuses self-suspension, for a browser form and for a JSON caller alike', function () {
        $other = User::factory()->create();
        $other->assignRole('operator');

        $this->actingAs($this->operator)
            ->from(route('users.show', $this->operator))
            ->post(route('users.suspend', $this->operator), ['reason' => 'Me'])
            ->assertRedirect(route('users.show', $this->operator))
            ->assertSessionHasErrors(['account' => 'You cannot suspend your own account.']);

        $this->actingAs($this->operator)
            ->postJson(route('users.suspend', $this->operator), ['reason' => 'Me'])
            ->assertStatus(422)
            ->assertJson(['message' => 'You cannot suspend your own account.']);

        expect($this->operator->fresh()->isSuspended())->toBeFalse()
            ->and(Activity::where('description', AccountLifecycleService::SUSPENDED)->exists())->toBeFalse();
    });
});

describe('reactivating', function () {
    it('clears the suspension, signs the user out again, logs it, and grants nothing', function () {
        $target = User::factory()->create(['name' => 'Sam Target']);
        $target->assignRole('user');
        $target->givePermissionTo('projects.manage');
        $grants = grantRows($target);
        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => 'Paused']);
        $suspendedAt = $target->fresh()->disabled_at;
        // A session row written back by a request that was in flight during the suspension.
        addSession($target, 'resurrected-session');
        $token = $target->fresh()->getRememberToken();

        $this->actingAs($this->operator)
            ->post(route('users.reactivate', $target), ['reason' => 'Rejoined'])
            ->assertSessionHas('status', 'Sam Target has been reactivated. They can sign in again.');

        $fresh = $target->fresh();
        expect($fresh->isSuspended())->toBeFalse()
            ->and(grantRows($target))->toBe($grants)
            ->and($fresh->getRoleNames()->all())->toBe(['user'])
            ->and($fresh->getDirectPermissions()->pluck('name')->all())->toBe(['projects.manage'])
            ->and($fresh->getRememberToken())->not->toBe($token)
            ->and(DB::table('sessions')->where('user_id', $target->id)->exists())->toBeFalse();

        $log = Activity::where('description', AccountLifecycleService::REACTIVATED)->sole();
        expect($log->causer_id)->toBe($this->operator->id)
            ->and($log->subject_id)->toBe($target->id)
            ->and($log->properties->get('reason'))->toBe('Rejoined')
            ->and($log->properties->get('suspended_at'))->toBe($suspendedAt->toIso8601String());
    });

    it('accepts no reason, and is idempotent for an active account', function () {
        $target = User::factory()->suspended()->create(['name' => 'Sam Target']);

        $this->actingAs($this->operator)->post(route('users.reactivate', $target))->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post(route('users.reactivate', $target))
            ->assertSessionHas('status', 'Sam Target is already active.');

        expect($target->fresh()->isSuspended())->toBeFalse()
            ->and(Activity::where('description', AccountLifecycleService::REACTIVATED)->count())->toBe(1)
            ->and(Activity::where('description', AccountLifecycleService::REACTIVATED)->sole()->properties->has('reason'))->toBeFalse();
    });
});

describe('the database session-driver prerequisite (R0.9-1C review finding L-2)', function () {
    it('refuses to suspend with another session driver rather than leave its sessions alive, changing nothing', function () {
        $target = User::factory()->create();
        addSession($target, 'stored-elsewhere-session');
        Password::broker()->createToken($target);
        $before = accountState($target);

        config(['session.driver' => 'array']);

        expect(fn () => app(AccountLifecycleService::class)->suspend($target, $this->operator, 'Left the company'))
            ->toThrow(RuntimeException::class, 'requires SESSION_DRIVER=database');
        expect(accountState($target))->toBe($before)
            ->and($target->fresh()->isSuspended())->toBeFalse();

        // The operator action reports a server error, never a success it did not achieve.
        $this->actingAs($this->operator)
            ->post(route('users.suspend', $target), ['reason' => 'Left the company'])
            ->assertServerError();
        expect(accountState($target))->toBe($before);
    });

    it('refuses to reactivate with another session driver, changing nothing', function () {
        $target = User::factory()->suspended()->create();
        addSession($target, 'written-during-suspension');
        $before = accountState($target);

        config(['session.driver' => 'redis']);

        expect(fn () => app(AccountLifecycleService::class)->reactivate($target, $this->operator))
            ->toThrow(RuntimeException::class, 'requires SESSION_DRIVER=database');
        expect(accountState($target))->toBe($before)
            ->and($target->fresh()->isSuspended())->toBeTrue();
    });
});

describe('the operator interface', function () {
    it('shows an active account with a reason field and a suspend action', function () {
        $target = User::factory()->create(['name' => 'Sam Target']);

        $this->actingAs($this->operator)->get(route('users.show', $target))
            ->assertOk()
            ->assertSee('Account access')
            ->assertSeeInOrder(['Status', 'Active'])
            ->assertSee('action="'.route('users.suspend', $target).'"', false)
            ->assertSee('id="suspend-reason"', false)
            ->assertSee('Suspend account')
            ->assertDontSee('Reactivate account');
    });

    it('shows a suspended account with its suspension time, the reactivate action and the logged reason and actor', function () {
        $target = User::factory()->create(['name' => 'Sam Target']);
        $this->actingAs($this->operator)->post(route('users.suspend', $target), ['reason' => 'Contract ended']);

        $this->actingAs($this->operator)->get(route('users.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['Status', 'Suspended', 'since'])
            ->assertSee('datetime="'.$target->fresh()->disabled_at->toIso8601String().'"', false)
            ->assertSee('action="'.route('users.reactivate', $target).'"', false)
            ->assertSee('Reactivate account')
            ->assertDontSee('Suspend account')
            ->assertSeeInOrder(['suspended account', 'by Olive Operator'])
            ->assertSee('Contract ended');
    });

    it('offers no suspend action on the operator\'s own page', function () {
        $this->actingAs($this->operator)->get(route('users.show', $this->operator))
            ->assertOk()
            ->assertSee('You cannot suspend your own account.')
            ->assertDontSee('action="'.route('users.suspend', $this->operator).'"', false);
    });

    it('shows no account access panel to a viewer without users.manage', function () {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('users.view');
        $target = User::factory()->suspended()->create();

        $this->actingAs($viewer)->get(route('users.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['Status', 'Suspended'])
            ->assertDontSee('Account access')
            ->assertDontSee('Reactivate account');
    });

    it('shows the last-administrator refusal on the page the operator came from', function () {
        $this->actingAs($this->operator)
            ->from(route('users.show', $this->operator))
            ->put(route('users.roles.update', $this->operator), ['roles' => ['user']])
            ->assertRedirect(route('users.show', $this->operator))
            ->assertSessionHasErrors(['account' => LastAccountAdministratorException::MESSAGE]);

        $this->actingAs($this->operator)
            ->withSession([
                'errors' => ['default' => ['format' => ':message', 'messages' => ['account' => [LastAccountAdministratorException::MESSAGE]]]],
                '_flash' => ['old' => [], 'new' => ['errors']],
            ])
            ->get(route('users.show', $this->operator))
            ->assertSee(LastAccountAdministratorException::MESSAGE);
    });

    it('lists suspended accounts in the directory with their status', function () {
        User::factory()->create(['name' => 'Active Annie']);
        User::factory()->suspended()->create(['name' => 'Suspended Sid']);

        $this->actingAs($this->operator)->get(route('users.index'))
            ->assertOk()
            ->assertSeeInOrder(['Active Annie', 'Active'])
            ->assertSeeInOrder(['Suspended Sid', 'Suspended']);
    });
});
