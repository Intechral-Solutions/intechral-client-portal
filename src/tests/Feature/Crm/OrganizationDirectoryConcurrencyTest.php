<?php

/*
 * Release 1.0 (ORG-2, CP6): the two-connection certification of the Organization Directory service (App\Services\OrganizationDirectory) on REAL MariaDB: two independent
 * PHP processes, two real connections, every pair in BOTH orders. Nothing here is inferred from reading the code. Method and harness: R1-3A / ORG-1
 * (tests/Support/portal_state_race_support.php) as extended for CP6 (tests/Support/org2_race_support.php: sessions, engine counters, attempts).
 *
 * WHAT IS ASSERTED, per pair and per order:
 *   1. the two workers ran on two different MariaDB sessions;
 *   2. the second operation really WAITED on the first at its conflicting statement (the server counted a row lock wait, and the process was still running a
 *      moment after it announced the statement), unless the pair is one that must not serialize, in which case it FINISHED while the first was still frozen;
 *   3. neither ended in a deadlock (1213) or a lock wait timeout (1205), neither transaction began twice, the server's deadlock counter did not move, and a
 *      successful operation rolled nothing back;
 *   4. the final COMMITTED state is exactly the one the design predicts for that order: controlled refusals (never a 500, never a raw foreign key error), no
 *      orphan, no lost dependant, no duplicate membership or audit entry.
 *
 * The groups are those of ORG-0 section 6 as revised by the CP2-CP5 review: A initial enablement, B deletion, D profile / Classification against lifecycle, E creation
 * uniqueness. (Group C, the Project association writers, is ProjectOrganizationConcurrencyTest.)
 *
 * Like the other concurrency tests this ends RefreshDatabase's transaction so worker processes can see its rows, builds a committed synchronized installation, and
 * removes everything it created in a finally block. It refuses to run if the testing database already holds committed roles or permissions.
 */

use App\Models\CrmContact;
use App\Models\Organization;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/org2_race_support.php';

/** The activity entries of one event. */
function o2dAudit(string $event, ?int $subject = null): array
{
    return DB::table('activity_log')->where('description', $event)->when($subject, fn ($q) => $q->where('subject_id', $subject))->orderBy('id')->get()
        ->map(fn ($row) => ['row' => $row] + json_decode($row->properties, true))->all();
}

function o2dMembers(int $organization): array
{
    return DB::table('organization_members')->where('organization_id', $organization)->orderBy('user_id')->pluck('role', 'user_id')->all();
}

function o2dOrg(int $id): ?object
{
    return DB::table('organizations')->where('id', $id)->first();
}

/**
 * Each scenario: x and y (the two operations), the statement each side freezes after when it runs FIRST and announces before when it runs SECOND, a setup, and the
 * expected committed outcome per order ('xy': x first and y second; 'yx': the reverse). `attempts` is how many times each worker's outermost transaction may begin.
 *
 * @return array<string, array<string, mixed>>
 */
