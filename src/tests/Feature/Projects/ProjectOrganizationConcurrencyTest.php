<?php

/*
 * Release 1.0 (ORG-2, CP6, Group C and the corrections F-1 to F-3): the Project association writers (App\Services\ProjectService::create and ::syncOrganizations)
 * against REAL MariaDB, two independent PHP processes, two real connections, every pair in BOTH orders. Method and harness: R1-3A / ORG-1, extended for CP6 (see
 * tests/Support/org2_race_support.php). The Directory half of the matrix (groups A, B, D, E) is tests/Feature/Crm/OrganizationDirectoryConcurrencyTest.php.
 *
 *   C14  two synchronizations of one Project                     serialize on the PROJECT row; the second restarts once (an application restart, not a deadlock)
 *   C15  synchronization vs removal of the actor's membership    (F-1) a revoked membership never authorizes a link; hidden links are never touched
 *   C15d a former member's synchronization to empty              (G-4) the revoked membership stops the removal of O's link; the hidden link survives either way
 *   C15b synchronization vs revocation of the actor's operator   (F-2) eligibility is re-derived from the TABLES; what that does and does not guarantee
 *   C21  disabling an Organization vs a NEW Project link         (CP8, owner ruling B-2) a committed link stays; a non-operator's new link after the disabling is refused
 *   C20  Project writes vs suspension of the actor               (G-8, CP8) serialized on the actor's row: suspension first -> REFUSED, nothing written; write first -> completes
 *   C16  Project creation vs suspension of a member              (F-3) every user row is locked BEFORE any Organization row
 *   C17  Project creation vs deletion of one of two Organizations the ascending order, proved from the engine, and the controlled refusal
 *   C18  the bounded restart (5 attempts): a retry that succeeds, one on the last attempt, and exhaustion producing a controlled 409 with no write
 *   +    a real opposing writer (assign customer) never deadlocks against the ascending Organization lock
 *
 * An INTENTIONAL application restart commits an empty transaction and begins another (attempts 2, rollbacks 0); it is counted as such and is never offered as proof of
 * deadlock freedom. The proof is the engine's own deadlock counter, which every pair asserts did not move.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/org2_race_support.php';

/** The membership rows of a Project, user id => role. */
function o2cProjectMembers(int $project): array
{
    return DB::table('project_members')->where('project_id', $project)->orderBy('user_id')->pluck('role', 'user_id')->map(fn ($role) => (string) $role)->all();
}

// ── C14: two synchronizations of one Project ─────────────────────────────────────────────────

foreach (['xy', 'yx'] as $order) {
    it('C14 two synchronizations of one Project serialize on the project row, and the second restarts once to lock what the first linked ['.($order === 'xy' ? 'x first' : 'y first').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $project = o2rProject($f);
            $x = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['org2']->id]];
            $y = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['other']->id, 'ids' => [$f['cust']->id]];
            [$first, $second] = $order === 'xy' ? [$x, $y] : [$y, $x];

            // The first stays frozen holding the project row. The second announces the project lock and waits there. When released, the second finds under the lock a link it
            // did not lock the Organization of (its plain read of the links predates the first's commit) and starts over ONCE: attempts 2, no rollback.
            $ran = o2rPair($first, O2R_PROJECT_EXCL, $second, O2R_PROJECT_EXCL, ['secondAttempts' => 2]);

            expect($ran['first']['outcome'])->toBe('ok')->and($ran['second']['outcome'])->toBe('ok')
                ->and($ran['second']['rollbacks'])->toBe(0, 'a restart commits an empty transaction; it is not a rollback')
                // The last committer's set is the project's set (an operator's submission replaces every link).
                ->and(o2rLinks($project))->toBe([$order === 'xy' ? $f['cust']->id : $f['org2']->id]);
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── C15: synchronization vs removal of the actor's membership (F-1) ──────────────────────────

