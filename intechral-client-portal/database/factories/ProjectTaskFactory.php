<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        return [
            'project_id'  => Project::factory(),
            'column_id'   => ProjectColumn::factory(),
            'milestone_id'=> null,
            'assignee_id' => null,
            'created_by'  => User::factory(),
            'title'       => $this->faker->sentence(5),
            'description' => $this->faker->optional()->paragraph(),
            'due_date'    => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'priority'    => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'position'    => $this->faker->numberBetween(0, 100),
        ];
    }

    public function overdue(): static
    {
        return $this->state(['due_date' => now()->subDay()]);
    }
}
