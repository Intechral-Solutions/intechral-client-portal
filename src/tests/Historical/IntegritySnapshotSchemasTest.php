<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationPreflight;
use App\Services\ProjectService;
use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Historical\Models\CrmCompany;
use Tests\Support\HistoricalSchema;

/*
 * Release 1.0 (ORG-2, CP1): the integrity snapshot, and `organizations:preflight`, on BOTH schemas.
 *
 * The snapshot must still run on the pinned historical baseline (the rehearsal) and on the canonical schema (RestoreDrill runs it on whatever schema the backup
 * holds). Skipping the checks of a retired table would be a way of passing, so each schema is held to ITS rules: the historical one keeps its Company
 * relationship check; the canonical one verifies portal-state pairing and the three portal-state constraints, the Directory-only invariant S11, created_by
 * completeness, the Classification catalogue and the re-pointed relationships. A check that cannot run is a failure, never a pass.
 */

function snapshot(): array
{
    // The snapshot opens its own connection to the target (it re-registers it, which discards this one), so it sees only what is COMMITTED. The test's
    // transaction ends here; the next use() empties the tables.
    $connection = DB::connection();

    while ($connection->transactionLevel() > 0) {
        // A DDL statement in the test has already ended the real transaction while Laravel still counts it: only a real one is committed.
        $connection->getPdo()->inTransaction() ? $connection->commit() : $connection->rollBack();
    }

    return IntegritySnapshot::capture(RehearsalTarget::primary());
}

/** Everything non-zero, as "name: value". @return list<string> */
function snapshotFailures(): array
{
    return IntegritySnapshot::failures(snapshot());
}

/** @return array{operator: User, enabled: Organization, disabled: Organization, directoryOnly: Organization} */
function seedPortalOrganizations(bool $withDirectoryOnly): array
{
    $operator = User::factory()->create();
    $enabled = Organization::factory()->create(['portal_enabled_by' => $operator->id, 'name' => 'Enabled']);
    $disabled = Organization::factory()->disabled()->create(['portal_enabled_by' => $operator->id, 'name' => 'Disabled']);
    $directoryOnly = $withDirectoryOnly ? Organization::factory()->directoryOnly()->create(['name' => 'Directory only']) : null;

    return ['operator' => $operator, 'enabled' => $enabled, 'disabled' => $disabled, 'directoryOnly' => $directoryOnly];
}

// ── The historical schema ────────────────────────────────────────────────────────────────────

describe('on the historical schema (crm_companies exists)', function () {
    beforeEach(fn () => HistoricalSchema::use(HistoricalSchema::ORG2));

    it('passes on valid data, keeps its Company relationship check, adds the portal-state checks, and has no canonical-only check', function () {
        $seed = seedPortalOrganizations(false);
        CrmCompany::factory()->create(['created_by' => $seed['operator']->id, 'organization_id' => $seed['enabled']->id]);

        $snapshot = snapshot();

        expect(IntegritySnapshot::failures($snapshot))->toBe([])
            ->and($snapshot['checks'])->toHaveKey('organizations:relationship_without_single_company')
            ->and($snapshot['checks'])->toHaveKeys(['organizations:portal_provenance_pair_broken', 'organizations:directory_only_with_members'])
            ->and($snapshot['checks'])->not->toHaveKey('organizations:created_by_missing')
            ->and($snapshot['checks'])->not->toHaveKey('organizations:unknown_classification')
            ->and($snapshot['schema']['columns'])->toHaveKey('crm_companies');
    });

    it('still catches a relationship whose Organization has no single Company', function () {
        $seed = seedPortalOrganizations(false);
        DB::table('organizations')->where('id', $seed['enabled']->id)->update(['can_manage_customers' => 1]);

        expect(snapshotFailures())->toBe(['organizations:relationship_without_single_company: 1']);
    });

    it('is deterministic', function () {
        seedPortalOrganizations(false);

        expect(IntegritySnapshot::hash(snapshot()))->toBe(IntegritySnapshot::hash(snapshot()));
    });
});

// ── The canonical schema ─────────────────────────────────────────────────────────────────────

