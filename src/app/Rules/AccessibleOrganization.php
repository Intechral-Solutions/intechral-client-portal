<?php

namespace App\Rules;

use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Require an Organization visible to the current actor through Organization's own scope (an operator sees all; anyone else only the Organizations they
 * belong to). An unknown id and an id the actor cannot see fail with the SAME message, so a hidden Organization is not distinguishable from a missing one.
 */
class AccessibleOrganization implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value) || ! Organization::query()->whereKey((int) $value)->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