foreach (['xy', 'yx'] as $order) {
    it('C15 (F-1) a membership removed before the Organization lock is granted does not authorize the link, and a hidden link is untouched ['.($order === 'xy' ? 'removal first, sync blocked' : 'sync first, removal blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            // A Project already linked to an Organization the customer is NOT a member of: a hidden link, which a synchronization must preserve.
            $project = o2rProject($f, [$f['partner']->id]);
            $remove = ['op' => 'remove_member', 'organization' => $f['org']->id, 'user' => $f['member']->id, 'actor' => $f['actor']->id];
            $sync = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['member']->id, 'ids' => [$f['org']->id]];

            // REMOVAL FIRST (frozen holding the Organization row exclusively, before the membership row is deleted): the synchronization reads the project's links (a PLAIN read,
            // which fixes its snapshot), then waits for the Organization lock. When the removal commits it is granted the lock, and its membership read must see the removal.
            // A plain membership read would still see the old snapshot and authorize a link the actor no longer may make (the CP2-CP5 review's F-1).
            $ran = $order === 'xy'
                ? o2rPair($remove, PSR_ORG_EXCL, $sync, O2R_ORGS_SHARE_ASC)
                : o2rPair($sync, O2R_ORGS_SHARE_ASC, $remove, PSR_ORG_EXCL);

            [$removal, $synchronization] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];

            expect($removal['outcome'])->toBe('ok')
                ->and(DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['member']->id)->exists())->toBeFalse();

            if ($order === 'xy') {
                expect($synchronization['outcome'])->toBe('validation:companies')
                    ->and($synchronization['detail'])->toBe('The selected organization is invalid.')
                    ->and(o2rLinks($project))->toBe([$f['partner']->id], 'no link was written, and the hidden one is untouched');

                // Indistinguishable from an id that does not exist at all: the same outcome and the same message.
                $unknown = psrResult(psrStart(['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['member']->id, 'ids' => [999999]]));
                expect($unknown['outcome'])->toBe($synchronization['outcome'])->and($unknown['detail'])->toBe($synchronization['detail']);
            } else {
                // The synchronization held the Organization row while the membership still existed, so it was entitled; the removal came after it. The hidden link is preserved.
                expect($synchronization['outcome'])->toBe('ok')->and(o2rLinks($project))->toBe([$f['org']->id, $f['partner']->id]);
            }
        } finally {
            o2rTeardown($f);
        }
    });
}

