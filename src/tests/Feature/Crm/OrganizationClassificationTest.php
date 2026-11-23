<?php

/*
 * Release 1.0 (ORG-2, CP5; ADR-011 section 6, invariant S12): the Classification (general, client, vendor) is DESCRIPTIVE ONLY. The source-level half of that
 * proof is tests/Unit/Architecture/ClassificationIndependenceTest.php (no authority file names it); this is the behavioural half: every decision an
 * Organization takes part in is run for each of the three values, and the outcomes must be IDENTICAL. A decision that began to read the Classification would
 * differ between the three and fail here.
 */

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationAuthority;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Support\Organizations\Classification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/**
 * Every decision an Organization of this Classification takes part in, as a flat list of outcomes that must not mention the Classification itself.
 *
 * @return array<string, mixed>
 */
function classificationOutcomes(User $operator, Classification $classification, $test): array
{
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id, 'name' => "Org {$classification->value}"]);
    app(OrganizationDirectory::class)->changeClassification($organization, $operator, $classification);

    $out = [];
    $out['starts not enabled'] = $organization->fresh()->portalState()->value ?? $organization->fresh()->portalState()->name;

    // A Directory-only Organization is no tenant: it can be neither invited into, joined, nor filed against, whatever it is called.
    $newcomer = User::factory()->create()->assignRole('user');
    try {
        app(OrganizationMembership::class)->add($organization, $newcomer, 'member', $operator);
        $out['member added while not enabled'] = true;
    } catch (Throwable $e) {
        $out['member added while not enabled'] = class_basename($e);
    }

    app(OrganizationDirectory::class)->enablePortalAccess($organization, $operator);
    $out['enabled'] = $organization->fresh()->portalState()->isEnabled();

    // Membership, roles, approval.
    $member = User::factory()->create()->assignRole('user');
    $admin = User::factory()->create()->assignRole('user');
    $membership = app(OrganizationMembership::class);
    $membership->add($organization, $member, 'member', $operator);
    $membership->add($organization, $admin, 'member', $operator);
    $membership->promote($organization, $admin, $operator);
    $membership->approve($organization, $admin, $operator);
    $out['approved administrator'] = OrganizationAuthority::isApprovedAdmin($organization->id, $admin->id);
    $out['member is not an administrator'] = OrganizationAuthority::isApprovedAdmin($organization->id, $member->id);
    $out['members'] = DB::table('organization_members')->where('organization_id', $organization->id)->pluck('role', 'user_id')->sortKeys()->count();

    // An invitation can be issued.
    $out['invitation issued'] = app(OrganizationInvitations::class)->issue($organization, 'invitee-'.$classification->value.'@example.test', $operator) !== null;

    // Service Request filing and global role behaviour.
    $out['member holds the default customer permissions'] = $member->can('tickets.create') && $member->can('tickets.view');
    $test->actingAs($member)->post(route('tickets.store'), [
        'organization_id' => $organization->id, 'title' => 'Filing', 'description' => 'Filed by a member.', 'category' => 'General', 'priority' => 'medium',
    ])->assertRedirect();
    $out['request filed and attributed'] = Ticket::where('title', 'Filing')->where('organization_id', $organization->id)->exists();
    Ticket::where('title', 'Filing')->delete();

    // Customer visibility: a member sees the Organization, a stranger does not.
    $stranger = User::factory()->create()->assignRole('user');
    $out['member sees it'] = $test->actingAs($member) && Organization::query()->whereKey($organization->id)->exists();
    $out['stranger sees it'] = $test->actingAs($stranger) && Organization::query()->whereKey($organization->id)->exists();

    // Partner designation, customer linking, support designation.
    $relationships = app(OrganizationRelationships::class);
    $customer = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $out['designated partner'] = $relationships->designatePartner($organization, $operator);
    $out['customer linked'] = $relationships->assignCustomer($customer, $organization->fresh(), $operator);
    $out['support granted'] = app(PartnerSupportGrants::class)->grant($organization->fresh(), $member, $operator) !== null;
    $relationships->unlinkCustomer($customer, $operator);

    // Lifecycle: disable and re-enable.
    $out['disabled'] = $relationships->disable($organization, $operator);
    $out['re-enabled'] = $relationships->enable($organization, $operator);

    // The portal columns after all of it.
    $row = DB::table('organizations')->where('id', $organization->id)->first();
    $out['final portal state'] = [$row->portal_enabled_at !== null, $row->disabled_at, (bool) $row->can_manage_customers, $row->managed_by_organization_id];

    return $out;
}

