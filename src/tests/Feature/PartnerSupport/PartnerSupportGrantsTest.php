<?php

/*
 * Release 1.0 (R1-4): who may designate partner staff, what refuses, what ends a designation for good (a suspension, removing the partner designation), the
 * organization disable state, and the operator screen. Authority itself (what a designation is worth at a moment) is PartnerSupportAccessTest.
 */

use App\Exceptions\PartnerSupportException;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\OrganizationMembership;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportAccess;
use App\Services\PartnerSupportGrants;
use App\Services\ServiceRequestAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/PartnerSupportTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function psGrantRow(Organization $organization, User $user): object
{
    return DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
}

// ── authority ───────────────────────────────────────────

it('lets only an operator with BOTH portal.operator and crm.manage grant or revoke, through the service and through the routes', function () {
    $w = psWorld();
    $target = pilotCustomer();
    psJoin($target, $w['partner']);

    $noDirectory = pilotCustomRoleUser('only_operator', ['portal.operator'], []);
    $noOperator = pilotCustomRoleUser('only_crm', ['crm.manage'], []);
    $partnerAdmin = pilotCustomer();
    psJoin($partnerAdmin, $w['partner'], 'admin');
    DB::table('organization_members')->where('user_id', $partnerAdmin->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $w['operator']->id]);
    $partnerAdmin->givePermissionTo('crm.manage');

    foreach ([$noDirectory, $noOperator, $partnerAdmin, $w['staff'], $w['requester']] as $actor) {
        expect(fn () => app(PartnerSupportGrants::class)->grant($w['partner'], $target, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $actor))->toThrow(AuthorizationException::class);
        // Never a success: 403 from the policy or the route's own `can:crm.manage`.
        $this->actingAs($actor)->post(route('organizations.members.support.grant', [$w['partner']->id, $target->id]))->assertForbidden();
        $this->actingAs($actor)->delete(route('organizations.members.support.revoke', [$w['partner']->id, $w['staff']->id]))->assertForbidden();
    }

    // Nothing changed.
    expect(psGrantRow($w['partner'], $target)->support_granted_at)->toBeNull()->and(PartnerSupportAccess::holdsGrant($w['staff']))->toBeTrue();

    // The operator can.
    $this->actingAs($w['operator'])->post(route('organizations.members.support.grant', [$w['partner']->id, $target->id]))->assertRedirect();
    expect(PartnerSupportAccess::holdsGrant($target))->toBeTrue();
    $this->actingAs($w['operator'])->delete(route('organizations.members.support.revoke', [$w['partner']->id, $target->id]))->assertRedirect();
    expect(PartnerSupportAccess::holdsGrant($target))->toBeFalse();
});

it('records who designated whom and when, and audits grant and revoke', function () {
    $w = psWorld();
    $row = psGrantRow($w['partner'], $w['staff']);
    expect((int) $row->support_granted_by)->toBe($w['operator']->id)->and($row->support_granted_at)->not->toBeNull();

    app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']);

    $events = Activity::query()->whereIn('description', [PartnerSupportGrants::GRANTED, PartnerSupportGrants::REVOKED])->orderBy('id')->get();
    expect($events->pluck('description')->all())->toBe([PartnerSupportGrants::GRANTED, PartnerSupportGrants::REVOKED])
        ->and($events->first()->causer_id)->toBe($w['operator']->id)
        ->and($events->first()->properties['user_id'])->toBe($w['staff']->id)
        ->and($events->last()->properties['old']['granted'])->toBeTrue();
});

it('is idempotent: a second grant keeps the original stamp, a second revoke does nothing', function () {
    $w = psWorld();
    $stamp = psGrantRow($w['partner'], $w['staff']);
    $other = User::factory()->create()->assignRole('operator');

    expect(app(PartnerSupportGrants::class)->grant($w['partner'], $w['staff'], $other))->toBeFalse();
    expect(psGrantRow($w['partner'], $w['staff']))->toEqual($stamp);

    expect(app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']))->toBeTrue()
        ->and(app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']))->toBeFalse();
});

it('clears a half-written record on revoke', function () {
    $w = psWorld();
    DB::table('organization_members')->where('user_id', $w['staff']->id)->update(['support_granted_by' => null]);

    expect(app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']))->toBeTrue();
    expect(psGrantRow($w['partner'], $w['staff'])->support_granted_at)->toBeNull();
});

dataset('psRefusals', [
    'an organization that is not a partner' => [fn (array $w) => [$w['customer'], $w['requester']], PartnerSupportException::NOT_PARTNER],
    'someone who is not a member' => [fn (array $w) => [$w['partner'], pilotCustomer()], PartnerSupportException::NOT_MEMBER],
    'a suspended account' => [function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['partner']);
        DB::table('users')->where('id', $user->id)->update(['disabled_at' => now()]);

        return [$w['partner'], $user];
    }, PartnerSupportException::ACCOUNT_SUSPENDED],
    'an operator account' => [function (array $w) {
        $user = User::factory()->create()->assignRole('operator');
        psJoin($user, $w['partner']);

        return [$w['partner'], $user];
    }, PartnerSupportException::OPERATOR_ACCOUNT],
    'a disabled partner' => [function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['partner']);
        app(OrganizationRelationships::class)->disable($w['partner'], $w['operator']);

        return [$w['partner'], $user];
    }, PartnerSupportException::ORGANIZATION_DISABLED],
]);

