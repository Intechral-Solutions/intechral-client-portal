<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-6 months', 'now');

        return [
            'name' => $this->faker->words(3, true).' Project',
            'description' => $this->faker->paragraph(),
            'created_by' => User::factory(),
            'client_id' => null,
            'start_date' => $start,
            'target_date' => $this->faker->dateTimeBetween($start, '+6 months'),
            'status' => 'active',
            'budget' => $this->faker->optional()->randomFloat(2, 1000, 50000),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function onHold(): static
    {
        return $this->state(['status' => 'on_hold']);
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed']);
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}
