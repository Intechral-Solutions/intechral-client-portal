<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\Classification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Enabled by default (Release 1.0, ORG-1): the existing invitation, membership, partner-support and ticket suites are the control group of
     * the portal-state fence, so every Organization a test creates is a portal Organization unless it says otherwise. `portal_enabled_by` is
     * the operator who enabled it; pass it to name one, or `portal_enabled_at` to choose the instant.
     *
     * ORG-2: `created_by` is NOT NULL. An Organization a test makes is, unless it says otherwise, created by the operator who enabled it (the
     * interpretation the migration's backfill records). The closure sees `portal_enabled_by` after an override of it. `classification` takes its
     * column default (`general`) and the profile columns are empty. The schema this factory serves is the canonical one; the tests that build the
     * schema as it was before ORG-2 use tests/Support/Historical/Factories.
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'portal_enabled_by' => User::factory(),
            'portal_enabled_at' => now(),
            'created_by' => fn (array $attributes) => $attributes['portal_enabled_by'],
        ];
    }

    /**
     * A Directory-only (Not enabled) Organization: no enabling operator, no instant. It has a creator, as every Organization does (`created_by` is NOT NULL),
     * and it has no portal authority of any kind.
     */
    public function directoryOnly(): static
    {
        return $this->state(fn () => ['portal_enabled_by' => null, 'portal_enabled_at' => null, 'created_by' => User::factory()]);
    }

    /** An Enabled-once Organization that is now Disabled. */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['disabled_at' => now()]);
    }

    /** With a Directory profile (website, phone, address, notes) a test can look for. */
    public function withProfile(): static
    {
        return $this->state(fn () => [
            'website' => 'https://'.Str::slug($this->faker->unique()->company()).'.example.test',
            'phone' => $this->faker->numerify('+27 ## ### ####'),
            'address' => $this->faker->address(),
            'notes' => 'Internal note '.$this->faker->unique()->numerify('######'),
        ]);
    }

    public function classified(Classification $classification): static
    {
        return $this->state(fn () => ['classification' => $classification->value]);
    }
}