it('refuses to designate', function (Closure $scenario, string $reason) {
    $w = psWorld();
    [$organization, $user] = $scenario($w);

    try {
        app(PartnerSupportGrants::class)->grant($organization, $user, $w['operator']);
        $this->fail('Expected a refusal.');
    } catch (PartnerSupportException $e) {
        expect($e->reason)->toBe($reason);
    }

    expect(DB::table('organization_members')->where('user_id', $user->id)->whereNotNull('support_granted_at')->count())->toBe(0);
})->with('psRefusals');

// ── what ends a designation for good ────────────────────

it('clears the designation when the account is suspended, so a reactivation cannot revive it', function () {
    $w = psWorld();
    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeTrue();

    app(AccountLifecycleService::class)->suspend($w['staff'], $w['operator'], 'left the partner');
    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeFalse()
        ->and(psGrantRow($w['partner'], $w['staff'])->support_granted_at)->toBeNull()
        ->and(psGrantRow($w['partner'], $w['staff'])->support_granted_by)->toBeNull();

    app(AccountLifecycleService::class)->reactivate($w['staff'], $w['operator']);
    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeFalse()
        ->and(Activity::query()->where('description', PartnerSupportGrants::REVOKED_SUSPENSION)->count())->toBe(1);
});

it('clears every designation of a partner when its partner designation is removed, so re-designation cannot revive them', function () {
    $w = psWorld();
    app(OrganizationRelationships::class)->unlinkCustomer($w['customer'], $w['operator']);

    app(OrganizationRelationships::class)->removePartner($w['partner'], $w['operator']);
    expect(psGrantRow($w['partner'], $w['staff'])->support_granted_at)->toBeNull()
        ->and(Activity::query()->where('description', PartnerSupportGrants::REVOKED_PARTNER_REMOVED)->count())->toBe(1);

    app(OrganizationRelationships::class)->designatePartner($w['partner'], $w['operator']);
    app(OrganizationRelationships::class)->assignCustomer($w['customer'], $w['partner'], $w['operator']);
    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeFalse();
});

it('deleting the membership removes the designation with it', function () {
    $w = psWorld();
    app(OrganizationMembership::class)->remove($w['partner'], $w['staff'], $w['operator']);

    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeFalse();
});

// ── organization disable state ──────────────────────────

it('disables and enables an organization, operator only, audited and idempotent', function () {
    $w = psWorld();
    $service = app(OrganizationRelationships::class);

    expect(fn () => $service->disable($w['customer'], $w['staff']))->toThrow(AuthorizationException::class);
    expect($service->disable($w['customer'], $w['operator']))->toBeTrue()->and($service->disable($w['customer'], $w['operator']))->toBeFalse();
    expect(DB::table('organizations')->where('id', $w['customer']->id)->value('disabled_at'))->not->toBeNull();
    expect($service->enable($w['customer'], $w['operator']))->toBeTrue()->and($service->enable($w['customer'], $w['operator']))->toBeFalse();
    expect(Activity::query()->whereIn('description', [OrganizationRelationships::DISABLED, OrganizationRelationships::ENABLED])->count())->toBe(2);

    $this->actingAs($w['staff'])->post(route('organizations.disable', $w['customer']->id))->assertForbidden();
    $this->actingAs($w['operator'])->post(route('organizations.disable', $w['customer']->id))->assertRedirect();
    $this->actingAs($w['operator'])->post(route('organizations.enable', $w['customer']->id))->assertRedirect();
});

