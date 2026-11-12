<?php

/*
 * Release 1.0 (R1-4): the one definition of EFFECTIVE PARTNER SUPPORT AUTHORITY (App\Services\PartnerSupportAccess, docs/product/release-1.0-r1-4-partner-support.md §1).
 *
 * Every row below changes ONE fact of the scenario and proves two things about the result: authority is gone on the very next read (no cache, no flush), and
 * the unlocked join (request-time authorization, the list) and the locked step-by-step re-check (the reply transaction) AGREE. They are two implementations
 * of one rule, so this is their drift test: a change to the rule that is made to one and not the other fails here.
 */

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportAccess;
use App\Services\PartnerSupportGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/PartnerSupportTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    pilotMode('pilot');
});

/** Both readings of the rule, for the world's staff member and ticket. @return array{bool, bool, bool} unlocked, locked, policy */
function psReadings(array $w, ?User $actor = null): array
{
    $actor ??= $w['staff'];
    $ticket = Ticket::query()->findOrFail($w['ticket']->id);

    return [
        PartnerSupportAccess::allows($actor, $ticket),
        DB::transaction(fn () => PartnerSupportAccess::assertLocked((int) $actor->id, (int) $ticket->organization_id)),
        Gate::forUser($actor)->allows('viewAsPartnerSupport', $ticket),
    ];
}

it('grants authority only to a designated member of the partner, for a customer it manages', function () {
    $w = psWorld();

    expect(psReadings($w))->toBe([true, true, true]);
    expect(PartnerSupportAccess::holdsGrant($w['staff']))->toBeTrue();
});

dataset('psBreakers', [
    'the operator revokes the designation' => fn (array $w) => app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']),
    'the membership is removed' => fn (array $w) => DB::table('organization_members')->where('user_id', $w['staff']->id)->where('organization_id', $w['partner']->id)->delete(),
    'the designation is half written (no grantor)' => fn (array $w) => DB::table('organization_members')->where('user_id', $w['staff']->id)->update(['support_granted_by' => null]),
    'the designation is half written (no time)' => fn (array $w) => DB::table('organization_members')->where('user_id', $w['staff']->id)->update(['support_granted_at' => null]),
    'the account is suspended' => fn (array $w) => DB::table('users')->where('id', $w['staff']->id)->update(['disabled_at' => now()]),
    'the account becomes an operator' => fn (array $w) => $w['staff']->assignRole('operator'),
    'the account holds portal.operator directly' => fn (array $w) => $w['staff']->givePermissionTo('portal.operator'),
    'the partner designation is removed' => fn (array $w) => DB::table('organizations')->where('id', $w['partner']->id)->update(['can_manage_customers' => 0, 'managed_by_organization_id' => null]),
    'the customer is unlinked from the partner' => fn (array $w) => app(OrganizationRelationships::class)->unlinkCustomer($w['customer'], $w['operator']),
    'the customer is reassigned to another partner' => function (array $w) {
        $other = psPaired('Other Partner');
        app(OrganizationRelationships::class)->designatePartner($other, $w['operator']);
        app(OrganizationRelationships::class)->assignCustomer($w['customer'], $other, $w['operator'], reassign: true);
    },
    'the partner is disabled' => fn (array $w) => app(OrganizationRelationships::class)->disable($w['partner'], $w['operator']),
    'the customer is disabled' => fn (array $w) => app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']),
]);

it('ends on the next read, with no cache flush, and both readings agree', function (Closure $change) {
    $w = psWorld();
    expect(psReadings($w))->toBe([true, true, true]);

    $change($w);

    expect(psReadings($w))->toBe([false, false, false]);
})->with('psBreakers');

it('is not granted by anything but the designation: role, approval, partner relationship, requester status, owner', function () {
    $w = psWorld();
    $other = pilotCustomer();
    psJoin($other, $w['partner'], 'admin');
    DB::table('organization_members')->where('user_id', $other->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $w['operator']->id]);
    $w['partner']->forceFill(['owner_id' => $other->id])->saveQuietly();

    // An APPROVED ADMINISTRATOR OWNER of the partner with no designation has nothing.
    expect(psReadings($w, $other))->toBe([false, false, false]);

    // A member of the customer organization (and the requester) has nothing through the partner path.
    expect(psReadings($w, $w['requester']))->toBe([false, false, false]);

    // The operator (holding `tickets.assign` and every capability) is not a support user.
    expect(psReadings($w, $w['operator']))->toBe([false, false, false]);
});