foreach (['xy', 'yx'] as $order) {
    it('C15c (F-1) a Project CREATED by a member whose membership is removed before the Organization lock is granted is refused whole, with no Project, column or link ['.($order === 'xy' ? 'removal first, creation blocked' : 'creation first, removal blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $remove = ['op' => 'remove_member', 'organization' => $f['org']->id, 'user' => $f['member']->id, 'actor' => $f['actor']->id];
            $create = ['op' => 'project_create', 'actor' => $f['member']->id, 'data' => ['name' => 'C15c Project', 'companies' => [$f['org']->id]]];

            // Creation's first CONSISTENT read is the table read of the creator's eligibility, which comes BEFORE the Organization locks and so fixes its snapshot there. The
            // membership read that follows the Organization lock must therefore be a locking read, or it would see a membership the removal has already deleted and committed.
            $ran = $order === 'xy'
                ? o2rPair($remove, PSR_ORG_EXCL, $create, O2R_ORGS_SHARE_ASC)
                : o2rPair($create, O2R_ORGS_SHARE_ASC, $remove, PSR_ORG_EXCL);

            [$removal, $creation] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];
            $project = DB::table('projects')->where('name', 'C15c Project')->first();

            expect($removal['outcome'])->toBe('ok');

            if ($order === 'xy') {
                expect($creation['outcome'])->toBe('validation:companies')->and($creation['detail'])->toBe('The selected organization is invalid.')
                    ->and($project)->toBeNull('a refused creation left a Project behind')
                    ->and(DB::table('project_organization')->count())->toBe(0);
            } else {
                expect($creation['outcome'])->toBe('ok')->and($project)->not->toBeNull()->and(o2rLinks($project->id))->toBe([$f['org']->id])
                    ->and(DB::table('project_columns')->where('project_id', $project->id)->count())->toBe(5);
            }
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── C15d: a FORMER member's synchronization to empty (CP7, G-4) ─────────────────────────────
//
// The F-1 interleaving from the removing side: the Project is linked to Organization O (the actor is a member of O) AND to a hidden Organization (the actor is not).
// The actor submits the EMPTY list, which for a scoped actor means "remove the links I own". Another connection removes the actor's membership of O and commits while
// the synchronization waits for the Organization lock. The synchronization's locking membership read must see that removal: the actor no longer owns O's link, so
// NEITHER link may be removed. (A plain membership read, the pre-F-1 code, still saw the membership and deleted O's link.)

foreach (['xy', 'yx'] as $order) {
    it('C15d (G-4) a former member\'s synchronization to empty removes neither O\'s link nor the hidden one when the removal committed first, and removes only O\'s when it ran first ['.($order === 'xy' ? 'removal first, sync blocked' : 'sync first, removal blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $project = o2rProject($f, [$f['org']->id, $f['partner']->id]);   // O (the actor's) and a hidden link
            $remove = ['op' => 'remove_member', 'organization' => $f['org']->id, 'user' => $f['member']->id, 'actor' => $f['actor']->id];
            $sync = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['member']->id, 'ids' => []];

            expect(o2rLinks($project))->toBe(collect([$f['org']->id, $f['partner']->id])->sort()->values()->all());

            $ran = $order === 'xy'
                ? o2rPair($remove, PSR_ORG_EXCL, $sync, O2R_ORGS_SHARE_ASC)
                : o2rPair($sync, O2R_ORGS_SHARE_ASC, $remove, PSR_ORG_EXCL);

            [$removal, $synchronization] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];

            expect($removal['outcome'])->toBe('ok')->and($synchronization['outcome'])->toBe('ok')
                ->and(DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['member']->id)->exists())->toBeFalse();

            $writes = collect($synchronization['statements'])->filter(fn ($s) => str_starts_with($s['sql'], 'delete from `project_organization`') || str_starts_with($s['sql'], 'insert into `project_organization`'))->count();

            if ($order === 'xy') {
                // Authority was revoked before the lock was granted: nothing is theirs to remove. Both links stand, and the synchronization wrote nothing at all.
                expect(o2rLinks($project))->toBe(collect([$f['org']->id, $f['partner']->id])->sort()->values()->all(), 'a former member removed a link it no longer owns')
                    ->and($writes)->toBe(0);

                // The former member cannot tell the hidden Organization from one that does not exist, nor from the one they have just left: one outcome, one message.
                $probes = collect([[$f['partner']->id], [999999], [$f['org']->id]])
                    ->map(fn (array $ids) => psrResult(psrStart(['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['member']->id, 'ids' => $ids])))
                    ->map(fn (array $r) => [$r['outcome'], $r['detail'] ?? null])
                    ->unique()->values()->all();

                expect($probes)->toBe([['validation:companies', 'The selected organization is invalid.']])
                    ->and(o2rLinks($project))->toBe(collect([$f['org']->id, $f['partner']->id])->sort()->values()->all());
            } else {
                // The synchronization held O while the membership still existed, so it was entitled to remove O's link, and ONLY O's: the hidden link stands.
                expect(o2rLinks($project))->toBe([$f['partner']->id])->and($writes)->toBeGreaterThan(0);
            }

            expect($synchronization['deadlocks'] ?? 0)->toBe(0);
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── C15b: synchronization vs revocation of the actor's operator eligibility (F-2) ────────────
//
// The role and permission writers lock the ACTING administrator's row and the `users.admin` permission row; they do NOT lock the rows of the accounts that hold the role
// (accepted by the owner; docs/product/release-1.0-org-2-physical-consolidation.md section 15). So these pairs do not serialize, and what is guaranteed is exactly this: a
// revocation that has COMMITTED before the synchronization's table read is observed (the actor is then a customer: hidden links are preserved, the unrestricted set is gone).
// A revocation that commits AFTER the table read is a window nobody closed; the third test pins that as a characterization so it is a stated limit and not a surprise.

it('C15b (F-2) an operator whose capability was revoked while its synchronization waited is treated as a customer: the table read observes it and the hidden link is preserved', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f, [$f['partner']->id]);   // a link the revoked operator is not a member of

        $ran = o2rPair(
            ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['r1']->id, 'ids' => []], O2R_USERS_SHARE_SET,
            ['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id], PSR_USER_SHARE,
            ['blocked' => false],
        );

        // The synchronization is frozen holding its actor row and has NOT yet asked what the actor is. The revocation does not wait for it (it does not lock the holder) and
        // commits. The synchronization's table read then sees a customer: an empty submission removes nothing it may not see, and the hidden link stands.
        expect($ran['second']['outcome'])->toBe('ok')->and($ran['first']['outcome'])->toBe('ok')
            ->and(o2rLinks($project))->toBe([$f['partner']->id], 'a customer\'s empty submission must not remove a link it cannot see')
            ->and(DB::table('model_has_roles')->where('model_id', $f['r1']->id)->count())->toBe(0);
    } finally {
        o2rTeardown($f);
    }
});

it('C15b (F-2) an operator still holds the capability when it reads: the same submission removes every link (the control)', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f, [$f['partner']->id]);
        $result = psrResult(psrStart(['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['r1']->id, 'ids' => []]));

        expect($result['outcome'])->toBe('ok')->and(o2rLinks($project))->toBe([]);
    } finally {
        o2rTeardown($f);
    }
});

it('C15b (F-2) a revocation that commits AFTER the synchronization\'s eligibility read is NOT observed by it (the documented window), and the revocation does not wait', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f, [$f['partner']->id]);

        $ran = o2rPair(
            ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['r1']->id, 'ids' => []], O2R_OPERATOR_READ,
            ['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id], PSR_USER_SHARE,
            ['blocked' => false],
        );

        expect($ran['second']['outcome'])->toBe('ok')->and($ran['first']['outcome'])->toBe('ok')
            ->and(o2rLinks($project))->toBe([], 'it had read an operator, and finished as one')
            ->and(DB::table('model_has_roles')->where('model_id', $f['r1']->id)->count())->toBe(0);
    } finally {
        o2rTeardown($f);
    }
});

