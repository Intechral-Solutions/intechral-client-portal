<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
 * Shared fixtures for the R1-3B registration suites (the HTTP face of organization invitations). Loaded with require_once; the file name does not
 * end in "Test" so Pest does not run it. Function names carry the `regInv` prefix (Pest loads every test file into one process). The foundation's
 * own helpers (`oinv*`) live in OrganizationInvitationFlowTest and are not available when a single file is run.
 */

/** An active account holding portal.operator AND crm.manage directly: a membership manager, hence a valid issuer. */
function regInvIssuer(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->givePermissionTo(['portal.operator', 'crm.manage']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * An open invitation written straight to the table (the service is the only writer in app/; a factory is a test fixture), with the plaintext
 * token the test knows. Returns [invitation, token].
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function regInvOpen(string $email = 'invited@example.test', ?Organization $organization = null, ?User $issuer = null, array $state = []): array
{
    $issuer ??= regInvIssuer();
    $organization ??= Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $issuer->id]);
    $token = Str::random(64);

    $invitation = OrganizationInvitation::factory()
        ->withToken($token)
        ->forAddress($email)
        ->state(['organization_id' => $organization->id, 'invited_by' => $issuer->id] + $state)
        ->create();

    return [$invitation, $token];
}

/** A password that passes every rule of the registration form. */
const REG_INV_PASSWORD = 'Str0ng!Password99';

function regInvForm(array $extra = []): array
{
    return array_merge(['name' => 'New Person', 'password' => REG_INV_PASSWORD, 'password_confirmation' => REG_INV_PASSWORD], $extra);
}

/** What acceptance can write, so "a refused attempt writes nothing" is a comparison. */
function regInvFootprint(): array
{
    return [
        'users' => DB::table('users')->count(),
        'members' => DB::table('organization_members')->count(),
        'roles' => DB::table('model_has_roles')->count(),
        'histories' => DB::table('password_histories')->count(),
        'social' => DB::table('social_accounts')->count(),
        'activity' => DB::table('activity_log')->count(),
        'invitations' => DB::table('organization_invitations')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}