function o2dScenarios(): array
{
    return [
        // ── Group A: initial enablement ──────────────────────────────────────────────────────────
        'A1 enablement vs invitation issuance' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'A1 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $c['d'], 'email' => 'a1@psr.test', 'actor' => $f['issuer']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $invitation = DB::table('organization_invitations')->where('email', 'a1@psr.test')->first();
                expect($r['x']['outcome'])->toBe('ok')
                    ->and(o2dOrg($c['d'])->portal_enabled_by)->toBe($f['actor']->id)
                    ->and(o2dMembers($c['d']))->toBe([$f['actor']->id => 'admin'])
                    ->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toHaveCount(1);

                if ($order === 'xy') {
                    // Enabled first: the invitation then finds an Enabled Organization and is issued.
                    expect($r['y']['outcome'])->toBe('ok')->and($invitation)->not->toBeNull();
                } else {
                    // The invitation took the row first, found it Not enabled, and was refused: nothing issued, and the enablement is untouched by it.
                    expect($r['y']['outcome'])->toBe('refused:portal_refused')->and($invitation)->toBeNull();
                }
            },
        ],
        'A2 enablement vs member addition' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'A2 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'add_member', 'organization' => $c['d'], 'user' => $f['newcomer']->id, 'role' => 'member', 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toHaveCount(1);

                if ($order === 'xy') {
                    expect($r['y']['outcome'])->toBe('ok')->and(o2dMembers($c['d']))->toBe([$f['actor']->id => 'admin', $f['newcomer']->id => 'member']);
                } else {
                    // Added first: refused (Not enabled), no member row; the enablement then made its own single admin.
                    expect($r['y']['outcome'])->toBe('membership:portal_not_enabled')->and(o2dMembers($c['d']))->toBe([$f['actor']->id => 'admin']);
                }
            },
        ],
        'A3 enablement vs Organization deletion' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'A3 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                if ($order === 'xy') {
                    // Enabled first: the deletion finds a portal history and is refused; the Organization and its one admin stand.
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('directory:has_portal_history')
                        ->and(o2dOrg($c['d'])->portal_enabled_at)->not->toBeNull()->and(o2dMembers($c['d']))->toBe([$f['actor']->id => 'admin'])
                        ->and(o2dAudit(OrganizationDirectory::DELETED))->toBeEmpty();
                } else {
                    // Deleted first: the enablement finds nothing (404), writes nothing, leaves no membership and no audit entry.
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('http:404')
                        ->and(o2dOrg($c['d']))->toBeNull()->and(o2dMembers($c['d']))->toBe([])
                        ->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toBeEmpty()->and(o2dAudit(OrganizationDirectory::DELETED))->toHaveCount(1);
                }
            },
        ],
        'A4 enablement vs actor suspension' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'A4 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['actor']->id, 'actor' => $f['admin']->id],
            'x_freeze' => PSR_USER_SHARE, 'x_block' => PSR_USER_SHARE, 'y_freeze' => PSR_USER_EXCL, 'y_block' => PSR_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['y']['outcome'])->toBe('ok')->and(DB::table('users')->where('id', $f['actor']->id)->value('disabled_at'))->not->toBeNull();

                if ($order === 'xy') {
                    // The enablement held the actor's row shared; the suspension waited at the user row, then committed after it. The enablement was authorized when it ran.
                    expect($r['x']['outcome'])->toBe('ok')->and(o2dOrg($c['d'])->portal_enabled_by)->toBe($f['actor']->id)->and(o2dMembers($c['d']))->toBe([$f['actor']->id => 'admin']);
                } else {
                    // The suspension committed first. The enablement's Gate answer (before its transaction) was old; the TABLE answer under the lock was not: no new privilege.
                    expect($r['x']['outcome'])->toBe('unauthorized')->and(o2dOrg($c['d'])->portal_enabled_at)->toBeNull()
                        ->and(o2dMembers($c['d']))->toBe([])->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toBeEmpty();
                }
            },
        ],
        'A6 two concurrent initial enablements' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'A6 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'dir_enable', 'organization' => $c['d'], 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                [$winner, $loser] = $order === 'xy' ? ['x', 'y'] : ['y', 'x'];
                $by = $order === 'xy' ? $f['actor'] : $f['other'];

                // Exactly one transition: the first enabler is the recorded one and the only admin; the second is the controlled refusal, never a re-record.
                expect($r[$winner]['outcome'])->toBe('ok')->and($r[$loser]['outcome'])->toBe('relationship:already_enabled')
                    ->and(o2dOrg($c['d'])->portal_enabled_by)->toBe($by->id)
                    ->and(o2dMembers($c['d']))->toBe([$by->id => 'admin'])
                    ->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toHaveCount(1);
            },
        ],

        // ── Group B: Organization deletion ───────────────────────────────────────────────────────
        'B7 deletion vs Person creation with affiliation' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B7 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'person_create', 'actor' => $f['other']->id, 'data' => ['first_name' => 'New', 'last_name' => 'Person', 'organization_id' => $c['d']]],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_ORG_SHARE, 'y_block' => O2R_ORG_SHARE,
            'expect' => function ($f, $c, $order, $r) {
                $people = DB::table('crm_contacts')->where('first_name', 'New')->get();
                expect($r['x']['outcome'])->toBe('ok')->and(o2dOrg($c['d']))->toBeNull();

                if ($order === 'xy') {
                    // Deleted first: the Person's affiliation names nothing, so it is refused (a validation message, not a foreign key error) and no Person exists.
                    expect($r['y']['outcome'])->toBe('validation:organization_id')->and($people)->toHaveCount(0);
                } else {
                    // The Person was created first, under the shared Organization lock; the deletion then cleared its affiliation and removed the Organization. The Person is KEPT.
                    expect($r['y']['outcome'])->toBe('ok')->and($people)->toHaveCount(1)->and($people[0]->organization_id)->toBeNull()
                        ->and(o2dAudit(OrganizationDirectory::DELETED)[0]['unaffiliated_person_ids'])->toBe([(int) $people[0]->id]);
                }
            },
        ],
        'B8 deletion vs Person reassignment to the Organization' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B8 Co')->id, 'p' => CrmContact::factory()->create(['organization_id' => null, 'created_by' => $f['actor']->id])->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'person_update', 'contact' => $c['p'], 'data' => ['organization_id' => $c['d']]],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_ORGS_SHARE_ASC, 'y_block' => O2R_ORGS_SHARE_ASC,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and(o2dOrg($c['d']))->toBeNull()->and(DB::table('crm_contacts')->where('id', $c['p'])->value('organization_id'))->toBeNull();

                if ($order === 'xy') {
                    expect($r['y']['outcome'])->toBe('validation:organization_id');
                } else {
                    // Reassigned first, then cleared by the deletion that followed: the Person is kept, unaffiliated, and the log names it.
                    expect($r['y']['outcome'])->toBe('ok')->and(o2dAudit(OrganizationDirectory::DELETED)[0]['unaffiliated_person_ids'])->toBe([$c['p']]);
                }
            },
        ],
        // B9. The Organization's deletion finds its People through the `organization_id` INDEX; a Person write that took the PERSON row first and then rewrote that index
        // record would meet it from the other side and InnoDB would kill one of them (a deadlock the framework silently retries). The writers therefore lock the Organization
        // the Person is LEAVING, shared, before the Person row (CrmService). The pair is certified in both orders; the engine's deadlock counter must not move.
        'B9a deletion vs a Person moving to another Organization' => [
            'setup' => function ($f) {
                $d = o2rDirectoryOrg($f, 'B9a Co');

                return ['d' => $d->id, 'p' => CrmContact::factory()->create(['organization_id' => $d->id, 'created_by' => $f['actor']->id])->id];
            },
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'person_update', 'contact' => $c['p'], 'data' => ['organization_id' => $f['org2']->id]],
            'x_freeze' => O2R_PEOPLE_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_PERSON_EXCL, 'y_block' => O2R_ORGS_SHARE_ASC,
            // Delete first: the move waits at the Organization, finds the Person cleared (a change of affiliation under its lock) and starts over once.
            'attempts' => ['xy' => [1, 2], 'yx' => [1, 1]],
            'expect' => function ($f, $c, $order, $r) {
                // Either order the Person ends in the other Organization, and the deleted one is gone.
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')
                    ->and(o2dOrg($c['d']))->toBeNull()->and((int) DB::table('crm_contacts')->where('id', $c['p'])->value('organization_id'))->toBe($f['org2']->id);

                // Who cleared whom: if the Person moved first the deletion found nobody to clear; if the deletion went first it cleared the Person, and the move then re-affiliated it.
                expect(o2dAudit(OrganizationDirectory::DELETED)[0]['unaffiliated_person_ids'])->toBe($order === 'xy' ? [$c['p']] : []);
            },
        ],
        'B9b deletion vs a Person having its affiliation cleared' => [
            'setup' => function ($f) {
                $d = o2rDirectoryOrg($f, 'B9b Co');

                return ['d' => $d->id, 'p' => CrmContact::factory()->create(['organization_id' => $d->id, 'created_by' => $f['actor']->id])->id];
            },
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'person_update', 'contact' => $c['p'], 'data' => ['organization_id' => null]],
            'x_freeze' => O2R_PEOPLE_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_PERSON_EXCL, 'y_block' => O2R_ORGS_SHARE_ASC,
            'attempts' => ['xy' => [1, 2], 'yx' => [1, 1]],
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')
                    ->and(o2dOrg($c['d']))->toBeNull()->and(DB::table('crm_contacts')->where('id', $c['p'])->value('organization_id'))->toBeNull()
                    ->and(o2dAudit(OrganizationDirectory::DELETED)[0]['unaffiliated_person_ids'])->toBe($order === 'xy' ? [$c['p']] : []);
            },
        ],
        'B9c deletion vs the deletion of a Person of that Organization' => [
            'setup' => function ($f) {
                $d = o2rDirectoryOrg($f, 'B9c Co');

                return ['d' => $d->id, 'p' => CrmContact::factory()->create(['organization_id' => $d->id, 'created_by' => $f['actor']->id])->id];
            },
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'person_delete', 'contact' => $c['p']],
            'x_freeze' => O2R_PEOPLE_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_PERSON_EXCL, 'y_block' => O2R_ORGS_SHARE_ASC,
            'attempts' => ['xy' => [1, 2], 'yx' => [1, 1]],
            'expect' => function ($f, $c, $order, $r) {
                // Either order: the Organization is gone and so is the Person that was deleted; nothing else.
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')
                    ->and(o2dOrg($c['d']))->toBeNull()->and(DB::table('crm_contacts')->where('id', $c['p'])->exists())->toBeFalse()
                    ->and(o2dAudit(OrganizationDirectory::DELETED)[0]['unaffiliated_person_ids'])->toBe($order === 'xy' ? [$c['p']] : []);
            },
        ],
        'B10 deletion vs Project link synchronization' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B10 Co')->id, 'pr' => o2rProject($f)->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'project_sync', 'project' => $c['pr'], 'actor' => $f['other']->id, 'ids' => [$c['d']]],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_ORGS_SHARE_ASC, 'y_block' => O2R_ORGS_SHARE_ASC,
            'expect' => function ($f, $c, $order, $r) {
                if ($order === 'xy') {
                    // Deleted first: the link names nothing; refused with the one message, no link, no foreign key error.
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('validation:companies')->and(o2dOrg($c['d']))->toBeNull()->and(o2rLinks($c['pr']))->toBe([]);
                } else {
                    // Linked first (under the shared lock): the deletion finds a project and is refused. Nothing orphaned: the Organization and its link both stand.
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('directory:has_projects')->and(o2dOrg($c['d']))->not->toBeNull()->and(o2rLinks($c['pr']))->toBe([$c['d']]);
                }
            },
        ],
        'B11 deletion vs Project creation with a linked Organization' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B11 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'project_create', 'actor' => $f['other']->id, 'data' => ['name' => 'B11 Project', 'companies' => [$c['d']]]],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => O2R_ORGS_SHARE_ASC, 'y_block' => O2R_ORGS_SHARE_ASC,
            'expect' => function ($f, $c, $order, $r) {
                $project = DB::table('projects')->where('name', 'B11 Project')->first();

                if ($order === 'xy') {
                    // Refused whole: no project, no column, no membership, no link.
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('validation:companies')->and($project)->toBeNull()->and(o2dOrg($c['d']))->toBeNull();
                } else {
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('directory:has_projects')
                        ->and($project)->not->toBeNull()->and(o2rLinks($project->id))->toBe([$c['d']])->and(o2dOrg($c['d']))->not->toBeNull()
                        ->and(DB::table('project_columns')->where('project_id', $project->id)->count())->toBe(5);
                }
            },
        ],
        'B12a deletion vs partner designation' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B12a Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'designate', 'organization' => $c['d'], 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORGS_ASC, 'y_block' => PSR_ORGS_ASC,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and(o2dOrg($c['d']))->toBeNull();

                // Deleted first: nothing to designate (404). Designation first: a Directory-only Organization cannot be designated, so it is refused and the deletion proceeds.
                expect($r['y']['outcome'])->toBe($order === 'xy' ? 'http:404' : 'relationship:portal_not_enabled');
            },
        ],
        'B12b deletion vs a managing-relationship assignment' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B12b Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'assign_customer', 'customer' => $c['d'], 'partner' => $f['partner']->id, 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORGS_ASC, 'y_block' => PSR_ORGS_ASC,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and(o2dOrg($c['d']))->toBeNull()
                    ->and(DB::table('organizations')->where('managed_by_organization_id', $f['partner']->id)->count())->toBe(0, 'no Organization was left managed by the partner');

                expect($r['y']['outcome'])->toBe($order === 'xy' ? 'http:404' : 'relationship:portal_not_enabled');
            },
        ],
        'B13 two concurrent deletions' => [
            'setup' => fn ($f) => ['d' => o2rDirectoryOrg($f, 'B13 Co')->id],
            'x' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['actor']->id],
            'y' => fn ($f, $c) => ['op' => 'dir_delete', 'organization' => $c['d'], 'actor' => $f['other']->id],
            'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                [$winner, $loser] = $order === 'xy' ? ['x', 'y'] : ['y', 'x'];

                expect($r[$winner]['outcome'])->toBe('ok')->and($r[$loser]['outcome'])->toBe('http:404')
                    ->and(o2dOrg($c['d']))->toBeNull()->and(o2dAudit(OrganizationDirectory::DELETED))->toHaveCount(1);
            },
        ],
    ];
}

