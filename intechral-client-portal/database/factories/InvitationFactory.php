<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email'      => fake()->unique()->safeEmail(),
            'token'      => Str::random(64),
            'invited_by' => null,
            'status'     => 'pending',
            'expires_at' => now()->addHours(48),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'expires_at' => now()->addHours(48)]);
    }

    public function expired(): static
    {
        return $this->state(['status' => 'expired', 'expires_at' => now()->subHour()]);
    }

    public function accepted(): static
    {
        return $this->state(['status' => 'accepted', 'accepted_at' => now()]);
    }
}
