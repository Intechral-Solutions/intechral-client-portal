<?php

namespace Database\Factories;

use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CmsPageFactory extends Factory
{
    protected $model = CmsPage::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);

        return [
            'slug'         => Str::slug($title) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'title'        => $title,
            'body'         => $this->faker->paragraphs(3, true),
            'status'       => 'draft',
            'published_at' => null,
            'created_by'   => User::factory(),
            'updated_by'   => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status'       => 'published',
            'published_at' => now(),
        ]);
    }
}