describe('on the fully migrated schema (crm_companies is gone)', function () {
    beforeEach(fn () => HistoricalSchema::use(null));

    it('passes on valid data, including a Directory-only Organization, and states the canonical checks', function () {
        $seed = seedPortalOrganizations(true);
        // Through the service, so the project has its board (the snapshot's project audit requires a done column).
        $project = app(ProjectService::class)->create($seed['operator'], ['name' => 'Linked project']);
        DB::table('project_organization')->insert(['project_id' => $project->id, 'organization_id' => $seed['enabled']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_contacts')->insert(['organization_id' => $seed['directoryOnly']->id, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => $seed['operator']->id, 'created_at' => now(), 'updated_at' => now()]);

        $snapshot = snapshot();

        expect(IntegritySnapshot::failures($snapshot))->toBe([])
            ->and($snapshot['schema']['columns'])->not->toHaveKey('crm_companies')
            ->and($snapshot['checks'])->not->toHaveKey('organizations:relationship_without_single_company')
            ->and($snapshot['checks'])->toHaveKeys([
                'organizations:portal_provenance_pair_broken', 'organizations:disabled_without_portal', 'organizations:partner_without_portal', 'organizations:managed_without_portal',
                'organizations:directory_only_with_members', 'organizations:directory_only_with_invitations', 'organizations:directory_only_with_tickets',
                'organizations:directory_only_partner', 'organizations:directory_only_with_relationships',
                'organizations:created_by_missing', 'organizations:unknown_classification', 'project_organization:project_missing', 'project_organization:organization_missing',
                'crm_contacts:organization_missing',
                'fk_orphans:crm_contacts.organization_id->organizations.id', 'fk_orphans:project_organization.organization_id->organizations.id', 'fk_orphans:organizations.created_by->users.id',
            ]);

        foreach (['organizations:created_by_missing', 'organizations:unknown_classification', 'organizations:directory_only_with_members'] as $check) {
            expect($snapshot['checks'][$check])->toBe(0);
        }
    });

    it('is deterministic and reproduces the same checks', function () {
        seedPortalOrganizations(true);

        $first = snapshot();
        $second = snapshot();

        expect(IntegritySnapshot::hash($first))->toBe(IntegritySnapshot::hash($second))->and($first['checks'])->toBe($second['checks']);
    });

    it('never reports a check as passing when it could not run', function () {
        seedPortalOrganizations(false);

        expect(collect(snapshot()['checks'])->filter(fn ($value) => is_string($value))->all())->toBe([]);
    });

    it('catches an unknown classification (there is deliberately no database CHECK for it)', function () {
        $seed = seedPortalOrganizations(false);
        DB::table('organizations')->where('id', $seed['enabled']->id)->update(['classification' => 'platinum']);

        expect(snapshotFailures())->toBe(['organizations:unknown_classification: 1']);
    });

    it('catches a created_by that is not a user (a dump loaded with checks off)', function () {
        $seed = seedPortalOrganizations(false);
        DB::statement('set foreign_key_checks = 0');
        DB::table('organizations')->where('id', $seed['enabled']->id)->update(['created_by' => 987654]);
        DB::statement('set foreign_key_checks = 1');

        expect(snapshotFailures())->toBe(['fk_orphans:organizations.created_by->users.id: 1', 'organizations:created_by_missing: 1']);
    });

    it('catches the Directory-only invariant S11: members and relationships on an Organization that never had portal access', function () {
        $seed = seedPortalOrganizations(true);
        $member = User::factory()->create();
        DB::table('organization_members')->insert(['organization_id' => $seed['directoryOnly']->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        // An Enabled Organization recorded as managed by the Directory-only one: allowed by every CHECK, forbidden by S11.
        DB::table('organizations')->where('id', $seed['enabled']->id)->update(['managed_by_organization_id' => $seed['directoryOnly']->id]);

        expect(snapshotFailures())->toBe(['organizations:directory_only_with_members: 1', 'organizations:directory_only_with_relationships: 1', 'organizations:managed_by_non_partner: 1']);
    });

    it('catches a Directory-only Organization that has an invitation, and one that has a service request (S11)', function (string $check, Closure $violate) {
        $seed = seedPortalOrganizations(true);
        $violate($seed['directoryOnly'], $seed['operator']);

        expect(snapshotFailures())->toBe([$check]);
    })->with([
        'an invitation' => ['organizations:directory_only_with_invitations: 1', function (Organization $o, User $operator) {
            DB::table('organization_invitations')->insert([
                'organization_id' => $o->id, 'email' => 'invitee@example.test', 'live_email' => 'invitee@example.test', 'token_hash' => str_repeat('a', 64),
                'invited_by' => $operator->id, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }],
        'a service request' => ['organizations:directory_only_with_tickets: 1', fn (Organization $o) => Ticket::factory()->inOrganization($o)->create()],
    ]);

    it('catches a managed relationship on an Organization that never had portal access, in both directions', function () {
        $seed = seedPortalOrganizations(true);
        $partner = Organization::factory()->create(['portal_enabled_by' => $seed['operator']->id, 'name' => 'Partner']);
        DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
        // Lifted for the fixture only: the CHECK that forbids a managed Organization without portal access.
        DB::statement('alter table organizations drop constraint organizations_managed_requires_portal_check');
        DB::table('organizations')->where('id', $seed['directoryOnly']->id)->update(['managed_by_organization_id' => $partner->id]);

        expect(snapshotFailures())->toContain('organizations:directory_only_with_relationships: 1')->toContain('organizations:managed_without_portal: 1');
    });

    it('catches the portal-state rules the database CHECKs state, read back from the rows', function (string $check, string $violation, string $failure) {
        $seed = seedPortalOrganizations(true);
        DB::statement("alter table organizations drop constraint `{$check}`");
        DB::statement($violation, [$seed['enabled']->id]);

        expect(snapshotFailures())->toContain($failure);
    })->with([
        'a half provenance pair' => ['organizations_portal_provenance_pair_check', 'update organizations set portal_enabled_at = null where id = ?', 'organizations:portal_provenance_pair_broken: 1'],
        'a Disabled Organization that never had portal access' => ['organizations_disabled_requires_portal_check', 'update organizations set portal_enabled_at = null, portal_enabled_by = null, disabled_at = now() where id = ?', 'organizations:disabled_without_portal: 1'],
        'a partner that never had portal access' => ['organizations_partner_requires_portal_check', 'update organizations set portal_enabled_at = null, portal_enabled_by = null, can_manage_customers = 1 where id = ?', 'organizations:partner_without_portal: 1'],
    ]);

    it('catches a project link and a Person affiliation that point at no Organization (foreign keys off)', function () {
        $seed = seedPortalOrganizations(false);
        $project = app(ProjectService::class)->create($seed['operator'], ['name' => 'Orphan-linked project']);
        DB::statement('set foreign_key_checks = 0');
        DB::table('project_organization')->insert(['project_id' => $project->id, 'organization_id' => 424242, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_contacts')->insert(['organization_id' => 535353, 'first_name' => 'A', 'last_name' => 'B', 'created_by' => $seed['operator']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('set foreign_key_checks = 1');

        expect(snapshotFailures())->toBe([
            'crm_contacts:organization_missing: 1', 'fk_orphans:crm_contacts.organization_id->organizations.id: 1',
            'fk_orphans:project_organization.organization_id->organizations.id: 1', 'project_organization:organization_missing: 1',
        ]);
    });
});

// ── organizations:preflight ──────────────────────────────────────────────────────────────────

describe('organizations:preflight', function () {
    it('runs on the historical schema and reports the Company sections', function () {
        HistoricalSchema::use(HistoricalSchema::ORG2);
        $seed = seedPortalOrganizations(false);
        CrmCompany::factory()->create(['created_by' => $seed['operator']->id, 'organization_id' => $seed['enabled']->id]);

        $report = app(OrganizationPreflight::class)->report();

        expect($report['companies'])->toBe(1)
            ->and($report['organizationsWithoutCompany'])->toBe([$seed['disabled']->id])
            ->and($report['canonical'])->toBeNull()
            ->and($report['failures'])->toBe([]);
        expect(Artisan::call('organizations:preflight'))->toBe(0);
    });

    it('runs on the canonical schema, without a Company table, and verifies its own rules', function () {
        HistoricalSchema::use(null);
        seedPortalOrganizations(true);

        $report = app(OrganizationPreflight::class)->report();

        expect($report['companies'])->toBe(0)
            ->and($report['duplicateLinks'])->toBe([])
            ->and($report['organizationsWithoutCompany'])->toBe([])
            ->and($report['canonical']['byClassification'])->toBe(['general' => 3])
            ->and($report['portalState'])->toMatchArray(['enabled' => 1, 'disabled' => 1, 'notEnabled' => 1])
            ->and($report['failures'])->toBe([]);
        expect(Artisan::call('organizations:preflight'))->toBe(0);
    });

    it('fails on an unknown classification and on an Organization with no creator, on the canonical schema', function () {
        HistoricalSchema::use(null);
        $seed = seedPortalOrganizations(false);
        DB::table('organizations')->where('id', $seed['enabled']->id)->update(['classification' => 'platinum']);
        DB::statement('set foreign_key_checks = 0');
        DB::table('organizations')->where('id', $seed['disabled']->id)->update(['created_by' => 987654]);
        DB::statement('set foreign_key_checks = 1');

        $report = app(OrganizationPreflight::class)->report();

        expect($report['failures'])->toHaveCount(2)
            ->and(implode(' ', $report['failures']))->toContain('unknownClassification')->toContain('createdByMissing');
        expect(Artisan::call('organizations:preflight'))->toBe(1);
    });
});
