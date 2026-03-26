<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name'     => $name,
            'slug'     => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'owner_id' => User::factory(),
        ];
    }
}
