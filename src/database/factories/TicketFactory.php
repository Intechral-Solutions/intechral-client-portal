<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $priority = $this->faker->randomElement(['low', 'medium', 'high', 'critical']);
        $status = $this->faker->randomElement(['open', 'in_progress', 'pending_user', 'resolved', 'closed']);
        $hours = Ticket::SLA_HOURS[$priority];

        return [
            // Factory numbers are 'TKT-F' + six digits. TicketService numbers are 'TKT-' + digits
            // (its ticket's id), so the two namespaces can never collide, and the seeded
            // fixture 'TKT-E2E1' stays outside both. Tests that need a specific value pass one.
            'ticket_number' => 'TKT-F'.str_pad($this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'user_id' => User::factory(),
            'assignee_id' => null,
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->paragraphs(2, true),
            'category' => $this->faker->randomElement(['General', 'Technical', 'Billing', 'Account', 'Other']),
            'priority' => $priority,
            'status' => $status,
            'sla_due_at' => now()->addHours($hours),
            'resolved_at' => in_array($status, ['resolved', 'closed']) ? now()->subHour() : null,
            'closed_at' => $status === 'closed' ? now() : null,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'open', 'resolved_at' => null, 'closed_at' => null]);
    }

    public function resolved(): static
    {
        return $this->state(['status' => 'resolved', 'resolved_at' => now()->subHour(), 'closed_at' => null]);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed', 'resolved_at' => now()->subDay(), 'closed_at' => now()]);
    }

    public function overdue(): static
    {
        return $this->state(['status' => 'open', 'sla_due_at' => now()->subHour()]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_id' => $user->id]);
    }
}
