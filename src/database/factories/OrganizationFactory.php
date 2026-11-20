<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Enabled by default (Release 1.0, ORG-1): the existing invitation, membership, partner-support and ticket suites are the control group of
     * the portal-state fence, so every Organization a test creates is a portal Organization unless it says otherwise. `portal_enabled_by` is
     * the operator who enabled it; pass it to name one, or `portal_enabled_at` to choose the instant.
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'portal_enabled_by' => User::factory(),
            'portal_enabled_at' => now(),
        ];
    }

    /**
     * A Directory-only (Not enabled) Organization: no enabling operator, no instant. FOR THE FENCE TESTS ONLY: until ORG-2 no application path
     * creates one, and a test that wants a portal Organization must never reach for this state.
     */
    public function directoryOnly(): static
    {
        return $this->state(fn () => ['portal_enabled_by' => null, 'portal_enabled_at' => null]);
    }

    /** An Enabled-once Organization that is now Disabled. */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['disabled_at' => now()]);
    }
}
