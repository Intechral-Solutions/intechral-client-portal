<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Helpdesk\TicketReferenceGenerator;
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
            // A random canonical reference, like TicketService issues (C6-R); the model refuses anything else. The seeded fixtures are
            // 'TK-E2E00-0000n'. Tests that need a specific value pass one.
            'ticket_number' => app(TicketReferenceGenerator::class)->next(),
            'user_id' => User::factory(),
            // R1-2: every ticket belongs to an organization and has a submitter (NOT NULL). A factory ticket is its requester's own
            // submission in a fresh organization. It writes NO membership: attribution is provenance, and the tests that exercise who may
            // CREATE a ticket go through TicketService (see `ticketMemberOf()`); `->for($org, 'organization')` / `->state([...])` override.
            'organization_id' => Organization::factory(),
            'submitted_by_id' => fn (array $attributes) => $attributes['user_id'],
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

    public function inOrganization(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->id]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_id' => $user->id]);
    }
}