// ── C20: Project writes vs suspension of the ACTOR (CP7 G-8, tightened in CP8) ───────────────
//
// A suspension takes the actor's user row exclusively; both Project writers take it shared FIRST and, since CP8, read `disabled_at` from that same locking statement and
// refuse a suspended actor (AuthorizationException, outcome `unauthorized`) before any write. So the two SERIALIZE and the order decides: suspension first -> the write is
// REFUSED with nothing written; the write first -> it completes and the suspension follows. This is a narrow tightening, for these two operations only, of the in-flight-request rule
// of docs/architecture/account-lifecycle.md (Info-4: a request already past authorization finishes). Before CP8 (CP7's characterization) the write completed even after the suspension
// had committed, and an unrestricted operator's synchronization could remove a link it should not. The members' own suspension is not checked (C16).

foreach (['xy', 'yx'] as $order) {
    it('C20 (G-8) a Project synchronization by an actor whose suspension committed first is REFUSED and writes nothing; one that ran first completes ['.($order === 'xy' ? 'suspension first, sync blocked' : 'sync first, suspension blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $project = o2rProject($f, [$f['partner']->id]);
            $before = DB::table('activity_log')->count();
            $suspend = ['op' => 'suspend', 'target' => $f['actor']->id, 'actor' => $f['admin']->id];
            $sync = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['org2']->id]];

            $ran = $order === 'xy'
                ? o2rPair($suspend, PSR_USER_EXCL, $sync, O2R_USERS_SHARE_SET)
                : o2rPair($sync, O2R_USERS_SHARE_SET, $suspend, PSR_USER_EXCL);

            [$suspension, $synchronization] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];

            expect($suspension['outcome'])->toBe('ok')
                ->and(DB::table('users')->where('id', $f['actor']->id)->value('disabled_at'))->not->toBeNull();

            if ($order === 'xy') {
                // Refused from the locked row, before any write: the links are exactly what they were (the hidden one included), and nothing but the suspension was audited.
                expect($synchronization['outcome'])->toBe('unauthorized')
                    ->and($synchronization['transactions'])->toBe(1)->and($synchronization['rollbacks'])->toBe(1)
                    ->and(o2rLinks($project))->toBe([$f['partner']->id], 'a suspended actor changed the links')
                    ->and(collect($synchronization['statements'])->filter(fn ($s) => str_contains($s['sql'], '`project_organization`') && ! str_starts_with($s['sql'], 'select'))->count())->toBe(0)
                    ->and(DB::table('activity_log')->count() - $before)->toBe(1, 'only the suspension is audited');
            } else {
                expect($synchronization['outcome'])->toBe('ok')
                    ->and(o2rLinks($project))->toBe([$f['org2']->id], 'an operator\'s submission replaced the set; the suspension followed');
            }
        } finally {
            o2rTeardown($f);
        }
    });

    it('C20b (G-8) a Project creation by a creator whose suspension committed first is REFUSED whole; one that ran first completes ['.($order === 'xy' ? 'suspension first, creation blocked' : 'creation first, suspension blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $suspend = ['op' => 'suspend', 'target' => $f['actor']->id, 'actor' => $f['admin']->id];
            $create = ['op' => 'project_create', 'actor' => $f['actor']->id, 'data' => ['name' => 'C20b Project', 'companies' => [$f['org2']->id], 'members' => [['user_id' => $f['member']->id, 'role' => 'member']]]];

            $ran = $order === 'xy'
                ? o2rPair($suspend, PSR_USER_EXCL, $create, O2R_USERS_SHARE_SET)
                : o2rPair($create, O2R_USERS_SHARE_SET, $suspend, PSR_USER_EXCL);

            [$suspension, $creation] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];
            $project = DB::table('projects')->where('name', 'C20b Project')->first();

            expect($suspension['outcome'])->toBe('ok')
                ->and(DB::table('users')->where('id', $f['actor']->id)->value('disabled_at'))->not->toBeNull();

            if ($order === 'xy') {
                expect($creation['outcome'])->toBe('unauthorized')->and($creation['transactions'])->toBe(1)->and($creation['rollbacks'])->toBe(1)
                    ->and($project)->toBeNull('a suspended creator created a Project')
                    ->and(DB::table('project_organization')->count())->toBe(0)
                    ->and(DB::table('project_members')->whereIn('project_id', DB::table('projects')->where('id', '>', $f['mark']['projects'])->select('id'))->count())->toBe(0)
                    ->and(DB::table('project_columns')->whereIn('project_id', DB::table('projects')->where('id', '>', $f['mark']['projects'])->select('id'))->count())->toBe(0);
            } else {
                expect($creation['outcome'])->toBe('ok')->and($project)->not->toBeNull()
                    ->and(o2rLinks($project->id))->toBe([$f['org2']->id])
                    ->and(DB::table('project_columns')->where('project_id', $project->id)->count())->toBe(5);
            }
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── C21: Disabling an Organization vs a NEW Project link to it (CP8, owner ruling B-2) ────────
//
// Disabling takes the Organization row exclusively (actor row first); the Project writers hold it SHARED while they read its portal state, so the two serialize on that row and the
// order decides, with no lock the writers did not already take. A link COMMITTED before the disabling stays (an existing link is never judged); a non-operator's NEW link attempted
// after it is refused with the message of an unknown id; an unrestricted operator may link a Disabled Organization either way.

foreach (['xy', 'yx'] as $order) {
    $label = $order === 'xy' ? 'disable first, link blocked' : 'link first, disable blocked';

    it('C21 a non-operator\'s NEW link by synchronization vs disabling the Organization: refused if the disabling committed first, kept if the link did ['.$label.']', function () use ($order) {
        $f = o2rFixture();

        try {
            $project = o2rProject($f, [$f['partner']->id]);   // a hidden link that must survive
            $disable = ['op' => 'disable', 'organization' => $f['org']->id, 'actor' => $f['actor']->id];
            $sync = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['member']->id, 'ids' => [$f['org']->id]];

            $ran = $order === 'xy'
                ? o2rPair($disable, PSR_ORG_EXCL, $sync, O2R_ORGS_SHARE_ASC)
                : o2rPair($sync, O2R_ORGS_SHARE_ASC, $disable, PSR_ORG_EXCL);

            [$disabling, $synchronization] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];

            expect($disabling['outcome'])->toBe('ok')->and(DB::table('organizations')->where('id', $f['org']->id)->value('disabled_at'))->not->toBeNull();

            if ($order === 'xy') {
                expect($synchronization['outcome'])->toBe('validation:companies')->and($synchronization['detail'])->toBe('The selected organization is invalid.')
                    ->and(o2rLinks($project))->toBe([$f['partner']->id], 'a link to a Disabled Organization was created by a non-operator');
            } else {
                // Committed before the disabling, so it stays after it.
                expect($synchronization['outcome'])->toBe('ok')->and(o2rLinks($project))->toBe(collect([$f['org']->id, $f['partner']->id])->sort()->values()->all());
            }
        } finally {
            o2rTeardown($f);
        }
    });

    it('C21 a non-operator\'s NEW link by Project creation vs disabling the Organization: refused whole if the disabling committed first ['.$label.']', function () use ($order) {
        $f = o2rFixture();

        try {
            $disable = ['op' => 'disable', 'organization' => $f['org']->id, 'actor' => $f['actor']->id];
            $create = ['op' => 'project_create', 'actor' => $f['member']->id, 'data' => ['name' => 'C21 Project', 'companies' => [$f['org']->id]]];

            $ran = $order === 'xy'
                ? o2rPair($disable, PSR_ORG_EXCL, $create, O2R_ORGS_SHARE_ASC)
                : o2rPair($create, O2R_ORGS_SHARE_ASC, $disable, PSR_ORG_EXCL);

            [$disabling, $creation] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];
            $project = DB::table('projects')->where('name', 'C21 Project')->first();

            expect($disabling['outcome'])->toBe('ok');

            if ($order === 'xy') {
                expect($creation['outcome'])->toBe('validation:companies')->and($creation['detail'])->toBe('The selected organization is invalid.')
                    ->and($project)->toBeNull('a refused creation left a Project behind')->and(DB::table('project_organization')->count())->toBe(0);
            } else {
                expect($creation['outcome'])->toBe('ok')->and($project)->not->toBeNull()->and(o2rLinks($project->id))->toBe([$f['org']->id])
                    ->and(DB::table('project_columns')->where('project_id', $project->id)->count())->toBe(5);
            }
        } finally {
            o2rTeardown($f);
        }
    });

    it('C21 an unrestricted operator may link the Organization whether the disabling committed first or not ['.$label.']', function () use ($order) {
        $f = o2rFixture();

        try {
            $project = o2rProject($f);
            $disable = ['op' => 'disable', 'organization' => $f['org']->id, 'actor' => $f['actor']->id];
            $sync = ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['other']->id, 'ids' => [$f['org']->id]];

            $ran = $order === 'xy'
                ? o2rPair($disable, PSR_ORG_EXCL, $sync, O2R_ORGS_SHARE_ASC)
                : o2rPair($sync, O2R_ORGS_SHARE_ASC, $disable, PSR_ORG_EXCL);

            [$disabling, $synchronization] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];

            expect($disabling['outcome'])->toBe('ok')->and($synchronization['outcome'])->toBe('ok')
                ->and(o2rLinks($project))->toBe([$f['org']->id])
                ->and(DB::table('organizations')->where('id', $f['org']->id)->value('disabled_at'))->not->toBeNull();
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── C16: Project creation vs suspension of a member (F-3) ────────────────────────────────────

foreach (['xy', 'yx'] as $order) {
    it('C16 (F-3) a Project created with a member who is being suspended locks every user row before any Organization row, and neither side is a victim ['.($order === 'xy' ? 'suspension first, creation blocked' : 'creation first, suspension blocked').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $suspend = ['op' => 'suspend', 'target' => $f['member']->id, 'actor' => $f['admin']->id];
            $create = ['op' => 'project_create', 'actor' => $f['actor']->id, 'data' => ['name' => 'C16 Project', 'companies' => [$f['org2']->id], 'members' => [['user_id' => $f['member']->id, 'role' => 'member']]]];

            $ran = $order === 'xy'
                ? o2rPair($suspend, PSR_USER_EXCL, $create, O2R_USERS_SHARE_SET)
                : o2rPair($create, O2R_USERS_SHARE_SET, $suspend, PSR_USER_EXCL);

            [$suspension, $creation] = $order === 'xy' ? [$ran['first'], $ran['second']] : [$ran['second'], $ran['first']];
            $project = DB::table('projects')->where('name', 'C16 Project')->first();

            // Nothing is half-done: the Project, its five columns, both members and its one link, or none of them (here: all, the creation does not refuse a suspended member).
            expect($suspension['outcome'])->toBe('ok')->and($creation['outcome'])->toBe('ok')
                ->and(DB::table('users')->where('id', $f['member']->id)->value('disabled_at'))->not->toBeNull()
                ->and($project)->not->toBeNull()
                ->and(o2cProjectMembers($project->id))->toBe([$f['actor']->id => 'manager', $f['member']->id => 'member'])
                ->and(o2rLinks($project->id))->toBe([$f['org2']->id])
                ->and(DB::table('project_columns')->where('project_id', $project->id)->count())->toBe(5);

            // The order the creation took its locks in, from its own statement stream: the user rows FIRST (one statement: the creator and the member), then the Organizations,
            // then the writes. The member's row is no longer taken implicitly by the foreign key after the Organization row.
            $locks = o2rLockOrder($creation, ['users', 'organizations']);
            $userStatement = collect($creation['statements'])->first(fn ($s) => $s['transactional'] && str_contains($s['sql'], 'from `users`') && str_contains($s['sql'], 'lock in share mode'));

            expect(array_slice($locks, 0, 2))->toBe(['S users', 'S organizations'])
                ->and(array_keys(array_filter($locks, fn ($l) => $l === 'S users')))->toBe([0], 'a user row was locked after an Organization row')
                ->and(substr_count($userStatement['sql'], '?'))->toBe(2, 'one statement locks the creator AND the member')
                ->and($userStatement['sql'])->toContain('order by `id` asc');
        } finally {
            o2rTeardown($f);
        }
    });
}

