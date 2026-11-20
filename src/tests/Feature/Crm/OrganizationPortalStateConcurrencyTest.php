<?php

/*
 * Release 1.0 (ORG-1): disable against every act that the portal-state fence guards, on REAL MariaDB with two independent PHP processes (two real
 * connections), each pair in BOTH orders (ORG-0 record, sections 6 and 6.1; ADR-011 section 13). A single connection cannot show lock order or who
 * blocks whom, so nothing here is inferred from reading code. The method and its harness are R1-3A's; see tests/Support/portal_state_race_support.php.
 *
 * WHAT IS ASSERTED, per pair and per order. (1) the second operation really waited on the first; (2) NEITHER ended in a deadlock or a lock-wait timeout, and
 * neither transaction was silently retried as a victim; (3) the final COMMITTED state is exactly the one the design predicts for that order:
 *
 *   disable first    the other act finds the Organization Disabled and is refused with its controlled reason, writing nothing (or, for an act that
 *                    takes authority away, proceeds);
 *   other act first  it commits, and disable then runs on the committed state: it revokes what is still open, and leaves an accepted, an earlier-revoked
 *                    or an operator-revoked invitation exactly as it is.
 *
 * (4) the statement stream of disable itself: the actor's user row (shared) first, then the Organization (exclusive), then the invitation rows (exclusive,
 * ascending), no statement on the cache tables inside the transaction, and the writes after the locks.
 *
 * Like the other concurrency tests this ends RefreshDatabase's transaction so worker processes can see its rows, builds a committed synchronized
 * installation, and removes everything it created in a finally block. It refuses to run if the testing database already holds committed roles or permissions.
 */

use App\Services\OrganizationInvitations;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/portal_state_race_support.php';

/** The disabling operation: `actor` disables `target`. @return array<string, mixed> */
function psrDisable(array $f, $target = null, $actor = null): array
{
    return ['op' => 'disable', 'organization' => ($target ?? $f['org'])->id, 'actor' => ($actor ?? $f['actor'])->id];
}

/**
 * Each scenario: the other operation (y), the statement each side freezes after when it runs FIRST and announces before when it runs SECOND, a setup, and the
 * expected committed outcome per order. The disabling operation is always x.
 *
 * @return array<string, array<string, mixed>>
 */