/**
 * Group D: lifecycle (disable, re-enable: manageRelationships, OrganizationRelationships) against the Directory's profile update and Classification change
 * (manageDirectory, OrganizationDirectory). They share the Organization row's exclusive lock, so they serialize; the one that runs second works on the COMMITTED row
 * of the first, writes only its own columns, and each leaves its own audit entry.
 *
 * @return array<string, array<string, mixed>>
 */
function o2dLifecycleScenarios(): array
{
    $scenarios = [];

    foreach (['20' => ['disable', false], '21' => ['re-enable', true]] as $number => [$verb, $startsDisabled]) {
        foreach (['profile' => ['dir_update', ['phone' => '555-0100']], 'classification' => ['dir_classify', null]] as $kind => [$op, $data]) {
            $label = ($kind === 'profile' ? $number : (string) ($number + 2)).' '.$verb.' vs '.($kind === 'profile' ? 'profile update' : 'Classification change');

            $scenarios["D{$label}"] = [
                'setup' => function ($f) use ($startsDisabled) {
                    $org = Organization::factory()->create(['name' => 'Lifecycle Co', 'portal_enabled_by' => $f['admin']->id, 'phone' => '555-0000']);

                    if ($startsDisabled) {
                        app(OrganizationRelationships::class)->disable($org, $f['actor']);
                    }

                    return ['d' => $org->id, 'enabledAt' => (string) o2dOrg($org->id)->portal_enabled_at, 'disabledBefore' => o2dOrg($org->id)->disabled_at];
                },
                'x' => fn ($f, $c) => ['op' => $startsDisabled ? 'enable' : 'disable', 'organization' => $c['d'], 'actor' => $f['actor']->id],
                'y' => fn ($f, $c) => $kind === 'profile'
                    ? ['op' => $op, 'organization' => $c['d'], 'actor' => $f['other']->id, 'data' => $data]
                    : ['op' => $op, 'organization' => $c['d'], 'actor' => $f['other']->id, 'classification' => 'vendor'],
                'x_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
                'expect' => function ($f, $c, $order, $r) use ($startsDisabled, $kind) {
                    $row = o2dOrg($c['d']);

                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')
                        // Whichever order, the Directory change never touched the portal-state columns.
                        ->and($row->portal_enabled_at)->toBe($c['enabledAt'])
                        ->and(o2dAudit($startsDisabled ? OrganizationRelationships::ENABLED : OrganizationRelationships::DISABLED, $c['d']))->toHaveCount(1);

                    // The lifecycle's own change, whichever order: the Directory change never undid it.
                    $startsDisabled ? expect($row->disabled_at)->toBeNull() : expect($row->disabled_at)->not->toBeNull();

                    // The Directory's own change, whichever order: the lifecycle never overwrote it, and its audit entry says what it changed.
                    if ($kind === 'profile') {
                        expect($row->phone)->toBe('555-0100')
                            ->and(o2dAudit(OrganizationDirectory::PROFILE_UPDATED, $c['d']))->toHaveCount(1)
                            ->and(o2dAudit(OrganizationDirectory::PROFILE_UPDATED, $c['d'])[0]['old'])->toBe(['phone' => '555-0000'])
                            ->and(o2dAudit(OrganizationDirectory::PROFILE_UPDATED, $c['d'])[0]['new'])->toBe(['phone' => '555-0100']);
                    } else {
                        expect($row->classification)->toBe('vendor')
                            ->and(o2dAudit(OrganizationDirectory::CLASSIFICATION_CHANGED, $c['d']))->toHaveCount(1)
                            ->and(o2dAudit(OrganizationDirectory::CLASSIFICATION_CHANGED, $c['d'])[0]['old'])->toBe(['classification' => 'general'])
                            ->and(o2dAudit(OrganizationDirectory::CLASSIFICATION_CHANGED, $c['d'])[0]['new'])->toBe(['classification' => 'vendor']);
                    }
                },
            ];
        }
    }

    return $scenarios;
}