it('C16 (F-3) proves from the engine that the user rows are locked in ascending order: while the creation waits for the higher id, the lower one is already held', function () {
    $f = o2rFixture();

    try {
        expect($f['actor']->id)->toBeLessThan($f['member']->id);

        // The suspension holds the HIGHER id (the member) exclusively. The creation's one statement locks the creator (lower) and the member (higher), ascending: it acquires the
        // creator, then waits for the member. A third connection asks for the creator's row: held, so its lock wait times out. In descending order it would wait for the member
        // first and hold nothing, and the creator's row would be free.
        $ran = o2rPair(
            ['op' => 'suspend', 'target' => $f['member']->id, 'actor' => $f['admin']->id], PSR_USER_EXCL,
            ['op' => 'project_create', 'actor' => $f['actor']->id, 'data' => ['name' => 'C16b Project', 'members' => [['user_id' => $f['member']->id, 'role' => 'member']]]], O2R_USERS_SHARE_SET,
            ['whileBlocked' => fn () => o2rHeldLocks([$f['actor']->id, $f['member']->id], 'users')],
        );

        // The member's row is the suspension's (exclusive): not asked for as free either. What matters is the creator's.
        expect(in_array($f['actor']->id, $ran['probe'], true))->toBeTrue('the creator\'s row (lower id) was not held while the creation waited for the member (higher id): the locks are not taken in ascending order');
    } finally {
        o2rTeardown($f);
    }
});

