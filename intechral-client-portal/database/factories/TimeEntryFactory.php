<?php

namespace Database\Factories;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'project_id'       => null,
            'task_id'          => null,
            'invoice_id'       => null,
            'date'             => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'duration_minutes' => $this->faker->numberBetween(15, 480),
            'description'      => $this->faker->sentence(6),
            'billable'         => true,
            'billed'           => false,
            'timer_started_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state([
            'duration_minutes' => 0,
            'timer_started_at' => now()->subMinutes(rand(1, 60)),
        ]);
    }

    public function nonBillable(): static
    {
        return $this->state(['billable' => false]);
    }

    public function billed(): static
    {
        return $this->state(['billed' => true]);
    }
}
