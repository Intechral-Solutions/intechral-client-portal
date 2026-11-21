<?php

namespace Tests\Support\Historical\Factories;

use App\Models\CrmContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test-only: a Person as it was BEFORE ORG-2 (affiliated through `crm_company_id`). */
class CrmContactFactory extends Factory
{
    protected $model = CrmContact::class;

    public function definition(): array
    {
        return [
            'crm_company_id' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->optional()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'job_title' => $this->faker->optional()->jobTitle(),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
