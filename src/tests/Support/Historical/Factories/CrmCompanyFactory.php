<?php

namespace Tests\Support\Historical\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Support\Historical\Models\CrmCompany;

/** Test-only: a pre-ORG-2 `crm_companies` row. */
class CrmCompanyFactory extends Factory
{
    protected $model = CrmCompany::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'website' => $this->faker->optional()->url(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->optional()->address(),
            'notes' => null,
            'organization_id' => null,
            'created_by' => User::factory(),
        ];
    }

    public function promoted(): static
    {
        return $this->state(fn () => ['organization_id' => OrganizationFactory::new()]);
    }
}
