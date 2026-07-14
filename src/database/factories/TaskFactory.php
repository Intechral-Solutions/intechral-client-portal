<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'column_id' => ProjectColumn::factory(),
            'ticket_id' => null,
            'milestone_id' => null,
            'assignee_id' => null,
            'created_by' => User::factory(),
            'title' => $this->faker->sentence(5),
            'description' => $this->faker->optional()->paragraph(),
            'due_date' => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'position' => $this->faker->numberBetween(0, 100),
            'status' => 'todo',
        ];
    }

    public function overdue(): static
    {
        return $this->state(['due_date' => now()->subDay()]);
    }

    public function standalone(): static
    {
        return $this->state(['project_id' => null, 'column_id' => null]);
    }
}
