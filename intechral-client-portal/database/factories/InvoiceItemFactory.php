<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $qty = $this->faker->randomFloat(2, 1, 10);
        $unitPrice = $this->faker->randomFloat(2, 10, 500);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => $this->faker->sentence(4),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'amount' => round($qty * $unitPrice, 2),
            'position' => 0,
        ];
    }
}