function psrScenarios(): array
{
    $reason = fn (int $id) => psrInvitation($id)->revoked_reason;

    return [
        'disable vs acceptance' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'setup' => fn ($f) => ['id' => ($i = psrInvite($f['org'], $f['issuer'], 'invitee@psr.test'))[0], 'token' => $i[1]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                expect(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull();
                if ($order === 'xy') {
                    // Disable committed first and revoked the invitation: the acceptance finds a revoked row and writes nothing.
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('refused:unavailable')->and($r['y']['detail'])->toBe('revoked')
                        ->and($reason($c['id']))->toBe('organization_disabled')
                        ->and(DB::table('users')->where('email', 'invitee@psr.test')->exists())->toBeFalse()
                        ->and(DB::table('organization_members')->where('organization_id', $f['org']->id)->whereNotIn('user_id', [$f['member']->id, $f['madmin']->id])->count())->toBe(0);
                } else {
                    // The acceptance committed first: an ordinary member exists, the invitation is accepted, and disable left it alone.
                    $row = psrInvitation($c['id']);
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('ok')
                        ->and($row->accepted_at)->not->toBeNull()->and($row->revoked_at)->toBeNull()
                        ->and(DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')->where('users.email', 'invitee@psr.test')->count())->toBe(1);
                }
            },
        ],
        'disable vs issue' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'late@psr.test', 'actor' => $f['issuer']->id],
            'setup' => fn ($f) => ['early' => psrInvite($f['org'], $f['other'], 'early@psr.test')[0]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                $late = DB::table('organization_invitations')->where('email', 'late@psr.test')->first();
                expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull()->and($reason($c['early']))->toBe('organization_disabled');
                if ($order === 'xy') {
                    expect($r['y']['outcome'])->toBe('refused:portal_refused')->and($late)->toBeNull();
                } else {
                    // Issued first, so disable found it open and revoked it too: no live invitation survives the disable.
                    expect($r['y']['outcome'])->toBe('ok')->and($late->revoked_reason)->toBe('organization_disabled')->and($late->live_email)->toBeNull();
                }
            },
        ],
        'disable vs member add' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'add_member', 'organization' => $f['org']->id, 'user' => $f['newcomer']->id, 'role' => 'member', 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['early' => psrInvite($f['org'], $f['other'], 'early@psr.test')[0]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $added = DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['newcomer']->id)->exists();
                expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull();
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('membership:portal_disabled')->and($added)->toBeFalse()
                    : expect($r['y']['outcome'])->toBe('ok')->and($added)->toBeTrue();
            },
        ],
        'disable vs promotion' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'promote_member', 'organization' => $f['org']->id, 'user' => $f['member']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['early' => psrInvite($f['org'], $f['other'], 'early@psr.test')[0]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $role = DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['member']->id)->value('role');
                expect($r['x']['outcome'])->toBe('ok');
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('membership:portal_disabled')->and($role)->toBe('member')
                    : expect($r['y']['outcome'])->toBe('ok')->and($role)->toBe('admin');
            },
        ],
        'disable vs administrator approval' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'approve_member', 'organization' => $f['org']->id, 'user' => $f['madmin']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['early' => psrInvite($f['org'], $f['other'], 'early@psr.test')[0]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $approved = DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['madmin']->id)->value('admin_approved_at');
                expect($r['x']['outcome'])->toBe('ok');
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('membership:portal_disabled')->and($approved)->toBeNull()
                    : expect($r['y']['outcome'])->toBe('ok')->and($approved)->not->toBeNull();
            },
        ],
        'disable vs support grant' => [
            'x' => fn ($f, $c) => psrDisable($f, $f['partner']),
            'y' => fn ($f, $c) => ['op' => 'grant_support', 'organization' => $f['partner']->id, 'user' => $f['pmember']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => [],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $granted = DB::table('organization_members')->where('organization_id', $f['partner']->id)->where('user_id', $f['pmember']->id)->value('support_granted_at');
                expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['partner']->id)->disabled_at)->not->toBeNull();
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('support:organization_disabled')->and($granted)->toBeNull()
                    : expect($r['y']['outcome'])->toBe('ok')->and($granted)->not->toBeNull();
            },
        ],
        'disable vs partner designation' => [
            'x' => fn ($f, $c) => psrDisable($f, $f['org2']),
            'y' => fn ($f, $c) => ['op' => 'designate', 'organization' => $f['org2']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => [],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORGS_ASC, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORGS_ASC,
            'expect' => function ($f, $c, $order, $r) {
                $row = psrOrganization($f['org2']->id);
                expect($r['x']['outcome'])->toBe('ok')->and($row->disabled_at)->not->toBeNull();
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('relationship:portal_disabled')->and((int) $row->can_manage_customers)->toBe(0)
                    : expect($r['y']['outcome'])->toBe('ok')->and((int) $row->can_manage_customers)->toBe(1);
            },
        ],
        'disable of the customer vs customer assignment' => [
            'x' => fn ($f, $c) => psrDisable($f, $f['cust']),
            'y' => fn ($f, $c) => ['op' => 'assign_customer', 'customer' => $f['cust']->id, 'partner' => $f['partner']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => [],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORGS_ASC, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORGS_ASC,
            'expect' => function ($f, $c, $order, $r) {
                $managedBy = psrOrganization($f['cust']->id)->managed_by_organization_id;
                expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['cust']->id)->disabled_at)->not->toBeNull();
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('relationship:portal_disabled')->and($managedBy)->toBeNull()
                    : expect($r['y']['outcome'])->toBe('ok')->and((int) $managedBy)->toBe($f['partner']->id);
            },
        ],
        'disable of the partner vs customer assignment' => [
            'x' => fn ($f, $c) => psrDisable($f, $f['partner']),
            'y' => fn ($f, $c) => ['op' => 'assign_customer', 'customer' => $f['cust']->id, 'partner' => $f['partner']->id, 'actor' => $f['other']->id],
            'setup' => fn ($f) => [],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORGS_ASC, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORGS_ASC,
            'expect' => function ($f, $c, $order, $r) {
                $managedBy = psrOrganization($f['cust']->id)->managed_by_organization_id;
                expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['partner']->id)->disabled_at)->not->toBeNull();
                $order === 'xy'
                    ? expect($r['y']['outcome'])->toBe('relationship:portal_disabled')->and($managedBy)->toBeNull()
                    : expect($r['y']['outcome'])->toBe('ok')->and((int) $managedBy)->toBe($f['partner']->id);
            },
        ],
        // The actor-first rule: the suspension of the very operator who disables holds that user row exclusively while it waits for invitation rows.
        'disable vs suspension of the disabling operator' => [
            'x' => fn ($f, $c) => psrDisable($f, null, $f['issuer']),
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['inv' => psrInvite($f['org'], $f['issuer'], 'issued-by-actor@psr.test')[0]],
            'x_freeze' => PSR_INV_SWEEP, 'y_freeze' => PSR_USER_EXCL, 'x_block' => PSR_USER_SHARE, 'y_block' => PSR_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                expect($r['y']['outcome'])->toBe('ok')->and(DB::table('users')->where('id', $f['issuer']->id)->value('disabled_at'))->not->toBeNull();
                if ($order === 'xy') {
                    // Disable (holding the actor's row shared, then the invitation rows) committed first; the suspension waited at the user row.
                    expect($r['x']['outcome'])->toBe('ok')->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull()->and($reason($c['inv']))->toBe('organization_disabled');
                } else {
                    // The suspension committed first and revoked the issuer's invitations; the disable's Gate answer was old, the TABLE answer under the lock was not.
                    expect($r['x']['outcome'])->toBe('unauthorized')->and(psrOrganization($f['org']->id)->disabled_at)->toBeNull()->and($reason($c['inv']))->toBe('issuer_suspended');
                }
            },
        ],
        'disable vs a user role change (issuer reconciliation)' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['r1' => psrInvite($f['org'], $f['r1'], 'by-r1@psr.test')[0], 'issuer' => psrInvite($f['org'], $f['issuer'], 'by-issuer@psr.test')[0]],
            'x_freeze' => PSR_INV_SWEEP, 'y_freeze' => PSR_INV_SWEEP, 'x_block' => PSR_INV_SWEEP, 'y_block' => PSR_INV_SWEEP,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull()
                    ->and($reason($c['issuer']))->toBe('organization_disabled')
                    // Whichever committed first revoked it, with its own reason, and the other left it exactly as it was.
                    ->and($reason($c['r1']))->toBe($order === 'xy' ? 'organization_disabled' : 'issuer_authority_lost');
            },
        ],
        'disable vs a role permission change (issuer reconciliation, cache fence)' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['portal.operator'], 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['r1' => psrInvite($f['org'], $f['r1'], 'by-r1@psr.test')[0], 'r2' => psrInvite($f['org'], $f['r2'], 'by-r2@psr.test')[0]],
            'x_freeze' => PSR_INV_SWEEP, 'y_freeze' => PSR_INV_SWEEP, 'x_block' => PSR_INV_SWEEP, 'y_block' => PSR_INV_SWEEP,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull();
                foreach (['r1', 'r2'] as $issuer) {
                    expect($reason($c[$issuer]))->toBe($order === 'xy' ? 'organization_disabled' : 'issuer_authority_lost');
                }
            },
        ],
        'disable vs an operator revoking the same invitation' => [
            'x' => fn ($f, $c) => psrDisable($f),
            'y' => fn ($f, $c) => ['op' => 'revoke', 'organization' => $f['org']->id, 'invitation' => $c['a'], 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['a' => psrInvite($f['org'], $f['issuer'], 'a@psr.test')[0], 'b' => psrInvite($f['org'], $f['issuer'], 'b@psr.test')[0]],
            'x_freeze' => PSR_ORG_EXCL, 'y_freeze' => PSR_ORG_EXCL, 'x_block' => PSR_ORG_EXCL, 'y_block' => PSR_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) use ($reason) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and($reason($c['b']))->toBe('organization_disabled')
                    ->and(psrOrganization($f['org']->id)->disabled_at)->not->toBeNull();
                if ($order === 'xy') {
                    // Disable revoked both; the operator's revoke found a terminal row and wrote nothing.
                    expect($r['y']['result']['revoked'])->toBeFalse()->and($reason($c['a']))->toBe('organization_disabled');
                } else {
                    // The operator revoked A first (reason operator, slot cleared); disable then took only B.
                    expect($r['y']['result']['revoked'])->toBeTrue()->and($reason($c['a']))->toBe('operator');
                }
            },
        ],
    ];
}