// ── C17: Project creation vs deletion of one of two Organizations ────────────────────────────

foreach (['xy', 'yx'] as $order) {
    it('C17 a Project created with two Organizations against the deletion of the HIGHER one: ascending order from the engine, a controlled refusal, no orphan ['.($order === 'xy' ? 'deletion first' : 'creation first').']', function () use ($order) {
        $f = o2rFixture();

        try {
            $low = o2rDirectoryOrg($f, 'C17 Low');
            $high = o2rDirectoryOrg($f, 'C17 High');
            expect($low->id)->toBeLessThan($high->id);

            $delete = ['op' => 'dir_delete', 'organization' => $high->id, 'actor' => $f['actor']->id];
            // Submitted in DESCENDING order on purpose: the service sorts.
            $create = ['op' => 'project_create', 'actor' => $f['other']->id, 'data' => ['name' => 'C17 Project', 'companies' => [$high->id, $low->id]]];

            if ($order === 'xy') {
                // The deletion holds the higher Organization. The creation's ascending statement acquires the LOWER one, then waits: the third connection finds the lower one held.
                $ran = o2rPair($delete, PSR_ORG_EXCL, $create, O2R_ORGS_SHARE_ASC, ['whileBlocked' => fn () => o2rHeldLocks([$low->id])]);

                expect($ran['probe'])->toBe([$low->id], 'the lower Organization was not held while the creation waited for the higher one: the locks are not taken in ascending order')
                    ->and($ran['first']['outcome'])->toBe('ok')->and($ran['second']['outcome'])->toBe('validation:companies')
                    ->and(DB::table('projects')->where('name', 'C17 Project')->exists())->toBeFalse('a refused creation left a Project behind')
                    ->and(DB::table('organizations')->where('id', $low->id)->exists())->toBeTrue()->and(DB::table('organizations')->where('id', $high->id)->exists())->toBeFalse()
                    ->and(DB::table('project_organization')->count())->toBe(0);
            } else {
                $ran = o2rPair($create, O2R_ORGS_SHARE_ASC, $delete, PSR_ORG_EXCL);
                $project = DB::table('projects')->where('name', 'C17 Project')->first();

                expect($ran['first']['outcome'])->toBe('ok')->and($ran['second']['outcome'])->toBe('directory:has_projects')
                    ->and(o2rLinks($project->id))->toBe([$low->id, $high->id])
                    ->and(DB::table('organizations')->whereIn('id', [$low->id, $high->id])->count())->toBe(2);
            }
        } finally {
            o2rTeardown($f);
        }
    });
}

