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
            // A board task's column belongs to the task's own project.
            'column_id' => fn (array $attributes) => ProjectColumn::factory()->create([
                'project_id' => $attributes['project_id'],
            ])->id,
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

    /** A board task in the given column; the project is always the column's own. */
    public function inColumn(ProjectColumn $column): static
    {
        return $this->state([
            'project_id' => $column->project_id,
            'column_id' => $column->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_id' => $user->id]);
    }

    public function standalone(): static
    {
        return $this->state(['project_id' => null, 'column_id' => null]);
    }
}