foreach (psrScenarios() as $name => $spec) {
    foreach (['xy', 'yx'] as $order) {
        it("{$name} [".($order === 'xy' ? 'disable first, the other blocked' : 'the other first, disable blocked').']', function () use ($spec, $order) {
            $fixture = psrFixture();

            try {
                $context = $spec['setup']($fixture);
                [$first, $second] = $order === 'xy' ? ['x', 'y'] : ['y', 'x'];

                $ran = psrInterleave(
                    $spec[$first]($fixture, $context), $spec[$first.'_freeze'],
                    $spec[$second]($fixture, $context), $spec[$second.'_block'],
                );

                $results = [$first => $ran['first'], $second => $ran['second']];
                $spec['expect']($fixture, $context, $order, $results);

                // Disable's own statement stream, whichever order it ran in: actor S, organization X, invitation rows X, before any write.
                $disable = psrLocks($results['x']);
                if (($results['x']['outcome'] ?? '') === 'ok' && in_array('X organization_invitations', $disable, true)) {
                    expect(array_slice($disable, 0, 3))->toBe(['S users', 'X organizations', 'X organization_invitations']);
                }
            } finally {
                psrTeardown($fixture);
            }
        });
    }
}

// ── Disable's statement stream, alone ────────────────────────────────────────