// ── The ascending order against a REAL opposing writer ───────────────────────────────────────

it('a synchronization and an assignment of a customer to a partner (which locks the same two Organizations, ascending) never deadlock, even lined up to be granted their first lock together', function () {
    $f = o2rFixture();
    $release = null;

    try {
        $project = o2rProject($f);
        expect($f['partner']->id)->toBeLessThan($f['cust']->id);
        $before = o2rCounters();

        // A third connection holds BOTH Organizations exclusively. Both workers then queue behind it for their first lock. When it commits, each is granted its first row at the same
        // instant and goes on to ask for the second: the one interleaving of two multi-row statements in which opposite orders deadlock. In the same order, they cannot.
        $release = o2rBlock([$f['partner']->id, $f['cust']->id]);
        $sync = psrStart(['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['partner']->id, $f['cust']->id]]);
        $assign = psrStart(['op' => 'assign_customer', 'customer' => $f['cust']->id, 'partner' => $f['partner']->id, 'actor' => $f['other']->id]);

        expect(o2rWaitForWaits(2))->toBeTrue('both workers were not waiting behind the blocker');
        $release();
        $release = null;

        [$syncResult, $assignResult] = [psrResult($sync), psrResult($assign)];

        // The engine's own counter FIRST: a deadlock victim is retried by the framework and ends `ok`, so the outcome alone would hide it.
        expect(o2rCounters()['deadlocks'] - $before['deadlocks'])->toBe(0, 'the server recorded a deadlock between the two Organization lock statements')
            ->and($syncResult['transactions'])->toBe(1, 'the synchronization was retried')->and($assignResult['transactions'])->toBe(1, 'the assignment was retried')
            ->and($syncResult['outcome'])->toBe('ok')->and($assignResult['outcome'])->toBe('ok')
            ->and($syncResult['rollbacks'])->toBe(0)->and($assignResult['rollbacks'])->toBe(0)
            ->and(o2rLinks($project))->toBe([$f['partner']->id, $f['cust']->id])
            ->and((int) DB::table('organizations')->where('id', $f['cust']->id)->value('managed_by_organization_id'))->toBe($f['partner']->id);
    } finally {
        if ($release !== null) {
            $release();
        }
        o2rTeardown($f);
    }
});

// ── C18: the bounded restart ─────────────────────────────────────────────────────────────────

/** A committed link from the test's own connection: the change another request's commit would make while the worker waited. */
function o2cInterfere(int $project, int $organization): void
{
    DB::table('project_organization')->insert(['project_id' => $project, 'organization_id' => $organization, 'created_at' => now(), 'updated_at' => now()]);
}

it('C18 a synchronization whose links changed while it waited restarts and succeeds on the second attempt: attempts 2, no rollback, no deadlock', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f);
        $result = o2rFreezeEvery(
            ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['org2']->id]],
            O2R_ORGS_SHARE_ASC,
            fn (int $attempt) => $attempt === 1 ? o2cInterfere($project->id, $f['cust']->id) : null,
        );

        expect($result['freezes'])->toBe(2)->and($result['outcome'])->toBe('ok')
            ->and($result['transactions'])->toBe(2)->and($result['rollbacks'])->toBe(0)->and($result['deadlocks'])->toBe(0)
            // Attempt 2 locked the Organization the first attempt had not planned for (the interfering link) and then replaced the set: an operator's submission is the set.
            ->and(o2rLinks($project))->toBe([$f['org2']->id]);
    } finally {
        o2rTeardown($f);
    }
});

