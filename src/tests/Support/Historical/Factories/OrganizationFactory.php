<?php

namespace Tests\Support\Historical\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only: an Organization as it was BEFORE ORG-2 (portal-state columns, no `created_by`, no profile, no classification). The production factory serves
 * only the canonical schema; a test under tests/Historical gets this one (HistoricalTestCase redirects factory discovery).
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

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

    public function directoryOnly(): static
    {
        return $this->state(fn () => ['portal_enabled_by' => null, 'portal_enabled_at' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['disabled_at' => now()]);
    }
}