it('refuses a NEW request for a disabled organization, and leaves the requester\'s existing ticket alone', function () {
    pilotMode('pilot');
    $w = psWorld();
    app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']);

    $this->actingAs($w['requester'])->post(route('tickets.store'), [
        'title' => 'New', 'description' => 'Body', 'category' => 'General', 'priority' => 'low', 'organization_id' => $w['customer']->id,
    ])->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);
    $this->actingAs($w['requester'])->post(route('tickets.store'), [
        'title' => 'New', 'description' => 'Body', 'category' => 'General', 'priority' => 'low',
    ])->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_LINKED]);

    // Their own existing request is untouched.
    $this->actingAs($w['requester'])->get(route('tickets.show', $w['ticket']))->assertOk();

    // The create page offers no disabled organization.
    $this->actingAs($w['requester'])->get(route('tickets.create'))->assertInertia(fn ($page) => $page->where('organizations', []));

    app(OrganizationRelationships::class)->enable($w['customer'], $w['operator']);
    $this->actingAs($w['requester'])->post(route('tickets.store'), [
        'title' => 'New', 'description' => 'Body', 'category' => 'General', 'priority' => 'low', 'organization_id' => $w['customer']->id,
    ])->assertSessionHasNoErrors();
});

// ── operator screen and preflight ───────────────────────

it('shows the controls to an operator on a partner page only, and never to anyone else', function () {
    $w = psWorld();

    $page = $this->actingAs($w['operator'])->get(route('organizations.show', $w['partner']))->assertOk();
    $page->assertSee('Revoke support access')->assertSee('Support access: in effect')->assertSee('Disable organization');

    // A non-partner organization carries no support controls.
    $this->actingAs($w['operator'])->get(route('organizations.show', $w['customer']))->assertOk()->assertDontSee('Grant support access')->assertDontSee('Revoke support access')->assertSee('Disable organization');

    // A customer holding a stray crm.manage who is a member sees neither the controls nor the status.
    $stray = pilotCustomer();
    $stray->givePermissionTo('crm.manage');
    psJoin($stray, $w['partner']);
    $this->actingAs($stray)->get(route('organizations.show', $w['partner']))->assertDontSee('Revoke support access')->assertDontSee('Grant support access')->assertDontSee('Disable organization');
});

it('says why a designation is not in effect', function () {
    $w = psWorld();
    app(OrganizationRelationships::class)->disable($w['partner'], $w['operator']);

    $this->actingAs($w['operator'])->get(route('organizations.show', $w['partner']))
        ->assertSee('granted, not in effect')->assertSee('the organization is disabled');
});

it('inventories designations in the preflight, failing only for records the application never writes', function () {
    $w = psWorld();
    $report = app(OrganizationPreflight::class)->report();
    expect($report['partnerSupport']['designations'])->toBe(1)->and($report['partnerSupport']['incomplete'])->toBe([])->and($report['failures'])->toBe([]);

    DB::table('organization_members')->where('user_id', $w['staff']->id)->update(['support_granted_by' => null]);
    $report = app(OrganizationPreflight::class)->report();
    expect($report['partnerSupport']['incomplete'])->toHaveCount(1)->and(implode(' ', $report['failures']))->toContain('half-written partner support record');

    DB::table('organization_members')->where('user_id', $w['staff']->id)->update(['support_granted_by' => $w['operator']->id]);
    DB::table('users')->where('id', $w['staff']->id)->update(['disabled_at' => now()]);
    $report = app(OrganizationPreflight::class)->report();
    expect($report['partnerSupport']['heldBySuspended'])->toHaveCount(1)->and(implode(' ', $report['warnings']))->toContain('suspended account');
});

it('writes only the designation columns: role, approval and membership are untouched by a grant, a revoke and a suspension', function () {
    $w = psWorld();
    $member = pilotCustomer();
    psJoin($member, $w['partner'], 'admin');
    DB::table('organization_members')->where('user_id', $member->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $w['operator']->id]);

    $untouched = fn () => collect((array) psGrantRow($w['partner'], $member))->only(['organization_id', 'user_id', 'role', 'admin_approved_at', 'admin_approved_by', 'created_at'])->all();
    $before = $untouched();

    app(PartnerSupportGrants::class)->grant($w['partner'], $member, $w['operator']);
    expect($untouched())->toBe($before);

    app(PartnerSupportGrants::class)->revoke($w['partner'], $member, $w['operator']);
    expect($untouched())->toBe($before);

    app(PartnerSupportGrants::class)->grant($w['partner'], $member, $w['operator']);
    app(AccountLifecycleService::class)->suspend($member, $w['operator'], 'x');
    expect($untouched())->toBe($before);
});