it('never reaches a ticket of the partner itself, of an unmanaged organization, or of a sibling customer of ANOTHER partner', function () {
    $w = psWorld();
    $own = Ticket::factory()->open()->for($w['staff'], 'user')->create(['organization_id' => $w['partner']->id]);
    $unmanaged = Ticket::factory()->open()->create(['organization_id' => psPaired('Unmanaged')->id]);

    $otherPartner = psPaired('Other Partner');
    app(OrganizationRelationships::class)->designatePartner($otherPartner, $w['operator']);
    $otherCustomer = psPaired('Other Customer');
    app(OrganizationRelationships::class)->assignCustomer($otherCustomer, $otherPartner, $w['operator']);
    $sibling = Ticket::factory()->open()->create(['organization_id' => $otherCustomer->id]);

    foreach ([$own, $unmanaged, $sibling] as $ticket) {
        expect(PartnerSupportAccess::allows($w['staff'], $ticket))->toBeFalse()
            ->and(DB::transaction(fn () => PartnerSupportAccess::assertLocked((int) $w['staff']->id, (int) $ticket->organization_id)))->toBeFalse();
    }
});

it('is a member of several partners: authority is per designation', function () {
    $w = psWorld();
    $second = psPaired('Second Partner');
    app(OrganizationRelationships::class)->designatePartner($second, $w['operator']);
    $secondCustomer = psPaired('Second Customer');
    app(OrganizationRelationships::class)->assignCustomer($secondCustomer, $second, $w['operator']);
    psJoin($w['staff'], $second); // a member of the second partner, but NOT designated there
    $theirTicket = Ticket::factory()->open()->create(['organization_id' => $secondCustomer->id]);

    expect(PartnerSupportAccess::allows($w['staff'], $theirTicket))->toBeFalse();

    app(PartnerSupportGrants::class)->grant($second, $w['staff'], $w['operator']);
    expect(PartnerSupportAccess::allows($w['staff'], $theirTicket))->toBeTrue();

    // Revoking one leaves the other.
    app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']);
    expect(PartnerSupportAccess::allows($w['staff'], $theirTicket))->toBeTrue()
        ->and(PartnerSupportAccess::allows($w['staff'], $w['ticket']))->toBeFalse();
});

it('asks only the named abilities; view, reply and the requester/operator ability are unchanged for a support user', function () {
    $w = psWorld();
    $policy = new TicketPolicy;

    expect($policy->viewAsPartnerSupport($w['staff'], $w['ticket']))->toBeTrue()
        ->and($policy->view($w['staff'], $w['ticket']))->toBeFalse()
        ->and($policy->reply($w['staff'], $w['ticket']))->toBeFalse()
        ->and($policy->viewAsRequesterOrOperator($w['staff'], $w['ticket']))->toBeFalse()
        ->and($policy->viewInternal($w['staff'], $w['ticket']))->toBeFalse()
        ->and($policy->adjustDueDate($w['staff'], $w['ticket']))->toBeFalse();
});

it('allows a reply only while the request is being worked', function (string $status, bool $allowed) {
    $w = psWorld(['status' => $status]);

    expect((new TicketPolicy)->replyAsPartnerSupport($w['staff'], Ticket::findOrFail($w['ticket']->id)))->toBe($allowed);
})->with([['open', true], ['in_progress', true], ['pending_user', true], ['resolved', false], ['closed', false]]);

it('refuses the locked check outside a transaction (it would hold nothing)', function () {
    $w = psWorld();
    // RefreshDatabase wraps the test in a transaction, so simulate "no transaction" by asking about the level contract directly.
    expect(fn () => (function () {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        PartnerSupportAccess::assertLocked(1, 1);
    })())->toThrow(LogicException::class);
});
