<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketReplyFactory extends Factory
{
    protected $model = TicketReply::class;

    public function definition(): array
    {
        return [
            'ticket_id'   => Ticket::factory(),
            'user_id'     => User::factory(),
            'body'        => $this->faker->paragraph(),
            'is_internal' => false,
        ];
    }

    public function internal(): static
    {
        return $this->state(['is_internal' => true]);
    }
}
