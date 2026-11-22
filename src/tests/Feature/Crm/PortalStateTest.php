<?php

use App\Models\Organization;
use App\Support\Organizations\PortalState;
use Illuminate\Support\Facades\DB;

/*
 * Release 1.0 (ORG-1): the portal-state predicate itself. One definition, two forms (a row in hand, and a query), which must never disagree; it is
 * read from the Organization's own columns and from nothing else; and the factory defaults every test Organization to Enabled, so the existing
 * invitation, membership, partner-support and ticket suites are the unchanged control group of the fence.
 */

it('derives the three states from the row alone', function () {
    $enabled = Organization::factory()->create();
    $disabled = Organization::factory()->disabled()->create();
    $directoryOnly = Organization::factory()->directoryOnly()->create();

    expect(PortalState::of($enabled->fresh())->value)->toBe('enabled')
        ->and(PortalState::of($disabled->fresh())->value)->toBe('disabled')
        ->and(PortalState::of($directoryOnly->fresh())->value)->toBe('not_enabled')
        ->and($enabled->fresh()->portalState())->toBe(PortalState::Enabled)
        ->and(PortalState::of(DB::table('organizations')->where('id', $disabled->id)->first()))->toBe(PortalState::Disabled)
        ->and(PortalState::of(['portal_enabled_at' => null, 'disabled_at' => null]))->toBe(PortalState::NotEnabled);
});

it('keeps the row form and the query form equal over every state, and fails closed on an impossible row', function () {
    $ids = [
        'enabled' => Organization::factory()->create()->id,
        'disabled' => Organization::factory()->disabled()->create()->id,
        'not_enabled' => Organization::factory()->directoryOnly()->create()->id,
    ];

    $viaQuery = PortalState::whereEnabled(DB::table('organizations'))->pluck('organizations.id')->map(fn ($id) => (int) $id)->all();
    $viaRow = DB::table('organizations')->get(['id', ...PortalState::columns()])
        ->filter(fn (object $row) => PortalState::of($row)->isEnabled())->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($viaQuery)->toBe([$ids['enabled']])->and($viaRow)->toBe($viaQuery);

    // A row the CHECKs forbid (disabled with no portal access) is Not enabled, never Enabled: only Enabled admits a new portal act.
    expect(PortalState::of(['portal_enabled_at' => null, 'disabled_at' => now()])->isEnabled())->toBeFalse();
});

it('keeps the ever-enabled query form equal to "not Not enabled" over every state (ORG-3a: the System list filter)', function () {
    $ids = [
        'enabled' => Organization::factory()->create()->id,
        'disabled' => Organization::factory()->disabled()->create()->id,
        'not_enabled' => Organization::factory()->directoryOnly()->create()->id,
    ];

    $viaQuery = PortalState::whereEverEnabled(DB::table('organizations'))->orderBy('organizations.id')->pluck('organizations.id')->map(fn ($id) => (int) $id)->all();
    $viaRow = DB::table('organizations')->orderBy('id')->get(['id', ...PortalState::columns()])
        ->reject(fn (object $row) => PortalState::of($row)->isNotEnabled())->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($viaQuery)->toBe([$ids['enabled'], $ids['disabled']])->and($viaRow)->toBe($viaQuery)
        // Through the model too, and with an alias.
        ->and(PortalState::whereEverEnabled(Organization::query())->orderBy('id')->pluck('id')->all())->toBe($viaQuery)
        ->and(PortalState::whereEverEnabled(DB::table('organizations as o'), 'o')->orderBy('o.id')->pluck('o.id')->map(fn ($id) => (int) $id)->all())->toBe($viaQuery);
});

it('uses an alias, and refuses a read that did not select the columns it needs', function () {
    $enabled = Organization::factory()->create();
    Organization::factory()->directoryOnly()->create();

    $ids = PortalState::whereEnabled(DB::table('organizations as o'), 'o')->pluck('o.id')->all();
    expect($ids)->toBe([$enabled->id]);

    expect(fn () => PortalState::of((object) ['id' => 1]))->toThrow(LogicException::class)
        ->and(fn () => PortalState::of(['portal_enabled_at' => now()]))->toThrow(LogicException::class)
        ->and(fn () => Organization::withoutGlobalScopes()->select('id')->first()->portalState())->toThrow(LogicException::class);
});

it('is an Enabled organization by default, and Directory-only only when a test says so', function () {
    $default = Organization::factory()->create();

    expect($default->fresh()->portal_enabled_at)->not->toBeNull()
        ->and($default->fresh()->portal_enabled_by)->not->toBeNull()
        ->and($default->fresh()->disabled_at)->toBeNull();

    $directoryOnly = Organization::factory()->directoryOnly()->create()->fresh();

    expect($directoryOnly->portal_enabled_at)->toBeNull()->and($directoryOnly->portal_enabled_by)->toBeNull();
});

it('keeps the portal columns out of mass assignment', function () {
    $organization = new Organization;
    $organization->fill(['name' => 'X', 'slug' => 'x', 'portal_enabled_at' => now(), 'portal_enabled_by' => 1, 'disabled_at' => now()]);

    expect($organization->getAttributes())->toBe(['name' => 'X', 'slug' => 'x']);
});
