<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationInvitations;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows for tests, written directly (the service is the only writer in `app/`; a factory is a test fixture). The table holds only the
 * HASH of the token, so a test that needs the plaintext passes its own: `->withToken($token)`. The default row is open and holds its
 * (organization, address) slot, exactly as issuance leaves it.
 *
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        $email = OrganizationInvitations::normalizeEmail(fake()->unique()->safeEmail());

        return [
            'organization_id' => Organization::factory(),
            'email' => $email,
            'token_hash' => OrganizationInvitations::hashToken(Str::random(64)),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(OrganizationInvitations::LIFETIME_DAYS),
            'live_email' => $email,
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => OrganizationInvitations::hashToken($token)]);
    }

    public function forAddress(string $email): static
    {
        $email = OrganizationInvitations::normalizeEmail($email);

        return $this->state(['email' => $email, 'live_email' => $email]);
    }

    /** Expired by time only: it keeps the slot, because nothing runs at the moment of expiry. */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    /** Slot released, as acceptance leaves it. */
    public function accepted(User $user): static
    {
        return $this->state(['accepted_at' => now(), 'accepted_user_id' => $user->getKey(), 'live_email' => null]);
    }

    /** Slot released, as every revocation leaves it. */
    public function revoked(string $reason = OrganizationInvitations::REASON_OPERATOR, ?User $by = null): static
    {
        return $this->state(['revoked_at' => now(), 'revoked_by' => $by?->getKey(), 'revoked_reason' => $reason, 'live_email' => null]);
    }

    public function withoutSlot(): static
    {
        return $this->state(['live_email' => null]);
    }
}
