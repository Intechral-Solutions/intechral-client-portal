<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskChecklistItemFactory extends Factory
{
    protected $model = TaskChecklistItem::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'title' => $this->faker->sentence(4),
            'completed' => false,
            'position' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(['completed' => true]);
    }
}