foreach ([...o2dScenarios(), ...o2dLifecycleScenarios()] as $name => $spec) {
    foreach (['xy', 'yx'] as $order) {
        it("{$name} [".($order === 'xy' ? 'x first, y blocked' : 'y first, x blocked').']', function () use ($spec, $order) {
            $fixture = o2rFixture();

            try {
                $context = $spec['setup']($fixture);

                [$first, $second] = $order === 'xy' ? ['x', 'y'] : ['y', 'x'];
                [$firstAttempts, $secondAttempts] = $spec['attempts'][$order] ?? [1, 1];
                $ran = o2rPair(
                    $spec[$first]($fixture, $context), $spec[$first.'_freeze'],
                    $spec[$second]($fixture, $context), $spec[$second.'_block'],
                    ['firstAttempts' => $firstAttempts, 'secondAttempts' => $secondAttempts],
                );

                $spec['expect']($fixture, $context, $order, [$first => $ran['first'], $second => $ran['second']]);

                // Two real sessions, named in the failure message of anything above.
                expect($ran['sessions'])->toHaveCount(2)->and($ran['counters']['deadlocks'])->toBe(0);
            } finally {
                o2rTeardown($fixture);
            }
        });
    }
}

// ── A5: enablement against a permission revocation ───────────────────────────────────────────
//
// The role and permission writers (AccessGrants) lock the ACTING administrator's row and the `users.admin` permission row; they do NOT lock the rows of the accounts that
// hold the role (the owner accepts that limitation). So an enablement and a revocation do not serialize, and the guarantee is exactly this: an authority change that has
// COMMITTED before the enablement's fresh table read (taken after its Organization lock) IS observed. A change that commits after that read is a window nobody closed;
// the third test pins it as a characterization so it is a stated limit and not a surprise.