it('takes every decision identically for general, client and vendor Organizations', function () {
    $outcomes = [];

    foreach (Classification::cases() as $classification) {
        $outcomes[$classification->value] = classificationOutcomes($this->operator, $classification, $this);
    }

    expect($outcomes['client'])->toBe($outcomes['general'])
        ->and($outcomes['vendor'])->toBe($outcomes['general'])
        // and the common outcome is the one that matters (so "identically refused" cannot pass): the Organization worked.
        ->and($outcomes['general']['enabled'])->toBeTrue()
        ->and($outcomes['general']['approved administrator'])->toBeTrue()
        ->and($outcomes['general']['request filed and attributed'])->toBeTrue()
        ->and($outcomes['general']['designated partner'])->toBeTrue()
        ->and($outcomes['general']['customer linked'])->toBeTrue()
        ->and($outcomes['general']['support granted'])->toBeTrue()
        ->and($outcomes['general']['disabled'])->toBeTrue()
        ->and($outcomes['general']['member sees it'])->toBeTrue()
        ->and($outcomes['general']['stranger sees it'])->toBeFalse();
});

it('lets the Classification be changed at any point of the lifecycle without moving the lifecycle, the partnership or the relationship', function () {
    $organization = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $relationships = app(OrganizationRelationships::class);
    $relationships->designatePartner($organization, $this->operator);
    $customer = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $relationships->assignCustomer($customer, $organization->fresh(), $this->operator);
    $relationships->disable($customer, $this->operator);

    $portal = fn () => DB::table('organizations')->whereIn('id', [$organization->id, $customer->id])->orderBy('id')
        ->get(['id', 'portal_enabled_at', 'portal_enabled_by', 'disabled_at', 'can_manage_customers', 'managed_by_organization_id'])->map(fn ($r) => (array) $r)->all();
    $before = $portal();
    $audit = DB::table('activity_log')->count();

    foreach ([$organization, $customer] as $each) {
        foreach (['client', 'vendor', 'general'] as $value) {
            app(OrganizationDirectory::class)->changeClassification($each, $this->operator, $value);
        }
    }

    expect($portal())->toBe($before)
        // Two organizations, three real changes each: exactly the six classification entries and nothing else.
        ->and(DB::table('activity_log')->count() - $audit)->toBe(6)
        ->and(DB::table('activity_log')->where('description', 'like', '%classification%')->count())->toBe(6);
});

it('does not let the Classification decide who may do what: an operator may manage every classification, and a customer none', function () {
    $customer = User::factory()->create()->assignRole('user');

    foreach (Classification::cases() as $classification) {
        $organization = Organization::factory()->classified($classification)->directoryOnly()->create(['created_by' => $this->operator->id]);

        expect($this->operator->can('manageDirectory', Organization::class))->toBeTrue()
            ->and($customer->can('manageDirectory', Organization::class))->toBeFalse()
            ->and($this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->getContent())->toContain(e($classification->label()));
    }
});

// ── F-4 (CP6): the Directory form has no Classification control; the value is shown read-only and changes only through the service ──────────────────────────────────

it('shows the Classification read-only and offers no control to change it on the Directory form', function () {
    $organization = Organization::factory()->classified(Classification::Vendor)->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Read Only Co']);

    $show = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->getContent();
    $edit = $this->actingAs($this->operator)->get(route('organizations.edit', $organization))->assertOk()->getContent();
    $create = $this->actingAs($this->operator)->get(route('organizations.create'))->assertOk()->getContent();

    expect($show)->toContain('Classification')->toContain(e(Classification::Vendor->label()));

    foreach (['show' => $show, 'edit' => $edit, 'create' => $create] as $page => $html) {
        expect($html)->not->toContain('name="classification"', "the {$page} page carries a Classification control")
            ->and($html)->not->toContain('id="classification"', "the {$page} page carries a Classification control");
    }
});

it('ignores a Classification forged into the Directory form: neither a create nor an update through the routes can set it', function () {
    $organization = Organization::factory()->classified(Classification::Vendor)->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Forge Co']);

    $this->actingAs($this->operator)->put(route('organizations.update', $organization), ['name' => 'Forge Co Renamed', 'classification' => 'client'])->assertRedirect();
    $this->actingAs($this->operator)->post(route('organizations.store'), ['name' => 'Forged New', 'classification' => 'vendor'])->assertRedirect();

    expect($organization->fresh()->name)->toBe('Forge Co Renamed')
        ->and($organization->fresh()->classification)->toBe(Classification::Vendor, 'an update through the form changed the Classification')
        ->and(Organization::withoutGlobalScopes()->where('name', 'Forged New')->firstOrFail()->classification)->toBe(Classification::General, 'a creation through the form set the Classification')
        ->and(DB::table('activity_log')->where('description', OrganizationDirectory::CLASSIFICATION_CHANGED)->count())->toBe(0);

    // The service is the way: authorized, validated, audited.
    app(OrganizationDirectory::class)->changeClassification($organization, $this->operator, Classification::Client);
    expect($organization->fresh()->classification)->toBe(Classification::Client)
        ->and(DB::table('activity_log')->where('description', OrganizationDirectory::CLASSIFICATION_CHANGED)->count())->toBe(1);
});
