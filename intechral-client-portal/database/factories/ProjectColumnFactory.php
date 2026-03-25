<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectColumnFactory extends Factory
{
    protected $model = ProjectColumn::class;

    public function definition(): array
    {
        return [
            'project_id'     => Project::factory(),
            'name'           => $this->faker->randomElement(['Backlog', 'To Do', 'In Progress', 'In Review', 'Done']),
            'position'       => $this->faker->numberBetween(0, 10),
            'is_done_column' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(['name' => 'Done', 'is_done_column' => true]);
    }
}