it('disable, in its own process, locks the actor then the organization then the invitations, runs no cache statement inside its transaction, and re-enable revives nothing', function () {
    $fixture = psrFixture();

    try {
        [$a] = psrInvite($fixture['org'], $fixture['issuer'], 'one@psr.test');
        [$b] = psrInvite($fixture['org'], $fixture['other'], 'two@psr.test');
        DB::table('cache')->delete();

        $result = psrResult(psrStart(psrDisable($fixture), ['CACHE_STORE' => 'database']));

        expect($result['outcome'])->toBe('ok');
        $inTransaction = collect($result['statements'])->filter(fn ($s) => $s['transactional']);
        $locks = psrLocks($result);

        expect($inTransaction->where('cache', true)->count())->toBe(0, 'a statement touched the cache tables inside the disabling transaction')
            ->and(array_slice($locks, 0, 3))->toBe(['S users', 'X organizations', 'X organization_invitations'])
            // Every lock comes before the first write: nothing is locked after a write of this transaction.
            ->and(collect($locks)->slice(3)->contains(fn ($l) => str_starts_with($l, 'S ') || str_starts_with($l, 'X ')))->toBeFalse()
            ->and(psrInvitation($a)->revoked_reason)->toBe(OrganizationInvitations::REASON_ORGANIZATION_DISABLED)
            ->and(psrInvitation($b)->revoked_reason)->toBe(OrganizationInvitations::REASON_ORGANIZATION_DISABLED);

        $enabled = psrResult(psrStart(['op' => 'enable', 'organization' => $fixture['org']->id, 'actor' => $fixture['actor']->id]));

        expect($enabled['outcome'])->toBe('ok')
            ->and(psrOrganization($fixture['org']->id)->disabled_at)->toBeNull()
            ->and(psrInvitation($a)->revoked_at)->not->toBeNull()->and(psrInvitation($b)->revoked_at)->not->toBeNull();
    } finally {
        DB::table('cache')->delete();
        psrTeardown($fixture);
    }
});