it('A5a enablement vs a role revocation that COMMITS BEFORE the authority read: the table read observes it and refuses, writing nothing [revocation committed while the enablement waits at its read]', function () {
    $f = o2rFixture();

    try {
        $d = o2rDirectoryOrg($f, 'A5a Co');

        $ran = o2rPair(
            ['op' => 'dir_enable', 'organization' => $d->id, 'actor' => $f['r1']->id], PSR_ORG_EXCL,
            ['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id], PSR_USER_SHARE,
            ['blocked' => false],
        );

        // The enablement was frozen holding the actor's row and the Organization row, BEFORE its table read. The revocation did not wait (it takes the administrator's row, not
        // the holder's) and committed. The enablement then read the tables, saw the committed revocation, and refused.
        expect($ran['second']['outcome'])->toBe('ok')
            ->and($ran['first']['outcome'])->toBe('unauthorized')
            ->and(o2dOrg($d->id)->portal_enabled_at)->toBeNull()
            ->and(o2dMembers($d->id))->toBe([])
            ->and(o2dAudit(OrganizationDirectory::PORTAL_ENABLED))->toBeEmpty();
    } finally {
        o2rTeardown($f);
    }
});

it('A5a\' enablement vs a role revocation that committed BEFORE it started: refused at the Gate, never reaching a lock', function () {
    $f = o2rFixture();

    try {
        $d = o2rDirectoryOrg($f, 'A5a2 Co');

        $revoked = psrResult(psrStart(['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id]));
        $enable = psrResult(psrStart(['op' => 'dir_enable', 'organization' => $d->id, 'actor' => $f['r1']->id]));

        expect($revoked['outcome'])->toBe('ok')->and($enable['outcome'])->toBe('unauthorized')->and($enable['transactions'])->toBe(0, 'refused before a transaction began')
            ->and(psrLocks($enable))->toBe([])
            ->and(o2dOrg($d->id)->portal_enabled_at)->toBeNull();
    } finally {
        o2rTeardown($f);
    }
});

it('A5b enablement vs a role permission revocation that commits AFTER the authority read: NOT serialized (the documented window), and it does not wait', function () {
    $f = o2rFixture();

    try {
        $d = o2rDirectoryOrg($f, 'A5b Co');

        $ran = o2rPair(
            ['op' => 'dir_enable', 'organization' => $d->id, 'actor' => $f['r1']->id], O2R_AUTHORITY_READ,
            ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => [], 'actor' => $f['admin']->id], PSR_USER_SHARE,
            ['blocked' => false],
        );

        // The enablement had ALREADY read its authority from the tables (and was allowed) when the revocation committed; it completes on that read. This is the limitation the owner
        // accepted (role and permission writers do not lock the holders), recorded in docs/product/release-1.0-org-2-physical-consolidation.md section 15. What it is NOT: stale
        // cache. The enablement was authorized by a table read made after its locks, and the revocation committed after it.
        expect($ran['second']['outcome'])->toBe('ok')
            ->and($ran['first']['outcome'])->toBe('ok')
            ->and(o2dOrg($d->id)->portal_enabled_by)->toBe($f['r1']->id)
            ->and(DB::table('role_has_permissions')->where('role_id', $f['role']->id)->count())->toBe(0);
    } finally {
        o2rTeardown($f);
    }
});

// ── E: concurrent creation ───────────────────────────────────────────────────────────────────

/** Two creations of Directory-only Organizations, one frozen after `$freezeAfter`; the other either blocks at `$blockAt` or (null) finishes alone. */
function o2dCreatePair(array $f, string $firstName, string $secondName, string $freezeAfter, ?string $blockAt): array
{
    return o2rPair(
        ['op' => 'dir_create', 'actor' => $f['actor']->id, 'data' => ['name' => $firstName]], $freezeAfter,
        ['op' => 'dir_create', 'actor' => $f['other']->id, 'data' => ['name' => $secondName]], $blockAt ?? O2R_ORG_INSERT,
        ['blocked' => $blockAt !== null],
    );
}

$collisions = [
    'identical names, both past the slug probe before either inserts' => ['Acme Co', 'Acme Co', O2R_SLUG_PROBE, null, ['acme-co', 'acme-co-2']],
    'identical names, the second waits on the first\'s uncommitted row' => ['Acme Co', 'Acme Co', O2R_ORG_INSERT, O2R_ORG_INSERT, ['acme-co', 'acme-co-2']],
    'different names with the same slug, past the probe' => ['Acme Co', 'ACME  co!', O2R_SLUG_PROBE, null, ['acme-co', 'acme-co-2']],
    'different names with the same slug, the second waits' => ['Acme Co', 'ACME  co!', O2R_ORG_INSERT, O2R_ORG_INSERT, ['acme-co', 'acme-co-2']],
    'names that have no slug of their own' => ['!!!', '???', O2R_ORG_INSERT, O2R_ORG_INSERT, ['organization', 'organization-2']],
];

dataset('creation collisions', function () use ($collisions) {
    foreach ($collisions as $label => [$one, $two, $freezeAfter, $blockAt, $slugs]) {
        yield "{$label} [first: {$one}]" => [$one, $two, $freezeAfter, $blockAt, $slugs];
        yield "{$label} [first: {$two}]" => [$two, $one, $freezeAfter, $blockAt, $slugs];
    }
});

it('E24 creates two Directory-only Organizations that collide on a slug, concurrently, in both orders: unique non-empty slugs, no orphan, no error', function (string $first, string $second, string $freezeAfter, ?string $blockAt, array $slugs) {
    $f = o2rFixture();

    try {
        $ran = o2dCreatePair($f, $first, $second, $freezeAfter, $blockAt);
        $rows = DB::table('organizations')->where('id', '>', $f['mark']['organizations'])->orderBy('id')->get();

        expect($ran['first']['outcome'])->toBe('ok')->and($ran['second']['outcome'])->toBe('ok')
            ->and($rows)->toHaveCount(2)
            ->and($rows->pluck('slug')->all())->each->not->toBe('')
            ->and($rows->pluck('slug')->unique())->toHaveCount(2, 'two Organizations share a slug')
            ->and($rows->pluck('slug')->sort()->values()->all())->toBe($slugs)
            ->and($rows->pluck('created_by')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([$f['actor']->id, $f['other']->id])
            ->and($rows->every(fn ($row) => $row->portal_enabled_at === null))->toBeTrue()
            ->and(o2dAudit(OrganizationDirectory::CREATED))->toHaveCount(2, 'one audit entry per creation, none for a collision');
    } finally {
        o2rTeardown($f);
    }
})->with('creation collisions');

// ── G2 (CP7): the bounded restart of the Person writers ──────────────────────────────────────────
//
// CrmService::updateContact / ::deleteContact lock the Organization(s) a Person is in NOW (and, for a move, the destination) shared and ascending BEFORE the Person row,
// then re-read the Person under its lock. If its affiliation moved while they waited, the attempt ends WITHOUT writing and the whole thing starts over, at most five times,
// then ConflictHttpException. These tests make the affiliation move on EVERY attempt, from a real second connection, deterministically: the worker is frozen after its
// Organization lock statement (the harness's `every` freeze), the test commits the move, and only then releases it. Nothing here sleeps to create the interleaving.
//
// Per attempt the test also asks the ENGINE which Organization rows the worker holds while it is frozen. That proves two things at once: the locks of the previous
// attempt were RELEASED (the previous attempt's Organization is no longer held), and the new attempt locked the Organization the Person is in NOW and not a stale one.

/**
 * Run one Person writer through $attempts frozen attempts, moving the Person to the next filler Organization each time `$moveOn` says so.
 *
 * @param  array<string, mixed>  $operation  the worker operation (person_update / person_delete) with `contact`
 * @param  list<int>  $fillers  the Organizations the Person is moved to, in order
 * @param  list<int>  $targets  the Organizations the writer locks as its destination (empty for a deletion)
 * @param  int  $moveUntil  the attempts (1-based) at which the Person is moved; later attempts leave it alone
 * @return array{result: array<string, mixed>, held: list<list<int>>, expectedHeld: list<list<int>>}
 */
function o2dPersonRestarts(array $operation, int $person, int $start, array $fillers, array $targets, int $moveUntil): array
{
    $all = [$start, ...$fillers, ...$targets];
    $held = [];
    $expected = [];
    $at = $start;   // the Organization the Person is in when attempt k reads it

    $result = o2rFreezeEvery($operation + ['contact' => $person], O2R_ORGS_SHARE_ASC, function (int $k) use (&$held, &$expected, &$at, $person, $fillers, $targets, $moveUntil, $all) {
        $locked = array_values(array_unique([$at, ...$targets]));
        sort($locked);
        $expected[$k] = $locked;
        $held[$k] = o2rHeldLocks(array_values(array_unique($all)));
        sort($held[$k]);

        if ($k <= $moveUntil) {
            $at = $fillers[$k - 1];
            DB::table('crm_contacts')->where('id', $person)->update(['organization_id' => $at]);   // committed on the test's own connection, while the worker is frozen
        }
    });

    return ['result' => $result, 'held' => array_values($held), 'expectedHeld' => array_values($expected)];
}

function o2dPersonFixture(array $f, string $label): array
{
    $start = o2rDirectoryOrg($f, "{$label} Start")->id;
    $fillers = collect(range(1, 5))->map(fn ($n) => o2rDirectoryOrg($f, "{$label} Filler {$n}")->id)->all();
    $person = CrmContact::factory()->create(['organization_id' => $start, 'first_name' => 'Unchanged', 'last_name' => 'Person', 'created_by' => $f['actor']->id])->id;

    return [$start, $fillers, $person];
}

/** The statements of a worker that WRITE a Person. */
function o2dPersonWrites(array $result): int
{
    return collect($result['statements'])->filter(fn ($s) => (bool) preg_match('/^(update|delete from) `crm_contacts`/', $s['sql']))->count();
}

it('G2 a Person UPDATE whose affiliation changes on every attempt is refused after exactly five attempts: 409, no write, locks released between attempts, no deadlock', function () {
    $f = o2rFixture();

    try {
        [$start, $fillers, $person] = o2dPersonFixture($f, 'G2u');
        $destination = o2rDirectoryOrg($f, 'G2u Destination')->id;
        $audit = DB::table('activity_log')->count();

        $ran = o2dPersonRestarts(['op' => 'person_update', 'data' => ['first_name' => 'Renamed', 'organization_id' => $destination]], $person, $start, $fillers, [$destination], 5);
        $r = $ran['result'];

        expect($r['freezes'])->toBe(5)
            ->and($r['outcome'])->toBe('conflict')->and($r['detail'])->toBe('The person kept changing while this request was processed. Please try again.')
            ->and($r['transactions'])->toBe(5, 'exactly the bounded number of attempts, no framework retry')->and($r['rollbacks'])->toBe(0)->and($r['deadlocks'])->toBe(0)
            // At every freeze the engine showed it holding exactly {the Organization the Person was in at that moment, the destination}: the previous attempt's rows were released
            // and the stale affiliation was not reused.
            ->and($ran['held'])->toBe($ran['expectedHeld'])
            ->and($ran['expectedHeld'][1])->toBe(collect([$fillers[0], $destination])->sort()->values()->all())
            // Zero partial writes: the Person is where the test last put it, under its old name; nothing was audited.
            ->and(o2dPersonWrites($r))->toBe(0)
            ->and(CrmContact::find($person)->first_name)->toBe('Unchanged')
            ->and((int) DB::table('crm_contacts')->where('id', $person)->value('organization_id'))->toBe($fillers[4])
            ->and(DB::table('activity_log')->count())->toBe($audit);
    } finally {
        o2rTeardown($f);
    }
});

it('G2 a Person DELETION whose affiliation changes on every attempt is refused after exactly five attempts: 409, the Person survives, locks released between attempts, no deadlock', function () {
    $f = o2rFixture();

    try {
        [$start, $fillers, $person] = o2dPersonFixture($f, 'G2d');

        $ran = o2dPersonRestarts(['op' => 'person_delete'], $person, $start, $fillers, [], 5);
        $r = $ran['result'];

        expect($r['freezes'])->toBe(5)
            ->and($r['outcome'])->toBe('conflict')
            ->and($r['transactions'])->toBe(5)->and($r['rollbacks'])->toBe(0)->and($r['deadlocks'])->toBe(0)
            ->and($ran['held'])->toBe($ran['expectedHeld'])
            ->and($ran['expectedHeld'][0])->toBe([$start])->and($ran['expectedHeld'][4])->toBe([$fillers[3]])
            ->and(o2dPersonWrites($r))->toBe(0)
            ->and(CrmContact::find($person))->not->toBeNull()
            ->and((int) DB::table('crm_contacts')->where('id', $person)->value('organization_id'))->toBe($fillers[4]);
    } finally {
        o2rTeardown($f);
    }
});

it('G2 the bound is five and not four: an update that restarts four times succeeds on the fifth attempt, once, with the new Organization locked', function () {
    $f = o2rFixture();

    try {
        [$start, $fillers, $person] = o2dPersonFixture($f, 'G2b');
        $destination = o2rDirectoryOrg($f, 'G2b Destination')->id;

        $ran = o2dPersonRestarts(['op' => 'person_update', 'data' => ['first_name' => 'Renamed', 'organization_id' => $destination]], $person, $start, $fillers, [$destination], 4);
        $r = $ran['result'];

        expect($r['freezes'])->toBe(5)->and($r['outcome'])->toBe('ok')
            ->and($r['transactions'])->toBe(5)->and($r['rollbacks'])->toBe(0)->and($r['deadlocks'])->toBe(0)
            ->and($ran['held'])->toBe($ran['expectedHeld'])
            ->and(o2dPersonWrites($r))->toBe(1, 'exactly one write, on the attempt that held the right locks')
            ->and(CrmContact::find($person)->first_name)->toBe('Renamed')
            ->and((int) DB::table('crm_contacts')->where('id', $person)->value('organization_id'))->toBe($destination);
    } finally {
        o2rTeardown($f);
    }
});
