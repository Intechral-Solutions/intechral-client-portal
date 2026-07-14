<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 5000);
        $taxRate = $this->faker->randomElement([0, 5, 10, 15, 20]);
        $taxAmt = round($subtotal * $taxRate / 100, 2);

        return [
            'invoice_number' => 'INV-'.str_pad($this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'client_id' => User::factory(),
            'created_by' => User::factory(),
            'project_id' => null,
            'issued_at' => now()->subDays(rand(0, 30))->toDateString(),
            'due_at' => now()->addDays(rand(1, 30))->toDateString(),
            'paid_at' => null,
            'sent_at' => null,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmt,
            'total' => round($subtotal + $taxAmt, 2),
            'currency' => 'USD',
            'status' => 'draft',
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft', 'sent_at' => null]);
    }

    public function sent(): static
    {
        return $this->state(['status' => 'sent', 'sent_at' => now()]);
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid', 'sent_at' => now()->subDays(3), 'paid_at' => now()]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => 'overdue',
            'sent_at' => now()->subDays(40),
            'due_at' => now()->subDays(10)->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}