it('C18 a synchronization that restarts four times still succeeds on the fifth and last attempt', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f);
        $others = collect(range(1, 4))->map(fn ($n) => o2rDirectoryOrg($f, "C18 Filler {$n}")->id)->all();

        $result = o2rFreezeEvery(
            ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['org2']->id]],
            O2R_ORGS_SHARE_ASC,
            fn (int $attempt) => $attempt <= 4 ? o2cInterfere($project->id, $others[$attempt - 1]) : null,
        );

        expect($result['freezes'])->toBe(5)->and($result['outcome'])->toBe('ok')
            ->and($result['transactions'])->toBe(5)->and($result['rollbacks'])->toBe(0)->and($result['deadlocks'])->toBe(0)
            ->and(o2rLinks($project))->toBe([$f['org2']->id]);
    } finally {
        o2rTeardown($f);
    }
});

it('C19 a synchronization whose links change on every attempt is refused after five, with a controlled 409 and NO write of its own', function () {
    $f = o2rFixture();

    try {
        $project = o2rProject($f);
        $others = collect(range(1, 5))->map(fn ($n) => o2rDirectoryOrg($f, "C19 Filler {$n}")->id)->all();
        $audit = DB::table('activity_log')->count();

        $result = o2rFreezeEvery(
            ['op' => 'project_sync', 'project' => $project->id, 'actor' => $f['actor']->id, 'ids' => [$f['org2']->id]],
            O2R_ORGS_SHARE_ASC,
            fn (int $attempt) => o2cInterfere($project->id, $others[$attempt - 1]),
        );

        expect($result['freezes'])->toBe(5)
            ->and($result['outcome'])->toBe('conflict')->and($result['detail'])->toBe('The linked organizations kept changing; try again.')
            ->and($result['transactions'])->toBe(5, 'exactly the bounded number of attempts')->and($result['deadlocks'])->toBe(0)
            // The worker wrote nothing: the links are exactly the five the test itself committed, and the requested one is absent.
            ->and(o2rLinks($project))->toBe(collect($others)->sort()->values()->all())
            ->and(DB::table('activity_log')->count())->toBe($audit);

        // None of its transactions wrote: every one of them was an empty commit (a restart), never a rollback of a write.
        expect(collect($result['statements'])->filter(fn ($s) => str_starts_with($s['sql'], 'insert into `project_organization`') || str_starts_with($s['sql'], 'delete from `project_organization`'))->count())->toBe(0);
    } finally {
        o2rTeardown($f);
    }
});
